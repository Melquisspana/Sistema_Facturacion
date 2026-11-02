<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Planilla\PlanillaSueldo;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\BajaEmpleado;
use App\Services\Planilla\SalarioMinimo;
use App\Services\Planilla\SueldoHabitual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SueldoYValidacionesTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['planilla.enabled' => true]);

        $this->actor = User::factory()->create(['activo' => true]);
        $this->actor->syncPermissions(PermisoSistema::todos());
    }

    private function alta(array $cambios = []): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->actor, array_replace([
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => true, 'alta_el' => '2026-01-01',
        ], $cambios));
    }

    private function fallaCon(string $campo, callable $accion): void
    {
        try {
            $accion();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors());

            return;
        }

        $this->fail('Se esperaba un error en '.$campo);
    }

    public function test_el_salario_minimo_sale_del_sector_y_de_la_vigencia(): void
    {
        $this->assertSame(40880, app(SalarioMinimo::class)->vigenteEn('2026-11-02'));

        config(['planilla.sector_salario_minimo' => 'maquila_textil']);
        $this->assertSame(40232, app(SalarioMinimo::class)->vigenteEn('2026-11-02'));
    }

    public function test_sin_minimo_vigente_da_error_y_nunca_cero(): void
    {
        $this->fallaCon('sueldo_mensual', fn () => app(SalarioMinimo::class)->vigenteEn('2025-05-31'));

        config(['planilla_descuentos.salario_minimo' => []]);
        $this->fallaCon('sueldo_mensual', fn () => $this->alta(['sueldo_mensual' => '500.00']));
        $this->assertSame(0, PlanillaEmpleado::count());
    }

    public function test_con_descuentos_el_sueldo_no_baja_del_minimo(): void
    {
        $this->alta(['sueldo_mensual' => '408.80']);
        $this->assertSame(1, PlanillaEmpleado::count());

        $this->fallaCon('sueldo_mensual', fn () => $this->alta(['dui' => 'FICTICIO-2', 'nombres' => 'Bea', 'sueldo_mensual' => '408.79']));
        $this->fallaCon('sueldo_mensual', fn () => $this->alta(['dui' => 'FICTICIO-2', 'nombres' => 'Bea', 'sueldo_mensual' => '408.79', 'confirmar_bajo_minimo' => true]));
        // La transacción deshizo a la persona: no queda a medias.
        $this->assertSame(1, PlanillaEmpleado::count());
    }

    public function test_sin_descuentos_bajo_el_minimo_hay_que_confirmarlo(): void
    {
        $datos = ['con_descuentos_ley' => false, 'sueldo_mensual' => '300.00'];

        $this->fallaCon('sueldo_mensual', fn () => $this->alta($datos));

        $this->alta($datos + ['confirmar_bajo_minimo' => true]);
        $this->assertSame(1, PlanillaEmpleado::count());
    }

    public function test_el_sueldo_tiene_que_ser_mayor_a_cero(): void
    {
        $this->fallaCon('sueldo_mensual', fn () => $this->alta(['con_descuentos_ley' => false, 'sueldo_mensual' => '0.00', 'confirmar_bajo_minimo' => true]));
    }

    public function test_alguien_con_ingreso_antiguo_se_compara_con_el_minimo_de_hoy(): void
    {
        $this->alta(['alta_el' => '2019-03-01', 'sueldo_mensual' => '408.80']);
        $this->assertSame(1, PlanillaEmpleado::count());

        $this->fallaCon('sueldo_mensual', fn () => $this->alta(['dui' => 'FICTICIO-2', 'nombres' => 'Bea', 'alta_el' => '2019-03-01', 'sueldo_mensual' => '365.00']));
    }

    public function test_el_mensual_y_el_importe_por_pago_viven_en_la_misma_fila(): void
    {
        $e = $this->alta(['sueldo_mensual' => '501.01']);

        // 250.505 redondea hacia arriba, y el contrato sigue diciendo 501.01.
        $fila = PlanillaSueldo::where('planilla_empleado_id', $e->id)->sole();
        $this->assertSame('250.51', (string) $fila->importe);
        $this->assertSame('501.01', (string) $fila->importe_mensual);
        $this->assertSame([$e->id => 50101], app(SueldoHabitual::class)->mensualesEn(collect([$e]), now()->toDateString()));
    }

    public function test_el_importe_por_pago_sin_mensual_se_reconstruye(): void
    {
        $e = $this->alta();
        app(SueldoHabitual::class)->registrar($this->actor, $e, ['importe' => '250.00', 'vigente_desde' => '2026-01-01']);

        $this->assertSame([$e->id => 50000], app(SueldoHabitual::class)->mensualesEn(collect([$e]), now()->toDateString()));
    }

    public function test_cambiar_el_sueldo_por_pantalla_queda_con_fecha_y_sin_importe_en_la_bitacora(): void
    {
        $e = $this->alta(['sueldo_mensual' => '408.80']);

        $this->actingAs($this->actor)->post(route('planilla.empleados.sueldo', $e), [
            'sueldo_mensual' => '450.00', 'vigente_desde' => '2026-10-01', 'motivo' => 'Aumento de prueba',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, PlanillaSueldo::where('planilla_empleado_id', $e->id)->count());
        $this->assertSame([$e->id => 45000], app(SueldoHabitual::class)->mensualesEn(collect([$e]), '2026-10-15'));
        $this->assertSame([$e->id => 40880], app(SueldoHabitual::class)->mensualesEn(collect([$e]), '2026-09-15'));

        $actividad = Activity::where('description', 'cambió el sueldo')->sole();
        $this->assertStringNotContainsString('450', json_encode($actividad->properties));
    }

    public function test_cambiar_el_sueldo_exige_el_permiso_de_importes(): void
    {
        $e = $this->alta();
        $sinImportes = User::factory()->create(['activo' => true]);
        $sinImportes->syncPermissions(['planilla.ver', 'planilla.gestionar']);

        $this->actingAs($sinImportes)->post(route('planilla.empleados.sueldo', $e), [
            'sueldo_mensual' => '450.00', 'vigente_desde' => '2026-10-01',
        ])->assertForbidden();
    }

    public function test_la_ficha_avisa_quien_queda_bajo_un_minimo_nuevo_sin_tocar_su_sueldo(): void
    {
        $bajo = $this->alta(['sueldo_mensual' => '408.80']);
        $this->alta(['dui' => 'FICTICIO-2', 'nombres' => 'Bea', 'sueldo_mensual' => '600.00']);

        $tabla = config('planilla_descuentos.salario_minimo');
        $tabla[] = ['vigente_desde' => '2026-06-01', 'sectores' => ['industria' => '500.00']];
        config(['planilla_descuentos.salario_minimo' => $tabla]);

        $this->actingAs($this->actor)->get(route('planilla.empleados'))
            ->assertOk()->assertSee('Una persona queda')->assertSee('Bajo el mínimo');

        $this->assertSame([$bajo->id => 40880], app(SueldoHabitual::class)->mensualesEn(collect([$bajo]), now()->toDateString()));
    }

    public function test_quien_no_ve_importes_no_ve_el_aviso_del_minimo(): void
    {
        $this->alta(['con_descuentos_ley' => false, 'sueldo_mensual' => '300.00', 'confirmar_bajo_minimo' => true]);

        $lector = User::factory()->create(['activo' => true]);
        $lector->syncPermissions(['planilla.ver']);

        $this->actingAs($lector)->get(route('planilla.empleados'))
            ->assertOk()->assertDontSee('Bajo el mínimo')->assertDontSee('300.00');
    }

    public function test_sin_minimo_cargado_la_ficha_lo_dice_y_abre_igual(): void
    {
        $this->alta();
        config(['planilla_descuentos.salario_minimo' => []]);

        $this->actingAs($this->actor)->get(route('planilla.empleados'))
            ->assertOk()->assertSee('No hay salario mínimo vigente');
    }

    public function test_el_alta_futura_hay_que_confirmarla(): void
    {
        $futura = now()->addDays(10)->toDateString();

        $this->fallaCon('alta_el', fn () => $this->alta(['alta_el' => $futura]));

        $this->alta(['alta_el' => $futura, 'confirmar_alta_futura' => true]);
        $this->assertSame(1, PlanillaEmpleado::count());
    }

    public function test_la_baja_no_puede_ser_anterior_al_alta(): void
    {
        $e = $this->alta(['alta_el' => '2026-03-01']);

        $this->fallaCon('fecha', fn () => app(BajaEmpleado::class)->dar($this->actor, $e, '2026-02-28'));

        app(BajaEmpleado::class)->dar($this->actor, $e, '2026-03-01');
        $this->assertFalse($e->fresh()->activo);
    }

    public function test_el_documento_no_se_repite_con_un_inactivo(): void
    {
        $e = $this->alta(['documento_tipo' => 'dui', 'dui' => '00000000-0']);
        app(BajaEmpleado::class)->dar($this->actor, $e, '2026-03-01');

        $this->fallaCon('dui', fn () => $this->alta(['documento_tipo' => 'dui', 'dui' => '000000000', 'nombres' => 'Bea', 'apellidos' => 'Otra']));
    }
}
