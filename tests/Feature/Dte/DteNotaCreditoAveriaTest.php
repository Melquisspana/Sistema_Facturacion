<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\ProductoPrecioCliente;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Modalidad "Avería" de la NC: acredita CUALQUIER producto del catálogo (no se
 * limita al CCF original ni valida saldo). Devolución/faltante siguen limitadas
 * a las líneas del CCF original.
 */
class DteNotaCreditoAveriaTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        foreach (['03', '05'] as $t) {
            Correlativo::create(['tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        }

        return compact('estab', 'pv');
    }

    private function producto(float $precio = 10): Producto
    {
        return Producto::factory()->create(['precio_unitario' => $precio, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
    }

    private function ccfGenerado(array $emisor, ?Cliente $cliente, Producto $producto): Dte
    {
        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente ?? Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 5);
        app(DteGeneracionService::class)->generar($dte);

        // La NC solo se crea desde un CCF ACEPTADO por Hacienda (regla de negocio).
        return $this->aceptarCcf($dte);
    }

    /**
     * NC por avería SIEMPRE desde un CCF ACEPTADO relacionado (regla obligatoria). La avería
     * hereda el cliente del CCF y luego se le agregan productos manuales del catálogo.
     */
    private function ncAveria(array $emisor, Cliente $cliente): Dte
    {
        $ccf = $this->ccfGenerado($emisor, $cliente, $this->producto());

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.nota-credito.store', $ccf), [
                'tipo' => 'averia',
                'motivo' => 'Producto averiado',
            ])->assertRedirect();

        return Dte::where('tipo_dte', '05')->where('tipo_nota_credito', 'averia')->firstOrFail();
    }

    // --- Creación ---

