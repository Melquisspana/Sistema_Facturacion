<?php

namespace App\Services\Dte;

use App\Enums\AmbienteHacienda;
use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Exceptions\Dte\DteTransmisionException;
use App\Support\Dte\CandadoEndpointOficial;
use App\Support\Dte\EndpointsHacienda;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ContingenciaTransporte
{
    public function __construct(private readonly DteTransmisionAuthService $auth) {}

    /**
     * Token para enviar el aviso o los lotes. Si no se puede iniciar sesión en Hacienda
     * (sin conexión, caído o rechazado), todavía no se envió nada: sale un mensaje para
     * volver a probar, no un error del servidor. La integración apagada y los candados de
     * seguridad ({@see DteTransmisionDeshabilitadaException}) siguen con su propio mensaje.
     */
    public function token(): string
    {
        try {
            return $this->auth->obtenerToken();
        } catch (DteTransmisionDeshabilitadaException $e) {
            throw $e;
        } catch (DteTransmisionException $e) {
            Log::warning('Contingencia: no se pudo iniciar sesión en Hacienda', ['error' => $e->getMessage()]);
            $this->error('No se pudo iniciar sesión en Hacienda. No se envió nada: probá de nuevo en unos minutos.');
        }
    }

    public function verificar(AmbienteHacienda $ambiente, string $servicio, string $oficial, string $url): void
    {
        abort_unless(config('dte.contingencia.enabled', false), 404);
        if ($ambiente !== EndpointsHacienda::ambienteTransmision() || ! config('dte.transmision.enabled') && ! (! $ambiente->esProduccion() && config('dte.transmision.test_enabled'))) {
            $this->error('La transmision esta deshabilitada o el ambiente no coincide.');
        }
        if ($ambiente->esProduccion() && ! config('dte.transmision.allow_production', false)) {
            $this->error('La transmision a produccion esta deshabilitada.');
        }
        if (config('dte.transmision.dry_run', true) || ! config('dte.transmision.real_confirmation', false) || config('dte.firma.mock', false)) {
            $this->error('Se requiere habilitar la transmision real y la firma sin mock.');
        }
        CandadoEndpointOficial::verificar($ambiente, $servicio, $oficial, $url);
    }

    public function guardar(string $ruta, string $contenido): void
    {
        if (! Storage::disk(config('dte.storage.disk', 'local'))->put($ruta, $contenido)) {
            $this->error('No se pudo guardar la evidencia del evento.');
        }
    }

    private function error(string $mensaje): never
    {
        throw ValidationException::withMessages(['contingencia' => $mensaje]);
    }
}
