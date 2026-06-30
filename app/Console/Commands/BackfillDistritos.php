<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Distrito;
use App\Models\Empresa;
use App\Models\Establecimiento;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Backfill del distrito (división 2024) en registros que ya tenían el municipio
 * del catálogo previo: como muchos "municipios" antiguos son hoy DISTRITOS con el
 * mismo nombre, se mapea por (departamento_id + nombre del municipio = nombre del
 * distrito). Seguro e idempotente: solo toca registros con distrito_id NULL y deja
 * intactos los que no encuentran coincidencia.
 */
class BackfillDistritos extends Command
{
    protected $signature = 'ubicacion:backfill-distritos {--dry-run : Solo muestra cuántos se mapearían}';

    protected $description = 'Asigna distrito_id a salas/establecimientos/clientes/empresas a partir del municipio previo.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Índice (departamento_id|nombre normalizado) => distrito_id.
        $indice = [];
        foreach (Distrito::query()->get(['id', 'departamento_id', 'nombre']) as $d) {
            $indice[$d->departamento_id.'|'.$this->norm($d->nombre)] = $d->id;
        }

        $modelos = [
            'cliente_sucursales' => ClienteSucursal::class,
            'establecimientos' => Establecimiento::class,
            'clientes' => Cliente::class,
            'empresas' => Empresa::class,
        ];

        $totalMapeados = 0;
        foreach ($modelos as $etiqueta => $clase) {
            $mapeados = 0;
            $sinMatch = 0;

            $clase::query()
                ->whereNull('distrito_id')
                ->whereNotNull('municipio_id')
                ->with('municipio:id,nombre,departamento_id')
                ->each(function (Model $registro) use ($indice, $dry, &$mapeados, &$sinMatch) {
                    $muni = $registro->municipio;
                    if (! $muni) {
                        $sinMatch++;

                        return;
                    }
                    $deptoId = $registro->departamento_id ?? $muni->departamento_id;
                    $distritoId = $indice[$deptoId.'|'.$this->norm($muni->nombre)] ?? null;

                    if ($distritoId === null) {
                        $sinMatch++;

                        return;
                    }
                    if (! $dry) {
                        $registro->forceFill(['distrito_id' => $distritoId])->saveQuietly();
                    }
                    $mapeados++;
                });

            $totalMapeados += $mapeados;
            $this->line(sprintf('  %-20s mapeados: %-4d sin coincidencia: %d', $etiqueta, $mapeados, $sinMatch));
        }

        $this->info(($dry ? '[dry-run] ' : '')."Distrito asignado a {$totalMapeados} registro(s).");

        return self::SUCCESS;
    }

    /** Normaliza para comparar nombres: sin acentos, minúsculas, espacios colapsados. */
    private function norm(string $texto): string
    {
        $texto = \Illuminate\Support\Str::ascii($texto);

        return preg_replace('/\s+/', ' ', strtolower(trim($texto)));
    }
}
