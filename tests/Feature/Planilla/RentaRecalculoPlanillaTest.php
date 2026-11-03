<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaConcepto;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Planilla\PlanillaRentaAcumulado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El recálculo de renta de junio y diciembre al generar la planilla, y la pantalla de
 * la renta antes del sistema que lo alimenta.
 *
 * Ana gana 1,000.00 al mes: 500.00 por quincena, ISSS 15.00, AFP 36.25, gravada
 * 448.75 y renta quincenal 30.23 (20 % de 1.13 = 0.23, más 30.00).
 */
class RentaRecalculoPlanillaTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private PlanillaEmpleado $ana;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-03 10:00:00');

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['planilla.enabled' => true, 'planilla.pagos_enabled' => true, 'gastos.enabled' => false]);

        $this->actor = User::factory()->create(['activo' => true]);
        $this->actor->syncPermissions(PermisoSistema::todos());

        $this->ana = $this->persona('Ana', true, 'FICTICIO-1');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function persona(string $nombre, bool $conDescuentos, string $dui): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->actor, [
            'nombres' => $nombre, 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => $dui,
            'con_descuentos_ley' => $conDescuentos, 'alta_el' => '2026-01-01', 'sueldo_mensual' => '1000.00',
        ]);
    }

    /** @return array<string, string> */
    private function periodo(string $fecha): array
    {
        return ['tipo_periodo' => 'quincenal', 'fecha' => $fecha, 'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => ''];
    }

    private function generar(string $fecha): Planilla
    {
        $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo($fecha))->assertSessionHasNoErrors();

        return Planilla::latest('id')->firstOrFail();
    }

    private function renta(Planilla $planilla, PlanillaEmpleado $empleado): ?string
    {
        $concepto = PlanillaConcepto::where('ley', 'renta')
            ->whereHas('detalle', fn ($q) => $q->where('planilla_id', $planilla->id)->where('planilla_empleado_id', $empleado->id))
            ->first();

        return $concepto === null ? null : (string) $concepto->importe;
    }

    /** @param  array<string, string>  $cambios */
    private function confirmarAcumulado(array $cambios = []): TestResponse
    {
        return $this->actingAs($this->actor)->post(route('planilla.renta.store'), $cambios + [
            'planilla_empleado_id' => (string) $this->ana->id, 'anio' => '2026',
            'hasta' => '2026-10-31', 'gravado' => '8975.00', 'renta_retenida' => '604.60',
        ]);
    }

    // ─────────────────────── Al generar ───────────────────────

    public function test_sin_acumulado_confirmado_diciembre_no_se_genera_y_dice_quien_falta(): void
    {
        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->periodo('2026-12-16')))
            ->assertOk()
            ->assertSee('Ana Ficticia')
            ->assertSee('falta confirmar lo gravado y la renta de 2026 antes del sistema')
            ->assertDontSee('Sí, generar la planilla');

        $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-12-16'))
            ->assertSessionHasErrors('reparos');

        $this->assertSame(0, Planilla::count());
    }

    public function test_las_demas_quincenas_no_cambian_ni_piden_acumulado(): void
    {
        foreach (['2026-11-16', '2026-12-01'] as $fecha) {
            $this->assertSame('30.23', $this->renta($this->generar($fecha), $this->ana));
        }
    }

    public function test_diciembre_recalcula_con_lo_confirmado_y_lo_del_sistema(): void
    {
        foreach (['2026-11-01', '2026-11-16', '2026-12-01'] as $fecha) {
            $this->generar($fecha);
        }

        // Enero a octubre: 20 quincenas de 448.75 y 30.23.
        $this->confirmarAcumulado()->assertSessionHasNoErrors();

        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->periodo('2026-12-16')))
            ->assertOk()
            ->assertSee('recálculo de diciembre')
            ->assertSee('Sí, generar la planilla');

        // 24 × 448.75 = 10,770.00 del año: 20 % de 27.14 = 5.43, más 720.00 = 725.43;
        // menos 23 × 30.23 = 695.29 retenidos.
        $this->assertSame('30.14', $this->renta($this->generar('2026-12-16'), $this->ana));
    }

    public function test_junio_con_todo_el_semestre_en_el_sistema_no_pide_acumulado(): void
    {
        foreach (range(1, 6) as $mes) {
            $this->generar(sprintf('2026-%02d-01', $mes));

            if ($mes < 6) {
                $this->generar(sprintf('2026-%02d-16', $mes));
            }
        }

        // 12 × 448.75 = 5,385.00: 20 % de 13.56 = 2.71, más 360.00 = 362.71; menos
        // 11 × 30.23 = 332.53 retenidos.
        $this->assertSame('30.18', $this->renta($this->generar('2026-06-16'), $this->ana));
        $this->assertSame(0, PlanillaRentaAcumulado::count());
    }

    public function test_quien_va_sin_descuentos_no_frena_ni_lleva_renta(): void
    {
        $beto = $this->persona('Beto', false, 'FICTICIO-2');

        // Ana sin ninguna quincena en el sistema: 23 quincenas antes de la última.
        $this->confirmarAcumulado(['hasta' => '2026-12-15', 'gravado' => '10321.25', 'renta_retenida' => '695.29'])
            ->assertSessionHasNoErrors();

        $planilla = $this->generar('2026-12-16');

        $this->assertSame('30.14', $this->renta($planilla, $this->ana));
        $this->assertSame(0, PlanillaConcepto::whereNotNull('ley')
            ->whereHas('detalle', fn ($q) => $q->where('planilla_id', $planilla->id)->where('planilla_empleado_id', $beto->id))
            ->count());
    }

    public function test_lo_confirmado_con_un_hueco_antes_de_esta_quincena_frena(): void
    {
        // Confirmado hasta octubre, pero noviembre y la primera de diciembre no están.
        $this->confirmarAcumulado()->assertSessionHasNoErrors();

        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->periodo('2026-12-16')))
            ->assertSee('después faltan quincenas en el sistema')
            ->assertDontSee('Sí, generar la planilla');

        $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-12-16'))
            ->assertSessionHasErrors('reparos');

        $this->assertSame(0, Planilla::count());
    }

    public function test_lo_confirmado_que_llega_hasta_esta_quincena_frena(): void
    {
        $this->confirmarAcumulado(['hasta' => '2026-12-20'])->assertSessionHasNoErrors();

        $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-12-16'))
            ->assertSessionHasErrors('reparos');

        $this->assertSame(0, Planilla::count());
    }

    // ─────────────────────── La pantalla ───────────────────────

    public function test_propone_el_sueldo_habitual_de_las_quincenas_que_faltan(): void
    {
        $this->persona('Beto', false, 'FICTICIO-2');

        // Sin quincenas en el sistema: 23 hasta el recálculo de diciembre.
        $this->actingAs($this->actor)->get(route('planilla.renta.index', ['anio' => 2026]))
            ->assertOk()
            ->assertSee('Ana Ficticia')
            ->assertSee('Falta confirmar')
            ->assertSee('value="2026-12-15"', false)
            ->assertSee('value="10321.25"', false)
            ->assertSee('value="695.29"', false)
            ->assertDontSee('Beto Ficticia');

        // Con noviembre en el sistema: 20, de enero a octubre.
        $this->generar('2026-11-01');

        $this->actingAs($this->actor)->get(route('planilla.renta.index', ['anio' => 2026]))
            ->assertSee('value="2026-10-31"', false)
            ->assertSee('value="8975.00"', false)
            ->assertSee('value="604.60"', false);
    }

    public function test_confirmar_guarda_una_por_ano_y_la_bitacora_no_lleva_importes(): void
    {
        $this->confirmarAcumulado()->assertRedirect(route('planilla.renta.index', ['anio' => 2026]));
        $this->confirmarAcumulado(['gravado' => '9000.00'])->assertSessionHasNoErrors();

        $fila = PlanillaRentaAcumulado::sole();
        $this->assertSame('9000.00', (string) $fila->gravado);
        $this->assertSame('604.60', (string) $fila->renta_retenida);
        $this->assertSame($this->actor->id, $fila->registrado_por);

        $actividades = Activity::where('log_name', 'planilla')->orderBy('id')->get();
        $this->assertSame(['confirmó la renta antes del sistema', 'corrigió la renta antes del sistema'], $actividades->pluck('description')->all());

        foreach ($actividades as $actividad) {
            $this->assertSame(['planilla_empleado_id' => $this->ana->id, 'anio' => 2026], $actividad->properties->all());
        }

        $this->actingAs($this->actor)->get(route('planilla.renta.index', ['anio' => 2026]))
            ->assertSee('Confirmada')
            ->assertSee('Guardar cambios');
    }

    public function test_valida_lo_que_se_confirma(): void
    {
        $beto = $this->persona('Beto', false, 'FICTICIO-2');

        $this->confirmarAcumulado(['planilla_empleado_id' => (string) $beto->id])->assertSessionHasErrors('planilla_empleado_id');
        $this->confirmarAcumulado(['hasta' => '2025-12-31'])->assertSessionHasErrors('hasta');
        $this->confirmarAcumulado(['renta_retenida' => '9000.00'])->assertSessionHasErrors('renta_retenida');
        $this->confirmarAcumulado(['gravado' => '1,000'])->assertSessionHasErrors('gravado');
        $this->confirmarAcumulado(['anio' => '2027', 'hasta' => '2027-01-31'])->assertSessionHasErrors('anio');

        $this->assertSame(0, PlanillaRentaAcumulado::count());
    }

    public function test_sin_permiso_de_salarios_o_de_gestionar_no_entra(): void
    {
        foreach (['planilla.salarios', 'planilla.gestionar'] as $falta) {
            $usuario = User::factory()->create(['activo' => true]);
            $usuario->syncPermissions(array_values(array_diff(PermisoSistema::todos(), [$falta])));

            $this->actingAs($usuario)->get(route('planilla.renta.index'))->assertForbidden();
            $this->actingAs($usuario)->post(route('planilla.renta.store'), [
                'planilla_empleado_id' => (string) $this->ana->id, 'anio' => '2026',
                'hasta' => '2026-10-31', 'gravado' => '8975.00', 'renta_retenida' => '604.60',
            ])->assertForbidden();
        }

        $this->assertSame(0, PlanillaRentaAcumulado::count());
    }
}
