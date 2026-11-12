<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\AnularPlanilla;
use App\Services\Planilla\ConfirmarPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El aguinaldo se paga una vez por año.
 *
 * El período de un aguinaldo es el MES de su fecha de pago, y se puede pagar de octubre a
 * diciembre: el índice único no frenaba un segundo aguinaldo en otro mes del mismo año.
 * Ahora quien ya está en un aguinaldo NO anulado de ese año queda fuera del siguiente,
 * como ya pasaba con las vacaciones, y la pantalla dice por qué. Datos ficticios.
 */
class AguinaldoUnaVezPorAnioTest extends TestCase
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
        config(['planilla.enabled' => true, 'gastos.enabled' => false]);

        $this->actor = User::factory()->create(['activo' => true]);
        $this->actor->syncPermissions(PermisoSistema::todos());
    }

    private function persona(string $nombre, string $dui, string $ingreso = '2020-01-01'): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->actor, [
            'nombres' => $nombre, 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => $dui,
            'con_descuentos_ley' => false, 'alta_el' => $ingreso, 'sueldo_mensual' => '408.80',
            // Vigente también para un aguinaldo de 2025.
            'sueldo_desde' => max($ingreso, '2025-06-01'), 'confirmar_bajo_minimo' => true,
        ]);
    }

    /** @return array<string, string> */
    private function aguinaldo(string $pago): array
    {
        return ['tipo_periodo' => 'mensual', 'fecha' => $pago, 'clase' => 'aguinaldo', 'moneda' => 'USD', 'fecha_pago' => $pago];
    }

    private function abrir(string $pago): Planilla
    {
        return app(PrepararPlanilla::class)->abrir($this->actor, $this->aguinaldo($pago));
    }

    public function test_quien_ya_cobro_el_aguinaldo_del_anio_no_entra_en_otro_y_la_revision_dice_por_que(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1');
        app(ConfirmarPlanilla::class)->confirmar($this->actor, $this->abrir('2026-11-30'));
        // Bea entra a la empresa después de ese pago: a ella sí le falta.
        $bea = $this->persona('Bea', 'FICTICIO-2', '2026-06-15');

        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->aguinaldo('2026-12-15')))
            ->assertOk()
            ->assertSee('Ana Ficticia queda fuera')
            ->assertSee('ya cobró el aguinaldo de 2026, en el pago del 30/11/2026');

        $segundo = $this->abrir('2026-12-15');

        $this->assertSame('2026-12', $segundo->periodo);
        $this->assertSame([$bea->id], PlanillaDetalle::where('planilla_id', $segundo->id)->pluck('planilla_empleado_id')->all());
        $this->assertSame(1, PlanillaDetalle::where('planilla_empleado_id', $ana->id)->count());
    }

    public function test_un_aguinaldo_todavia_en_borrador_tambien_cuenta(): void
    {
        $this->persona('Ana', 'FICTICIO-1');
        $this->abrir('2026-11-30');

        $segundo = $this->abrir('2026-12-15');

        $this->assertSame(0, PlanillaDetalle::where('planilla_id', $segundo->id)->count());
    }

    public function test_un_aguinaldo_anulado_no_cuenta(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1');
        $primero = app(ConfirmarPlanilla::class)->confirmar($this->actor, $this->abrir('2026-11-30'));
        app(AnularPlanilla::class)->anular($this->actor, $primero, 'Se pagó con la fecha equivocada.');

        $segundo = $this->abrir('2026-12-15');

        $detalle = PlanillaDetalle::where('planilla_id', $segundo->id)->sole();
        $this->assertSame($ana->id, (int) $detalle->planilla_empleado_id);
        // 408.80 × 19 ÷ 30 = 258.91: el mismo monto de siempre.
        $this->assertSame('258.91', (string) $detalle->a_pagar);
    }

    public function test_el_aguinaldo_de_otro_anio_no_cuenta(): void
    {
        config(['planilla_prestaciones.aguinaldo.2025' => config('planilla_prestaciones.aguinaldo.2026')]);
        $ana = $this->persona('Ana', 'FICTICIO-1');
        app(ConfirmarPlanilla::class)->confirmar($this->actor, $this->abrir('2025-12-15'));

        $deEsteAnio = $this->abrir('2026-12-15');

        $this->assertSame([$ana->id], PlanillaDetalle::where('planilla_id', $deEsteAnio->id)->pluck('planilla_empleado_id')->all());
    }

    public function test_agregar_a_mano_a_quien_ya_cobro_frena_la_confirmacion(): void
    {
        $ana = $this->persona('Ana', 'FICTICIO-1');
        app(ConfirmarPlanilla::class)->confirmar($this->actor, $this->abrir('2026-11-30'));
        $this->persona('Bea', 'FICTICIO-2', '2026-06-15');
        $segundo = $this->abrir('2026-12-15');

        $lineas = PlanillaDetalle::where('planilla_id', $segundo->id)->get()
            ->map(fn (PlanillaDetalle $d) => ['planilla_empleado_id' => $d->planilla_empleado_id, 'salario' => '0.00', 'conceptos' => [
                ['tipo' => 'ingreso', 'concepto' => 'Aguinaldo 2026', 'importe' => (string) $d->a_pagar],
            ]])->all();
        $lineas[] = ['planilla_empleado_id' => $ana->id, 'salario' => '0.00', 'conceptos' => [
            ['tipo' => 'ingreso', 'concepto' => 'Aguinaldo 2026', 'importe' => '258.91'],
        ]];
        app(PrepararPlanilla::class)->guardarLineas($this->actor, $segundo, $lineas);

        $reparos = implode(' ', app(PrepararPlanilla::class)->reparosParaConfirmar($segundo->fresh()->load('detalles.conceptos', 'detalles.empleado')));

        $this->assertStringContainsString('Ana Ficticia: ya cobró el aguinaldo de 2026', $reparos);
    }
}
