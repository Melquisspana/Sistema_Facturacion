<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\User;
use App\Services\Gastos\CuentaProveedor;
use App\Services\Gastos\SaldosGastos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Los tres flujos recorridos POR HTTP, como los recorre una persona.
 *
 * Las pruebas de servicio de al lado comprueban la lógica. Estas comprueban el camino
 * entero: que la pantalla abra, que el formulario llegue con los nombres que el
 * controlador espera, que el permiso frene a quien no debe pasar y que reenviar no
 * duplique. Un servicio correcto detrás de una ruta mal cableada no sirve de nada.
 */
class PantallasSimplesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config()->set('gastos.enabled', true);
    }

    /** @param  array<int, PermisoSistema>  $permisos */
    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions(array_map(fn (PermisoSistema $p) => $p->value, $permisos));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function completo(): User
    {
        return $this->usuario([
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar,
            PermisoSistema::GastosPagosRegistrar, PermisoSistema::GastosPersonales,
            PermisoSistema::GastosRecurrencias, PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]);
    }

    // ═══════════ Compré y pagué ═══════════

    public function test_la_pantalla_de_compre_y_pague_abre_con_las_dos_opciones_de_ambito(): void
    {
        $r = $this->actingAs($this->completo())->get(route('gastos.compre-y-pague'))->assertOk();

        $r->assertSee('Compré y pagué', false);
        $r->assertSee('¿Qué compraste?', false);
        // Las dos opciones a la vista, no una suposición.
        $r->assertSee('¿Para quién es?', false);
        $r->assertSee('De la empresa');
        $r->assertSee('Personal o de la casa');
        // Lo que NO debe pedir.
        $r->assertDontSee('Vencimiento</label>', false);
        $r->assertDontSee('naturaleza', false);
    }

    public function test_quien_no_alcanza_lo_personal_no_ve_esa_opcion(): void
    {
        $limitado = $this->usuario([
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar,
            PermisoSistema::GastosPagosRegistrar, PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]);

        $r = $this->actingAs($limitado)->get(route('gastos.compre-y-pague'))->assertOk();

        $r->assertSee('De la empresa');
        $r->assertDontSee('Personal o de la casa');
    }

    public function test_guardar_una_compra_al_contado_por_http(): void
    {
        $u = $this->completo();

        $this->actingAs($u)->post(route('gastos.compre-y-pague.store'), [
            'clave' => (string) Str::uuid(), 'concepto' => 'Bolsas', 'importe' => '85.00',
            'fecha' => now()->toDateString(), 'metodo' => 'efectivo',
            'ambito' => 'empresarial', 'moneda' => 'USD',
        ])->assertRedirect();

        $gasto = Gasto::firstOrFail();
        $this->assertSame('Bolsas', $gasto->concepto);
        $this->assertSame(1, Pago::count());
        $this->assertSame(0, app(SaldosGastos::class)->pendienteCuota($gasto->cuotas()->first()));
    }

    public function test_reenviar_el_formulario_no_registra_dos_compras(): void
    {
        $u = $this->completo();
        $datos = [
            'clave' => (string) Str::uuid(), 'concepto' => 'Bolsas', 'importe' => '85.00',
            'fecha' => now()->toDateString(), 'metodo' => 'efectivo',
            'ambito' => 'empresarial', 'moneda' => 'USD',
        ];

        $this->actingAs($u)->post(route('gastos.compre-y-pague.store'), $datos)->assertRedirect();
        $this->actingAs($u)->post(route('gastos.compre-y-pague.store'), $datos)->assertRedirect();

        $this->assertSame(1, Gasto::count());
        $this->assertSame(1, Pago::count());
    }

    public function test_sin_permiso_de_pagos_la_ruta_esta_cerrada(): void
    {
        $soloGastos = $this->usuario([
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar,
            PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]);

        $this->actingAs($soloGastos)->get(route('gastos.compre-y-pague'))->assertForbidden();
        $this->actingAs($soloGastos)->post(route('gastos.compre-y-pague.store'), [
            'clave' => (string) Str::uuid(), 'concepto' => 'Bolsas', 'importe' => '85.00',
            'fecha' => now()->toDateString(), 'metodo' => 'efectivo',
            'ambito' => 'empresarial', 'moneda' => 'USD',
        ])->assertForbidden();

        $this->assertSame(0, Gasto::count());
    }

    // ═══════════ Cuentas de proveedores ═══════════

    private function compraDeProveedorA(User $u, string $concepto, string $importe): Gasto
    {
        return app(CuentaProveedor::class)->agregarCompra($u, [
            'clave' => (string) Str::uuid(), 'beneficiario' => 'Proveedor A',
            'concepto' => $concepto, 'importe' => $importe, 'fecha' => now()->toDateString(),
            'ambito' => 'empresarial', 'moneda' => 'USD',
        ]);
    }

    public function test_la_lista_de_cuentas_muestra_el_saldo(): void
    {
        $u = $this->completo();
        $this->compraDeProveedorA($u, 'Pepitoria', '1200.00');

        $this->actingAs($u)->get(route('gastos.cuentas'))->assertOk()
            ->assertSee('Proveedor A')
            // Con separador: en pantalla los importes se leen, y «1,200.00» se lee de
            // un vistazo mientras «1200.00» hay que contarlo.
            ->assertSee('1,200.00');
    }

    public function test_la_cuenta_abre_con_sus_dos_acciones(): void
    {
        $u = $this->completo();
        $this->compraDeProveedorA($u, 'Pepitoria', '1200.00');

        $r = $this->actingAs($u)->get(route('gastos.cuentas.show', [
            'proveedor' => 'Proveedor A', 'moneda' => 'USD',
        ]))->assertOk();

        $r->assertSee('Agregar compra', false);
        $r->assertSee('Registrar abono', false);
        $r->assertSee('Le debemos');
        $r->assertSee('Pepitoria');
    }

    public function test_agregar_compra_y_abonar_por_http_mueven_el_saldo(): void
    {
        $u = $this->completo();

        $this->actingAs($u)->post(route('gastos.cuentas.compras'), [
            'clave' => (string) Str::uuid(), 'beneficiario' => 'Proveedor A',
            'concepto' => 'Pepitoria', 'importe' => '1200.00', 'fecha' => now()->toDateString(),
            'ambito' => 'empresarial', 'moneda' => 'USD',
        ])->assertRedirect();

        $cuenta = app(CuentaProveedor::class);
        $this->assertSame(120000, $cuenta->saldo($u, 'Proveedor A', 'USD'));

        $this->actingAs($u)->post(route('gastos.cuentas.abonos'), [
            'clave' => (string) Str::uuid(), 'beneficiario' => 'Proveedor A',
            'importe' => '400.00', 'fecha' => now()->toDateString(),
            'metodo' => 'transferencia', 'moneda' => 'USD',
        ])->assertRedirect();

        $this->assertSame(80000, $cuenta->saldo($u, 'Proveedor A', 'USD'));
    }

    public function test_el_abono_dirigido_por_http_respeta_lo_que_se_eligio(): void
    {
        $u = $this->completo();
        $vieja = $this->compraDeProveedorA($u, 'Pepitoria', '300.00');
        $nueva = $this->compraDeProveedorA($u, 'Maní', '500.00');

        $this->actingAs($u)->post(route('gastos.cuentas.abonos'), [
            'clave' => (string) Str::uuid(), 'beneficiario' => 'Proveedor A',
            'importe' => '400.00', 'fecha' => now()->toDateString(),
            'metodo' => 'transferencia', 'moneda' => 'USD',
            // La casilla «elegir yo a qué compra va».
            'dirigido' => '1',
            'reparto' => [
                ['cuota_id' => $nueva->cuotas()->first()->id, 'importe' => '400.00'],
            ],
        ])->assertRedirect();

        $saldos = app(SaldosGastos::class);
        $this->assertSame(30000, $saldos->pendienteCuota($vieja->cuotas()->first()), 'La vieja no se tocó.');
        $this->assertSame(10000, $saldos->pendienteCuota($nueva->cuotas()->first()));
    }

    public function test_sin_permiso_de_pagos_no_se_puede_abonar_por_la_ruta(): void
    {
        $u = $this->completo();
        $this->compraDeProveedorA($u, 'Pepitoria', '300.00');

        $sinPagos = $this->usuario([
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar,
            PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]);

        $this->actingAs($sinPagos)->post(route('gastos.cuentas.abonos'), [
            'clave' => (string) Str::uuid(), 'beneficiario' => 'Proveedor A',
            'importe' => '100.00', 'fecha' => now()->toDateString(),
            'metodo' => 'transferencia', 'moneda' => 'USD',
        ])->assertForbidden();

        $this->assertSame(0, Pago::count());
    }

    // ═══════════ Pago rápido ═══════════

    public function test_el_pago_rapido_llega_con_el_importe_precargado(): void
    {
        $u = $this->completo();
        $gasto = $this->compraDeProveedorA($u, 'Pepitoria', '48.00');

        $r = $this->actingAs($u)->get(route('gastos.pagar-rapido', $gasto))->assertOk();

        $r->assertSee('Registrar pago');
        $r->assertSee('Proveedor A');
        // El importe pendiente, ya puesto en el campo.
        $r->assertSee('value="48.00"', false);
        // Lo que ya no pregunta.
        $r->assertSee('No se vuelve a preguntar', false);
    }

    /**
     * El formulario de pago rápido, ENVIADO tal como lo manda el navegador.
     *
     * Abrir la pantalla no prueba nada sobre si funciona: los campos pueden llamarse
     * distinto de lo que el controlador espera y la página se ve perfecta igual. Eso
     * pasó acá —los campos iban como `aplicaciones[][cuota_id]` y el controlador
     * espera `aplicar[cuota_id]`—, y no lo detectó nada hasta que una prueba envió
     * el formulario de verdad.
     */
    public function test_el_formulario_de_pago_rapido_se_envia_y_salda(): void
    {
        $u = $this->completo();
        $gasto = $this->compraDeProveedorA($u, 'Pepitoria', '48.00');
        $cuota = $gasto->cuotas()->first();

        // Se extraen del HTML los nombres REALES de los campos, para enviar lo mismo
        // que enviaría el navegador y no lo que uno cree recordar.
        $html = $this->actingAs($u)->get(route('gastos.pagar-rapido', $gasto))->assertOk()->getContent();
        $this->assertStringContainsString('name="aplicar['.$cuota->id.']"', $html);

        $this->actingAs($u)->post(route('gastos.pagos.store'), [
            'clave' => (string) Str::uuid(), 'importe' => '48.00',
            'fecha' => now()->toDateString(), 'metodo' => 'transferencia',
            'pagado_por' => $u->id, 'sin_comprobante' => 'Pagado.',
            'aplicar' => [$cuota->id => '48.00'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, app(SaldosGastos::class)->pendienteCuota($cuota->fresh()));
    }

    public function test_el_pago_rapido_de_un_gasto_saldado_no_existe(): void
    {
        $u = $this->completo();
        $gasto = $this->compraDeProveedorA($u, 'Pepitoria', '48.00');

        app(CuentaProveedor::class)->abonar($u, [
            'clave' => (string) Str::uuid(), 'beneficiario' => 'Proveedor A',
            'importe' => '48.00', 'fecha' => now()->toDateString(),
            'metodo' => 'efectivo', 'moneda' => 'USD',
        ]);

        // Sin nada que pagar, la pantalla no tiene sentido.
        $this->actingAs($u)->get(route('gastos.pagar-rapido', $gasto))->assertNotFound();
    }

    public function test_el_menu_ofrece_las_cuentas_de_proveedores(): void
    {
        $r = $this->actingAs($this->completo())->get(route('gastos.index'))->assertOk();

        $r->assertSee(route('gastos.cuentas', [], false), false);
        $r->assertSee('Cuentas de proveedores');
    }
}
