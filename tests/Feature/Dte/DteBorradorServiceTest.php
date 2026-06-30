<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\DocumentoInmutableException;
use App\Exceptions\Dte\OrdenCompraRequeridaException;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteStateMachine;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DteBorradorServiceTest extends TestCase
{
    use RefreshDatabase;

    private DteBorradorService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosMhSeeder::class);
        $this->service = app(DteBorradorService::class);
        $this->user = User::factory()->create();
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta, correlativo: Correlativo} */
    private function emisor(): array
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        $correlativo = Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
        ]);

        return compact('estab', 'pv', 'correlativo');
    }

    /** @param array<string, mixed> $extra */
    private function nuevoBorrador(TipoDte $tipo, ?Cliente $cliente = null, array $extra = []): Dte
    {
        ['estab' => $estab, 'pv' => $pv, 'correlativo' => $correlativo] = $this->emisor();

        return $this->service->crearBorrador(array_merge([
            'tipo_dte' => $tipo,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'correlativo_id' => $correlativo->id,
            'cliente_id' => $cliente,
        ], $extra), $this->user);
    }

    public function test_crear_borrador_ccf(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = $this->nuevoBorrador(TipoDte::CreditoFiscal, $cliente);

        $this->assertSame(TipoDte::CreditoFiscal, $dte->tipo_dte);
        $this->assertSame(EstadoDte::Borrador, $dte->estado);
        $this->assertTrue($dte->esEditable());
        $this->assertSame($cliente->id, $dte->cliente_id);
        $this->assertNull($dte->numero_control, 'No se asigna número de control en borrador.');
        $this->assertSame('0.00', $dte->total_pagar);
        $this->assertCount(1, $dte->historial, 'Se registró la creación en la bitácora.');
        // El correlativo se referencia, no se consume.
        $this->assertSame(0, $dte->correlativo->ultimo_numero);
    }

    public function test_agregar_producto_gravado_y_recalcula(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = $this->nuevoBorrador(TipoDte::CreditoFiscal, $cliente);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        $linea = $this->service->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);

        $this->assertSame('100.00', $linea->venta_gravada);
        $this->assertSame('13.00', $linea->iva_linea);
        $this->assertSame('113.00', $linea->total_linea);

        $dte->refresh();
        $this->assertSame('100.00', $dte->total_gravado);
        $this->assertSame('13.00', $dte->iva);
        $this->assertSame('113.00', $dte->total_pagar);
        $this->assertSame('0.00', $dte->iva_retenido);
    }

    public function test_agregar_exento_y_no_sujeto_recalcula(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = $this->nuevoBorrador(TipoDte::CreditoFiscal, $cliente);

        $exento = Producto::factory()->create(['precio_unitario' => 50, 'tipo_impuesto' => TipoImpuesto::Exento->value]);
        $noSujeto = Producto::factory()->create(['precio_unitario' => 30, 'tipo_impuesto' => TipoImpuesto::NoSujeto->value]);

        $this->service->agregarLineaDesdeProducto($dte, $exento, cantidad: 1);
        $this->service->agregarLineaDesdeProducto($dte, $noSujeto, cantidad: 1);

        $dte->refresh();
        $this->assertSame('0.00', $dte->total_gravado);
        $this->assertSame('50.00', $dte->total_exento);
        $this->assertSame('30.00', $dte->total_no_sujeto);
        $this->assertSame('0.00', $dte->iva);
        $this->assertSame('80.00', $dte->total_pagar);
        $this->assertCount(2, $dte->lineas);
    }

    public function test_factura_consumidor_final_iva_incluido(): void
    {
        $cliente = Cliente::factory()->create(); // consumidor final
        $dte = $this->nuevoBorrador(TipoDte::Factura, $cliente);
        $producto = Producto::factory()->create(['precio_unitario' => 1.13, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        $linea = $this->service->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);

        $this->assertSame('1.00', $linea->venta_gravada);
        $this->assertSame('0.13', $linea->iva_linea);
        $this->assertSame('1.13', $linea->total_linea);

        $dte->refresh();
        $this->assertSame('1.00', $dte->total_gravado);
        $this->assertSame('0.13', $dte->iva);
        $this->assertSame('1.13', $dte->total_pagar);
    }

    public function test_factura_exportacion_con_flete_y_seguro(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $dte = $this->nuevoBorrador(TipoDte::FacturaExportacion, $cliente, ['flete' => 5, 'seguro' => 2]);
        $producto = Producto::factory()->create(['precio_unitario' => 1.15, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        $linea = $this->service->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);

        $this->assertSame('11.50', $linea->venta_exportacion);
        $this->assertSame('0.00', $linea->iva_linea);

        $dte->refresh();
        $this->assertSame('11.50', $dte->total_exportacion);
        $this->assertSame('0.00', $dte->iva);
        $this->assertSame('5.00', $dte->flete);
        $this->assertSame('2.00', $dte->seguro);
        $this->assertSame('18.50', $dte->total_pagar);
    }

    public function test_cliente_requiere_orden_compra_en_ccf_exige_numero(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create(['requiere_orden_compra' => true]);

        $this->expectException(OrdenCompraRequeridaException::class);
        $this->nuevoBorrador(TipoDte::CreditoFiscal, $cliente);
    }

    public function test_cliente_requiere_orden_compra_se_guarda_el_numero(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create(['requiere_orden_compra' => true]);

        $dte = $this->nuevoBorrador(TipoDte::CreditoFiscal, $cliente, ['numero_orden_compra' => 'OC-2026-001']);

        $this->assertSame('OC-2026-001', $dte->numero_orden_compra);
    }

    public function test_cliente_sin_requiere_orden_compra_no_la_exige(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create(['requiere_orden_compra' => false]);

        $dte = $this->nuevoBorrador(TipoDte::CreditoFiscal, $cliente);

        $this->assertSame(EstadoDte::Borrador, $dte->estado);
        $this->assertNull($dte->numero_orden_compra);
    }

    public function test_cliente_agente_de_retencion_aplica_automaticamente_sobre_umbral(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create(['es_agente_retencion' => true]);
        $dte = $this->nuevoBorrador(TipoDte::CreditoFiscal, $cliente);

        // Sin líneas: aún no se decide retención.
        $this->assertFalse((bool) $dte->aplica_retencion_iva);

        // Base gravada 110 > 100 → retención automática 1%.
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->service->agregarLineaDesdeProducto($dte, $producto, cantidad: 11);

        $dte->refresh();
        $this->assertTrue((bool) $dte->aplica_retencion_iva);
        $this->assertSame('110.00', $dte->total_gravado);
        $this->assertSame('1.10', $dte->iva_retenido);
        $this->assertSame('124.30', $dte->total_antes_retencion);
        $this->assertSame('123.20', $dte->total_pagar);
    }

    public function test_dte_no_borrador_no_permite_modificar_lineas(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = $this->nuevoBorrador(TipoDte::CreditoFiscal, $cliente);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->service->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);

        // Sale de borrador.
        app(DteStateMachine::class)->transicionar($dte, EstadoDte::Generado, $this->user);

        $this->expectException(DocumentoInmutableException::class);
        $this->service->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);
    }

    public function test_snapshot_no_cambia_aunque_el_producto_cambie_despues(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = $this->nuevoBorrador(TipoDte::CreditoFiscal, $cliente);
        $producto = Producto::factory()->create([
            'nombre' => 'Dulce de leche',
            'precio_unitario' => 10,
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);

        $linea = $this->service->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);

        // El producto cambia DESPUÉS de capturar la línea.
        $producto->update([
            'nombre' => 'Producto modificado',
            'precio_unitario' => 99,
            'tipo_impuesto' => TipoImpuesto::Exento->value,
        ]);

        $linea->refresh();
        $this->assertSame('Dulce de leche', $linea->descripcion);
        $this->assertSame('10.000000', $linea->precio_unitario);
        $this->assertSame(TipoImpuesto::Gravado, $linea->tipo_impuesto);
        $this->assertSame('20.00', $linea->venta_gravada);

        // El producto vivo sí cambió (referencia blanda).
        $this->assertSame('Producto modificado', $linea->producto->nombre);
    }

    public function test_eliminar_linea_renumera_y_recalcula(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = $this->nuevoBorrador(TipoDte::CreditoFiscal, $cliente);
        $p1 = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $p2 = Producto::factory()->create(['precio_unitario' => 5, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        $linea1 = $this->service->agregarLineaDesdeProducto($dte, $p1, cantidad: 1);
        $this->service->agregarLineaDesdeProducto($dte, $p2, cantidad: 1);

        $this->service->eliminarLinea($linea1);

        $dte->refresh();
        $this->assertCount(1, $dte->lineas);
        $this->assertSame(1, $dte->lineas->first()->numero_linea, 'La línea restante se renumera a 1.');
        $this->assertSame('5.00', $dte->total_gravado);
        $this->assertSame('5.65', $dte->total_pagar);
    }

    // --- Validaciones de entrada ---

    public function test_crear_sin_punto_venta_falla(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        ['estab' => $estab, 'correlativo' => $correlativo] = $this->emisor();

        $this->expectException(ValidationException::class);
        $this->service->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'establecimiento_id' => $estab->id,
            'correlativo_id' => $correlativo->id,
            'cliente_id' => $cliente,
        ], $this->user);
    }

    public function test_crear_con_tipo_no_habilitado_falla(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();

        $this->expectException(ValidationException::class);
        $this->nuevoBorrador(TipoDte::NotaDebito, $cliente); // 06 no está habilitado
    }

    public function test_ccf_con_cliente_consumidor_final_falla(): void
    {
        $cliente = Cliente::factory()->create(); // consumidor final

        $this->expectException(ValidationException::class);
        $this->nuevoBorrador(TipoDte::CreditoFiscal, $cliente);
    }

    public function test_fex_con_cliente_nacional_falla(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create(); // nacional

        $this->expectException(ValidationException::class);
        $this->nuevoBorrador(TipoDte::FacturaExportacion, $cliente);
    }

    public function test_agregar_linea_con_cantidad_cero_falla(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = $this->nuevoBorrador(TipoDte::CreditoFiscal, $cliente);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        $this->expectException(ValidationException::class);
        $this->service->agregarLineaDesdeProducto($dte, $producto, cantidad: 0);
    }

    public function test_agregar_linea_con_descuento_mayor_al_importe_falla(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = $this->nuevoBorrador(TipoDte::CreditoFiscal, $cliente);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        // importe = 1 × 10 = 10; descuento 20 > 10.
        $this->expectException(ValidationException::class);
        $this->service->agregarLineaDesdeProducto($dte, $producto, cantidad: 1, descuento: 20);
    }

    public function test_actualizar_linea_con_cantidad_cero_falla(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = $this->nuevoBorrador(TipoDte::CreditoFiscal, $cliente);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $linea = $this->service->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);

        $this->expectException(ValidationException::class);
        $this->service->actualizarLinea($linea, ['cantidad' => 0]);
    }

    public function test_actualizar_linea_en_dte_no_borrador_falla(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = $this->nuevoBorrador(TipoDte::CreditoFiscal, $cliente);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $linea = $this->service->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);

        app(DteStateMachine::class)->transicionar($dte, EstadoDte::Generado, $this->user);

        $this->expectException(DocumentoInmutableException::class);
        $this->service->actualizarLinea($linea, ['cantidad' => 5]);
    }

    public function test_eliminar_linea_en_dte_no_borrador_falla(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = $this->nuevoBorrador(TipoDte::CreditoFiscal, $cliente);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $linea = $this->service->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);

        app(DteStateMachine::class)->transicionar($dte, EstadoDte::Generado, $this->user);

        $this->expectException(DocumentoInmutableException::class);
        $this->service->eliminarLinea($linea);
    }
}
