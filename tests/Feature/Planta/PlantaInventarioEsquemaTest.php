<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Esquema del motor de inventario: `planta_movimientos` y `planta_existencias`.
 *
 * Lo que se prueba aquí son las garantías que da el MOTOR, no el servicio. Son
 * las que siguen en pie cuando la escritura no pasa por el código de dominio:
 * el UNIQUE del bucket, el UNIQUE de `efecto_uid`, el CHECK de cantidad no
 * negativa y las claves foráneas restrictivas.
 *
 * SOBRE EL CHECK Y EL MOTOR DE LA SUITE. Estas pruebas corren en SQLite, donde
 * la restricción se implementa con dos triggers `RAISE(ABORT)` porque SQLite no
 * admite `ALTER TABLE ... ADD CONSTRAINT`. En MySQL 8.4 es un CHECK nativo. La
 * garantía observable es la misma —la sentencia aborta y sale como
 * QueryException— pero son dos implementaciones distintas, así que verificar una
 * NO verifica la otra: el comportamiento en MySQL se comprueba aparte, contra la
 * base local, y queda documentado en la entrega del paso.
 */
class PlantaInventarioEsquemaTest extends TestCase
{
    use InventarioPlantaFixtures;
    use RefreshDatabase;

    /** Inserta una fila de existencias saltándose todo el dominio. */
    private function insertarExistencia(array $columnas, string $cantidad): void
    {
        DB::table('planta_existencias')->insert(array_merge($columnas, [
            'cantidad' => $cantidad,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /** Inserta una fila del mayor saltándose todo el dominio. */
    private function insertarMovimiento(array $columnas, string $cantidad, ?string $efectoUid = null): void
    {
        DB::table('planta_movimientos')->insert(array_merge($columnas, [
            'cantidad' => $cantidad,
            'unidad_base' => 'libra',
            'tipo' => 'carga_inicial',
            'documento_type' => 'Tests\\Documento',
            'documento_id' => 1,
            'documento_detalle_id' => null,
            'transicion' => 'confirmar',
            'efecto_uid' => $efectoUid ?? hash('sha256', (string) Str::uuid()),
            'grupo_uuid' => (string) Str::uuid(),
            'fecha_efectiva' => '2026-07-30',
            'created_at' => now(),
        ]));
    }

    // --- Estructura ---

    public function test_las_dos_tablas_existen_con_sus_columnas(): void
    {
        $this->assertTrue(Schema::hasTable('planta_movimientos'));
        $this->assertTrue(Schema::hasTable('planta_existencias'));

        $this->assertTrue(Schema::hasColumns('planta_movimientos', [
            'id', 'planta_insumo_id', 'planta_lote_id', 'planta_ubicacion_id', 'estado',
            'planta_traslado_id', 'cantidad', 'unidad_base', 'tipo', 'documento_type',
            'documento_id', 'documento_detalle_id', 'transicion', 'efecto_uid', 'grupo_uuid',
            'movimiento_revertido_id', 'user_id', 'responsable_nombre', 'fecha_efectiva',
            'metadata', 'created_at',
        ]));

        $this->assertTrue(Schema::hasColumns('planta_existencias', [
            'id', 'planta_insumo_id', 'planta_lote_id', 'planta_ubicacion_id', 'estado',
            'planta_traslado_id', 'cantidad', 'actualizado_en', 'created_at', 'updated_at',
        ]));
    }

    public function test_el_mayor_no_tiene_updated_at_ni_deleted_at(): void
    {
        // Es lo que convierte «append-only» en una propiedad del esquema y no en
        // una promesa del código: no hay dónde anotar una modificación.
        $this->assertFalse(Schema::hasColumn('planta_movimientos', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('planta_movimientos', 'deleted_at'));
    }

    public function test_las_existencias_no_tienen_borrado_logico(): void
    {
        $this->assertFalse(Schema::hasColumn('planta_existencias', 'deleted_at'));
    }

    // --- CHECK cantidad >= 0 ---

    public function test_el_check_rechaza_una_cantidad_negativa_en_existencias(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->expectException(QueryException::class);

        $this->insertarExistencia($bucket->aColumnas(), '-0.0001');
    }

    public function test_el_check_rechaza_dejar_en_negativo_una_fila_existente(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->insertarExistencia($bucket->aColumnas(), '5.0000');

        $this->expectException(QueryException::class);

        // El trigger de SQLite cubre INSERT y UPDATE; el CHECK de MySQL, ambos por
        // definición. Un UPDATE crudo es justo la vía que el servicio no ve.
        DB::table('planta_existencias')->where($bucket->aColumnas())->update(['cantidad' => '-1.0000']);
    }

    public function test_el_check_admite_cero_porque_un_bucket_vaciado_es_legitimo(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->insertarExistencia($bucket->aColumnas(), '0.0000');

        $this->assertSame('0.0000', $this->saldoProyectado($bucket));
    }

    public function test_la_restriccion_de_cantidad_esta_declarada_en_el_motor_de_la_suite(): void
    {
        // Deja constancia de CÓMO está implementada aquí, para que nadie lea la
        // prueba anterior como «hay un CHECK nativo en SQLite»: no lo hay.
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        $triggers = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->where('tbl_name', 'planta_existencias')
            ->pluck('name')
            ->all();

        $this->assertContains('planta_exist_cantidad_no_negativa_insert', $triggers);
        $this->assertContains('planta_exist_cantidad_no_negativa_update', $triggers);
    }

    // --- UNIQUE del bucket ---

    public function test_el_unique_impide_dos_filas_para_el_mismo_bucket(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->insertarExistencia($bucket->aColumnas(), '1.0000');

        $this->expectException(QueryException::class);

        $this->insertarExistencia($bucket->aColumnas(), '2.0000');
    }

    public function test_el_unique_agrupa_por_las_cinco_dimensiones_y_no_por_cuatro(): void
    {
        $insumo = $this->insumo();
        $lote = $this->lote($insumo);
        $ubicacion = $this->ubicacion();
        $transito = $this->transito();

        // Mismo insumo, lote y ubicación: solo cambia el ESTADO.
        $this->insertarExistencia(
            $this->bucket($insumo, $lote, $ubicacion, EstadoDisponibilidad::Disponible)->aColumnas(),
            '1.0000'
        );
        $this->insertarExistencia(
            $this->bucket($insumo, $lote, $ubicacion, EstadoDisponibilidad::Retenido)->aColumnas(),
            '2.0000'
        );

        // Mismo insumo, lote, ubicación y estado: solo cambia el TRASLADO.
        $this->insertarExistencia(
            $this->bucket($insumo, $lote, $transito, EstadoDisponibilidad::Disponible, 7)->aColumnas(),
            '3.0000'
        );
        $this->insertarExistencia(
            $this->bucket($insumo, $lote, $transito, EstadoDisponibilidad::Disponible, 9)->aColumnas(),
            '4.0000'
        );

        $this->assertSame(4, DB::table('planta_existencias')->count());
    }

    // --- UNIQUE de efecto_uid ---

    public function test_efecto_uid_es_unico_a_nivel_de_motor(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $uid = hash('sha256', 'efecto-repetido');

        $this->insertarMovimiento($bucket->aColumnas(), '1.0000', $uid);

        $this->expectException(QueryException::class);

        $this->insertarMovimiento($bucket->aColumnas(), '1.0000', $uid);
    }

    public function test_dos_movimientos_con_uid_distinto_conviven_en_el_mismo_bucket(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->insertarMovimiento($bucket->aColumnas(), '1.0000', hash('sha256', 'a'));
        $this->insertarMovimiento($bucket->aColumnas(), '2.0000', hash('sha256', 'b'));

        $this->assertSame(2, DB::table('planta_movimientos')->where($bucket->aColumnas())->count());
        $this->assertSame('3.0000', $this->sumaMayor($bucket));
    }

    // --- Claves foráneas ---

    public function test_no_se_puede_borrar_un_insumo_con_movimientos(): void
    {
        ['insumo' => $insumo, 'bucket' => $bucket] = $this->escenarioBasico();

        $this->insertarMovimiento($bucket->aColumnas(), '1.0000');

        $this->expectException(QueryException::class);

        // Borrado FÍSICO: el softDeletes del insumo no llega al motor y aquí lo
        // que se prueba es el restrictOnDelete.
        DB::table('planta_insumos')->where('id', $insumo->id)->delete();
    }

    public function test_no_se_puede_borrar_un_lote_con_saldo_proyectado(): void
    {
        ['lote' => $lote, 'bucket' => $bucket] = $this->escenarioBasico();

        $this->insertarExistencia($bucket->aColumnas(), '1.0000');

        $this->expectException(QueryException::class);

        DB::table('planta_lotes')->where('id', $lote->id)->delete();
    }
}
