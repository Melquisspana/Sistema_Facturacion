<?php

namespace App\Console\Commands;

use App\Models\PpqAlbaran;
use App\Services\Ppq\AlbaranParser;
use App\Support\Albaran;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Vuelve a leer la fecha de los albaranes desde su PDF guardado. Antes el lector tomaba la
 * fecha del PEDIDO («Pedido de Compras … de Fecha 23/09/2026») en lugar de la del albarán,
 * y el archivo de quedan salía con el mes equivocado: el portal de Calleja no encontraba los
 * albaranes creados en otro mes. Solo cambia `fecha_albaran` y solo si el PDF trae otra.
 * Idempotente: una segunda corrida no cambia nada.
 */
class PpqRecalcularFechaAlbaranesCommand extends Command
{
    protected $signature = 'ppq:recalcular-fecha-albaranes
        {--desde= : Solo albaranes registrados desde esta fecha (AAAA-MM-DD)}
        {--dry-run : Solo muestra lo que cambiaría, sin cambiar nada}';

    protected $description = 'Corrige la fecha de los albaranes leyéndola de nuevo de su PDF (fecha del albarán, no del pedido)';

    public function handle(AlbaranParser $parser): int
    {
        $desde = $this->option('desde');
        if (filled($desde) && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $desde)) {
            $this->error('La fecha --desde debe ser AAAA-MM-DD.');

            return self::FAILURE;
        }

        $simular = (bool) $this->option('dry-run');
        $disco = Storage::disk((string) config('dte.storage.disk', 'local'));
        $cambios = [];
        $sinArchivo = 0;
        $sinFecha = 0;
        $iguales = 0;

        PpqAlbaran::query()->whereNotNull('archivo_path')
            ->when(filled($desde), fn ($q) => $q->whereDate('created_at', '>=', $desde))
            ->orderBy('id')
            ->chunkById(200, function ($albaranes) use ($parser, $disco, $simular, &$cambios, &$sinArchivo, &$sinFecha, &$iguales) {
                foreach ($albaranes as $albaran) {
                    try {
                        $contenido = $disco->exists($albaran->archivo_path) ? $disco->get($albaran->archivo_path) : null;
                    } catch (Throwable) {
                        $contenido = null;
                    }
                    if (blank($contenido)) {
                        $sinArchivo++;

                        continue;
                    }

                    $nueva = Albaran::fecha($parser->desdePdf($contenido)['fecha']);
                    $actual = $albaran->fecha_albaran?->toDateString();
                    if ($nueva === null) {
                        $sinFecha++;

                        continue;
                    }
                    if ($nueva === $actual) {
                        $iguales++;

                        continue;
                    }

                    $cambios[] = [$albaran->id, $albaran->numero_albaran, $actual ?? '—', $nueva];
                    if (! $simular) {
                        $albaran->forceFill(['fecha_albaran' => $nueva])->save();
                    }
                }
            });

        if ($cambios !== []) {
            $this->table(['Id', 'Albarán', 'Fecha guardada', 'Fecha del PDF'], $cambios);
        }
        $this->info(($simular ? 'Cambiarían' : 'Corregidos').': '.count($cambios)
            ." · Ya estaban bien: {$iguales} · PDF sin fecha legible: {$sinFecha} · Sin archivo: {$sinArchivo}");
        if ($simular) {
            $this->warn('Simulación: no se cambió nada.');
        }

        return self::SUCCESS;
    }
}
