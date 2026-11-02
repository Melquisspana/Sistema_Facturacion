<?php

namespace Tests\Feature\Planilla;

use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\Asistencia\AsistenciaMarcacion;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\BajaEmpleado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class BajaEmpleadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['planilla.enabled' => true]);
    }

    private function actor(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function empleado(User $actor, array $datos = []): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($actor, array_replace([
            'nombres' => 'Empleado', 'apellidos' => 'Ficticio', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-BAJA',
            'con_descuentos_ley' => false, 'usa_sistema' => true, 'marca' => true, 'sale_rutas' => true,
            'rol' => 'vendedor', 'email' => 'baja@example.test', 'password' => 'Prueba#Segura2026', 'password_confirmation' => 'Prueba#Segura2026',
        ], $datos));
    }

    public function test_baja_y_reactivacion_conservan_filas_marcaciones_y_huellas(): void
    {
        $actor = $this->actor();
        $e = $this->empleado($actor);
        $marcacion = AsistenciaMarcacion::factory()->create(['asistencia_empleado_id' => $e->asistencia_empleado_id]);
        $huella = AsistenciaHuella::factory()->create(['asistencia_empleado_id' => $e->asistencia_empleado_id, 'activo' => true]);
        $this->actingAs($actor)->from(route('personal.empleados'))->post(route('planilla.empleados.baja', $e), ['fecha' => '2026-11-02'])
            ->assertSessionHasNoErrors()->assertRedirect(route('personal.empleados'))->assertSessionHas('planilla.aviso', fn ($aviso) => str_contains($aviso, 'huella(s) vigente(s)'));
        $this->assertFalse($e->fresh()->activo);
        $this->assertSame('2026-11-02', $e->fresh()->baja_el);
        $this->assertFalse($e->asistencia->fresh()->activo);
        $this->assertFalse($e->personalRuta->fresh()->activo);
        $this->assertFalse($e->usuario->fresh()->activo);
        $this->assertSame(1, PlanillaEmpleado::count());
        $this->assertSame(1, PersonalRuta::count());
        $this->assertSame(1, AsistenciaEmpleado::count());
        $this->assertSame(2, User::count());
        $this->assertNotNull($marcacion->fresh());
        $this->assertTrue($huella->fresh()->activo);
        $this->post(route('planilla.empleados.reactivar', $e))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($e->fresh()->activo);
        $this->assertNull($e->fresh()->baja_el);
        $this->assertTrue($e->asistencia->fresh()->activo);
        $this->assertTrue($e->personalRuta->fresh()->activo);
        $this->assertTrue($e->usuario->fresh()->activo);
        $this->assertNotNull($marcacion->fresh());
        $this->assertTrue($huella->fresh()->activo);
        foreach (Activity::where('log_name', 'empleado')->get() as $actividad) {
            $this->assertStringNotContainsString('FICTICIO-BAJA', $actividad->toJson());
            $this->assertStringNotContainsString('password', $actividad->toJson());
        }
    }

    public function test_ultimo_administrador_no_cambia_nada(): void
    {
        $actor = User::factory()->create(['activo' => true]);
        $actor->givePermissionTo(['planilla.gestionar', 'usuarios.gestionar', 'asistencia.gestionar', 'rutas.personal.gestionar']);
        $e = $this->empleado($actor, ['rol' => 'administrador']);
        try {
            app(BajaEmpleado::class)->dar($actor, $e, '2026-11-02');
            $this->fail('Debía proteger al último administrador.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('empleado', $error->errors());
        }
        $this->assertTrue($e->fresh()->activo);
        $this->assertNull($e->fresh()->baja_el);
        $this->assertTrue($e->asistencia->activo);
        $this->assertTrue($e->personalRuta->activo);
        $this->assertTrue($e->usuario->activo);
    }

    public function test_no_puede_darse_de_baja_a_si_mismo_aunque_haya_otro_admin(): void
    {
        $actor = $this->actor();
        $this->actor();
        $e = $this->empleado($actor, ['user_id' => $actor->id, 'es_otra_persona' => true]);
        $this->actingAs($actor)->post(route('planilla.empleados.baja', $e), ['fecha' => '2026-11-02'])->assertSessionHasErrors('empleado');
        $this->assertTrue($e->fresh()->activo);
        $this->assertTrue($actor->fresh()->activo);
        $this->assertTrue($e->asistencia->activo);
        $this->assertTrue($e->personalRuta->activo);
    }

    public static function permisos(): array
    {
        return [['planilla.gestionar'], ['usuarios.gestionar'], ['asistencia.gestionar'], ['rutas.personal.gestionar']];
    }

    #[DataProvider('permisos')]
    public function test_permisos_de_cada_enlace_en_baja_y_reactivacion(string $sin): void
    {
        $e = $this->empleado($this->actor());
        $actor = User::factory()->create(['activo' => true]);
        $actor->syncPermissions(array_values(array_diff(['planilla.ver', 'planilla.gestionar', 'usuarios.gestionar', 'asistencia.gestionar', 'rutas.personal.gestionar'], [$sin])));
        $this->actingAs($actor)->post(route('planilla.empleados.baja', $e), ['fecha' => '2026-11-02'])->assertForbidden();
        $this->post(route('planilla.empleados.reactivar', $e))->assertForbidden();
        $this->assertTrue($e->fresh()->activo);
        $this->assertTrue($e->asistencia->activo);
        $this->assertTrue($e->personalRuta->activo);
        $this->assertTrue($e->usuario->activo);
    }

    public function test_fecha_invalida_no_cambia_nada(): void
    {
        $actor = $this->actor();
        $e = $this->empleado($actor);
        $this->actingAs($actor)->post(route('planilla.empleados.baja', $e), ['fecha' => '02/11/2026'])->assertSessionHasErrors('fecha');
        $this->assertTrue($e->fresh()->activo);
    }
}
