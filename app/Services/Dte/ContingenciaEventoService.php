<?php

namespace App\Services\Dte;

use App\Enums\AmbienteHacienda;
use App\Enums\EstadoDte;
use App\Models\Contingencia;
use App\Models\ContingenciaEvento;
use App\Models\Dte;
use App\Services\Dte\Serializadores\SerializadorContingenciaMh;
use App\Support\Dte\CandadoEndpointOficial;
use App\Support\Dte\CodigoGeneracion;
use App\Support\Dte\EndpointsHacienda;
use App\Support\HoraNegocio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ContingenciaEventoService
{
    public function __construct(
        private readonly SerializadorContingenciaMh $serializador,
        private readonly DteSchemaValidator $validador,
        private readonly DteFirmaService $firma,
        private readonly DteTransmisionAuthService $auth,
        private readonly DteConsultaService $consulta,
        private readonly DteTransmisionService $transmision,
    ) {}

    public function plazo(Contingencia $contingencia, ?ContingenciaEvento $evento = null): ?Carbon
    {
        $desde = $evento?->rechazado_en ?? $contingencia->cese;

        return $desde ? HoraNegocio::aLocal($desde)->addHours(24) : null;
    }

    public function requiereInformeTecnico(Contingencia $contingencia): bool
    {
        return HoraNegocio::aLocal($contingencia->cese ?? HoraNegocio::ahora())
            ->gte(HoraNegocio::aLocal($contingencia->inicio)->addDays(3));
    }

    private function verificar(Contingencia $contingencia, ?ContingenciaEvento $evento = null): void
    {
        abort_unless(config('dte.contingencia.enabled', false), 404);
        $plazo = $this->plazo($contingencia, $evento);
        if (! $contingencia->cese || ! in_array($contingencia->estado, ['cerrada', 'informada'], true)) {
            $this->error('No se puede enviar una contingencia sin cese o fuera del estado permitido.');
        }
        if ($plazo === null || HoraNegocio::ahora()->gt($plazo)) {
            $this->error('El plazo de 24 horas vencio. Se requiere gestionar una prorroga ante el MH.');
        }
    }

    private function error(string $mensaje): never
    {
        throw ValidationException::withMessages(['contingencia' => $mensaje]);
    }

    /** Conserva los intentos anteriores; solo los ultimos de cada parte son vigentes. */
    public function partesVigentes(Contingencia $contingencia): Collection
    {
        return $contingencia->eventos()->orderBy('id')->get()->keyBy('parte')->sortKeys()->values();
    }

    public function preparar(Contingencia $contingencia): Collection
    {
        abort_unless(config('dte.contingencia.enabled', false), 404);

        return DB::transaction(function () use ($contingencia) {
            $contingencia = Contingencia::whereKey($contingencia->id)->lockForUpdate()->firstOrFail();
            $ambiente = EndpointsHacienda::ambienteTransmision();
            CandadoEndpointOficial::verificar($ambiente, 'contingencia', EndpointsHacienda::contingenciaOficial($ambiente), EndpointsHacienda::contingencia($ambiente));
            $partes = $this->partesVigentes($contingencia);
            if ($partes->isNotEmpty()) {
                foreach ($partes as $parte) {
                    if ($parte->estado !== 'rechazado') {
                        continue;
                    }
                    $this->verificar($contingencia, $parte);
                    $documentos = $parte->dtes()->with(['establecimiento.empresa', 'puntoVenta'])->orderBy('id')->get();
                    $documentos = $this->consultarDocumentos($documentos);
                    if ($documentos->isEmpty()) {
                        // La respuesta rechazada sigue disponible; ningun DTE queda vinculado.
                        $parte->update(['estado' => 'sin_documentos']);

                        continue;
                    }
                    $this->crearParte($contingencia, $documentos, $parte->parte, $parte->rechazado_en);
                }

                return $this->partesVigentes($contingencia);
            }
            $this->verificar($contingencia);
            $documentos = $contingencia->dtes()->with(['establecimiento.empresa', 'puntoVenta'])
                ->where(fn ($query) => $query->whereNull('sello_recepcion')->orWhere('sello_recepcion', ''))
                ->orderBy('id')->get();
            $documentos = $this->consultarDocumentos($documentos);
            if ($documentos->isEmpty()) {
                return collect();
            }
            $numeroParte = 0;
            // El evento identifica un solo emisor/ambiente y lugar de transmision.
            foreach ($documentos->groupBy(fn ($dte) => $dte->ambiente->value.':'.$dte->establecimiento_id.':'.$dte->punto_venta_id)->values() as $grupo) {
                foreach ($grupo->chunk(1000) as $chunk) {
                    $this->crearParte($contingencia, $chunk, ++$numeroParte);
                }
            }

            return $this->partesVigentes($contingencia);
        });
    }

    private function consultarDocumentos(Collection $documentos): Collection
    {
        return $documentos->filter(function ($dte) {
            if ($dte->ambiente !== EndpointsHacienda::ambienteTransmision()) {
                $this->error('El ambiente de transmision no coincide con el documento.');
            }
            if ($dte->estado !== EstadoDte::Firmado) {
                $this->error('Todos los documentos del evento deben estar firmados.');
            }
            $resultado = $this->consulta->consultar($dte);
            if ($resultado['resultado'] === 'aceptado' && filled($resultado['sello'])) {
                $this->transmision->aplicarResultadoDeConsulta($dte, $resultado);
                // Solo este servicio modifica los vinculos de regularizacion bajo el
                // bloqueo de la contingencia. El observer sigue impidiendo editarlos.
                Dte::whereKey($dte->id)->update(['contingencia_id' => null, 'contingencia_evento_id' => null]);

                return false;
            }
            if ($resultado['resultado'] !== 'no_encontrado') {
                $this->error('No se pudo confirmar que el MH no tiene el documento '.$dte->codigo_generacion.'.');
            }

            return true;
        })->values();
    }

    private function crearParte(Contingencia $contingencia, Collection $documentos, int $numero, ?Carbon $rechazado = null): void
    {
        if ($documentos->isEmpty()) {
            $this->error('No quedan documentos para incluir en el evento.');
        }
        $json = $this->serializador->serializar($contingencia, $documentos);
        $validacion = $this->validador->validarContingencia($json);
        if (! $validacion['valido']) {
            $this->error($validacion['mensaje'].' '.implode(' | ', $validacion['errores']));
        }
        $ruta = 'dte/contingencia/'.$contingencia->id.'/'.$json['identificacion']['codigoGeneracion'];
        $this->guardar($ruta.'.json', json_encode($json, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $evento = $contingencia->eventos()->create(['parte' => $numero,
            'codigo_generacion' => $json['identificacion']['codigoGeneracion'], 'estado' => 'preparado',
            'json_path' => $ruta.'.json', 'rechazado_en' => $rechazado]);
        foreach ($documentos as $dte) {
            Dte::whereKey($dte->id)->update(['contingencia_evento_id' => $evento->id]);
        }
    }

    public function enviar(Contingencia $contingencia): array
    {
        $resultados = [];
        $partes = $this->preparar($contingencia);
        if ($partes->isEmpty()) {
            return [['resultado' => 'sin_documentos', 'mensaje' => 'No quedan documentos pendientes para informar al MH.']];
        }
        foreach ($partes as $evento) {
            if (in_array($evento->estado, ['recibido', 'sin_documentos'], true)) {
                continue;
            }
            $resultados[] = DB::transaction(function () use ($contingencia, $evento) {
                $actual = Contingencia::whereKey($contingencia->id)->lockForUpdate()->firstOrFail();
                $evento = ContingenciaEvento::whereKey($evento->id)->lockForUpdate()->firstOrFail();
                if ($evento->estado === 'recibido') {
                    return ['resultado' => 'recibido'];
                }
                if ($evento->estado !== 'preparado' || $this->partesVigentes($actual)->firstWhere('parte', $evento->parte)?->id !== $evento->id) {
                    $this->error('El evento ya fue enviado o reemplazado.');
                }
                $this->verificar($actual, $evento);
                $json = json_decode(Storage::disk(config('dte.storage.disk', 'local'))->get($evento->json_path), true, 512, JSON_THROW_ON_ERROR);
                $ambiente = AmbienteHacienda::from($json['identificacion']['ambiente']);
                if ($ambiente !== EndpointsHacienda::ambienteTransmision() || ! config('dte.transmision.enabled') && ! (! $ambiente->esProduccion() && config('dte.transmision.test_enabled'))) {
                    $this->error('La transmision esta deshabilitada o el ambiente no coincide.');
                }
                if ($ambiente->esProduccion() && ! config('dte.transmision.allow_production', false)) {
                    $this->error('La transmision a produccion esta deshabilitada.');
                }
                if (config('dte.transmision.dry_run', true) || ! config('dte.transmision.real_confirmation', false) || config('dte.firma.mock', false)) {
                    $this->error('Se requiere habilitar la transmision real y la firma sin mock.');
                }
                CandadoEndpointOficial::verificar($ambiente, 'contingencia', EndpointsHacienda::contingenciaOficial($ambiente), EndpointsHacienda::contingencia($ambiente));
                $validacion = $this->validador->validarContingencia($json);
                if (! $validacion['valido']) {
                    $this->error($validacion['mensaje'].' '.implode(' | ', $validacion['errores']));
                }
                $ruta = substr($evento->json_path, 0, -5);
                $disco = Storage::disk(config('dte.storage.disk', 'local'));
                // Ante un timeout se reintenta EXACTAMENTE el mismo JSON, UUID y JWS.
                $jws = $disco->exists($ruta.'.jws') ? $disco->get($ruta.'.jws') : $this->firma->firmarJson($json);
                if (! $disco->exists($ruta.'.jws')) {
                    $this->guardar($ruta.'.jws', $jws);
                }
                $token = $this->auth->obtenerToken();
                try {
                    $respuesta = Http::timeout((int) config('dte.transmision.timeout', 8))->acceptJson()
                        ->withHeaders(['Authorization' => $token, 'User-Agent' => config('dte.transmision.user_agent', 'DTE/1.0')])
                        ->post(EndpointsHacienda::contingencia($ambiente), ['nit' => $json['emisor']['nit'], 'documento' => $jws]);
                } catch (ConnectionException) {
                    return ['resultado' => 'error_conexion', 'mensaje' => 'No se pudo conectar con el MH. Se puede reintentar.'];
                }
                $cuerpo = $respuesta->json();
                $estado = is_array($cuerpo) ? ($cuerpo['estado'] ?? null) : null;
                // Conserva tambien respuestas inciertas anteriores, sin sobrescribir evidencia.
                $rutaRespuesta = $ruta.'-respuesta-'.CodigoGeneracion::generar().'.json';
                $this->guardar($rutaRespuesta, $respuesta->body());
                if (! in_array($estado, ['RECIBIDO', 'RECHAZADO'], true)
                    || ! ($respuesta->successful() || $respuesta->status() === 400)
                    || ($estado === 'RECIBIDO' && (! $respuesta->successful() || blank($cuerpo['selloRecibido'] ?? null)))) {
                    return ['resultado' => 'respuesta_incierta', 'mensaje' => 'Respuesta del MH no concluyente. Revisar antes de reintentar.'];
                }
                $recibido = $estado === 'RECIBIDO';
                $evento->update(['estado' => $recibido ? 'recibido' : 'rechazado', 'jws_path' => $ruta.'.jws',
                    'respuesta_mh_path' => $rutaRespuesta, 'respuesta_mh' => $cuerpo,
                    'sello_recibido' => $recibido ? $cuerpo['selloRecibido'] : null,
                    'fecha_transmision' => HoraNegocio::ahora(), 'fecha_procesamiento' => $this->fechaMh($cuerpo['fechaHora'] ?? null),
                    'rechazado_en' => $recibido ? null : HoraNegocio::ahora()]);
                if ($this->partesVigentes($actual)->every(fn ($parte) => $parte->estado === 'recibido')) {
                    $actual->update(['estado' => 'informada']);
                }
                activity('dte_contingencia')->performedOn($evento)->withProperties(['estado' => $evento->estado])->log('Evento de contingencia enviado');

                return ['resultado' => $evento->estado, 'mensaje' => $cuerpo['mensaje'] ?? '', 'evento_id' => $evento->id];
            });
            if (in_array(end($resultados)['resultado'], ['error_conexion', 'respuesta_incierta'], true)) {
                break;
            }
        }

        if ($resultados === [] && $partes->contains('estado', 'sin_documentos')) {
            return [['resultado' => 'sin_documentos', 'mensaje' => 'Los documentos de las partes descartadas ya tienen sello del MH.']];
        }

        return $resultados;
    }

    private function guardar(string $ruta, string $contenido): void
    {
        if (! Storage::disk(config('dte.storage.disk', 'local'))->put($ruta, $contenido)) {
            $this->error('No se pudo guardar la evidencia del evento.');
        }
    }

    private function fechaMh(?string $fecha): ?Carbon
    {
        if (blank($fecha)) {
            return null;
        }

        return rescue(fn () => Carbon::createFromFormat('d/m/Y H:i:s', $fecha, HoraNegocio::zona()), null, false)
            ?: rescue(fn () => Carbon::parse($fecha, HoraNegocio::zona()), null, false);
    }
}
