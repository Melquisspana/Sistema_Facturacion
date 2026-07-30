<?php

namespace Tests\Feature\Planta;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Reversibilidad de las DOS migraciones del paso 5.
 *
 * Se invocan los `down()` y `up()` directamente en vez de `migrate:rollback`
 * porque `RefreshDatabase` deja todas las migraciones del proyecto en un mismo
 * lote y un rollback por lote arrastraría tablas ajenas. El rollback POR LOTE
 * real —comprobando antes que el último lote contiene solo estas dos— se
 * ejecuta a mano contra MySQL y se documenta en la entrega del paso.
 *
 * Lo que verifica en la práctica: que al deshacer el paso 5 desaparecen
 * EXACTAMENTE dos tablas y ninguna de los pasos anteriores, que el orden de los
 * `dropIfExists` respeta las claves foráneas, y que volver a migrar reconstruye
 * también los triggers que implementan el CHECK en SQLite.
 */
class PlantaInventarioMigracionesRollbackTest extends TestCase
{
    use InventarioPlantaFixtures;
    use RefreshDatabase;

    /** Las dos del paso 5, en orden de dependencias. */
    private const MIGRACIONES = [
        '2026_07_30_100000_create_planta_movimientos_table' => 'planta_movimientos',
        '2026_07_30_100100_create_planta_existencias_table' => 'planta_existencias',
    ];

    /** Tablas de pasos anteriores: ninguna puede caer con el rollback del paso 5. */
    private const ANTERIORES = [
        'planta_ubicaciones',
        'planta_proveedores',
        'planta_insumos',
        'planta_lotes',
        'planta_productos_base',
        'planta_presentaciones',
        'planta_empaque_configs',
    ];

    /** @return array<int, object> */
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

    public function test_el_rollback_elimina_solo_las_dos_tablas_del_paso_5(): void
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

        foreach (self::ANTERIORES as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), "El rollback del paso 5 no debe tocar {$tabla}.");
        }
    }

    public function test_el_rollback_se_lleva_los_triggers_del_check(): void
    {
        foreach (array_reverse($this->migraciones()) as $migracion) {
            $migracion->down();
        }

        $triggers = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->where('name', 'like', 'planta_exist_cantidad_no_negativa%')
            ->count();

        $this->assertSame(0, $triggers);
    }

    public function test_volver_a_migrar_reconstruye_el_esquema_completo(): void
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

        // El unique y el CHECK vuelven con la tabla, no solo las columnas.
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->aplicar($bucket, '10.0000');

        $this->assertSame('10.0000', $this->saldoProyectado($bucket));

        $this->expectException(QueryException::class);

        DB::table('planta_existencias')->where($bucket->aColumnas())->update(['cantidad' => '-1.0000']);
    }
}
