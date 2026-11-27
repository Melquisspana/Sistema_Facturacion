<?php

namespace Tests\Feature\Asistencia;

use App\Enums\Asistencia\MetodoMarcacion;
use App\Enums\Asistencia\TipoMarcacion;
use App\Enums\PermisoSistema;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaMarcacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cada marca dice si fue «con huella» o «con número» donde se listan marcas: Marcaciones,
 * «Marcas de hoy», la hoja para imprimir, las jornadas y las últimas de la ficha. Las
 * correcciones manuales no dicen ninguna de las dos.
 */
class MetodoMarcacionVistasTest extends TestCase
{
    use RefreshDatabase;

    private string $zonaPhp;

    private AsistenciaEmpleado $persona;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zonaPhp = date_default_timezone_get();
        config(['asistencia.enabled' => true, 'asistencia.zona_horaria' => 'America/El_Salvador', 'app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        Carbon::setTestNow(Carbon::parse('2026-11-26 22:00:00', 'UTC'));

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->persona = AsistenciaEmpleado::factory()->create(['nombres' => 'Empleado', 'apellidos' => 'de prueba']);
        $this->marca('2026-11-26 07:02:00', TipoMarcacion::Entrada, MetodoMarcacion::Numero);
        $this->marca('2026-11-26 15:30:00', TipoMarcacion::Salida, MetodoMarcacion::Huella);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->zonaPhp);
        parent::tearDown();
    }

    private function marca(string $local, TipoMarcacion $tipo, ?MetodoMarcacion $metodo): void
    {
        AsistenciaMarcacion::factory()->en(Carbon::parse($local, 'America/El_Salvador'))->tipo($tipo)
            ->create(['asistencia_empleado_id' => $this->persona->id, 'metodo' => $metodo]);
    }

    private function usuario(): User
    {
        return User::factory()->create(['activo' => true])->givePermissionTo(PermisoSistema::AsistenciaVer->value);
    }

    public function test_el_historial_dice_con_que_se_marco(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('asistencia.marcaciones.index', ['desde' => '2026-11-26', 'hasta' => '2026-11-26']))
            ->assertOk()
            ->assertSeeInOrder(['15:30:00', 'Con huella', '07:02:00', 'Con número']);
    }

    public function test_marcas_de_hoy_dice_con_que_entro_y_con_que_salio(): void
    {
        $this->actingAs($this->usuario())->get(route('asistencia.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['07:02', 'Con número', '15:30', 'Con huella']);
    }

    public function test_la_hoja_dice_con_que_se_marco_cada_hora(): void
    {
        $respuesta = $this->actingAs($this->usuario())->get(route('asistencia.hoja', ['fecha' => '2026-11-26']))->assertOk();

        $fila = collect($respuesta->viewData('filas'))->sole();
        $this->assertSame(['07:02', 'Con número', '15:30', 'Con huella'],
            [$fila['entrada'], $fila['entrada_metodo'], $fila['salida'], $fila['salida_metodo']]);
        $respuesta->assertSeeInOrder(['07:02', 'Con número', '15:30', 'Con huella']);
    }

    public function test_las_jornadas_dicen_con_que_se_marco(): void
    {
        $this->actingAs($this->usuario())
            ->get(route('asistencia.jornadas.index', ['desde' => '2026-11-26', 'hasta' => '2026-11-26']))
            ->assertOk()
            ->assertSeeInOrder(['07:02', 'Con número', '15:30', 'Con huella']);
    }

    public function test_la_ficha_dice_con_que_se_marco(): void
    {
        $this->actingAs($this->usuario())->get(route('personal.empleados.show', 'a'.$this->persona->id))
            ->assertOk()
            ->assertSeeInOrder(['15:30:00', 'Con huella', '07:02:00', 'Con número']);
    }

    public function test_la_correccion_manual_no_dice_ni_huella_ni_numero(): void
    {
        AsistenciaMarcacion::query()->delete();
        AsistenciaMarcacion::factory()->manual()->en(Carbon::parse('2026-11-26 08:00:00', 'America/El_Salvador'))
            ->create(['asistencia_empleado_id' => $this->persona->id]);

        $this->assertNull(AsistenciaMarcacion::query()->sole()->metodo);
        $this->actingAs($this->usuario())
            ->get(route('asistencia.marcaciones.index', ['desde' => '2026-11-26', 'hasta' => '2026-11-26']))
            ->assertOk()->assertSee('Manual')->assertDontSee('Con huella')->assertDontSee('Con número');
    }
}
