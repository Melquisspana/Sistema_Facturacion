<?php

namespace Tests\Feature\Planta;

use App\Models\Planta\PlantaCambioDisponibilidad;
use App\Models\Secuencia;
use App\Services\Planta\PlantaCambioDisponibilidadService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Esquema y numeración de `planta_cambios_disponibilidad`.
 *
 * Verifica las garantías del MOTOR —las que siguen en pie cuando la escritura no
 * pasa por el servicio— y que el contador propio no roza el dominio fiscal.
 */
class PlantaCambioDisponibilidadEsquemaTest extends TestCase
{
    use CambioDisponibilidadFixtures;
    use RefreshDatabase;

    private const MIGRACION = '2026_07_30_120000_create_planta_cambios_disponibilidad_table';

    /** Tablas de pasos anteriores: ninguna puede caer con el rollback del paso 7. */
    private const ANTERIORES = [
        'planta_ubicaciones', 'planta_proveedores', 'planta_insumos', 'planta_lotes',
        'planta_productos_base', 'planta_presentaciones', 'planta_empaque_configs',
        'planta_movimientos', 'planta_existencias', 'planta_recepciones', 'planta_recepcion_detalles',
    ];

    private function migracion(): object
    {
        return require database_path('migrations/'.self::MIGRACION.'.php');
    }

    // --- Estructura ---

    public function test_la_tabla_se_crea_con_sus_columnas(): void
    {
        $this->assertTrue(Schema::hasTable('planta_cambios_disponibilidad'));

        $this->assertTrue(Schema::hasColumns('planta_cambios_disponibilidad', [
            'id', 'numero', 'estado', 'planta_insumo_id', 'planta_lote_id', 'planta_ubicacion_id',
            'estado_origen', 'estado_destino', 'cantidad', 'fecha', 'motivo',
            'creado_por', 'confirmado_por', 'confirmado_en',
            'responsable_user_id', 'responsable_nombre',
            'reversion_de_id', 'revertido_por_id', 'created_at', 'updated_at',
        ]));
    }

    public function test_no_tiene_borrado_logico(): void
    {
        // Un documento confirmado ya escribió en el mayor: no desaparece.
        $this->assertFalse(Schema::hasColumn('planta_cambios_disponibilidad', 'deleted_at'));
    }

    public function test_las_columnas_obligatorias_son_not_null(): void
    {
        $bucket = $this->bucketDe($this->saldoRetenido());

        $base = [
            'numero' => 9001, 'estado' => 'borrador',
            'planta_insumo_id' => $bucket->insumoId,
            'planta_lote_id' => $bucket->loteId,
            'planta_ubicacion_id' => $bucket->ubicacionId,
            'estado_origen' => 'retenido', 'estado_destino' => 'disponible',
            'cantidad' => '10.0000', 'fecha' => '2026-07-30', 'motivo' => 'un motivo',
            'created_at' => now(), 'updated_at' => now(),
        ];

        foreach (['numero', 'estado', 'estado_origen', 'estado_destino', 'cantidad', 'fecha', 'motivo'] as $columna) {
            $fila = $base;
            $fila['numero'] = 9100 + strlen($columna);
            $fila[$columna] = null;

            try {
                DB::table('planta_cambios_disponibilidad')->insert($fila);
                $this->fail("La columna {$columna} debería ser NOT NULL.");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_los_usuarios_si_admiten_nulo(): void
    {
        $cambio = PlantaCambioDisponibilidad::factory()->create([
            'creado_por' => null, 'confirmado_por' => null, 'responsable_user_id' => null,
        ]);

        $this->assertNull($cambio->creado_por);
    }

    // --- Unique ---

    public function test_el_numero_es_unico(): void
    {
        $cambio = PlantaCambioDisponibilidad::factory()->create();

        $this->expectException(QueryException::class);

        PlantaCambioDisponibilidad::factory()->create(['numero' => $cambio->numero]);
    }

    // --- Claves foráneas ---

    public function test_no_se_puede_borrar_un_insumo_con_cambios(): void
    {
        $cambio = PlantaCambioDisponibilidad::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('planta_insumos')->where('id', $cambio->planta_insumo_id)->delete();
    }

    public function test_no_se_puede_borrar_una_ubicacion_con_cambios(): void
    {
        $cambio = PlantaCambioDisponibilidad::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('planta_ubicaciones')->where('id', $cambio->planta_ubicacion_id)->delete();
    }

    // --- Numeración ---

    public function test_cada_documento_recibe_un_numero_distinto(): void
    {
        $recepcion = $this->saldoRetenido();
        $numeros = [];

        for ($i = 0; $i < 4; $i++) {
            $numeros[] = $this->servicioCambio()
                ->crearBorrador($this->payloadCambio($recepcion, '10'), $this->admin())
                ->numero;
        }

        $this->assertSame([1, 2, 3, 4], $numeros);
        $this->assertCount(4, array_unique($numeros));
    }

    public function test_la_secuencia_usa_su_propia_clave(): void
    {
        $this->borradorCambio();

        $this->assertSame('planta_cambio_disponibilidad', PlantaCambioDisponibilidadService::CLAVE_SECUENCIA);
        $this->assertSame(1, Secuencia::ultimo(PlantaCambioDisponibilidadService::CLAVE_SECUENCIA));
    }

    public function test_no_toca_la_numeracion_fiscal_ni_la_de_recepciones(): void
    {
        // El saldo retenido se crea ANTES de tomar la referencia: esa recepción sí
        // consume su número, y con razón. Lo que se comprueba es que el cambio de
        // disponibilidad no consuma ninguno más.
        $recepcion = $this->saldoRetenido();

        $antesSistema = Secuencia::ultimo(Secuencia::NUMERO_SISTEMA);
        $antesRecepcion = Secuencia::ultimo('planta_recepcion');

        $this->servicioCambio()->crearBorrador($this->payloadCambio($recepcion, '10'), $this->admin());

        $this->assertSame($antesSistema, Secuencia::ultimo(Secuencia::NUMERO_SISTEMA));
        $this->assertSame($antesRecepcion, Secuencia::ultimo('planta_recepcion'));
        $this->assertFalse(Schema::hasColumn('planta_cambios_disponibilidad', 'numero_sistema'));
    }

    public function test_anular_deja_un_hueco_y_no_reutiliza_el_numero(): void
    {
        $recepcion = $this->saldoRetenido();

        $anulado = $this->servicioCambio()->crearBorrador($this->payloadCambio($recepcion, '10'), $this->admin());
        $this->servicioCambio()->anular($anulado);

        $siguiente = $this->servicioCambio()->crearBorrador($this->payloadCambio($recepcion, '10'), $this->admin());

        $this->assertSame($anulado->numero + 1, $siguiente->numero);
    }

    // --- Rollback / remigrate ---

    public function test_el_rollback_elimina_solo_la_tabla_del_paso_7(): void
    {
        $this->migracion()->down();

        $this->assertFalse(Schema::hasTable('planta_cambios_disponibilidad'));

        foreach (self::ANTERIORES as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), "El rollback del paso 7 no debe tocar {$tabla}.");
        }
    }

    public function test_volver_a_migrar_reconstruye_el_esquema(): void
    {
        $this->migracion()->down();
        $this->migracion()->up();

        $this->assertTrue(Schema::hasTable('planta_cambios_disponibilidad'));

        // Y el unique del número vuelve con la tabla, no solo las columnas.
        $cambio = PlantaCambioDisponibilidad::factory()->create();

        $this->expectException(QueryException::class);

        PlantaCambioDisponibilidad::factory()->create(['numero' => $cambio->numero]);
    }
}
