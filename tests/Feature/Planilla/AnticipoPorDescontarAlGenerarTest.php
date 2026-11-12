<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaAnticipo;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\RegistrarAnticipo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Un anticipo registrado tiene que poder descontarse.
 *
 * La planilla se genera y se confirma en un paso, sin descuentos: quien tenía un anticipo
 * cobraba la quincena entera y el anticipo quedaba «por recuperar» para siempre. Ahora, si
 * alguien de la planilla tiene un anticipo con saldo, la revisión dice quién y cuánto, y la
 * planilla queda en borrador para pasar por «Preparar», que es donde se elige el descuento.
 * Sin anticipos pendientes, todo sigue igual. Datos ficticios.
 */
class AnticipoPorDescontarAlGenerarTest extends TestCase
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

        // Sin descuentos de ley: 450.00 al mes son 225.00 por quincena, sin más cuentas.
        $this->ana = app(AltaEmpleado::class)->registrar($this->actor, [
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => false, 'alta_el' => '2026-01-01', 'sueldo_mensual' => '450.00',
            'sueldo_desde' => '2026-01-01', 'confirmar_bajo_minimo' => true,
        ]);
    }

    /** @return array<string, string> */
    private function periodo(string $fecha): array
    {
        return ['tipo_periodo' => 'quincenal', 'fecha' => $fecha, 'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => ''];
    }

    private function anticipo(string $importe = '50.00'): PlanillaAnticipo
    {
        return app(RegistrarAnticipo::class)->registrar($this->actor, $this->ana, [
            'fecha' => '2026-09-28', 'importe' => $importe, 'moneda' => 'USD', 'referencia' => 'recibo de prueba',
        ]);
    }

    public function test_la_revision_dice_quien_tiene_anticipo_y_cuanto_y_no_ofrece_generar_en_un_paso(): void
    {
        $this->anticipo();

        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->periodo('2026-10-01')))
            ->assertOk()
            ->assertSee('Ana Ficticia')
            ->assertSee('tiene un anticipo por descontar de USD 50.00')
            ->assertSee('Queda en borrador')
            ->assertSee('Seguir a revisar la planilla')
            ->assertDontSee('Sí, generar la planilla');

        $this->assertSame(0, Planilla::count());
    }

    public function test_generar_con_un_anticipo_con_saldo_deja_el_borrador_y_lleva_a_prepararlo(): void
    {
        $this->anticipo();

        $respuesta = $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-10-01'));

        $planilla = Planilla::sole();
        $respuesta->assertRedirect(route('planilla.preparar', $planilla))
            ->assertSessionHas('planilla.aviso', fn ($aviso) => str_contains($aviso, 'quedó en borrador'));
        $this->assertSame('borrador', $planilla->estado);
        $this->assertSame(0, Gasto::count(), 'Un borrador no debe nada todavía.');
    }

    public function test_en_preparar_el_anticipo_se_descuenta_y_baja_su_saldo(): void
    {
        $anticipo = $this->anticipo();
        $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-10-01'));
        $planilla = Planilla::sole();

        // La pantalla de preparar abre, avisa y ofrece ese anticipo.
        $this->actingAs($this->actor)->get(route('planilla.preparar', $planilla))
            ->assertOk()
            ->assertSee('tiene un anticipo por descontar de USD 50.00')
            ->assertSee('quedan 50.00');

        $this->actingAs($this->actor)->put(route('planilla.guardar', $planilla), ['lineas' => [[
            'planilla_empleado_id' => $this->ana->id, 'salario' => '225.00', 'conceptos' => [[
                'tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => '50.00',
                'destino' => 'anticipo', 'planilla_anticipo_id' => $anticipo->id,
            ]],
        ]]])->assertSessionHasNoErrors()->assertRedirect(route('planilla.impresos', $planilla));

        $this->assertSame('confirmada', $planilla->fresh()->estado);
        // 225.00 de la quincena menos los 50.00 que ya se le habían entregado.
        $this->assertSame('175.00', (string) PlanillaDetalle::where('planilla_id', $planilla->id)->sole()->a_pagar);
        $this->assertSame(0, $anticipo->fresh()->pendiente());
    }

    public function test_con_el_anticipo_ya_recuperado_la_quincena_siguiente_se_genera_en_un_paso(): void
    {
        $anticipo = $this->anticipo();
        $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-10-01'));
        $primera = Planilla::sole();
        $this->actingAs($this->actor)->put(route('planilla.guardar', $primera), ['lineas' => [[
            'planilla_empleado_id' => $this->ana->id, 'salario' => '225.00', 'conceptos' => [[
                'tipo' => 'descuento', 'concepto' => 'Anticipo', 'importe' => '50.00',
                'destino' => 'anticipo', 'planilla_anticipo_id' => $anticipo->id,
            ]],
        ]]])->assertSessionHasNoErrors();

        $this->actingAs($this->actor)->get(route('planilla.revisar', $this->periodo('2026-10-16')))
            ->assertOk()->assertSee('Sí, generar la planilla')->assertDontSee('anticipo por descontar');

        $respuesta = $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-10-16'));

        $segunda = Planilla::latest('id')->firstOrFail();
        $respuesta->assertRedirect(route('planilla.impresos', $segunda));
        $this->assertSame('confirmada', $segunda->estado);
        $this->assertSame('225.00', (string) PlanillaDetalle::where('planilla_id', $segunda->id)->sole()->a_pagar);
    }

    public function test_un_anticipo_en_otra_moneda_no_frena_porque_ahi_no_se_puede_descontar(): void
    {
        config(['gastos.monedas' => ['USD', 'EUR']]);
        app(RegistrarAnticipo::class)->registrar($this->actor, $this->ana, [
            'fecha' => '2026-09-28', 'importe' => '50.00', 'moneda' => 'EUR',
        ]);

        $respuesta = $this->actingAs($this->actor)->post(route('planilla.store'), $this->periodo('2026-10-01'));

        $planilla = Planilla::sole();
        $respuesta->assertRedirect(route('planilla.impresos', $planilla));
        $this->assertSame('confirmada', $planilla->estado);
    }

    public function test_sin_permiso_de_importes_no_queda_ni_el_borrador(): void
    {
        $this->anticipo();
        $sinImportes = User::factory()->create(['activo' => true]);
        $sinImportes->syncPermissions(array_values(array_diff(PermisoSistema::todos(), [PermisoSistema::PlanillaSalarios->value])));

        $this->actingAs($sinImportes)->post(route('planilla.store'), $this->periodo('2026-10-01'))->assertForbidden();

        $this->assertSame(0, Planilla::count());
    }
}
