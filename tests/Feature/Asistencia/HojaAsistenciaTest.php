<?php

namespace Tests\Feature\Asistencia;

use App\Enums\Asistencia\TipoMarcacion;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaMarcacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La hoja diaria de asistencia de Producción, para imprimir y firmar (issue #87, etapa 2).
 * Lista a quien marca con el lector: entrada y salida son la primera y la última marca
 * del día local, en hora de El Salvador. Guardado en UTC a propósito: si la hoja tomara
 * la hora o el día sin pasar por la zona de Asistencia, las horas saldrían corridas.
 * Datos ficticios.
 */
class HojaAsistenciaTest extends TestCase
{
    use RefreshDatabase;

    private string $zonaPhp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zonaPhp = date_default_timezone_get();
        config(['asistencia.enabled' => true, 'asistencia.zona_horaria' => 'America/El_Salvador', 'app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        Carbon::setTestNow(Carbon::parse('2026-11-10 20:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zonaPhp);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(array $permisos = ['asistencia.ver']): User
    {
        return User::factory()->create(['activo' => true])->givePermissionTo($permisos);
    }

    private function marcar(AsistenciaEmpleado $persona, string $local, TipoMarcacion $tipo = TipoMarcacion::Entrada): void
    {
        AsistenciaMarcacion::factory()->en(Carbon::parse($local, 'America/El_Salvador'))->tipo($tipo)
            ->create(['asistencia_empleado_id' => $persona->id]);
    }

    public function test_lista_a_quien_marca_con_primera_y_ultima_marca_del_dia_elegido_en_hora_local(): void
    {
        $ana = AsistenciaEmpleado::factory()->create(['nombres' => 'Ana', 'apellidos' => 'Prueba Uno']);
        $beto = AsistenciaEmpleado::factory()->create(['nombres' => 'Beto', 'apellidos' => 'Prueba Dos']);
        $sinMarcar = AsistenciaEmpleado::factory()->create(['nombres' => 'Carla', 'apellidos' => 'Prueba Tres']);
        AsistenciaEmpleado::factory()->inactivo()->create(['nombres' => 'Dario', 'apellidos' => 'De Baja']);

        // Ana: entrada, almuerzo y salida. En la hoja van la primera y la última.
        $this->marcar($ana, '2026-11-09 07:55:00');
        $this->marcar($ana, '2026-11-09 12:00:00', TipoMarcacion::Salida);
        $this->marcar($ana, '2026-11-09 13:00:00');
        // 18:30 en El Salvador ya es el día 10 en UTC: sigue siendo del 9.
        $this->marcar($ana, '2026-11-09 18:30:00', TipoMarcacion::Salida);
        // Beto: una sola marca, sin salida.
        $this->marcar($beto, '2026-11-09 08:20:00');
        // Otro día: no entra.
        $this->marcar($sinMarcar, '2026-11-08 08:00:00');

        $respuesta = $this->actingAs($this->usuario())->get(route('asistencia.hoja', ['fecha' => '2026-11-09']))->assertOk();

        $filas = collect($respuesta->viewData('filas'))->map(fn (array $f) => [$f['nombre'], $f['entrada'], $f['salida']])->all();
        $this->assertEquals([
            ['Beto Prueba Dos', '08:20', null],
            ['Carla Prueba Tres', null, null],
            ['Ana Prueba Uno', '07:55', '18:30'],
        ], $filas);
        $respuesta->assertSee('CONTROL DE ASISTENCIA')->assertSee('Producción')->assertSee('09/11/2026')
            ->assertSee('Firma')->assertDontSee('De Baja')->assertDontSee('Dario');
    }

    public function test_sin_dia_elegido_es_hoy_local_y_sin_marcas_sale_la_lista_para_firmar(): void
    {
        AsistenciaEmpleado::factory()->create(['nombres' => 'Ana', 'apellidos' => 'Prueba Uno']);

        $respuesta = $this->actingAs($this->usuario())->get(route('asistencia.hoja'))->assertOk();

        $this->assertSame('2026-11-10', $respuesta->viewData('fecha')->format('Y-m-d'));
        $this->assertEquals([['nombre' => 'Ana Prueba Uno', 'entrada' => null, 'salida' => null]], $respuesta->viewData('filas'));
        $respuesta->assertSee('10/11/2026')->assertSee('Ana Prueba Uno');
    }

    public function test_quien_marco_ese_dia_y_despues_se_dio_de_baja_sigue_en_la_hoja(): void
    {
        $baja = AsistenciaEmpleado::factory()->inactivo()->create(['nombres' => 'Dario', 'apellidos' => 'De Baja']);
        $this->marcar($baja, '2026-11-02 08:00:00');

        $respuesta = $this->actingAs($this->usuario())->get(route('asistencia.hoja', ['fecha' => '2026-11-02']))->assertOk();

        $this->assertEquals([['nombre' => 'Dario De Baja', 'entrada' => '08:00', 'salida' => null]], $respuesta->viewData('filas'));
    }

    public function test_rechaza_una_fecha_que_no_es_fecha(): void
    {
        $this->actingAs($this->usuario())->get(route('asistencia.hoja', ['fecha' => '2026-13-45']))
            ->assertSessionHasErrors('fecha');
    }

    public function test_sin_permiso_de_asistencia_no_entra(): void
    {
        $this->get(route('asistencia.hoja'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['activo' => true]))->get(route('asistencia.hoja'))->assertForbidden();
    }

    public function test_la_pantalla_de_hoy_ofrece_la_hoja(): void
    {
        $this->actingAs($this->usuario())->get(route('asistencia.dashboard'))->assertOk()
            ->assertSee(route('asistencia.hoja'));
    }
}
