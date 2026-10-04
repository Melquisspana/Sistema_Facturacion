<?php

namespace App\Console\Commands;

use App\Enums\EstadoDte;
use App\Models\Dte;
use App\Services\Dte\ArchivoEntregaDteService;
use App\Support\Dte\ArchivoEntregaDte;
use Illuminate\Console\Command;

/** Diagnóstico de solo lectura: no regenera ni modifica evidencia. */
class DteEntregaCheckCommand extends Command
{
    protected $signature = 'dte:entrega-check {dte? : ID del DTE}';

    protected $description = 'Revisa el archivo DTE de entrega con firma y sello (solo lectura)';

    public function handle(ArchivoEntregaDteService $archivos): int
    {
        $id = $this->argument('dte');
        $consulta = Dte::query();
        if ($id !== null) {
            $consulta->whereKey($id);
            if (! $consulta->exists()) {
                $this->error('No existe el DTE con id '.$id.'.');

                return self::FAILURE;
            }
        } else {
            $consulta->where('estado', EstadoDte::Aceptado->value);
        }

        $incompletos = 0;
        foreach ($consulta->lazyById() as $dte) {
            $archivo = $archivos->construir($dte);
            if ($archivo->estado === ArchivoEntregaDte::INCOMPLETO) {
                $incompletos++;
            } elseif ($id === null) {
                continue;
            }
            $this->line('DTE #'.$dte->id.' — '.$archivo->explicacion());
            foreach ($archivo->recuperacion as $paso) {
                $this->line('  - '.$paso);
            }
        }

        return $incompletos > 0 ? self::FAILURE : self::SUCCESS;
    }
}
