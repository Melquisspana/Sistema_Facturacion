<?php

namespace App\Services\Dte;

use App\Enums\EstadoDte;
use App\Exceptions\Dte\DteFirmaException;
use App\Exceptions\Dte\DteTransmisionException;
use App\Models\Contingencia;
use App\Models\Dte;
use App\Models\User;
use App\Support\Dte\CodigoGeneracion;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ContingenciaAutomaticaService
{
    public function __construct(
        private readonly ContingenciaService $contingencias,
        private readonly DteSchemaValidator $validador,
        private readonly DteFirmaService $firma,
        private readonly ContingenciaTransporte $transporte,
    ) {}

    /** Exclusivo del resultado confirmado de la política; nunca consulta ni transmite. */
    public function convertirTrasReintentos(Dte $dte, array $resultado, ?User $usuario = null): bool
    {
        if (! config('dte.contingencia.enabled', false)) {
            return false;
        }
        if (($resultado['resultado'] ?? null) !== 'reintentos_agotados'
            || ($resultado['contingencia_requerida'] ?? false) !== true
            || ($resultado['consulta_resultado'] ?? null) !== 'no_encontrado'
            || ! empty($resultado['consulta_no_disponible'])) {
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

        return DB::transaction(function () use ($contingencia, $dte, $usuario) {
            $contingencia = Contingencia::whereKey($contingencia->id)->lockForUpdate()->firstOrFail();
            $actual = Dte::whereKey($dte->id)->lockForUpdate()->firstOrFail();
            if ($actual->contingencia_id !== null) {
                return $actual->esTransitorio();
            }
            if ($contingencia->estado !== 'activa' || $actual->estado !== EstadoDte::Firmado
                || filled($actual->sello_recepcion) || blank($actual->json_firmado_path)) {
                throw new DteTransmisionException('No se puede convertir este documento a contingencia.');
            }
            $disco = Storage::disk(config('dte.storage.disk', 'local'));
            $json = json_decode($disco->get($actual->json_generado_path), true, 512, JSON_THROW_ON_ERROR);
            if (($json['identificacion']['codigoGeneracion'] ?? null) !== $actual->codigo_generacion
                || ($json['identificacion']['numeroControl'] ?? null) !== $actual->numero_control) {
                throw new DteTransmisionException('El JSON no corresponde a la numeración del documento.');
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
                ->withProperties(['contingencia_id' => $contingencia->id, 'consulta_resultado' => 'no_encontrado',
                    'json_anterior' => $actual->json_generado_path, 'firma_anterior' => $actual->json_firmado_path])
                ->log('Documento re-firmado en contingencia tras reintentos agotados');
            $dte->refresh();

            return true;
        });
    }
}
