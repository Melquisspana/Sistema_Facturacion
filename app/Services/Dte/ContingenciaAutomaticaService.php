<?php

namespace App\Services\Dte;

use App\Enums\EstadoDte;
use App\Exceptions\Dte\DteFirmaException;
use App\Exceptions\Dte\DteTransmisionException;
use App\Models\Contingencia;
use App\Models\Dte;
use App\Models\User;
use App\Support\Dte\CodigoGeneracion;
use App\Support\HoraNegocio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ContingenciaAutomaticaService
{
    public function __construct(
        private readonly ContingenciaService $contingencias,
        private readonly DteSchemaValidator $validador,
        private readonly DteFirmaService $firma,
        private readonly ContingenciaTransporte $transporte,
        private readonly BitacoraEnvioDte $bitacora,
    ) {}

    /**
     * Exclusivo del resultado confirmado de la política; nunca consulta ni transmite.
     *
     * Convierte solo si está PROBADO que Hacienda no tiene el documento, por una de dos:
     *   · la consulta contestó «no encontrado»; o
     *   · ningún envío del documento llegó a salir de la máquina (sin internet la
     *     consulta tampoco contesta). Esta no se le cree al resultado que llega: se
     *     vuelve a leer la bitácora de envíos acá, antes de activar nada, y otra vez más
     *     abajo con el documento bloqueado ({@see BitacoraEnvioDte::sinEnviosEnDuda()}).
     */
    public function convertirTrasReintentos(Dte $dte, array $resultado, ?User $usuario = null): bool
    {
        if (! config('dte.contingencia.enabled', false)) {
            return false;
        }
        if (($resultado['resultado'] ?? null) !== 'reintentos_agotados'
            || ($resultado['contingencia_requerida'] ?? false) !== true
            || ! empty($resultado['consulta_no_disponible'])) {
            return false;
        }
        $porConsulta = ($resultado['consulta_resultado'] ?? null) === 'no_encontrado';
        $porSinSalida = ! $porConsulta && ($resultado['sin_salida'] ?? false) === true
            && $this->bitacora->sinEnviosEnDuda($dte);
        if (! $porConsulta && ! $porSinSalida) {
            return false;
        }

        // El inicio de la contingencia retrocede hasta la emisión del documento (ver abajo),
        // pero no sin límite: un documento firmado hace horas (por ejemplo, con el worker
        // caído) no puede declarar contingencia por horas en que Hacienda sí respondía. Más
        // allá del tope no se convierte ni se activa nada: queda para revisión manual.
        if (! $this->dentroDeLaVentana($dte)) {
            $emision = $this->emision($dte);
            $tope = $this->topeMinutos();
            Log::warning('Contingencia: documento fuera de la ventana para la entrada automática', [
                'dte_id' => $dte->id, 'emision' => $emision?->toDateTimeString(), 'tope_minutos' => $tope]);
            activity('dte_contingencia')->performedOn($dte)
                ->withProperties(['emision' => $emision?->toDateTimeString(), 'tope_minutos' => $tope])
                ->log('Reintentos agotados con un documento emitido hace más del tope: revisión manual, sin contingencia automática');

            return false;
        }

        $contingencia = $this->contingencias->activa();
        if (! $contingencia) {
            try {
                // Cualquier respuesta HTTP demuestra salida a internet.
                Http::timeout(3)->connectTimeout(3)->get(config('dte.contingencia.sonda_internet_url'));
                $tipo = 1;
            } catch (ConnectionException) {
                $tipo = 3;
            }
            try {
                $contingencia = $this->contingencias->activar($tipo,
                    $tipo === 1 ? 'Hacienda no responde tras agotar los reintentos.' : 'Sin conexión a internet tras agotar los reintentos.',
                    'automatica', $usuario);
            } catch (ValidationException $e) {
                // Incluye la carrera por activa_unica, una vez revertido el INSERT.
                $contingencia = $this->contingencias->activa();
                if (! $contingencia) {
                    throw $e;
                }
            }
        }

        return DB::transaction(function () use ($contingencia, $dte, $usuario, $resultado, $porSinSalida) {
            $contingencia = Contingencia::whereKey($contingencia->id)->lockForUpdate()->firstOrFail();
            $actual = Dte::whereKey($dte->id)->lockForUpdate()->firstOrFail();
            if ($actual->contingencia_id !== null) {
                return $actual->esTransitorio();
            }
            if ($contingencia->estado !== 'activa' || $actual->estado !== EstadoDte::Firmado
                || filled($actual->sello_recepcion) || blank($actual->json_firmado_path)) {
                throw new DteTransmisionException('No se puede convertir este documento a contingencia.');
            }
            // Con el documento bloqueado no puede empezar otro envío: si entre la primera
            // lectura y esta alguno empezó, ya no está probado que no salió.
            if ($porSinSalida && ! $this->bitacora->sinEnviosEnDuda($actual)) {
                throw new DteTransmisionException('No se puede convertir este documento a contingencia.');
            }
            $disco = Storage::disk(config('dte.storage.disk', 'local'));
            $json = json_decode($disco->get($actual->json_generado_path), true, 512, JSON_THROW_ON_ERROR);
            if (($json['identificacion']['codigoGeneracion'] ?? null) !== $actual->codigo_generacion
                || ($json['identificacion']['numeroControl'] ?? null) !== $actual->numero_control) {
                throw new DteTransmisionException('El JSON no corresponde a la numeración del documento.');
            }
            // El MH rechaza un documento de contingencia emitido ANTES del inicio informado
            // («fecEmi/horEmi VALOR NO ES PERMITIDO», ensayo en apitest del 19/10/2026). El
            // documento que dispara la entrada se emitió segundos antes de activarla: el
            // inicio retrocede hasta su emisión. Es seguro mientras la contingencia siga
            // activa, porque su evento todavía no se envió. El tope ya se comprobó arriba.
            // Se escribe en la zona del negocio, igual que ContingenciaService::activar()
            // escribe el inicio con HoraNegocio::ahora().
            $emision = $this->emisionDesdeJson($json);
            if ($emision !== null && $emision->lt(HoraNegocio::aLocal($contingencia->inicio))) {
                $contingencia->update(['inicio' => $emision]);
            }
            $json['identificacion'] = array_replace($json['identificacion'], [
                'tipoModelo' => 2, 'tipoOperacion' => 2,
                'tipoContingencia' => $contingencia->tipo, 'motivoContin' => $contingencia->motivo,
            ]);
            $validacion = $this->validador->validar($json, $actual->tipo_dte);
            if (! $validacion['valido']) {
                throw new DteTransmisionException('El JSON de contingencia no es válido: '.implode(' | ', $validacion['errores']));
            }
            $jws = $this->firma->firmarJson($json);
            $partes = explode('.', $jws);
            $payload = count($partes) === 3 ? base64_decode(strtr($partes[1], '-_', '+/'), true) : false;
            if ($payload === false || json_decode($payload, true) != $json) {
                throw new DteFirmaException('La nueva firma no corresponde al JSON de contingencia.');
            }
            // Nuevas rutas: un fallo o rollback conserva intacta la firma original.
            $ruta = 'dte/contingencia/'.$contingencia->id.'/dte-'.$actual->id.'-'.CodigoGeneracion::generar();
            $this->transporte->guardar($ruta.'.json', json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->transporte->guardar($ruta.'.jws', $jws);
            // Excepción acotada a estos campos bajo bloqueo, sin desactivar el observer.
            Dte::whereKey($actual->id)->update([
                'tipo_modelo' => 2, 'tipo_operacion' => 2, 'tipo_contingencia' => $contingencia->tipo,
                'motivo_contingencia' => $contingencia->motivo, 'contingencia_id' => $contingencia->id,
                'json_generado_path' => $ruta.'.json', 'json_firmado_path' => $ruta.'.jws', 'pdf_path' => null,
            ]);
            activity('dte_contingencia')->performedOn($actual)->causedBy($usuario)
                ->withProperties(['contingencia_id' => $contingencia->id,
                    'consulta_resultado' => $resultado['consulta_resultado'] ?? null, 'sin_salida' => $porSinSalida,
                    'json_anterior' => $actual->json_generado_path, 'firma_anterior' => $actual->json_firmado_path])
                ->log('Documento re-firmado en contingencia tras reintentos agotados');
            $dte->refresh();

            return true;
        });
    }

    /**
     * ¿Se emitió el documento dentro del tope de minutos que permite pasarlo a contingencia
     * (`retroceso_max_minutos`)? Sin una emisión legible en su JSON, no.
     */
    public function dentroDeLaVentana(Dte $dte): bool
    {
        $emision = $this->emision($dte);

        return $emision !== null && ! $emision->lt(HoraNegocio::ahora()->subMinutes($this->topeMinutos()));
    }

    private function topeMinutos(): int
    {
        return max(1, (int) config('dte.contingencia.retroceso_max_minutos', 10));
    }

    /** Emisión del documento según su JSON generado (lo que el MH compara), en hora local. */
    private function emision(Dte $dte): ?Carbon
    {
        $ruta = $dte->json_generado_path;
        $disco = Storage::disk(config('dte.storage.disk', 'local'));
        if (blank($ruta) || ! $disco->exists($ruta)) {
            return null;
        }

        return $this->emisionDesdeJson(json_decode($disco->get($ruta), true) ?: []);
    }

    /** @param  array<string, mixed>  $json */
    private function emisionDesdeJson(array $json): ?Carbon
    {
        $fecha = $json['identificacion']['fecEmi'] ?? null;
        $hora = $json['identificacion']['horEmi'] ?? null;
        if (! is_string($fecha) || ! is_string($hora)) {
            return null;
        }

        return rescue(fn () => Carbon::createFromFormat('Y-m-d H:i:s', $fecha.' '.$hora, HoraNegocio::zona()), null, false);
    }
}
