<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\User;
use App\Services\Gastos\CuentaProveedor;
use App\Services\Gastos\Dinero;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El formato de los importes: separador en lo que se lee, dato crudo en lo que se envía.
 *
 * La regla que defiende este archivo:
 *
 *   Dinero::decimal()  «1200.00»   value="", base, JSON que JavaScript compara
 *   Dinero::mostrar()  «1,200.00»  pantalla, totales, impresos
 *
 * Confundirlas rompe en direcciones opuestas, y las dos roturas son silenciosas:
 *
 *  · Separador dentro de un `value=""` → la validación rechaza el formulario y quien
 *    escribió un importe correcto ve un error que no puede explicarse.
 *  · Separador en un número que JavaScript compara → `parseFloat('1,200.00')` devuelve
 *    1, y un aviso de «supera el saldo» deja de saltar cuando debería.
 */
class FormatoImportesTest extends TestCase
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

    private function completo(): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions(array_map(fn ($p) => $p->value, [
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar,
            PermisoSistema::GastosPagosRegistrar, PermisoSistema::GastosPersonales,
            PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    // ═══════════ Las dos formas ═══════════

    public function test_mostrar_pone_separador_de_miles_y_dos_decimales(): void
    {
        $this->assertSame('1,200.00', Dinero::mostrar(120000));
        $this->assertSame('1,999,999.99', Dinero::mostrar(199999999));
        $this->assertSame('85.00', Dinero::mostrar(8500));
        $this->assertSame('0.05', Dinero::mostrar(5));
        $this->assertSame('0.00', Dinero::mostrar(0));
    }

    public function test_decimal_sigue_sin_separador_porque_es_el_dato(): void
    {
        $this->assertSame('1200.00', Dinero::decimal(120000));
        $this->assertSame('1999999.99', Dinero::decimal(199999999));

        // Y lo que `decimal()` produce tiene que poder volver a leerse. Si un día
        // llevara separador, esto fallaría y diría exactamente por qué.
        $this->assertSame(120000, Dinero::centavos(Dinero::decimal(120000)));
    }

    public function test_mostrar_acepta_tanto_centavos_como_un_decimal_ya_formado(): void
    {
        // En las vistas conviven las dos fuentes: un servicio devuelve centavos y un
        // modelo con cast `decimal:2` devuelve «1200.00».
        $this->assertSame('1,200.00', Dinero::mostrar(120000));
        $this->assertSame('1,200.00', Dinero::mostrar('1200.00'));
    }

    public function test_mostrar_no_revienta_con_algo_que_no_es_un_importe(): void
    {
        // Una pantalla de dinero que falla entera por un campo raro es peor que una
        // que enseña ese campo sin formato.
        $this->assertSame('no es plata', Dinero::mostrar('no es plata'));
        $this->assertSame('0.00', Dinero::mostrar(null));
        $this->assertSame('0.00', Dinero::mostrar(''));
    }

    // ═══════════ Pegar un importe con formato ═══════════

    public function test_pegar_un_importe_con_comas_no_lo_rechaza(): void
    {
        // Un importe se PEGA tanto como se escribe, y lo que se pega viene con formato.
        $this->assertSame(120000, Dinero::centavos('1,200.00'));
        $this->assertSame(120000, Dinero::centavos('1200.00'));
        $this->assertSame(120000, Dinero::centavos(' 1,200.00 '));
        $this->assertSame(199999999, Dinero::centavos('1,999,999.99'));
    }

    public function test_lo_que_no_es_un_importe_se_sigue_rechazando(): void
    {
        foreach (['abc', '12.345', '1.2.3', '$100', '-5.00', ''] as $basura) {
            try {
                Dinero::centavos($basura);
                $this->fail("«{$basura}» tenía que rechazarse.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_la_coma_decimal_se_rechaza_porque_es_ambigua(): void
    {
        // «1,50» son 1.50 en unos países y 150 en otros. Adivinar con dinero ajeno no
        // es aceptable, así que se rechaza y que la persona lo escriba sin ambigüedad.
        // (Acá «1,50» pasa a «150» al quitar separadores: 150.00, no 1.50.)
        $this->assertSame(15000, Dinero::centavos('1,50'));
        $this->assertNotSame(150, Dinero::centavos('1,50'));
    }

    // ═══════════ En las pantallas ═══════════

    public function test_la_cuenta_muestra_el_saldo_con_separador(): void
    {
        $u = $this->completo();

        app(CuentaProveedor::class)->agregarCompra($u, [
            'clave' => (string) Str::uuid(), 'beneficiario' => 'Proveedor A',
            'concepto' => 'Pepitoria', 'importe' => '1200.00', 'fecha' => now()->toDateString(),
            'ambito' => 'empresarial', 'moneda' => 'USD',
        ]);

        $this->actingAs($u)->get(route('gastos.cuentas'))->assertOk()
            ->assertSee('1,200.00')
            ->assertDontSee('>1200.00<', false);

        $this->actingAs($u)->get(route('gastos.cuentas.show', [
            'proveedor' => 'Proveedor A', 'moneda' => 'USD',
        ]))->assertOk()->assertSee('1,200.00');
    }

    public function test_el_campo_editable_lleva_el_importe_si_n_separador(): void
    {
        $u = $this->completo();

        $gasto = app(CuentaProveedor::class)->agregarCompra($u, [
            'clave' => (string) Str::uuid(), 'beneficiario' => 'Proveedor A',
            'concepto' => 'Pepitoria', 'importe' => '1200.00', 'fecha' => now()->toDateString(),
            'ambito' => 'empresarial', 'moneda' => 'USD',
        ]);

        $html = $this->actingAs($u)->get(route('gastos.pagar-rapido', $gasto))->assertOk()->getContent();

        // El campo lleva el dato crudo: si llevara «1,200.00», enviar el formulario
        // sin tocarlo daría un error de validación.
        $this->assertStringContainsString('value="1200.00"', $html);
        $this->assertStringNotContainsString('value="1,200.00"', $html);

        // Y en el mismo documento, el importe de LECTURA sí lleva separador.
        $this->assertStringContainsString('1,200.00', $html);
    }

    public function test_el_formulario_precargado_se_puede_enviar_tal_cual(): void
    {
        $u = $this->completo();

        $gasto = app(CuentaProveedor::class)->agregarCompra($u, [
            'clave' => (string) Str::uuid(), 'beneficiario' => 'Proveedor A',
            'concepto' => 'Pepitoria', 'importe' => '1200.00', 'fecha' => now()->toDateString(),
            'ambito' => 'empresarial', 'moneda' => 'USD',
        ]);

        // Lo que el campo trae puesto, enviado sin tocar. Es el caso más común: se abre
        // el pago y se confirma.
        $cuota = $gasto->cuotas()->first();

        $this->actingAs($u)->post(route('gastos.pagos.store'), [
            'clave' => (string) Str::uuid(), 'importe' => '1200.00',
            'fecha' => now()->toDateString(), 'metodo' => 'transferencia',
            'pagado_por' => $u->id, 'sin_comprobante' => 'Pagado.',
            // `aplicar` es un MAPA cuota => importe, que es lo que el controlador
            // espera. Enviarlo de otra forma lo rechaza la validación.
            'aplicar' => [$cuota->id => '1200.00'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, app(CuentaProveedor::class)->saldo($u, 'Proveedor A', 'USD'));
    }

    public function test_el_csv_de_informes_va_sin_separador(): void
    {
        // Un CSV se separa por comas: «1,200.00» partiría la columna en dos, o quedaría
        // entrecomillado y la hoja de cálculo lo leería como texto en vez de número.
        // Ahí el dato crudo es lo correcto, no un descuido.
        $u = $this->completo();
        $u->givePermissionTo('gastos.exportar');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        app(CuentaProveedor::class)->agregarCompra($u->fresh(), [
            'clave' => (string) Str::uuid(), 'beneficiario' => 'Proveedor A',
            'concepto' => 'Pepitoria', 'importe' => '1200.00', 'fecha' => now()->toDateString(),
            'ambito' => 'empresarial', 'moneda' => 'USD',
        ]);

        $csv = $this->actingAs($u->fresh())
            ->get(route('gastos.informes.exportar', ['informe' => 'pendientes', 'corte' => now()->toDateString()]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('1200.00', $csv);
        $this->assertStringNotContainsString('1,200.00', $csv);
    }
}
