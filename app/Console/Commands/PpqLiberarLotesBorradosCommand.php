<?php

namespace App\Console\Commands;

use App\Models\PpqLote;
use App\Services\Cobros\LiberarCcfDeLoteBorrado;
use Illuminate\Console\Command;

/**
 * Reparación de los lotes PPQ que se borraron ANTES del arreglo del issue #8: sus CCF
 * quedaron como «En PPQ» en el Seguimiento. Misma regla que el borrado desde la ficha
 * ({@see LiberarCcfDeLoteBorrado}). Idempotente: una segunda corrida no cambia nada.
 */
class PpqLiberarLotesBorradosCommand extends Command
{
    protected $signature = 'ppq:liberar-lotes-borrados
        {--lote=* : Ids de lotes borrados (por defecto, todos los borrados)}
        {--dry-run : Solo muestra lo que haría, sin cambiar nada}';

    protected $description = 'Devuelve a por presentar los CCF pendientes que quedaron «En PPQ» por un lote borrado';

    public function handle(LiberarCcfDeLoteBorrado $liberador): int
    {
        $ids = array_values(array_unique($this->option('lote')));
        if (collect($ids)->contains(fn ($id) => ! ctype_digit((string) $id) || (int) $id < 1)) {
            $this->error('Indique ids de lotes válidos.');

            return self::FAILURE;
        }

        $consulta = PpqLote::onlyTrashed()->when($ids !== [], fn ($q) => $q->whereIn('id', $ids));
        if ($ids !== [] && (clone $consulta)->count() !== count($ids)) {
            $this->error('Todos los ids indicados deben ser de lotes borrados.');

            return self::FAILURE;
        }

        $simular = (bool) $this->option('dry-run');
        $totales = ['liberados' => 0, 'conservados' => 0, 'sin_cambio' => 0];

        foreach ($consulta->orderBy('id')->get() as $lote) {
            $resultado = $liberador->liberar($lote, aplicar: ! $simular);

            $this->newLine();
            $this->info("PPQ #{$lote->id} «{$lote->referencia}» · borrado el {$lote->deleted_at}");
            $this->line(($simular ? 'Volverían' : 'Volvieron').' a por presentar: '.$resultado['liberados']->count());
            if ($resultado['liberados']->isNotEmpty()) {
                $this->table(['CCF', 'Monto'], $resultado['liberados']
                    ->map(fn ($doc) => [$doc->correlativoCorto(), $doc->monto])->all());
            }
            $this->line('No se tocan: '.$resultado['conservados']->count());
            if ($resultado['conservados']->isNotEmpty()) {
                $this->table(['CCF', 'Motivo'], $resultado['conservados']
                    ->map(fn ($fila) => [$fila['documento']->correlativoCorto(), $fila['motivo']])->all());
            }
            $this->line('Ya estaban por presentar: '.$resultado['sin_cambio']->count());

            foreach ($totales as $grupo => $n) {
                $totales[$grupo] = $n + $resultado[$grupo]->count();
            }
        }

        $this->newLine();
        $this->info("Total: {$totales['liberados']} liberados; {$totales['conservados']} no se tocan; {$totales['sin_cambio']} ya estaban por presentar.");
        if ($simular) {
            $this->warn('Simulación: no se cambió nada.');
        }

        return self::SUCCESS;
    }
}
