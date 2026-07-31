<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoAjuste;
use App\Models\Planta\PlantaAjuste;
use App\Models\Planta\PlantaAjusteDetalle;
use App\Models\Secuencia;
use App\Services\Planta\PlantaAjusteService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Esquema y numeración de `planta_ajustes` y `planta_ajuste_detalles`.
 *
 * Verifica lo que garantiza el MOTOR —lo que sigue en pie cuando la escritura no
 * pasa por el servicio— y que el contador propio no roza el dominio fiscal.
 */
class PlantaAjusteEsquemaTest extends TestCase
{
    use AjustePlantaFixtures;
    use RefreshDatabase;

    /** Las dos del paso 9, en orden de dependencias. */
    private const MIGRACIONES = [
        '2026_07_30_140000_create_planta_ajustes_table' => 'planta_ajustes',
        '2026_07_30_140100_create_planta_ajuste_detalles_table' => 'planta_ajuste_detalles',
    ];

    /** Tablas de pasos anteriores: ninguna puede caer con el rollback del paso 9. */
    private const ANTERIORES = [
        'planta_ubicaciones', 'planta_proveedores', 'planta_insumos', 'planta_lotes',
        'planta_productos_base', 'planta_presentaciones', 'planta_empaque_configs',
        'planta_movimientos', 'planta_existencias', 'planta_recepciones',
        'planta_recepcion_detalles', 'planta_cambios_disponibilidad',
        'planta_traslados', 'planta_traslado_detalles',
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
        $this->assertTrue(Schema::hasTable('planta_ajustes'));
        $this->assertTrue(Schema::hasTable('planta_ajuste_detalles'));

        $this->assertTrue(Schema::hasColumns('planta_ajustes', [
            'id', 'numero', 'estado', 'tipo', 'fecha', 'motivo',
            'creado_por', 'confirmado_por', 'confirmado_en',
            'responsable_user_id', 'responsable_nombre', 'observaciones',
            'reversion_de_id', 'revertido_por_id', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('planta_ajuste_detalles', [
            'id', 'planta_ajuste_id', 'planta_insumo_id', 'planta_lote_id',
            'planta_ubicacion_id', 'estado_disponibilidad', 'cantidad',
            'cantidad_conteo', 'cantidad_sistema', 'diferencia',
            'unidad_base', 'observaciones', 'created_at', 'updated_at',
        ]));
    }

    public function test_el_motivo_es_obligatorio_en_la_base_no_solo_en_el_servicio(): void
    {
        // Es LA columna del paso: un ajuste no tiene documento externo que lo
        // respalde, y sin motivo el asiento queda sin explicación posible.
        $ajuste = PlantaAjuste::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('planta_ajustes')->where('id', $ajuste->id)->update(['motivo' => null]);
    }

    public function test_la_linea_lleva_el_bucket_completo_y_todo_el_es_obligatorio(): void
    {
        // A diferencia del traslado —donde la ubicación es del documento— aquí
        // cada línea puede tocar una ubicación y un estado distintos.
        $detalle = PlantaAjusteDetalle::factory()->create();

        foreach (['planta_insumo_id', 'planta_lote_id', 'planta_ubicacion_id', 'estado_disponibilidad'] as $columna) {
            try {
                DB::table('planta_ajuste_detalles')->where('id', $detalle->id)->update([$columna => null]);
                $this->fail("{$columna} debería ser NOT NULL.");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_las_tres_columnas_de_conteo_son_opcionales(): void
    {
        // Solo existen para `correccion_conteo`; el resto de tipos las deja nulas.
        $detalle = PlantaAjusteDetalle::factory()->create();

        $this->assertNull($detalle->cantidad_conteo);
        $this->assertNull($detalle->cantidad_sistema);
        $this->assertNull($detalle->diferencia);
    }

    public function test_la_quinta_dimension_del_bucket_no_se_guarda_porque_siempre_es_cero(): void
    {
        // Un ajuste no toca saldo en tránsito: ese saldo pertenece a un traslado.
        $this->assertFalse(Schema::hasColumn('planta_ajuste_detalles', 'planta_traslado_id'));

        $detalle = PlantaAjusteDetalle::factory()->create();

        $this->assertSame(0, $detalle->bucket()->trasladoId);
    }

    public function test_ninguna_tiene_borrado_logico(): void
    {
        // Un ajuste confirmado ya escribió en el mayor: no desaparece, se anula o
        // se reversa.
        $this->assertFalse(Schema::hasColumn('planta_ajustes', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('planta_ajuste_detalles', 'deleted_at'));
    }

    // --- Unique ---

    public function test_el_numero_es_unico(): void
    {
        $ajuste = PlantaAjuste::factory()->create();

        $this->expectException(QueryException::class);

        PlantaAjuste::factory()->create(['numero' => $ajuste->numero]);
    }

    public function test_no_puede_haber_dos_lineas_del_mismo_bucket_en_el_mismo_ajuste(): void
    {
        // Dos líneas del mismo bucket no son dos hechos: son el mismo hecho
        // escrito dos veces, y sumarían dos movimientos sin que nadie sepa por qué.
        $detalle = PlantaAjusteDetalle::factory()->create();

        $this->expectException(QueryException::class);

        PlantaAjusteDetalle::factory()->create([
            'planta_ajuste_id' => $detalle->planta_ajuste_id,
            'planta_insumo_id' => $detalle->planta_insumo_id,
            'planta_lote_id' => $detalle->planta_lote_id,
            'planta_ubicacion_id' => $detalle->planta_ubicacion_id,
            'estado_disponibilidad' => $detalle->estado_disponibilidad->value,
        ]);
    }

    public function test_el_mismo_insumo_y_lote_si_pueden_repetirse_en_otro_estado(): void
    {
        // Ajustar lo disponible y lo retenido del mismo lote son DOS hechos: son
        // dos buckets distintos.
        $detalle = PlantaAjusteDetalle::factory()->create([
            'estado_disponibilidad' => EstadoDisponibilidad::Disponible->value,
        ]);

        PlantaAjusteDetalle::factory()->create([
            'planta_ajuste_id' => $detalle->planta_ajuste_id,
            'planta_insumo_id' => $detalle->planta_insumo_id,
            'planta_lote_id' => $detalle->planta_lote_id,
            'planta_ubicacion_id' => $detalle->planta_ubicacion_id,
            'estado_disponibilidad' => EstadoDisponibilidad::Retenido->value,
        ]);

        $this->assertSame(2, PlantaAjusteDetalle::count());
    }

    public function test_el_mismo_bucket_si_puede_estar_en_dos_ajustes_distintos(): void
    {
        $detalle = PlantaAjusteDetalle::factory()->create();
        $otro = PlantaAjuste::factory()->create();

        PlantaAjusteDetalle::factory()->create([
            'planta_ajuste_id' => $otro->id,
            'planta_insumo_id' => $detalle->planta_insumo_id,
            'planta_lote_id' => $detalle->planta_lote_id,
            'planta_ubicacion_id' => $detalle->planta_ubicacion_id,
            'estado_disponibilidad' => $detalle->estado_disponibilidad->value,
        ]);

        $this->assertSame(2, PlantaAjusteDetalle::count());
    }

    // --- Claves foráneas ---

    public function test_no_se_puede_borrar_un_insumo_un_lote_ni_una_ubicacion_con_lineas(): void
    {
        $detalle = PlantaAjusteDetalle::factory()->create();

        foreach ([
            'planta_insumos' => $detalle->planta_insumo_id,
            'planta_lotes' => $detalle->planta_lote_id,
            'planta_ubicaciones' => $detalle->planta_ubicacion_id,
        ] as $tabla => $id) {
            try {
                DB::table($tabla)->where('id', $id)->delete();
                $this->fail("Borrar de {$tabla} debería estar restringido.");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_borrar_la_cabecera_arrastra_sus_lineas(): void
    {
        $ajuste = PlantaAjuste::factory()->create();
        PlantaAjusteDetalle::factory()->count(2)->create(['planta_ajuste_id' => $ajuste->id]);

        DB::table('planta_ajustes')->where('id', $ajuste->id)->delete();

        $this->assertSame(0, DB::table('planta_ajuste_detalles')
            ->where('planta_ajuste_id', $ajuste->id)->count());
    }

    public function test_no_se_puede_borrar_un_ajuste_que_otro_señala_como_reversion(): void
    {
        $original = PlantaAjuste::factory()->create();
        PlantaAjuste::factory()->create(['reversion_de_id' => $original->id]);

        $this->expectException(QueryException::class);

        DB::table('planta_ajustes')->where('id', $original->id)->delete();
    }

    public function test_borrar_el_usuario_no_borra_el_ajuste(): void
    {
        // La instantánea del nombre es la que sobrevive; la FK solo se anula.
        $usuario = $this->admin();
        $ajuste = PlantaAjuste::factory()->create([
            'creado_por' => $usuario->id,
            'responsable_nombre' => 'Quien constató',
        ]);

        DB::table('users')->where('id', $usuario->id)->delete();

        $this->assertNull($ajuste->refresh()->creado_por);
        $this->assertSame('Quien constató', $ajuste->responsable_nombre);
    }

    // --- Numeración ---

    public function test_cada_ajuste_recibe_un_numero_distinto(): void
    {
        $e = $this->escenarioConSaldo();
        $numeros = [];

        for ($i = 0; $i < 4; $i++) {
            $numeros[] = $this->borradorAjuste($e, TipoAjuste::Positivo, '10')->numero;
        }

        $this->assertSame([1, 2, 3, 4], $numeros);
    }

    public function test_la_secuencia_usa_su_propia_clave(): void
    {
        $this->borradorAjuste($this->escenarioConSaldo());

        $this->assertSame('planta_ajuste', PlantaAjusteService::CLAVE_SECUENCIA);
        $this->assertSame(1, Secuencia::ultimo(PlantaAjusteService::CLAVE_SECUENCIA));
    }

    public function test_no_toca_la_numeracion_fiscal_ni_la_de_otros_documentos(): void
    {
        $e = $this->escenarioConSaldo();

        $antesSistema = Secuencia::ultimo(Secuencia::NUMERO_SISTEMA);
        $antesRecepcion = Secuencia::ultimo('planta_recepcion');
        $antesTraslado = Secuencia::ultimo('planta_traslado');
        $antesCambio = Secuencia::ultimo('planta_cambio_disponibilidad');

        $this->borradorAjuste($e);

        $this->assertSame($antesSistema, Secuencia::ultimo(Secuencia::NUMERO_SISTEMA));
        $this->assertSame($antesRecepcion, Secuencia::ultimo('planta_recepcion'));
        $this->assertSame($antesTraslado, Secuencia::ultimo('planta_traslado'));
        $this->assertSame($antesCambio, Secuencia::ultimo('planta_cambio_disponibilidad'));
        $this->assertFalse(Schema::hasColumn('planta_ajustes', 'numero_sistema'));
    }

    public function test_anular_deja_un_hueco_y_no_reutiliza_el_numero(): void
    {
        $e = $this->escenarioConSaldo();

        $anulado = $this->borradorAjuste($e, TipoAjuste::Positivo, '10');
        $this->servicioAjuste()->anular($anulado);

        $siguiente = $this->borradorAjuste($e, TipoAjuste::Positivo, '10');

        $this->assertSame($anulado->numero + 1, $siguiente->numero);
    }

    public function test_la_reversion_consume_su_propio_numero_de_la_misma_serie(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '10');

        $reversion = $this->servicioAjuste()->reversar($ajuste, 'Se ajustó lo que no era', $this->admin());

        $this->assertSame($ajuste->numero + 1, $reversion->numero);
    }

    // --- Rollback / remigrate ---

    public function test_el_rollback_elimina_solo_las_dos_tablas_del_paso_9(): void
    {
        foreach (array_reverse($this->migraciones()) as $migracion) {
            $migracion->down();
        }

        foreach (self::MIGRACIONES as $tabla) {
            $this->assertFalse(Schema::hasTable($tabla), "La tabla {$tabla} debería haberse eliminado.");
        }

        foreach (self::ANTERIORES as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), "El rollback del paso 9 no debe tocar {$tabla}.");
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
        $ajuste = PlantaAjuste::factory()->create();

        $this->expectException(QueryException::class);

        PlantaAjuste::factory()->create(['numero' => $ajuste->numero]);
    }
}
