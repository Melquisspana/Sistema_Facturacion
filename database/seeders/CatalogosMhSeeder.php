<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Orquesta la carga de todos los catálogos base del Ministerio de Hacienda.
 * Idempotente: cada seeder usa updateOrCreate.
 *
 * Incluye {@see CatalogosMhTablaSeeder} al final, y no por comodidad: es el que importa el
 * Excel oficial y con él completa el código CAT-008 de cada distrito, el CAT-013 de cada
 * municipio y el vínculo distrito → municipio 2024. Sin ese paso los catálogos quedan a
 * medias (códigos en NULL) y NINGÚN documento puede emitirse, porque `direccion.municipio`
 * y `direccion.distrito` saldrían vacíos y Hacienda los rechaza. Un catálogo incompleto no
 * es un estado válido del sistema, así que dejó de ser opcional.
 */
class CatalogosMhSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PaisSeeder::class,
            DepartamentoSeeder::class,
            MunicipioSeeder::class,
            DistritoSeeder::class,
            ActividadEconomicaSeeder::class,
            UnidadMedidaSeeder::class,
            // Catálogos oficiales (CAT-001..033) + códigos y vínculos territoriales.
            CatalogosMhTablaSeeder::class,
        ]);
    }
}
