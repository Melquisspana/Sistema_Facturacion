<?php

namespace Tests\Feature\Asistencia;

use App\Models\Asistencia\AsistenciaEmpleado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La migración del número para marcar: las marcas del lector que ya existían quedan
 * «con huella» (era la única forma de marcar) y las correcciones manuales sin método.
 *
 * En MySQL el DDL de `down()`/`up()` confirma la transacción de RefreshDatabase, así que
 * lo que la prueba escribió quedaría para las siguientes (Personal y Planilla cuentan
 * personas). Por eso al cerrar se borran las filas nuevas de las tablas que toca y la
 * migración queda aplicada, en los dos motores. Mismo patrón que la prueba de la
 * migración de Cobros IdentidadPorEjercicioMigracionTest.
 */
class NumeroParaMarcarMigracionTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACION = 'database/migrations/2026_11_26_100000_numero_para_marcar_en_asistencia.php';

    /** En orden de borrado: primero las que apuntan a las otras. La fábrica de personas audita. */
    private const TABLAS = ['asistencia_marcaciones', 'asistencia_empleados', 'activity_log'];

    /** @var array<string, int> */
    private array $ultimoId = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TABLAS as $tabla) {
            $this->ultimoId[$tabla] = (int) DB::table($tabla)->max('id');
        }
    }

    protected function tearDown(): void
    {
        if (! Schema::hasColumn('asistencia_empleados', 'numero_marcar_indice')) {
            $this->migracion()->up();
        }
        foreach (self::TABLAS as $tabla) {
            DB::table($tabla)->where('id', '>', $this->ultimoId[$tabla])->delete();
        }

        parent::tearDown();
    }

    private function migracion(): object
    {
        return require base_path(self::MIGRACION);
    }

    public function test_las_marcas_del_lector_que_ya_existian_quedan_con_huella_y_las_manuales_sin_metodo(): void
    {
        $migracion = $this->migracion();
        $migracion->down();
        $this->assertFalse(Schema::hasColumn('asistencia_marcaciones', 'metodo'));
        $this->assertFalse(Schema::hasColumn('asistencia_empleados', 'numero_marcar'));

        $empleado = AsistenciaEmpleado::factory()->create()->id;
        foreach (['dispositivo', 'diferida', 'manual'] as $origen) {
            DB::table('asistencia_marcaciones')->insert([
                'asistencia_empleado_id' => $empleado, 'tipo' => 'entrada', 'marcado_at' => now(),
                'fecha_local' => now()->toDateString(), 'origen' => $origen, 'created_at' => now(),
            ]);
        }

        $migracion->up();

        $this->assertSame(
            ['dispositivo' => 'huella', 'diferida' => 'huella', 'manual' => null],
            DB::table('asistencia_marcaciones')->orderBy('id')->pluck('metodo', 'origen')->all(),
        );
        $this->assertTrue(Schema::hasColumns('asistencia_empleados', ['numero_marcar', 'numero_marcar_indice']));
    }
}
