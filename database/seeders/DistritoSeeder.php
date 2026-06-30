<?php

namespace Database\Seeders;

use App\Models\Departamento;
use App\Models\Distrito;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Importa el catálogo COMPLETO de la división territorial 2024 de El Salvador:
 * 14 departamentos, 44 municipios, 262 distritos.
 *
 * Dataset: database/data/distritos_el_salvador_2024.csv (formato
 * codigo_departamento,municipio,distrito). Fuente: lista oficial de la reforma
 * municipal 2024 (los 262 municipios previos pasaron a ser distritos), tomada de
 * Wikipedia «List of municipalities and districts of El Salvador».
 *
 * Idempotente (updateOrCreate por departamento+municipio+distrito), por lo que no
 * rompe datos previos. El código MH (CAT) del distrito queda NULL hasta confirmar
 * el catálogo oficial del Ministerio de Hacienda — igual que MunicipioSeeder.
 *
 * Para cargar otro dataset, reemplazá el CSV y volvé a correr este seeder.
 */
class DistritoSeeder extends Seeder
{
    public function run(): void
    {
        $ruta = database_path('data/distritos_el_salvador_2024.csv');
        if (! File::exists($ruta)) {
            $this->command?->warn("Dataset de distritos no encontrado: {$ruta}");

            return;
        }

        // Cache de departamentos por código (CAT-012).
        $departamentosPorCodigo = Departamento::pluck('id', 'codigo');

        $lineas = preg_split('/\r\n|\r|\n/', trim((string) File::get($ruta)));
        array_shift($lineas); // descarta el encabezado

        $total = 0;
        foreach ($lineas as $linea) {
            if (trim($linea) === '') {
                continue;
            }

            [$codigoDepto, $municipio, $distrito] = array_pad(str_getcsv($linea), 3, null);
            $departamentoId = $departamentosPorCodigo[trim((string) $codigoDepto)] ?? null;

            if (! $departamentoId || blank($municipio) || blank($distrito)) {
                continue;
            }

            Distrito::updateOrCreate(
                [
                    'departamento_id' => $departamentoId,
                    'municipio' => trim($municipio),
                    'nombre' => trim($distrito),
                ],
                ['activo' => true],
            );
            $total++;
        }

        $this->command?->info("Distritos importados/actualizados: {$total}");
    }
}
