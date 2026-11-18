<?php

namespace App\Console\Commands;

use App\Exceptions\Dte\DteTransmisionException;
use App\Models\Contingencia;
use App\Models\LoteDte;
use App\Services\Dte\ContingenciaEventoService;
use App\Services\Dte\LoteDteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DteContingenciaLotesCommand extends Command
{
    protected $signature = 'dte:contingencia-lotes';

    protected $description = 'Envia documentos informados y consulta lotes abiertos de contingencia';

    /**
     * Lo que falla en un aviso o en un lote no frena a los demás. Sin poder iniciar sesión
     * en Hacienda no se envió nada y la corrida siguiente vuelve a probar: termina bien,
     * con el aviso. Un candado de seguridad o la integración apagada necesitan que alguien
     * los revise: se informan con su mensaje y la tarea termina con error.
     */
    public function handle(LoteDteService $service, ContingenciaEventoService $eventos): int
    {
        if (! config('dte.contingencia.enabled', false) || ! Schema::hasTable('lotes_dte') || ! Schema::hasTable('contingencias')) {
            return self::SUCCESS;
        }
        $bloqueada = false;
        foreach (Contingencia::whereIn('estado', ['cerrada', 'informada', 'vencida'])->get() as $contingencia) {
            $service->actualizarEstado($contingencia);
            if ($contingencia->estado === 'vencida') {
                continue;
            }
            foreach ($eventos->partesVigentes($contingencia)->where('estado', 'recibido') as $evento) {
                // Tras un lote rechazado entero espera más cada vez, con tope. Acá solo se
                // avisa: la espera se decide en enviar(), con el candado de la contingencia.
                if ($pausa = $service->pausaTrasRechazos($evento)) {
                    $this->warn($pausa);
                }
                $bloqueada = ! $this->intentar(fn () => $service->enviar($evento, esperarTrasRechazos: true)) || $bloqueada;
            }
        }
        foreach (LoteDte::where('estado', 'recibido')->get() as $lote) {
            $bloqueada = ! $this->intentar(fn () => $service->consultar($lote)) || $bloqueada;
        }

        return $bloqueada ? self::FAILURE : self::SUCCESS;
    }

    /** false solo si la tarea quedó frenada por un candado o por la integración apagada. */
    private function intentar(callable $accion): bool
    {
        try {
            $accion();
        } catch (ValidationException $e) {
            $this->warn(collect($e->errors())->flatten()->implode(' | '));
        } catch (DteTransmisionException $e) {
            $this->error($e->getMessage());

            return false;
        }

        return true;
    }
}
