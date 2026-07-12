<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\DocumentoInmutableException;
use App\Exceptions\Dte\SaldoAcreditableExcedidoException;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteStateMachine;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Nota de crédito (05): base de cálculo y reglas de saldo.
 *
 * Simulación del documento original: como aún no existe el flujo real de
 * generación/envío, el CCF original se crea como borrador, se le agrega una
 * línea y se transiciona a "generado" con la máquina de estados, dejándolo
 * emitido (no borrador) y por tanto acreditable.
 */
class DteNotaCreditoTest extends TestCase
{
    use RefreshDatabase;

    private DteBorradorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosMhSeeder::class);
        $this->service = app(DteBorradorService::class);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);

        return compact('estab', 'pv');
    }

    /** Crea un CCF emitido (no borrador) con una línea gravada 10 × 10. */
    private function ccfEmitido(?Cliente $cliente = null): Dte
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente ??= Cliente::factory()->contribuyente()->create();

        $ccf = $this->service->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);

        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->service->agregarLineaDesdeProducto($ccf, $producto, cantidad: 10);

        // La NC exige un CCF ACEPTADO por Hacienda (regla de negocio).
        return $this->aceptarCcf($ccf);
    }

    public function test_crear_nota_credito_relacionada_a_ccf(): void
    {
        $ccf = $this->ccfEmitido();

        $nc = $this->service->crearNotaCredito($ccf, ['motivo' => 'Devolución parcial']);

        $this->assertSame(TipoDte::NotaCredito, $nc->tipo_dte);
        $this->assertSame(EstadoDte::Borrador, $nc->estado);
        $this->assertSame($ccf->id, $nc->dte_relacionado_id);
        $this->assertSame($ccf->cliente_id, $nc->cliente_id);
    }

    public function test_no_permite_nota_credito_sin_documento_original(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->crearNotaCredito(null);
    }

    public function test_no_permite_nota_credito_con_ccf_no_aceptado(): void
    {
        // Un CCF que NO está ACEPTADO por Hacienda (aquí en borrador) no puede originar una NC.
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $ccf = $this->service->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);
        $this->assertSame(EstadoDte::Borrador, $ccf->estado); // no aceptado

        $this->expectException(ValidationException::class);
        $this->service->crearNotaCredito($ccf);
    }

    public function test_produccion_bloquea_nc_contra_ccf_sin_aceptacion_real_mh(): void
    {
        // CCF en PRODUCCIÓN (ambiente 01) marcado Aceptado pero SIN aceptación real:
        // sello MOCK y sin fecha de procesamiento del MH. No cumple aceptadoRealMh().
        $ccf = $this->ccfProduccion(aceptacionReal: false);
        $this->assertSame(EstadoDte::Aceptado, $ccf->estado);              // estado genérico OK
        $this->assertFalse(Dte::whereKey($ccf->id)->aceptadoRealMh()->exists()); // pero no real

        $this->expectException(ValidationException::class);
        $this->service->crearNotaCredito($ccf, ['motivo' => 'Devolución']);
    }

    public function test_produccion_permite_nc_contra_ccf_con_aceptacion_real_mh(): void
    {
        // CCF en PRODUCCIÓN (ambiente 01) con aceptación REAL (sello no-mock + fecha MH).
        $ccf = $this->ccfProduccion(aceptacionReal: true);
        $this->assertTrue(Dte::whereKey($ccf->id)->aceptadoRealMh()->exists());

        $nc = $this->service->crearNotaCredito($ccf, ['motivo' => 'Devolución parcial']);

        $this->assertSame(TipoDte::NotaCredito, $nc->tipo_dte);
        $this->assertSame($ccf->id, $nc->dte_relacionado_id);
        $this->assertSame('01', $nc->ambiente->value); // la NC hereda producción
    }

    public function test_ambiente_pruebas_conserva_comportamiento_con_ccf_aceptado_mock(): void
    {
        // En PRUEBAS (ambiente 00) NO se exige aceptación real: un CCF Aceptado con
        // sello MOCK sigue permitiendo crear la NC (comportamiento actual intacto).
        $ccf = $this->ccfEmitido(); // ambiente 00
        $ccf->forceFill([
            'sello_recepcion' => 'MOCK-SIMULADO-0987654321',
            'fecha_procesamiento_mh' => null,
        ])->save();
        $this->assertSame('00', $ccf->ambiente->value);

        $nc = $this->service->crearNotaCredito($ccf->refresh(), ['motivo' => 'Prueba local']);

        $this->assertSame(TipoDte::NotaCredito, $nc->tipo_dte);
        $this->assertSame('00', $nc->ambiente->value);
    }

    /**
     * CCF de PRODUCCIÓN (ambiente 01). El ambiente se fija mientras aún es borrador
     * (editable); luego se acepta con sello REAL (no-mock + fecha MH) o sin aceptación
     * real (sello mock + sin fecha MH) según el caso. No toca correlativos de prod real.
     */
    private function ccfProduccion(bool $aceptacionReal): Dte
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $ccf = $this->service->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);
        // Ambiente producción mientras es borrador (el observer bloquea cambiarlo luego).
        $ccf->forceFill(['ambiente' => '01'])->save();

        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->service->agregarLineaDesdeProducto($ccf, $producto, cantidad: 10);

        // Emitido (Generado) y luego Aceptado. sello/fecha son campos permitidos fuera de borrador.
        app(DteStateMachine::class)->transicionar($ccf, EstadoDte::Generado);
        $ccf->numero_control = $ccf->numero_control ?: ('DTE-03-M001P001-'.str_pad((string) $ccf->id, 15, '0', STR_PAD_LEFT));
        $ccf->codigo_generacion = $ccf->codigo_generacion ?: strtoupper((string) Str::uuid());
        $ccf->sello_recepcion = $aceptacionReal ? '2026'.strtoupper(Str::random(36)) : 'MOCK-SIMULADO-1234567890';
        $ccf->fecha_procesamiento_mh = $aceptacionReal ? now() : null;
        $ccf->estado = EstadoDte::Aceptado;
        $ccf->save();

        return $ccf->refresh();
    }

    public function test_no_permite_nota_credito_con_cliente_distinto(): void
    {
        $ccf = $this->ccfEmitido();
        $otro = Cliente::factory()->contribuyente()->create();

        $this->expectException(ValidationException::class);
        $this->service->crearNotaCredito($ccf, ['cliente_id' => $otro->id]);
    }

    public function test_acreditar_una_linea_parcial(): void
    {
        $ccf = $this->ccfEmitido();
        $lineaOriginal = $ccf->lineas()->first();
        $nc = $this->service->crearNotaCredito($ccf);

        $linea = $this->service->acreditarLinea($nc, $lineaOriginal, cantidad: 4);

        $this->assertSame($lineaOriginal->id, $linea->dte_linea_original_id);
        $this->assertSame('40.00', $linea->venta_gravada);
        $this->assertSame('5.20', $linea->iva_linea);

        $nc->refresh();
        $this->assertSame('40.00', $nc->total_gravado);
        $this->assertSame('5.20', $nc->iva);
        $this->assertSame('45.20', $nc->total_pagar);
    }

    public function test_no_permite_acreditar_mas_cantidad_que_la_linea_original(): void
    {
        $ccf = $this->ccfEmitido();
        $lineaOriginal = $ccf->lineas()->first();
        $nc = $this->service->crearNotaCredito($ccf);

        $this->expectException(SaldoAcreditableExcedidoException::class);
        $this->service->acreditarLinea($nc, $lineaOriginal, cantidad: 11); // original es 10
    }

    public function test_no_permite_acreditar_mas_monto_que_la_linea_original(): void
    {
        $ccf = $this->ccfEmitido();
        $lineaOriginal = $ccf->lineas()->first();
        $nc = $this->service->crearNotaCredito($ccf);

        // Acredita 6 de 10; quedan 4.
        $this->service->acreditarLinea($nc, $lineaOriginal, cantidad: 6);

        // Intentar 5 más (acumulado 11 > 10) excede el saldo.
        $this->expectException(SaldoAcreditableExcedidoException::class);
        $this->service->acreditarLinea($nc, $lineaOriginal, cantidad: 5);
    }

    public function test_totales_coinciden_con_la_cantidad_acreditada(): void
    {
        $ccf = $this->ccfEmitido();
        $lineaOriginal = $ccf->lineas()->first();
        $nc = $this->service->crearNotaCredito($ccf);

        // Acredita el total (10) → debe igualar al CCF original.
        $this->service->acreditarLinea($nc, $lineaOriginal, cantidad: 10);

        $nc->refresh();
        $this->assertSame('100.00', $nc->total_gravado);
        $this->assertSame('13.00', $nc->iva);
        $this->assertSame('113.00', $nc->total_pagar);
        $this->assertSame($ccf->total_pagar, $nc->total_pagar);
    }

    public function test_nota_credito_queda_como_borrador(): void
    {
        $ccf = $this->ccfEmitido();
        $nc = $this->service->crearNotaCredito($ccf);

        $this->assertTrue($nc->esEditable());
        $this->assertDatabaseHas('dtes', ['id' => $nc->id, 'tipo_dte' => '05', 'estado' => 'borrador']);
    }

    public function test_documento_no_borrador_sigue_inmutable(): void
    {
        $ccf = $this->ccfEmitido();
        $lineaOriginal = $ccf->lineas()->first();
        $nc = $this->service->crearNotaCredito($ccf);

        app(DteStateMachine::class)->transicionar($nc, EstadoDte::Generado);

        $this->expectException(DocumentoInmutableException::class);
        $this->service->acreditarLinea($nc, $lineaOriginal, cantidad: 1);
    }

    // --- Modalidades por monto (pronto pago / descuento / ajuste) ---

    public function test_nc_por_faltante_exige_ccf_relacionado(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->crearNotaCredito(null, ['tipo' => 'faltante_entrega']);
    }

    public function test_nc_pronto_pago_permite_concepto_manual_sin_producto(): void
    {
        $ccf = $this->ccfEmitido();
        $nc = $this->service->crearNotaCredito($ccf, ['tipo' => 'pronto_pago', 'motivo' => 'Pronto pago Calleja']);

        $linea = $this->service->agregarConceptoNotaCredito($nc, [
            'descripcion' => 'Descuento por pronto pago', 'monto' => 25, 'tipo_impuesto' => 'gravado',
        ]);

        $this->assertNull($linea->producto_id);
        $this->assertNull($linea->dte_linea_original_id);
        $this->assertSame('Descuento por pronto pago', $linea->descripcion);

        $nc->refresh();
        $this->assertSame('25.00', $nc->total_gravado);
    }

    public function test_nc_pronto_pago_no_exige_linea_original_y_no_valida_saldo(): void
    {
        $ccf = $this->ccfEmitido();
        $nc = $this->service->crearNotaCredito($ccf, ['tipo' => 'pronto_pago']);

        // No exige líneas del original; un concepto se agrega sin tocar saldo de productos.
        $this->service->agregarConceptoNotaCredito($nc, ['descripcion' => 'Ajuste', 'monto' => 9999, 'tipo_impuesto' => 'gravado']);

        $this->assertCount(1, $nc->refresh()->lineas);

        // Y NO se puede acreditar una línea de producto en una NC por monto.
        $this->expectException(ValidationException::class);
        $this->service->acreditarLinea($nc, $ccf->lineas()->first(), cantidad: 1);
    }

    public function test_nc_ajuste_comercial_permite_concepto(): void
    {
        $ccf = $this->ccfEmitido();
        $nc = $this->service->crearNotaCredito($ccf, ['tipo' => 'ajuste_comercial']);

        $linea = $this->service->agregarConceptoNotaCredito($nc, ['descripcion' => 'Bonificación', 'monto' => 10]);

        $this->assertSame('Bonificación', $linea->descripcion);
    }

    public function test_no_se_mezclan_conceptos_en_nc_de_productos(): void
    {
        $ccf = $this->ccfEmitido();
        $nc = $this->service->crearNotaCredito($ccf, ['tipo' => 'devolucion_producto']);

        $this->expectException(ValidationException::class);
        $this->service->agregarConceptoNotaCredito($nc, ['descripcion' => 'X', 'monto' => 5]);
    }

    public function test_concepto_requiere_descripcion_y_monto_positivo(): void
    {
        $ccf = $this->ccfEmitido();
        $nc = $this->service->crearNotaCredito($ccf, ['tipo' => 'pronto_pago']);

        $this->expectException(ValidationException::class);
        $this->service->agregarConceptoNotaCredito($nc, ['descripcion' => '', 'monto' => 0]);
    }
}
