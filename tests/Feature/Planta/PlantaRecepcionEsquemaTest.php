<?php

namespace Tests\Feature\Planta;

use App\Models\Planta\PlantaRecepcion;
use App\Models\Planta\PlantaRecepcionDetalle;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Esquema de `planta_recepciones` y `planta_recepcion_detalles`.
 *
 * Verifica las garantías del MOTOR, que son las que siguen en pie cuando la
 * escritura no pasa por el servicio: el unique del número, las claves foráneas
 * restrictivas y la nulabilidad de cada columna.
 */
class PlantaRecepcionEsquemaTest extends TestCase
{
    use RecepcionPlantaFixtures;
    use RefreshDatabase;

    /** Las dos del paso 6, en orden de dependencias. */
    private const MIGRACIONES = [
        '2026_07_30_110000_create_planta_recepciones_table' => 'planta_recepciones',
        '2026_07_30_110100_create_planta_recepcion_detalles_table' => 'planta_recepcion_detalles',
    ];

    /** Tablas de pasos anteriores: ninguna puede caer con el rollback del paso 6. */
    private const ANTERIORES = [
        'planta_ubicaciones', 'planta_proveedores', 'planta_insumos', 'planta_lotes',
        'planta_productos_base', 'planta_presentaciones', 'planta_empaque_configs',
        'planta_movimientos', 'planta_existencias',
    ];

    /** @return array<int, object> */
    private function migraciones(): array
    {
        return array_map(
            fn (string $archivo) => require database_path("migrations/{$archivo}.php"),
            array_keys(self::MIGRACIONES)
        );
    }

    // --- Estructura ---

    public function test_ambas_tablas_se_crean_con_sus_columnas(): void
    {
        $this->assertTrue(Schema::hasTable('planta_recepciones'));
        $this->assertTrue(Schema::hasTable('planta_recepcion_detalles'));

        $this->assertTrue(Schema::hasColumns('planta_recepciones', [
            'id', 'numero', 'estado', 'fecha', 'planta_proveedor_id', 'planta_ubicacion_id',
            'documento_referencia', 'creado_por', 'confirmado_por', 'confirmado_en',
            'responsable_user_id', 'responsable_nombre', 'observaciones',
            'reversion_de_id', 'revertido_por_id', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('planta_recepcion_detalles', [
            'id', 'planta_recepcion_id', 'planta_insumo_id', 'planta_lote_id',
            'cantidad_recibida', 'unidad_recibida', 'contenido_por_unidad', 'factor_conversion',
            'cantidad_base', 'unidad_base', 'estado_destino', 'lote_codigo_proveedor',
            'fecha_elaboracion', 'fecha_vencimiento', 'observaciones', 'created_at', 'updated_at',
        ]));
    }

    public function test_ninguna_de_las_dos_tiene_borrado_logico(): void
    {
        // Un documento que movió inventario no desaparece, ni siquiera de forma
        // lógica: se anula o se reversa.
        $this->assertFalse(Schema::hasColumn('planta_recepciones', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('planta_recepcion_detalles', 'deleted_at'));
    }

    // --- Nulabilidad ---

    public function test_la_cabecera_exige_numero_estado_fecha_y_ubicacion(): void
    {
        $ubicacion = $this->bodega();

        foreach (['numero', 'estado', 'fecha', 'planta_ubicacion_id'] as $columna) {
            $fila = [
                'numero' => 1000 + array_search($columna, ['numero', 'estado', 'fecha', 'planta_ubicacion_id'], true),
                'estado' => 'borrador',
                'fecha' => '2026-07-30',
                'planta_ubicacion_id' => $ubicacion->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $fila[$columna] = null;

            try {
                DB::table('planta_recepciones')->insert($fila);
                $this->fail("La columna {$columna} debería ser NOT NULL.");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_el_proveedor_y_los_usuarios_si_admiten_nulo(): void
    {
        $ubicacion = $this->bodega();

        // Una recepción puede no tener proveedor conocido, y sus usuarios pueden
        // haber sido borrados: las FK a `users` son nullOnDelete.
        DB::table('planta_recepciones')->insert([
            'numero' => 5001, 'estado' => 'borrador', 'fecha' => '2026-07-30',
            'planta_ubicacion_id' => $ubicacion->id,
            'planta_proveedor_id' => null, 'creado_por' => null, 'confirmado_por' => null,
            'responsable_user_id' => null, 'reversion_de_id' => null, 'revertido_por_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('planta_recepciones')->where('numero', 5001)->count());
    }

    public function test_el_detalle_admite_lote_nulo_mientras_es_borrador(): void
    {
        $recepcion = PlantaRecepcion::factory()->create();
        $detalle = PlantaRecepcionDetalle::factory()->create([
            'planta_recepcion_id' => $recepcion->id,
            'planta_lote_id' => null,
        ]);

        $this->assertNull($detalle->planta_lote_id);
    }

    // --- Unique e índices ---

    public function test_el_numero_es_unico(): void
    {
        $ubicacion = $this->bodega();

        $fila = [
            'numero' => 777, 'estado' => 'borrador', 'fecha' => '2026-07-30',
            'planta_ubicacion_id' => $ubicacion->id, 'created_at' => now(), 'updated_at' => now(),
        ];

        DB::table('planta_recepciones')->insert($fila);

        $this->expectException(QueryException::class);

        DB::table('planta_recepciones')->insert($fila);
    }

    // --- Claves foráneas ---

    public function test_no_se_puede_borrar_una_ubicacion_con_recepciones(): void
    {
        $recepcion = PlantaRecepcion::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('planta_ubicaciones')->where('id', $recepcion->planta_ubicacion_id)->delete();
    }

    public function test_no_se_puede_borrar_un_insumo_con_lineas_de_recepcion(): void
    {
        $detalle = PlantaRecepcionDetalle::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('planta_insumos')->where('id', $detalle->planta_insumo_id)->delete();
    }

    public function test_borrar_la_cabecera_arrastra_sus_lineas(): void
    {
        $recepcion = PlantaRecepcion::factory()->create();
        PlantaRecepcionDetalle::factory()->count(3)->create(['planta_recepcion_id' => $recepcion->id]);

        DB::table('planta_recepciones')->where('id', $recepcion->id)->delete();

        // El cascade solo evita líneas huérfanas; la aplicación nunca borra un
        // documento confirmado.
        $this->assertSame(0, DB::table('planta_recepcion_detalles')
            ->where('planta_recepcion_id', $recepcion->id)->count());
    }

    // --- Rollback / remigrate ---

    public function test_el_rollback_elimina_solo_las_dos_tablas_del_paso_6(): void
    {
        foreach (array_reverse($this->migraciones()) as $migracion) {
            $migracion->down();
        }

        foreach (self::MIGRACIONES as $tabla) {
            $this->assertFalse(Schema::hasTable($tabla), "La tabla {$tabla} debería haberse eliminado.");
        }

        foreach (self::ANTERIORES as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), "El rollback del paso 6 no debe tocar {$tabla}.");
        }
    }

    public function test_volver_a_migrar_reconstruye_el_esquema(): void
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

        // Y el unique del número vuelve con la tabla, no solo las columnas.
        $ubicacion = $this->bodega();
        $fila = [
            'numero' => 4242, 'estado' => 'borrador', 'fecha' => '2026-07-30',
            'planta_ubicacion_id' => $ubicacion->id, 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('planta_recepciones')->insert($fila);

        $this->expectException(QueryException::class);

        DB::table('planta_recepciones')->insert($fila);
    }
}
