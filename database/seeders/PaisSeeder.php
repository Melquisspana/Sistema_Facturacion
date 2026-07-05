<?php

namespace Database\Seeders;

use App\Models\Pais;
use Illuminate\Database\Seeder;

/**
 * Países (CAT-020). Subconjunto inicial centrado en El Salvador y socios
 * comerciales relevantes para exportación.
 *
 * Los códigos son ISO alpha-2, iguales a los que trae el catálogo real CAT-020
 * en `catalogos_mh` (importado del Excel oficial del MH vía
 * {@see \Database\Seeders\CatalogosMhTablaSeeder}) — necesario para que el
 * receptor de la Factura de Exportación resuelva `nombrePais` correctamente.
 */
class PaisSeeder extends Seeder
{
    public function run(): void
    {
        $paises = [
            ['codigo' => 'SV', 'nombre' => 'El Salvador'],
            ['codigo' => 'US', 'nombre' => 'Estados Unidos'],
            ['codigo' => 'GT', 'nombre' => 'Guatemala'],
            ['codigo' => 'HN', 'nombre' => 'Honduras'],
            ['codigo' => 'NI', 'nombre' => 'Nicaragua'],
            ['codigo' => 'CR', 'nombre' => 'Costa Rica'],
            ['codigo' => 'PA', 'nombre' => 'Panamá'],
            ['codigo' => 'MX', 'nombre' => 'México'],
        ];

        foreach ($paises as $pais) {
            Pais::updateOrCreate(
                ['codigo' => $pais['codigo']],
                ['nombre' => $pais['nombre'], 'activo' => true],
            );
        }
    }
}
