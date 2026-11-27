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
 */
class NumeroParaMarcarMigracionTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACION = 'database/migrations/2026_11_26_100000_numero_para_marcar_en_asistencia.php';

    public function test_las_marcas_del_lector_que_ya_existian_quedan_con_huella_y_las_manuales_sin_metodo(): void
    {
        $migracion = require base_path(self::MIGRACION);
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
