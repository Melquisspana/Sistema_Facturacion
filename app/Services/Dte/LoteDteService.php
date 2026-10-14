<?php

namespace App\Services\Dte;

use App\Enums\EstadoDte;
use App\Models\Contingencia;
use App\Models\ContingenciaEvento;
use App\Models\Dte;
use App\Models\LoteDte;
use App\Support\Dte\CodigoGeneracion;
use App\Support\Dte\EndpointsHacienda;
use App\Support\HoraNegocio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class LoteDteService
{
    public function __construct(
        private readonly ContingenciaTransporte $transporte,
        private readonly ContingenciaEventoService $eventos,
        private readonly DteTransmisionAuthService $auth,
        private readonly DteTransmisionService $transmision,
    ) {}

    public function plazo(ContingenciaEvento $evento): ?Carbon
    {
        $desde = $evento->fecha_procesamiento ?? $evento->fecha_transmision;

        return $desde ? HoraNegocio::aLocal($desde)->addHours(72) : null;
    }

    public function actualizarEstado(Contingencia $contingencia): void
    {
        abort_unless(config('dte.contingencia.enabled', false), 404);
        if (! in_array($contingencia->estado, ['cerrada', 'informada', 'vencida'], true)) {
            return;
        }
        $pendientes = $contingencia->dtes()->whereNotIn('estado', [EstadoDte::Aceptado->value, EstadoDte::Rechazado->value]);
        if ($contingencia->dtes()->exists() && ! $pendientes->exists()) {
            $contingencia->update(['estado' => 'regularizada']);

            return;
        }
        foreach ($this->eventos->partesVigentes($contingencia) as $evento) {
            $plazo = $this->plazo($evento);
            if ($evento->estado === 'recibido' && $plazo && HoraNegocio::ahora()->gt($plazo)
                && $evento->dtes()->whereNotIn('estado', ['aceptado', 'rechazado'])->exists()) {
                $contingencia->update(['estado' => 'vencida']);

                return;
            }
        }
    }

    private function verificarEvento(ContingenciaEvento $evento): void
    {
        abort_unless(config('dte.contingencia.enabled', false), 404);
        $contingencia = $evento->contingencia;
        $this->actualizarEstado($contingencia);
        if ($evento->estado !== 'recibido' || in_array($contingencia->estado, ['vencida', 'regularizada'], true)
            || $this->eventos->partesVigentes($contingencia)->firstWhere('parte', $evento->parte)?->id !== $evento->id
            || ! $this->plazo($evento) || HoraNegocio::ahora()->gt($this->plazo($evento))) {
            throw ValidationException::withMessages(['contingencia' => 'Evento no recibido, reemplazado o plazo de 72 horas vencido.']);
        }
    }

    public function preparar(ContingenciaEvento $evento): Collection
    {
        $this->verificarEvento($evento);

        return DB::transaction(function () use ($evento) {
            Contingencia::whereKey($evento->contingencia_id)->lockForUpdate()->firstOrFail();
            $evento = ContingenciaEvento::whereKey($evento->id)->lockForUpdate()->firstOrFail();
            $this->verificarEvento($evento);
            $documentos = $evento->dtes()->whereNull('lote_dte_id')->where('estado', EstadoDte::Firmado->value)
                ->where(fn ($q) => $q->whereNull('sello_recepcion')->orWhere('sello_recepcion', ''))
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($documentos->chunk(max(1, min(100, (int) config('dte.contingencia.lote_max', 100)))) as $chunk) {
                $lote = LoteDte::create(['contingencia_evento_id' => $evento->id, 'id_envio' => CodigoGeneracion::generar(),
                    'estado' => 'pendiente', 'cantidad' => $chunk->count()]);
                Dte::whereIn('id', $chunk->modelKeys())->whereNull('lote_dte_id')->update(['lote_dte_id' => $lote->id]);
            }

            return LoteDte::where('contingencia_evento_id', $evento->id)->orderBy('id')->get();
        });
    }

    public function enviar(ContingenciaEvento $evento): array
    {
        $lotes = $this->preparar($evento);
        $resultados = [];
        foreach ($lotes->where('estado', 'pendiente') as $lote) {
            $resultado = $this->enviarLote($lote);
            $resultados[] = $resultado;
            if (in_array($resultado['resultado'], ['error_conexion', 'respuesta_incierta'], true)) {
                break;
            }
        }

        return $resultados;
    }

    public function enviarLote(LoteDte $lote): array
    {
        $this->verificarEvento($lote->contingenciaEvento);

        return DB::transaction(function () use ($lote) {
            Contingencia::whereKey($lote->contingenciaEvento->contingencia_id)->lockForUpdate()->firstOrFail();
            $lote = LoteDte::whereKey($lote->id)->lockForUpdate()->firstOrFail();
            if ($lote->estado !== 'pendiente' || filled($lote->codigo_lote)) {
                return ['resultado' => $lote->estado];
            }
            $this->verificarEvento($lote->contingenciaEvento);
            $documentos = $lote->dtes()->with('establecimiento.empresa')->orderBy('id')->get();
            $ambiente = $documentos->first()?->ambiente;
            if (! $ambiente || $documentos->contains(fn ($dte) => $dte->estado !== EstadoDte::Firmado || filled($dte->sello_recepcion)
                || $dte->ambiente !== $ambiente || $dte->contingencia_evento_id !== $lote->contingencia_evento_id)) {
                throw ValidationException::withMessages(['contingencia' => 'El lote contiene documentos no elegibles.']);
            }
            $url = EndpointsHacienda::lote($ambiente);
            $this->transporte->verificar($ambiente, 'lote', EndpointsHacienda::loteOficial($ambiente), $url);
            $disco = Storage::disk(config('dte.storage.disk', 'local'));
            $ruta = 'dte/contingencia/lotes/'.$lote->id_envio;
            // Se conserva el mismo payload e idEnvio ante respuestas inciertas.
            if ($disco->exists($ruta.'.json')) {
                $payload = json_decode($disco->get($ruta.'.json'), true, 512, JSON_THROW_ON_ERROR);
            } else {
                $firmados = $documentos->map(function ($dte) use ($disco) {
                    if (blank($dte->json_firmado_path) || ! $disco->exists($dte->json_firmado_path)) {
                        throw ValidationException::withMessages(['contingencia' => 'Falta el JWS del documento.']);
                    }
                    $jws = trim($disco->get($dte->json_firmado_path));
                    if ($jws === '' || str_contains($jws, 'MOCK')) {
                        throw ValidationException::withMessages(['contingencia' => 'Firma vacia o simulada.']);
                    }

                    return $jws;
                })->all();
                $payload = ['ambiente' => $ambiente->value, 'idEnvio' => $lote->id_envio,
                    'version' => config('dte.contingencia.lote_version', 1),
                    'nitEmisor' => preg_replace('/\D/', '', $documentos->first()->establecimiento->empresa->nit), 'documentos' => $firmados];
                $this->transporte->guardar($ruta.'.json', json_encode($payload, JSON_THROW_ON_ERROR));
            }
            try {
                $respuesta = $this->cliente()->post($url, $payload);
            } catch (ConnectionException) {
                return ['resultado' => 'error_conexion'];
            }
            $cuerpo = $respuesta->json();
            $path = $ruta.'-envio-'.CodigoGeneracion::generar().'.json';
            $this->transporte->guardar($path, $respuesta->body());
            $recibido = $respuesta->successful() && is_array($cuerpo) && ($cuerpo['estado'] ?? '') === 'RECIBIDO' && filled($cuerpo['codigoLote'] ?? null);
            $rechazado = $respuesta->status() === 400 && is_array($cuerpo) && ($cuerpo['estado'] ?? '') === 'RECHAZADO';
            $lote->update(['respuesta_envio' => is_array($cuerpo) ? $cuerpo : null, 'respuesta_envio_path' => $path,
                'estado' => $recibido ? 'recibido' : ($rechazado ? 'error' : 'pendiente'),
                'codigo_lote' => $recibido ? $cuerpo['codigoLote'] : null, 'enviado_en' => HoraNegocio::ahora()]);

            if ($rechazado) {
                // El rechazo definitivo permite otro idEnvio; el intento y su evidencia se conservan.
                $lote->dtes()->where('estado', EstadoDte::Firmado->value)
                    ->where(fn ($q) => $q->whereNull('sello_recepcion')->orWhere('sello_recepcion', ''))
                    ->update(['lote_dte_id' => null]);
            }

            return ['resultado' => $recibido ? 'recibido' : ($rechazado ? 'error' : 'respuesta_incierta')];
        });
    }

    private function cliente(): PendingRequest
    {
        return Http::timeout((int) config('dte.transmision.timeout', 8))->acceptJson()
            ->withHeaders(['Authorization' => $this->auth->obtenerToken(), 'User-Agent' => config('dte.transmision.user_agent', 'DTE/1.0')]);
    }

    public function consultar(LoteDte $lote): array
    {
        abort_unless(config('dte.contingencia.enabled', false), 404);

        return DB::transaction(function () use ($lote) {
            Contingencia::whereKey($lote->contingenciaEvento->contingencia_id)->lockForUpdate()->firstOrFail();
            $lote = LoteDte::whereKey($lote->id)->lockForUpdate()->firstOrFail();
            if ($lote->estado !== 'recibido' || blank($lote->codigo_lote)) {
                return ['resultado' => $lote->estado];
            }
            $ambiente = $lote->dtes()->firstOrFail()->ambiente;
            $url = EndpointsHacienda::consultaLote($ambiente, $lote->codigo_lote);
            $this->transporte->verificar($ambiente, 'consulta_lote', EndpointsHacienda::consultaLoteOficial($ambiente, $lote->codigo_lote), $url);
            $lote->update(['consultas' => min(65535, $lote->consultas + 1), 'ultima_consulta_en' => HoraNegocio::ahora()]);
            try {
                $respuesta = $this->cliente()->get($url);
            } catch (ConnectionException) {
                return ['resultado' => 'error_conexion'];
            }
            $cuerpo = $respuesta->json();
            $ruta = 'dte/contingencia/lotes/'.$lote->id_envio.'-consulta-'.CodigoGeneracion::generar().'.json';
            $this->transporte->guardar($ruta, $respuesta->body());
            $lote->update(['respuesta_consulta' => is_array($cuerpo) ? $cuerpo : null, 'respuesta_consulta_path' => $ruta]);
            if (! $respuesta->successful() || ! is_array($cuerpo)) {
                return ['resultado' => 'respuesta_incierta'];
            }
            $documentos = $lote->dtes()->lockForUpdate()->get()->keyBy('codigo_generacion');
            // index es solo informativo: nunca identifica un DTE.
            $entradas = [];
            foreach (['procesados' => 'aceptado', 'rechazados' => 'rechazado'] as $grupo => $estado) {
                foreach (is_array($cuerpo[$grupo] ?? null) ? $cuerpo[$grupo] : [] as $item) {
                    if (! is_array($item) || blank($item['codigoGeneracion'] ?? null)) {
                        continue;
                    }
                    $entradas[$item['codigoGeneracion']][] = [$estado, $item];
                }
            }
            foreach ($entradas as $codigo => $items) {
                $dte = $documentos->get($codigo);
                if (! $dte || count($items) !== 1 || $dte->estado !== EstadoDte::Firmado) {
                    continue;
                }
                [$estado, $item] = $items[0];
                if ($estado === 'aceptado' && blank($item['selloRecibido'] ?? null)) {
                    continue;
                }
                $item['estado'] = $estado === 'aceptado' ? 'PROCESADO' : 'RECHAZADO';
                $item['fhProcesamiento'] ??= $cuerpo['fhProcesamiento'] ?? $cuerpo['fechaHora'] ?? null;
                $this->transmision->aplicarResultadoDeConsulta($dte, ['resultado' => $estado, 'http_status' => $respuesta->status(),
                    'sello' => $item['selloRecibido'] ?? null, 'mensaje' => $item['descripcionMsg'] ?? $item['mensaje'] ?? '', 'cuerpo' => $item]);
            }
            $aceptados = $lote->dtes()->where('estado', 'aceptado')->count();
            $rechazados = $lote->dtes()->where('estado', 'rechazado')->count();
            $cerrado = $aceptados + $rechazados === $lote->cantidad;
            $lote->update(['procesados' => $aceptados, 'rechazados' => $rechazados,
                'estado' => $cerrado ? 'procesado' : 'recibido', 'cerrado_en' => $cerrado ? HoraNegocio::ahora() : null]);
            $this->actualizarEstado($lote->contingenciaEvento->contingencia);

            return ['resultado' => $lote->estado];
        });
    }
}