    public function test_averia_se_puede_crear_con_ccf_relacionado(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfGenerado($emisor, null, $this->producto());

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.nota-credito.store', $ccf), ['tipo' => 'averia', 'motivo' => 'Avería'])
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', [
            'tipo_dte' => '05', 'tipo_nota_credito' => 'averia',
            'dte_relacionado_id' => $ccf->id, 'cliente_id' => $ccf->cliente_id,
        ]);
    }

    public function test_averia_sin_ccf_relacionado_falla(): void
    {
        // Regla obligatoria: TODA NC (incluida avería) requiere un CCF aceptado relacionado.
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-nota-credito'), [
                'tipo' => 'averia',
                'cliente_id' => $cliente->id,
                'establecimiento_id' => $emisor['estab']->id,
                'punto_venta_id' => $emisor['pv']->id,
                'motivo' => 'Producto averiado',
            ])->assertSessionHasErrors('dte_relacionado_id');

        $this->assertDatabaseMissing('dtes', ['tipo_dte' => '05', 'tipo_nota_credito' => 'averia']);
    }

    public function test_averia_muestra_catalogo_de_productos(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $this->producto()->update(['nombre' => 'CANILLITAS']);
        $nc = $this->ncAveria($emisor, $cliente);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.edit', $nc))
            ->assertOk()
            ->assertSee('Productos para nota de crédito por avería')
            ->assertSee('CANILLITAS')
            ->assertDontSee('Líneas del documento original');
    }

    // --- Producto libre (no del CCF) ---

    public function test_averia_permite_agregar_producto_que_no_esta_en_el_ccf(): void
    {
        $emisor = $this->emisor();
        $enElCcf = $this->producto(10);
        $cliente = Cliente::factory()->contribuyente()->create();
        $ccf = $this->ccfGenerado($emisor, $cliente, $enElCcf);

        // NC avería desde el CCF (hereda cliente), pero se agrega OTRO producto.
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.nota-credito.store', $ccf), ['tipo' => 'averia'])
            ->assertRedirect();
        $nc = Dte::where('tipo_dte', '05')->where('tipo_nota_credito', 'averia')->firstOrFail();

        $otro = $this->producto(7); // NO está en el CCF
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.averia.store', $nc), ['producto_id' => $otro->id, 'cantidad' => 2])
            ->assertRedirect();

        $linea = $nc->refresh()->lineas->first();
        $this->assertNotNull($linea);
        $this->assertSame($otro->id, $linea->producto_id);
        $this->assertNull($linea->dte_linea_original_id); // no acredita línea del original
    }

    public function test_averia_no_valida_saldo_del_ccf_original(): void
    {
        $emisor = $this->emisor();
        $producto = $this->producto(10);
        $cliente = Cliente::factory()->contribuyente()->create();
        $ccf = $this->ccfGenerado($emisor, $cliente, $producto); // CCF con 5 unidades

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.nota-credito.store', $ccf), ['tipo' => 'averia'])
            ->assertRedirect();
        $nc = Dte::where('tipo_dte', '05')->where('tipo_nota_credito', 'averia')->firstOrFail();

        // 99 unidades del mismo producto: en avería no hay tope por saldo.
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.averia.store', $nc), ['producto_id' => $producto->id, 'cantidad' => 99])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(99, (int) $nc->refresh()->lineas->first()->cantidad);
    }

    public function test_averia_usa_precio_especial_del_cliente(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $producto = $this->producto(10);
        ProductoPrecioCliente::create(['producto_id' => $producto->id, 'cliente_id' => $cliente->id, 'precio' => 4.25, 'activo' => true]);

        $nc = $this->ncAveria($emisor, $cliente);
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.averia.store', $nc), ['producto_id' => $producto->id, 'cantidad' => 1])
            ->assertRedirect();

        $this->assertSame('4.250000', $nc->refresh()->lineas->first()->precio_unitario);
    }

    public function test_averia_bloquea_producto_sin_precio(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $sinPrecio = $this->producto(0);
        $nc = $this->ncAveria($emisor, $cliente);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.averia.store', $nc), ['producto_id' => $sinPrecio->id, 'cantidad' => 1])
            ->assertSessionHasErrors('producto_id');

        $this->assertCount(0, $nc->refresh()->lineas);
    }

    public function test_averia_cantidad_decimal_falla(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $producto = $this->producto(10);
        $nc = $this->ncAveria($emisor, $cliente);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.averia.store', $nc), ['producto_id' => $producto->id, 'cantidad' => 1.5])
            ->assertSessionHasErrors('cantidad');

        $this->assertCount(0, $nc->refresh()->lineas);
    }

    public function test_averia_recalcula_totales(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $producto = $this->producto(10);
        $nc = $this->ncAveria($emisor, $cliente);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.averia.store', $nc), ['producto_id' => $producto->id, 'cantidad' => 2])
            ->assertRedirect();

        // CCF sin descuento global → la avería tampoco aplica descuento (sigue igual).
        $this->assertSame('0.00', $nc->refresh()->descuento_global);
        $this->assertSame('20.00', $nc->total_gravado);
        $this->assertSame('22.60', $nc->total_pagar); // 20 + IVA 2.60
    }

    /**
     * La NC por avería relacionada a un CCF con descuento global (5%) hereda ese
     * mismo descuento (como Conta Portable). Reproduce el Caso 7 del piloto:
     * CANILLITAS ×3 @1.05 → gravado 3.15, descuento 0.16, base 2.99, IVA 0.39, total 3.38.
     */
    public function test_averia_hereda_descuento_global_del_ccf_relacionado(): void
    {
        $emisor = $this->emisor();
        // Cliente con 5% de descuento global → el CCF nace con descuento_porcentaje_aplicado 5.
        $cliente = Cliente::factory()->contribuyente()->create(['descuento_global_default' => 5]);
        $producto = $this->producto(1.05);
        $ccf = $this->ccfGenerado($emisor, $cliente, $producto);
        $this->assertSame('5.00', $ccf->descuento_porcentaje_aplicado);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.nota-credito.store', $ccf), ['tipo' => 'averia', 'motivo' => 'Avería'])
            ->assertRedirect();
        $nc = Dte::where('tipo_dte', '05')->where('tipo_nota_credito', 'averia')->firstOrFail();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.averia.store', $nc), ['producto_id' => $producto->id, 'cantidad' => 3])
            ->assertRedirect();

        $nc->refresh();
        $this->assertSame('5.00', $nc->descuento_porcentaje_aplicado); // heredado del CCF
        $this->assertSame('3.15', $nc->total_gravado);   // bruto 3×1.05
        $this->assertSame('0.16', $nc->descuento_global); // 5% de 3.15
        $this->assertSame('0.39', $nc->iva);             // 13% de la base neta 2.99
        $this->assertSame('3.38', $nc->total_pagar);      // 2.99 + 0.39
    }

    // --- Devolución/faltante siguen limitadas al CCF original ---

    public function test_devolucion_exige_ccf_relacionado(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-nota-credito'), [
                'tipo' => 'devolucion_producto',
                'cliente_id' => $cliente->id,
                'establecimiento_id' => $emisor['estab']->id,
                'punto_venta_id' => $emisor['pv']->id,
            ])->assertSessionHasErrors('dte_relacionado_id');
    }

    public function test_devolucion_no_permite_producto_libre_de_catalogo(): void
    {
        $emisor = $this->emisor();
        $producto = $this->producto(10);
        $cliente = Cliente::factory()->contribuyente()->create();
        $ccf = $this->ccfGenerado($emisor, $cliente, $producto);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.nota-credito.store', $ccf), ['tipo' => 'devolucion_producto'])
            ->assertRedirect();
        $nc = Dte::where('tipo_dte', '05')->where('tipo_nota_credito', 'devolucion_producto')->firstOrFail();

        // La ruta de avería rechaza una NC que no es de avería.
        $otro = $this->producto(5);
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.averia.store', $nc), ['producto_id' => $otro->id, 'cantidad' => 1])
            ->assertSessionHasErrors('tipo');

        $this->assertCount(0, $nc->refresh()->lineas);
    }

    public function test_lineas_store_rechaza_nota_credito(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $producto = $this->producto(10);
        $nc = $this->ncAveria($emisor, $cliente);

        // La ruta normal de líneas no aplica a NC (ni siquiera de avería).
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.lineas.store', $nc), ['producto_id' => $producto->id, 'cantidad' => 1])
            ->assertSessionHasErrors('producto_id');
    }

    public function test_pronto_pago_sigue_usando_concepto_manual(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $ccf = $this->ccfGenerado($emisor, $cliente, $this->producto());

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.nota-credito.store', $ccf), [
                'tipo' => 'pronto_pago',
            ])->assertRedirect();
        $nc = Dte::where('tipo_dte', '05')->where('tipo_nota_credito', 'pronto_pago')->firstOrFail();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.conceptos.store', $nc), ['descripcion' => 'Pronto pago 5%', 'monto' => 3.00])
            ->assertRedirect();

        $this->assertCount(1, $nc->refresh()->lineas);
        $this->assertNull(DteLinea::where('dte_id', $nc->id)->first()->producto_id);
    }

    /**
     * La NC por pronto pago (concepto manual) debe poder GENERARSE: el concepto no
     * tiene producto ni unidad física, pero el esquema del MH exige CAT-014 en toda
     * línea, así que el concepto toma la unidad 99 ("Otra"). Reproduce el Caso 8 del
     * piloto: concepto gravado $5.00 → IVA 0.65 → total 5.65, sin descuento ni retención.
     */
    public function test_pronto_pago_genera_con_unidad_otra_y_totales_correctos(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $ccf = $this->ccfGenerado($emisor, $cliente, $this->producto());

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.nota-credito.store', $ccf), ['tipo' => 'pronto_pago', 'motivo' => 'Pronto pago'])
            ->assertRedirect();
        $nc = Dte::where('tipo_dte', '05')->where('tipo_nota_credito', 'pronto_pago')->firstOrFail();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.conceptos.store', $nc), ['descripcion' => 'Descuento por pronto pago', 'monto' => 5.00])
            ->assertRedirect();

        // El concepto lleva unidad CAT-014 "99" (Otra) y no es un producto físico.
        $linea = DteLinea::where('dte_id', $nc->id)->firstOrFail();
        $this->assertSame('99', $linea->unidad_codigo);
        $this->assertNull($linea->producto_id);

        // Se genera sin el error "no tiene unidad de medida (CAT-014)".
        app(DteGeneracionService::class)->generar($nc->refresh());

        $nc->refresh();
        $this->assertSame('generado', $nc->estado->value);
        $this->assertSame('5.00', $nc->total_gravado);
        $this->assertSame('0.00', $nc->descuento_global); // pronto pago no hereda descuento
        $this->assertSame('0.65', $nc->iva);
        $this->assertFalse((bool) $nc->aplica_retencion_iva); // la NC no retiene
        $this->assertSame('5.65', $nc->total_pagar);
    }
}
