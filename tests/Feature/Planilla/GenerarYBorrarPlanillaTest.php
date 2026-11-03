<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Cuota;
use App\Models\Gastos\Gasto;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaConcepto;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaDocumento;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Planilla\PlanillaObligacionTercero;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\AnularPlanilla;
use App\Services\Planilla\PagarPlanilla;
use App\Services\Planilla\PrepararPlanilla;
use App\Services\Planilla\RegistrarAnticipo;
use App\Services\Planilla\RegistrarEmpleado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Una planilla se genera de una vez —calcula, confirma y lleva a la hoja para firmar— y
 * la última se puede borrar con todo lo que creó, como si nunca se hubiera generado.
 */
class GenerarYBorrarPlanillaTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private PlanillaEmpleado $ana;

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

        // Con descuentos: genera, además de su línea, lo que se le debe al ISSS y a la AFP.
        $this->ana = app(AltaEmpleado::class)->registrar($this->actor, [
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => true, 'alta_el' => '2026-01-01', 'sueldo_mensual' => '408.80',
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

    // ─────────────────────── Generar ───────────────────────

    public function test_revisar_dice_cuantas_personas_y_el_total_sin_crear_nada(): void
    {
        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->periodo('2026-10-01')))
            ->assertOk()
            ->assertSee('Se va a generar')
            ->assertSee('Ana Ficticia')
            ->assertSee('183.45')
            ->assertSee('Sí, generar la planilla');

        $this->assertSame(0, Planilla::count());
        $this->assertSame(0, Gasto::count());
    }

    public function test_generar_confirma_en_un_paso_y_lleva_a_la_hoja(): void
    {
        $respuesta = $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-10-01'));

        $planilla = Planilla::sole();
        $respuesta->assertRedirect(route('planilla.impresos', $planilla));
        $this->assertSame('confirmada', $planilla->estado);
        // Una deuda con Ana y una con cada tercero de ley.
        $this->assertSame(1, PlanillaDetalle::whereNotNull('gasto_id')->count());
        $this->assertSame(2, PlanillaObligacionTercero::count());

        $this->actingAs($this->actor)->get(route('planilla.impresos', $planilla))->assertOk()->assertSee('generada');
    }

    public function test_con_algo_que_resolver_no_se_ofrece_generar_y_no_queda_nada(): void
    {
        // Sin sueldo registrado: entra en cero, y una línea en cero frena la confirmación.
        app(RegistrarEmpleado::class)->registrar($this->actor, ['nombre' => 'Beto Sinsueldo', 'cargo' => 'Bodega']);

        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->periodo('2026-10-01')))
            ->assertOk()->assertSee('Antes de generar hay que resolver')->assertDontSee('Sí, generar la planilla');

        $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-10-01'))->assertSessionHasErrors('reparos');

        $this->assertSame(0, Planilla::count(), 'Ni siquiera queda un borrador.');
        $this->assertSame(0, Gasto::count());
    }

    public function test_un_periodo_ya_generado_no_se_genera_dos_veces(): void
    {
        $planilla = $this->generar('2026-10-01');

        $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-10-01'))->assertSessionHasErrors('periodo');
        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->periodo('2026-10-01')))->assertRedirect(route('planilla.show', $planilla));

        $this->assertSame(1, Planilla::count());
        $this->assertSame(3, Gasto::count());
    }

    public function test_un_borrador_de_antes_se_termina_generandolo(): void
    {
        $borrador = app(PrepararPlanilla::class)->abrir($this->actor, $this->periodo('2026-10-01'));

        $this->actingAs($this->actor)->get(route('planilla.show', $borrador))->assertOk()->assertSee('Terminar y generar');

        $this->actingAs($this->actor)->put(route('planilla.guardar', $borrador), ['lineas' => [
            ['planilla_empleado_id' => $this->ana->id, 'salario' => '204.40'],
        ]])->assertRedirect(route('planilla.impresos', $borrador));

        $this->assertSame('confirmada', $borrador->fresh()->estado);
    }

    // ─────────────────────── Borrar ───────────────────────

    public function test_borrar_la_ultima_deshace_todo_lo_que_creo(): void
    {
        $planilla = $this->generar('2026-10-01');
        $this->assertSame(1, DB::table('gastos_eventos')->where('accion', 'planilla_confirmada')->count());

        $this->actingAs($this->actor)->get(route('planilla.show', $planilla))->assertOk()->assertSee('Borrar la planilla');

        $this->actingAs($this->actor)->delete(route('planilla.destroy', $planilla))
            ->assertSessionHasNoErrors()->assertRedirect(route('planilla.index'));

        $this->assertSame(0, Planilla::count());
        $this->assertSame(0, PlanillaDetalle::count());
        $this->assertSame(0, PlanillaConcepto::count());
        $this->assertSame(0, PlanillaObligacionTercero::count());
        $this->assertSame(0, Gasto::count());
        $this->assertSame(0, Cuota::count());
        $this->assertSame(0, DB::table('gastos_eventos')->count());

        $constancia = Activity::where('description', 'borró la planilla')->sole();
        $this->assertSame($this->actor->id, $constancia->causer_id);
        $this->assertSame(3, $constancia->properties['gastos']);

        // Y el período queda libre para generarlo de nuevo.
        $this->generar('2026-10-01');
        $this->assertSame(1, Planilla::count());
    }

    public function test_no_se_borra_una_planilla_que_tiene_otra_despues(): void
    {
        $primera = $this->generar('2026-10-01');
        $segunda = $this->generar('2026-10-16');

        $this->actingAs($this->actor)->get(route('planilla.show', $primera))->assertOk()->assertDontSee('Borrar la planilla');

        $this->actingAs($this->actor)->delete(route('planilla.destroy', $primera))->assertSessionHasErrors('borrar');
        $this->assertNotNull($primera->fresh());

        // En orden, de la última hacia atrás, sí.
        $this->actingAs($this->actor)->delete(route('planilla.destroy', $segunda))->assertSessionHasNoErrors();
        $this->actingAs($this->actor)->delete(route('planilla.destroy', $primera))->assertSessionHasNoErrors();
        $this->assertSame(0, Planilla::count());
    }

    public function test_un_borrador_de_antes_se_borra_aunque_no_sea_el_ultimo(): void
    {
        $borrador = app(PrepararPlanilla::class)->abrir($this->actor, $this->periodo('2026-10-01'));
        $this->generar('2026-10-16');

        $this->actingAs($this->actor)->delete(route('planilla.destroy', $borrador))->assertSessionHasNoErrors();

        $this->assertNull($borrador->fresh());
        $this->assertSame(1, Planilla::count());
    }

    public function test_al_borrar_el_anticipo_vuelve_a_tener_su_saldo(): void
    {
        $anticipo = app(RegistrarAnticipo::class)->registrar($this->actor, $this->ana, [
            'fecha' => '2026-10-02', 'importe' => '50.00', 'moneda' => 'USD', 'referencia' => 'vale 1',
        ]);

        $borrador = app(PrepararPlanilla::class)->abrir($this->actor, $this->periodo('2026-10-01'));
        $this->actingAs($this->actor)->put(route('planilla.guardar', $borrador), ['lineas' => [
            ['planilla_empleado_id' => $this->ana->id, 'salario' => '204.40', 'conceptos' => [
                ['tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => '30.00', 'destino' => 'anticipo', 'planilla_anticipo_id' => $anticipo->id],
            ]],
        ]])->assertSessionHasNoErrors();

        $this->assertSame(2000, $anticipo->fresh()->pendiente());

        $this->actingAs($this->actor)->delete(route('planilla.destroy', $borrador))->assertSessionHasNoErrors();

        $this->assertSame(5000, $anticipo->fresh()->pendiente());
        $this->assertSame(0, DB::table('planilla_anticipo_aplicaciones')->count());
    }

    public function test_una_anulada_que_es_la_ultima_se_borra_con_su_ajuste(): void
    {
        $planilla = $this->generar('2026-10-01');
        app(AnularPlanilla::class)->anular($this->actor, $planilla, 'Se generó con el período equivocado');
        $this->assertGreaterThan(0, DB::table('gastos_ajustes')->count());

        $this->actingAs($this->actor)->delete(route('planilla.destroy', $planilla))->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('gastos_ajustes')->count());
        $this->assertSame(0, Gasto::count());
        $this->assertSame(0, DB::table('gastos_eventos')->count());
    }

    public function test_con_un_pago_registrado_no_se_borra_y_se_dice_por_que(): void
    {
        $planilla = $this->generar('2026-10-01');

        app(PagarPlanilla::class)->pagarPersona($this->actor, PlanillaDetalle::sole(), [
            'importe' => '50.00', 'fecha' => '2026-10-16', 'metodo' => 'efectivo',
            'pagado_por' => $this->actor->id, 'sin_comprobante' => 'Abono en caja.',
        ]);

        $this->actingAs($this->actor)->get(route('planilla.show', $planilla))->assertOk()->assertSee('Tiene pagos registrados');
        $this->actingAs($this->actor)->delete(route('planilla.destroy', $planilla))->assertSessionHasErrors('borrar');

        $this->assertNotNull($planilla->fresh());
        $this->assertSame(3, Gasto::count());
    }

    public function test_con_un_documento_firmado_subido_no_se_borra(): void
    {
        $planilla = $this->generar('2026-10-01');

        PlanillaDocumento::create([
            'planilla_id' => $planilla->id, 'tipo' => 'hoja_firmada', 'ruta' => 'planilla/firmados/ficticio.pdf',
            'nombre' => 'hoja.pdf', 'mime' => 'application/pdf', 'bytes' => 10, 'sha256' => str_repeat('a', 64),
            'registrado_por' => $this->actor->id, 'created_at' => now(),
        ]);

        $this->actingAs($this->actor)->delete(route('planilla.destroy', $planilla))
            ->assertSessionHasErrors(['borrar' => 'Tiene documentos firmados subidos. Para dejarla sin efecto, anulala.']);

        $this->assertNotNull($planilla->fresh());
    }

    public function test_borrar_exige_gestionar_la_planilla(): void
    {
        $planilla = $this->generar('2026-10-01');

        $mirón = User::factory()->create(['activo' => true]);
        $mirón->syncPermissions([PermisoSistema::PlanillaVer->value, PermisoSistema::PlanillaSalarios->value]);

        $this->actingAs($mirón)->get(route('planilla.show', $planilla))->assertOk()->assertDontSee('Borrar la planilla');
        $this->actingAs($mirón)->delete(route('planilla.destroy', $planilla))->assertForbidden();

        $this->assertNotNull($planilla->fresh());
    }
}
