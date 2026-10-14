<?php

namespace App\Console\Commands;

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

    public function handle(LoteDteService $service, ContingenciaEventoService $eventos): int
    {
        if (! config('dte.contingencia.enabled', false) || ! Schema::hasTable('lotes_dte') || ! Schema::hasTable('contingencias')) {
            return self::SUCCESS;
        }
        foreach (Contingencia::whereIn('estado', ['cerrada', 'informada', 'vencida'])->get() as $contingencia) {
            $service->actualizarEstado($contingencia);
            if ($contingencia->estado === 'vencida') {
                continue;
            }
            foreach ($eventos->partesVigentes($contingencia)->where('estado', 'recibido') as $evento) {
                try {
                    $service->enviar($evento);
                } catch (ValidationException $e) {
                    $this->warn(collect($e->errors())->flatten()->implode(' | '));
                }
            }
        }
        foreach (LoteDte::where('estado', 'recibido')->get() as $lote) {
            try {
                $service->consultar($lote);
            } catch (ValidationException $e) {
                $this->warn(collect($e->errors())->flatten()->implode(' | '));
            }
        }

        return self::SUCCESS;
    }
}
