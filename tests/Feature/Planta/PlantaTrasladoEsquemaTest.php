<?php

namespace Tests\Feature\Planta;

use App\Models\Planta\PlantaTraslado;
use App\Models\Planta\PlantaTrasladoDetalle;
use App\Models\Secuencia;
use App\Services\Planta\PlantaTrasladoService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Esquema y numeración de `planta_traslados` y `planta_traslado_detalles`.
 *
 * Verifica las garantías del MOTOR —las que siguen en pie cuando la escritura no
 * pasa por el servicio— y que el contador propio no roza el dominio fiscal.
 */
class PlantaTrasladoEsquemaTest extends TestCase
{
    use RefreshDatabase;
    use TrasladoPlantaFixtures;

    /** Las dos del paso 8, en orden de dependencias. */
    private const MIGRACIONES = [
        '2026_07_30_130000_create_planta_traslados_table' => 'planta_traslados',
        '2026_07_30_130100_create_planta_traslado_detalles_table' => 'planta_traslado_detalles',
    ];

    /** Tablas de pasos anteriores: ninguna puede caer con el rollback del paso 8. */
    private const ANTERIORES = [
        'planta_ubicaciones', 'planta_proveedores', 'planta_insumos', 'planta_lotes',
        'planta_productos_base', 'planta_presentaciones', 'planta_empaque_configs',
        'planta_movimientos', 'planta_existencias', 'planta_recepciones',
        'planta_recepcion_detalles', 'planta_cambios_disponibilidad',
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
        $this->assertTrue(Schema::hasTable('planta_traslados'));
        $this->assertTrue(Schema::hasTable('planta_traslado_detalles'));

        $this->assertTrue(Schema::hasColumns('planta_traslados', [
            'id', 'numero', 'estado', 'fecha',
            'planta_ubicacion_origen_id', 'planta_ubicacion_destino_id',
            'creado_por', 'enviado_por', 'enviado_en', 'recibido_por', 'recibido_en',
            'responsable_user_id', 'responsable_nombre', 'observaciones', 'motivo_reversion',
            'reversion_de_id', 'revertido_por_id', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('planta_traslado_detalles', [
            'id', 'planta_traslado_id', 'planta_insumo_id', 'planta_lote_id',
            'cantidad', 'unidad_base', 'observaciones', 'created_at', 'updated_at',
        ]));
    }

    public function test_ninguna_tiene_borrado_logico(): void
    {
        // Un traslado enviado ya escribió en el mayor: no desaparece.
        $this->assertFalse(Schema::hasColumn('planta_traslados', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('planta_traslado_detalles', 'deleted_at'));
    }

    public function test_el_lote_del_detalle_es_obligatorio(): void
    {
        // Al contrario que en las recepciones: un traslado mueve saldo que YA
        // existe, y ese saldo siempre está en un lote concreto.
        $detalle = PlantaTrasladoDetalle::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('planta_traslado_detalles')->where('id', $detalle->id)->update(['planta_lote_id' => null]);
    }

    // --- Unique ---

    public function test_el_numero_es_unico(): void
    {
        $traslado = PlantaTraslado::factory()->create();

        $this->expectException(QueryException::class);

        PlantaTraslado::factory()->create(['numero' => $traslado->numero]);
    }

    public function test_no_puede_haber_dos_lineas_del_mismo_insumo_y_lote(): void
    {
        $detalle = PlantaTrasladoDetalle::factory()->create();

        // Dos líneas del mismo lote no son dos cosas distintas: son la misma
        // cantidad escrita dos veces. El servicio las fusiona; el unique es la
        // garantía dura de que ninguna otra vía las duplique.
        $this->expectException(QueryException::class);

        PlantaTrasladoDetalle::factory()->create([
            'planta_traslado_id' => $detalle->planta_traslado_id,
            'planta_insumo_id' => $detalle->planta_insumo_id,
            'planta_lote_id' => $detalle->planta_lote_id,
        ]);
    }

    public function test_el_mismo_lote_si_puede_estar_en_dos_traslados_distintos(): void
    {
        $detalle = PlantaTrasladoDetalle::factory()->create();
        $otro = PlantaTraslado::factory()->create();

        PlantaTrasladoDetalle::factory()->create([
            'planta_traslado_id' => $otro->id,
            'planta_insumo_id' => $detalle->planta_insumo_id,
            'planta_lote_id' => $detalle->planta_lote_id,
        ]);

        $this->assertSame(2, PlantaTrasladoDetalle::count());
    }

    // --- Claves foráneas ---

    public function test_no_se_puede_borrar_una_ubicacion_con_traslados(): void
    {
        $traslado = PlantaTraslado::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('planta_ubicaciones')->where('id', $traslado->planta_ubicacion_origen_id)->delete();
    }

    public function test_no_se_puede_borrar_un_lote_con_lineas_de_traslado(): void
    {
        $detalle = PlantaTrasladoDetalle::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('planta_lotes')->where('id', $detalle->planta_lote_id)->delete();
    }

    public function test_borrar_la_cabecera_arrastra_sus_lineas(): void
    {
        $traslado = PlantaTraslado::factory()->create();
        PlantaTrasladoDetalle::factory()->count(2)->create(['planta_traslado_id' => $traslado->id]);

        DB::table('planta_traslados')->where('id', $traslado->id)->delete();

        $this->assertSame(0, DB::table('planta_traslado_detalles')
            ->where('planta_traslado_id', $traslado->id)->count());
    }

    // --- Numeración ---

    public function test_cada_traslado_recibe_un_numero_distinto(): void
    {
        $e = $this->escenarioTraslado();
        $numeros = [];

        for ($i = 0; $i < 4; $i++) {
            $numeros[] = $this->servicioTraslado()
                ->crearBorrador($this->payloadTraslado($e, '10'), $this->admin())->numero;
        }

        $this->assertSame([1, 2, 3, 4], $numeros);
    }

    public function test_la_secuencia_usa_su_propia_clave(): void
    {
        $this->borradorTraslado($this->escenarioTraslado());

        $this->assertSame('planta_traslado', PlantaTrasladoService::CLAVE_SECUENCIA);
        $this->assertSame(1, Secuencia::ultimo(PlantaTrasladoService::CLAVE_SECUENCIA));
    }

    public function test_no_toca_la_numeracion_fiscal_ni_la_de_otros_documentos(): void
    {
        $e = $this->escenarioTraslado();

        $antesSistema = Secuencia::ultimo(Secuencia::NUMERO_SISTEMA);
        $antesRecepcion = Secuencia::ultimo('planta_recepcion');
        $antesCambio = Secuencia::ultimo('planta_cambio_disponibilidad');

        $this->borradorTraslado($e);

        $this->assertSame($antesSistema, Secuencia::ultimo(Secuencia::NUMERO_SISTEMA));
        $this->assertSame($antesRecepcion, Secuencia::ultimo('planta_recepcion'));
        $this->assertSame($antesCambio, Secuencia::ultimo('planta_cambio_disponibilidad'));
        $this->assertFalse(Schema::hasColumn('planta_traslados', 'numero_sistema'));
    }

    public function test_cancelar_deja_un_hueco_y_no_reutiliza_el_numero(): void
    {
        $e = $this->escenarioTraslado();

        $cancelado = $this->borradorTraslado($e, '10');
        $this->servicioTraslado()->cancelar($cancelado);

        $siguiente = $this->borradorTraslado($e, '10');

        $this->assertSame($cancelado->numero + 1, $siguiente->numero);
    }

    // --- Rollback / remigrate ---

    public function test_el_rollback_elimina_solo_las_dos_tablas_del_paso_8(): void
    {
        foreach (array_reverse($this->migraciones()) as $migracion) {
            $migracion->down();
        }

        foreach (self::MIGRACIONES as $tabla) {
            $this->assertFalse(Schema::hasTable($tabla), "La tabla {$tabla} debería haberse eliminado.");
        }

        foreach (self::ANTERIORES as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), "El rollback del paso 8 no debe tocar {$tabla}.");
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
            $this->assertTrue(Schema::hasTable($tabla));
        }

        // Y el unique del número vuelve con la tabla, no solo las columnas.
        $traslado = PlantaTraslado::factory()->create();

        $this->expectException(QueryException::class);

        PlantaTraslado::factory()->create(['numero' => $traslado->numero]);
    }
}
