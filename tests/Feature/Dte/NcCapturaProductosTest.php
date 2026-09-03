<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoNotaCredito;
use App\Exceptions\Dte\SaldoAcreditableExcedidoException;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * CAPTURA de productos de la nota de crédito, unificada con la del CCF.
 *
 * Lo que se protege acá es la diferencia con lo anterior: escribir una cantidad la FIJA
 * en vez de sumarla, 0 la retira, y corregir a la baja no choca contra el saldo. Antes
 * solo existía «acreditar», que sumaba: dos envíos de 3 dejaban 6 y para arreglarlo había
 * que borrar la línea a mano.
 *
 * Nada de esto emite, firma ni transmite, y ninguna regla fiscal cambia: los totales los
 * sigue calculando el mismo motor.
 */
class NcCapturaProductosTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        foreach (['03', '05'] as $t) {
            Correlativo::create([
                'tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
            ]);
        }

        return compact('estab', 'pv');
    }

    private function producto(float $precio = 10): Producto
    {
        return Producto::factory()->create([
            'precio_unitario' => $precio,
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
    }

    /** CCF aceptado con UNA línea de 10 unidades. */
    private function ccfAceptado(array $emisor, Producto $producto, int $cantidad = 10): Dte
    {
        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);
        $this->borradores->agregarLineaDesdeProducto($ccf, $producto, cantidad: $cantidad);
        app(DteGeneracionService::class)->generar($ccf);

        return $this->aceptarCcf($ccf);
    }

    private function nc(Dte $ccf, TipoNotaCredito $tipo): Dte
    {
        return $this->borradores->crearNotaCredito($ccf, [
            'tipo' => $tipo->value,
            'origen_averia' => 'entrega', // solo lo consume la avería
        ], $this->usuario());
    }

    private function cantidadAcreditada(Dte $nc, DteLinea $original): ?string
    {
        $linea = $nc->lineas()->where('dte_linea_original_id', $original->id)->first();

        return $linea === null ? null : (string) $linea->cantidad;
    }

    // ---------------------------------------------------------- fijar cantidad

    public function test_escribir_la_cantidad_la_fija_en_vez_de_sumarla(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfAceptado($emisor, $this->producto());
        $original = $ccf->lineas()->firstOrFail();
        $nc = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);

        $this->borradores->establecerCantidadAcreditada($nc, $original, 3);
        $this->assertSame('3.0000', $this->cantidadAcreditada($nc->refresh(), $original));

        // La misma cantidad otra vez NO la duplica: sigue en 3, no pasa a 6.
        $this->borradores->establecerCantidadAcreditada($nc, $original, 3);
        $this->assertSame('3.0000', $this->cantidadAcreditada($nc->refresh(), $original));
        $this->assertSame(1, $nc->lineas()->count(), 'No debe crearse una segunda línea para la misma línea original.');
    }

    public function test_se_puede_corregir_a_la_baja_y_al_alza_sin_chocar_con_el_saldo(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfAceptado($emisor, $this->producto());
        $original = $ccf->lineas()->firstOrFail();
        $nc = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);

        // Tomar TODO el saldo y después subir/bajar: el saldo propio no puede contarse
        // contra uno mismo (era el caso que antes obligaba a borrar la línea).
        $this->borradores->establecerCantidadAcreditada($nc, $original, 10);
        $this->assertSame('10.0000', $this->cantidadAcreditada($nc->refresh(), $original));

        $this->borradores->establecerCantidadAcreditada($nc, $original, 4);
        $this->assertSame('4.0000', $this->cantidadAcreditada($nc->refresh(), $original));

        $this->borradores->establecerCantidadAcreditada($nc, $original, 9);
        $this->assertSame('9.0000', $this->cantidadAcreditada($nc->refresh(), $original));
    }

    public function test_cero_o_vacio_retira_la_linea(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfAceptado($emisor, $this->producto());
        $original = $ccf->lineas()->firstOrFail();
        $nc = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);

        $this->borradores->establecerCantidadAcreditada($nc, $original, 5);
        $this->assertNotNull($this->cantidadAcreditada($nc->refresh(), $original));

        $r = $this->borradores->establecerCantidadAcreditada($nc, $original, 0);
        $this->assertSame('eliminada', $r['accion']);
        $this->assertNull($this->cantidadAcreditada($nc->refresh(), $original));

        // Y con la línea ya fuera, volver a mandar 0 no rompe ni inventa nada.
        $this->assertSame('sin_cambio', $this->borradores->establecerCantidadAcreditada($nc, $original, null)['accion']);
    }

    public function test_no_deja_acreditar_mas_que_el_saldo_y_conserva_la_linea_previa(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfAceptado($emisor, $this->producto());
        $original = $ccf->lineas()->firstOrFail();
        $nc = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);

        $this->borradores->establecerCantidadAcreditada($nc, $original, 4);

        try {
            $this->borradores->establecerCantidadAcreditada($nc, $original, 11); // original: 10
            $this->fail('Debía rechazarse por saldo insuficiente.');
        } catch (SaldoAcreditableExcedidoException) {
            // esperado
        }

        // La transacción no puede dejar la nota a medias: los 4 anteriores siguen ahí.
        $this->assertSame('4.0000', $this->cantidadAcreditada($nc->refresh(), $original));
    }

    public function test_el_saldo_descuenta_lo_acreditado_por_otra_nota(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfAceptado($emisor, $this->producto());
        $original = $ccf->lineas()->firstOrFail();

        $primera = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);
        $this->borradores->establecerCantidadAcreditada($primera, $original, 7);

        $segunda = $this->nc($ccf, TipoNotaCredito::FaltanteEntrega);
        $this->borradores->establecerCantidadAcreditada($segunda, $original, 3); // 7 + 3 = 10, justo

        $this->expectException(SaldoAcreditableExcedidoException::class);
        $this->borradores->establecerCantidadAcreditada($segunda, $original, 4); // 7 + 4 = 11
    }

    public function test_una_nota_por_monto_no_acredita_lineas_del_original(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfAceptado($emisor, $this->producto());
        $original = $ccf->lineas()->firstOrFail();
        $nc = $this->nc($ccf, TipoNotaCredito::ProntoPago);

        $this->expectException(ValidationException::class);
        $this->borradores->establecerCantidadAcreditada($nc, $original, 1);
    }

    // ---------------------------------------------------------- endpoint HTTP

    public function test_el_endpoint_fija_la_cantidad_y_devuelve_el_resumen(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfAceptado($emisor, $this->producto());
        $original = $ccf->lineas()->firstOrFail();
        $nc = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);

        $r = $this->actingAs($this->usuario())
            ->postJson(route('facturacion.acreditar.cantidad', [$nc, $original]), ['cantidad' => 6])
            ->assertOk()->assertJsonPath('ok', true);

        $this->assertSame('6.0000', $this->cantidadAcreditada($nc->refresh(), $original));
        // El panel que vuelve es el de la NOTA DE CRÉDITO, no el del CCF.
        $this->assertStringContainsString('Generar nota de crédito', $r->json('resumen_html'));
        // Y trae el mapa por línea original que sincroniza los inputs de la tabla.
        $this->assertSame('6', $r->json('acreditadas.'.$original->id));
    }

    public function test_el_endpoint_exige_permiso_de_gestion(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfAceptado($emisor, $this->producto());
        $original = $ccf->lineas()->firstOrFail();
        $nc = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);

        $this->actingAs($this->usuario('jefatura'))
            ->post(route('facturacion.acreditar.cantidad', [$nc, $original]), ['cantidad' => 1])
            ->assertForbidden();

        $this->assertNull($this->cantidadAcreditada($nc->refresh(), $original));
    }

    public function test_el_endpoint_rechaza_una_linea_de_otro_documento(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfAceptado($emisor, $this->producto());
        $ajeno = $this->ccfAceptado($emisor, $this->producto());
        $nc = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.acreditar.cantidad', [$nc, $ajeno->lineas()->firstOrFail()]), ['cantidad' => 1])
            ->assertNotFound();
    }

    /**
     * Un documento ACEPTADO es inmutable: la captura no puede tocarlo por ninguna puerta,
     * ni siquiera por la nueva.
     */
    public function test_una_nota_ya_aceptada_no_admite_captura(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfAceptado($emisor, $this->producto());
        $original = $ccf->lineas()->firstOrFail();
        $nc = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);
        $this->borradores->establecerCantidadAcreditada($nc, $original, 2);

        app(DteGeneracionService::class)->generar($nc);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.acreditar.cantidad', [$nc, $original]), ['cantidad' => 5])
            ->assertForbidden();

        $this->assertSame('2.0000', $this->cantidadAcreditada($nc->refresh(), $original));
    }

    // ---------------------------------------------------------- avería por catálogo

    public function test_la_averia_usa_el_mismo_endpoint_de_cantidad_que_el_ccf(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfAceptado($emisor, $this->producto());
        $nc = $this->nc($ccf, TipoNotaCredito::Averia);
        $otro = $this->producto(4.50);

        // Agregar un producto que NO está en el CCF: la avería no se limita al original.
        $this->actingAs($this->usuario())
            ->postJson(route('facturacion.productos.cantidad', [$nc, $otro]), ['cantidad' => 3])
            ->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(3, (int) $nc->refresh()->lineas()->firstOrFail()->cantidad);

        // Cambiar la cantidad NO duplica la línea.
        $this->actingAs($this->usuario())
            ->postJson(route('facturacion.productos.cantidad', [$nc, $otro]), ['cantidad' => 5])
            ->assertOk();
        $this->assertSame(1, $nc->refresh()->lineas()->count());
        $this->assertSame(5, (int) $nc->lineas()->firstOrFail()->cantidad);

        // 0 la retira.
        $this->actingAs($this->usuario())
            ->postJson(route('facturacion.productos.cantidad', [$nc, $otro]), ['cantidad' => 0])
            ->assertOk();
        $this->assertSame(0, $nc->refresh()->lineas()->count());
    }

    public function test_una_devolucion_no_admite_productos_libres_del_catalogo(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfAceptado($emisor, $this->producto());
        $nc = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);
        $otro = $this->producto(4.50);

        $this->actingAs($this->usuario())
            ->postJson(route('facturacion.productos.cantidad', [$nc, $otro]), ['cantidad' => 1])
            ->assertStatus(422);

        $this->assertSame(0, $nc->refresh()->lineas()->count());
    }

    // ---------------------------------------------------------- pantalla

    public function test_la_pantalla_muestra_la_captura_y_el_resumen_de_cada_modalidad(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfAceptado($emisor, $this->producto());
        $usuario = $this->usuario();

        // Devolución: tabla de líneas del CCF con su saldo.
        $devolucion = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);
        $this->actingAs($usuario)->get(route('facturacion.edit', $devolucion))->assertOk()
            ->assertSee('Líneas del CCF original')
            ->assertSee('Disponible')
            ->assertSee('Generar nota de crédito')
            ->assertSee('Devolución o faltante de entrega');

        // Avería: catálogo libre + su origen operativo en la cabecera.
        $averia = $this->nc($ccf, TipoNotaCredito::Averia);
        $this->actingAs($usuario)->get(route('facturacion.edit', $averia))->assertOk()
            ->assertSee('Productos disponibles')
            ->assertSee('Durante una entrega')
            ->assertSee('Generar nota de crédito');

        // Pronto pago: conceptos por monto + referencia del CCF.
        $prontoPago = $this->nc($ccf, TipoNotaCredito::ProntoPago);
        $this->actingAs($usuario)->get(route('facturacion.edit', $prontoPago))->assertOk()
            ->assertSee('Agregar concepto')
            ->assertSee('Total del CCF')
            ->assertSee('Generar nota de crédito');
    }

    /** El resumen fiscal del panel refleja el recálculo, no un valor de pantalla. */
    public function test_el_resumen_fiscal_se_actualiza_al_capturar(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfAceptado($emisor, $this->producto(10));
        $original = $ccf->lineas()->firstOrFail();
        $nc = $this->nc($ccf, TipoNotaCredito::DevolucionProducto);

        $this->assertSame('0.00', $nc->total_pagar);

        $this->borradores->establecerCantidadAcreditada($nc, $original, 5);
        $nc->refresh();

        // 5 × 10 = 50 gravado + 13 % de IVA. El motor es el mismo de siempre.
        $this->assertSame('50.00', $nc->total_gravado);
        $this->assertSame('6.50', $nc->iva);
        $this->assertSame('56.50', $nc->total_pagar);

        $this->actingAs($this->usuario())->get(route('facturacion.edit', $nc))
            ->assertOk()->assertSee('56.50');
    }
}
