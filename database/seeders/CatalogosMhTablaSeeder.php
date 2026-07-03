<?php

namespace Database\Seeders;

use App\Services\Importacion\ImportadorCatalogosMh;
use Illuminate\Database\Seeder;

/**
 * Puebla la tabla genérica `catalogos_mh` (CAT-001..CAT-033) desde el Excel oficial del
 * repo (resources/dte/catalogos/*.xlsx), igual que en producción. Es la tabla que consulta
 * la serialización del JSON oficial (p. ej. CAT-014 unidad de medida, CAT-019 actividad),
 * distinta de los catálogos propios (paises/municipios/unidades_medida) de {@see CatalogosMhSeeder}.
 *
 * Necesario en pruebas para poder GENERAR el JSON oficial de un DTE; sin esto la
 * serialización rechaza la unidad de medida ("CAT-014 no reconocido"). Idempotente.
 */
class CatalogosMhTablaSeeder extends Seeder
{
    public function run(): void
    {
        app(ImportadorCatalogosMh::class)->importar();
    }
}
