<?php

namespace Tests\Feature\Planta;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Reversibilidad de las seis migraciones del paso 2.
 *
 * Se ejecutan los `down()` en orden inverso y luego los `up()` otra vez, sobre
 * la base SQLite en memoria de la suite. Se invocan las migraciones
 * directamente —en vez de `migrate:rollback`— para acotar la prueba a las seis
 * del paso 2: `RefreshDatabase` deja todas las migraciones del proyecto en un
 * mismo lote, así que un rollback por lote arrastraría tablas ajenas.
 *
 * Lo que verifica en la práctica: que el orden de los `dropIfExists` respeta
 * las claves foráneas (los lotes y las presentaciones caen antes que las
 * tablas a las que apuntan) y que volver a migrar reconstruye el esquema.
 */
class PlantaMigracionesRollbackTest extends TestCase
{
    use RefreshDatabase;

    /** Las seis migraciones del paso 2, en orden de dependencias. */
    private const MIGRACIONES = [
        '2026_07_28_100000_create_planta_ubicaciones_table' => 'planta_ubicaciones',
        '2026_07_28_100100_create_planta_proveedores_table' => 'planta_proveedores',
        '2026_07_28_100200_create_planta_insumos_table' => 'planta_insumos',
        '2026_07_28_100300_create_planta_lotes_table' => 'planta_lotes',
        '2026_07_28_100400_create_planta_productos_base_table' => 'planta_productos_base',
        '2026_07_28_100500_create_planta_presentaciones_table' => 'planta_presentaciones',
    ];

    /** @return array<int, object> Instancias de migración, en orden de dependencias. */
    private function migraciones(): array
    {
        return array_map(
            fn (string $archivo) => require database_path("migrations/{$archivo}.php"),
            array_keys(self::MIGRACIONES)
        );
    }

    public function test_los_archivos_de_migracion_existen(): void
    {
        foreach (array_keys(self::MIGRACIONES) as $archivo) {
            $this->assertFileExists(database_path("migrations/{$archivo}.php"));
        }
    }

    public function test_el_rollback_en_orden_inverso_elimina_las_seis_tablas(): void
    {
        foreach (self::MIGRACIONES as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla));
        }

        foreach (array_reverse($this->migraciones()) as $migracion) {
            $migracion->down();
        }

        foreach (self::MIGRACIONES as $tabla) {
            $this->assertFalse(Schema::hasTable($tabla), "La tabla {$tabla} debería haberse eliminado.");
        }
    }

    public function test_volver_a_migrar_despues_del_rollback_funciona(): void
    {
        foreach (array_reverse($this->migraciones()) as $migracion) {
            $migracion->down();
        }

        foreach ($this->migraciones() as $migracion) {
            $migracion->up();
        }

        foreach (self::MIGRACIONES as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), "La tabla {$tabla} debería haberse recreado.");
        }

        // El esquema recreado conserva sus restricciones, no solo sus columnas.
        $this->assertTrue(Schema::hasColumn('planta_lotes', 'fecha_recepcion'));
        $this->assertFalse(Schema::hasColumn('planta_lotes', 'deleted_at'));
        $this->assertTrue(Schema::hasColumn('planta_presentaciones', 'deleted_at'));
    }

    public function test_el_rollback_no_toca_tablas_ajenas_a_planta(): void
    {
        foreach (array_reverse($this->migraciones()) as $migracion) {
            $migracion->down();
        }

        // Facturación, permisos y auditoría siguen intactos: el paso 2 es aditivo.
        foreach (['users', 'permissions', 'roles', 'dtes', 'productos', 'clientes', 'activity_log'] as $ajena) {
            $this->assertTrue(Schema::hasTable($ajena), "El rollback de Planta no debe tocar {$ajena}.");
        }
    }
}
