<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\BajaEmpleado;
use App\Services\Planilla\PrepararPlanilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La última quincena de quien fue dado de baja.
 *
 * La baja apaga a la persona en el acto, y la quincena solo proponía a las personas
 * activas: quien se iba el día 5 no aparecía en la quincena del 1 al 15, y esos días no
 * quedaban en ninguna planilla. Ahora entra quien estuvo activo algún día del período.
 *
 * CUÁNTO se le paga por esos días no lo decide el sistema: se propone la quincena de
 * siempre, se marca con un aviso y la planilla queda en borrador para revisar el importe.
 * Datos ficticios.
 */
class UltimaQuincenaDeLaBajaTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private PlanillaEmpleado $ana;

    private PlanillaEmpleado $beto;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['planilla.enabled' => true, 'planilla.pagos_enabled' => true, 'gastos.enabled' => false]);

        $this->actor = User::factory()->create(['activo' => true]);
        $this->actor->syncPermissions(PermisoSistema::todos());

        $this->ana = $this->persona('Ana', 'FICTICIO-1');
        $this->beto = $this->persona('Beto', 'FICTICIO-2');
    }

    /** Sin descuentos de ley: 450.00 al mes son 225.00 por quincena. */
    private function persona(string $nombre, string $dui): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->actor, [
            'nombres' => $nombre, 'apellidos' => 'Ficticio', 'documento_tipo' => 'pasaporte', 'dui' => $dui,
            'con_descuentos_ley' => false, 'alta_el' => '2026-01-01', 'sueldo_mensual' => '450.00',
            'sueldo_desde' => '2026-01-01', 'confirmar_bajo_minimo' => true,
        ]);
    }

    private function baja(PlanillaEmpleado $empleado, string $fecha): void
    {
        app(BajaEmpleado::class)->dar($this->actor, $empleado, $fecha, 'otro');
    }

    /** @return array<string, string> */
    private function periodo(string $fecha, string $clase = 'regular'): array
    {
        return ['tipo_periodo' => 'quincenal', 'fecha' => $fecha, 'clase' => $clase, 'moneda' => 'USD', 'fecha_pago' => ''];
    }

    private function abrir(string $fecha): Planilla
    {
        return app(PrepararPlanilla::class)->abrir($this->actor, $this->periodo($fecha));
    }

    /** @return array<int, int> */
    private function quienes(Planilla $planilla): array
    {
        return PlanillaDetalle::where('planilla_id', $planilla->id)->orderBy('planilla_empleado_id')->pluck('planilla_empleado_id')->all();
    }

    /** @return array<int, string> */
    private function avisos(Planilla $planilla): array
    {
        return app(PrepararPlanilla::class)->avisosParaConfirmar($planilla->fresh()->load('detalles.conceptos', 'detalles.empleado'), true);
    }

    public function test_quien_se_fue_dentro_de_la_quincena_entra_en_ella_con_su_aviso(): void
    {
        $this->baja($this->beto, '2026-10-05');

        $planilla = $this->abrir('2026-10-01');

        $this->assertSame([$this->ana->id, $this->beto->id], $this->quienes($planilla));
        // Se propone la quincena de siempre: el importe por esos días lo decide una persona.
        $this->assertSame('225.00', (string) PlanillaDetalle::where('planilla_id', $planilla->id)->where('planilla_empleado_id', $this->beto->id)->sole()->a_pagar);
        $this->assertContains('Beto Ficticio: tiene baja el 05/10/2026: revisá el importe antes de confirmar.', $this->avisos($planilla));
        $this->assertStringNotContainsString('Ana Ficticio', implode(' ', $this->avisos($planilla)));
    }

    public function test_una_baja_con_fecha_posterior_a_la_quincena_entra_sin_aviso(): void
    {
        // Preaviso: se registra hoy una baja que rige después de que termine el período.
        $this->baja($this->beto, '2026-10-20');

        $planilla = $this->abrir('2026-10-01');

        $this->assertSame([$this->ana->id, $this->beto->id], $this->quienes($planilla));
        $this->assertSame([], $this->avisos($planilla));
    }

    public function test_quien_se_fue_antes_de_que_empezara_la_quincena_sigue_fuera(): void
    {
        $this->baja($this->beto, '2026-09-30');

        $this->assertSame([$this->ana->id], $this->quienes($this->abrir('2026-10-01')));
    }

    public function test_quien_esta_inactivo_sin_fecha_de_baja_sigue_fuera(): void
    {
        $this->beto->forceFill(['activo' => false, 'baja_el' => null])->save();

        $this->assertSame([$this->ana->id], $this->quienes($this->abrir('2026-10-01')));
    }

    public function test_el_aguinaldo_sigue_siendo_solo_de_quien_esta_activo(): void
    {
        $this->baja($this->beto, '2026-12-05');

        $aguinaldo = app(PrepararPlanilla::class)->abrir($this->actor, [
            'tipo_periodo' => 'mensual', 'fecha' => '2026-12-15', 'clase' => 'aguinaldo', 'moneda' => 'USD', 'fecha_pago' => '2026-12-15',
        ]);

        $this->assertSame([$this->ana->id], $this->quienes($aguinaldo));
    }

    public function test_la_revision_avisa_y_generar_deja_el_borrador_para_revisar_el_importe(): void
    {
        $this->baja($this->beto, '2026-10-05');

        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->periodo('2026-10-01')))
            ->assertOk()
            ->assertSee('Beto Ficticio')
            ->assertSee('tiene baja el 05/10/2026: revisá el importe antes de confirmar')
            ->assertSee('Seguir a revisar la planilla')
            ->assertDontSee('Sí, generar la planilla');

        $respuesta = $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-10-01'));

        $planilla = Planilla::sole();
        $respuesta->assertRedirect(route('planilla.preparar', $planilla));
        $this->assertSame('borrador', $planilla->estado);
        $this->assertSame(0, Gasto::count());
    }

    public function test_en_preparar_se_corrige_el_importe_de_quien_se_fue_y_se_genera(): void
    {
        $this->baja($this->beto, '2026-10-05');
        $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-10-01'));
        $planilla = Planilla::sole();

        $this->actingAs($this->actor)->get(route('planilla.preparar', $planilla))
            ->assertOk()
            ->assertSee('Beto Ficticio')
            ->assertSee('tiene baja el 05/10/2026: revisá el importe antes de confirmar');

        // El importe lo escribe una persona: acá, 75.00 por los días que trabajó.
        $this->actingAs($this->actor)->put(route('planilla.guardar', $planilla), ['lineas' => [
            ['planilla_empleado_id' => $this->ana->id, 'salario' => '225.00'],
            ['planilla_empleado_id' => $this->beto->id, 'salario' => '75.00'],
        ]])->assertSessionHasNoErrors()->assertRedirect(route('planilla.impresos', $planilla));

        $this->assertSame('confirmada', $planilla->fresh()->estado);
        $this->assertSame('75.00', (string) PlanillaDetalle::where('planilla_id', $planilla->id)->where('planilla_empleado_id', $this->beto->id)->sole()->a_pagar);
        $this->assertSame(2, Gasto::count());
    }

    public function test_quien_se_fue_y_se_quito_del_borrador_se_puede_volver_a_agregar(): void
    {
        $this->baja($this->beto, '2026-10-05');
        $planilla = $this->abrir('2026-10-01');
        app(PrepararPlanilla::class)->guardarLineas($this->actor, $planilla, [
            ['planilla_empleado_id' => $this->ana->id, 'salario' => '225.00'],
        ]);

        $disponibles = $this->actingAs($this->actor)->get(route('planilla.preparar', $planilla))
            ->assertOk()->viewData('disponibles');

        $this->assertSame([$this->beto->id], $disponibles->pluck('id')->all());
    }
}
