<?php

namespace App\Console\Commands;

use App\Exceptions\Dte\DteTransmisionException;
use App\Models\Contingencia;
use App\Services\Dte\ContingenciaEventoService;
use App\Services\Dte\ContingenciaService;
use App\Services\Dte\ContingenciaTransporte;
use App\Services\Dte\DteTransmisionAuthService;
use App\Services\Dte\DteTransmisionService;
use App\Services\Dte\LoteDteService;
use App\Support\Dte\EndpointsHacienda;
use App\Support\HoraNegocio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class DteContingenciaReconexionCommand extends Command
{
    protected $signature = 'dte:contingencia-reconexion';

    protected $description = 'Detecta la reconexión del MH y envía avisos pendientes desde las 12 horas del cese';

    public function handle(DteTransmisionAuthService $auth, ContingenciaEventoService $eventos, ContingenciaTransporte $transporte, DteTransmisionService $transmision, ContingenciaService $contingencias): int
    {
        if (! config('dte.contingencia.enabled', false) || ! Schema::hasTable('contingencias') || ! Schema::hasTable('contingencia_eventos')) {
            return self::SUCCESS;
        }
        // También cubre invocaciones manuales concurrentes con el scheduler.
        $lock = Cache::lock('dte:contingencia-reconexion', 900);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            $activa = Contingencia::where('estado', 'activa')->first();
            if ($activa) {
                try {
                    $ambiente = EndpointsHacienda::ambienteTransmision();
                    $transporte->verificar($ambiente, 'auth', EndpointsHacienda::authOficial($ambiente), EndpointsHacienda::auth($ambiente));
                    $transporte->verificar($ambiente, 'recepcion', EndpointsHacienda::recepcionOficial($ambiente), EndpointsHacienda::recepcion($ambiente));
                    $auth->comprobarConexion();
                    // El login puede contestar con la recepción todavía caída: sin una
                    // respuesta de la recepción misma no se le declara un cese a Hacienda.
                    if (! $transmision->recepcionResponde()) {
                        throw new DteTransmisionException('La recepción del MH no responde.');
                    }
                    if ($activa->origen === 'automatica') {
                        DB::transaction(function () use ($activa) {
                            $actual = Contingencia::whereKey($activa->id)->lockForUpdate()->firstOrFail();
                            if ($actual->estado !== 'activa' || $actual->origen !== 'automatica') {
                                return;
                            }
                            $actual->update(['cese' => HoraNegocio::ahora(), 'estado' => 'cerrada', 'activa_unica' => null]);
                            activity('dte_contingencia')->performedOn($actual)->withProperties(['cese' => $actual->cese])
                                ->log('Reconexión automática: contingencia terminada');
                            $this->info('Volvió la conexión: enviá el aviso a Hacienda.');
                        });
                    } else {
                        // Una manual no se cierra sola: pudo activarse por otro motivo
                        // (el firmador, la luz). Se avisa en pantalla y por correo.
                        if ($contingencias->anotarHaciendaResponde($activa, true)) {
                            activity('dte_contingencia')->performedOn($activa)
                                ->log('Reconexión: Hacienda ya responde y la contingencia manual sigue activa');
                        }
                        $this->warn('Hacienda ya responde: terminá la contingencia si su motivo ya pasó.');
                    }
                } catch (DteTransmisionException $e) {
                    $contingencias->anotarHaciendaResponde($activa, false);
                    $this->warn('El MH sigue sin conexión confirmada.');
                } catch (ValidationException $e) {
                    $this->warn($e->getMessage());
                }
            }
            foreach (Contingencia::where('estado', 'cerrada')->whereNotNull('cese')
                ->where('cese', '<=', HoraNegocio::ahora()->subHours(max(12, (int) config('dte.contingencia.envio_automatico_horas', 12))))->get() as $pendiente) {
                try {
                    // Un rechazo o una parte en duda exigen revisión humana. Las partes
                    // recibidas se omiten en enviar(); un timeout de otra parte sigue pendiente.
                    if ($pendiente->eventos()->whereIn('estado', ['rechazado', 'incierto'])->exists()) {
                        continue;
                    }
                    $resultados = $eventos->enviar($pendiente);
                    if (collect($resultados)->contains('resultado', 'sin_documentos')) {
                        app(LoteDteService::class)->actualizarEstado($pendiente->refresh());
                    }
                    activity('dte_contingencia')->performedOn($pendiente)->withProperties(['resultados' => $resultados])
                        ->log('Envío automático del evento tras 12 horas del cese');
                } catch (Throwable $e) {
                    report($e);
                    $this->warn('No se pudo enviar el aviso de contingencia #'.$pendiente->id.'. Revisá su plazo y configuración.');
                }
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
