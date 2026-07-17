<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Secciones B (unidad 99 → Caja/Cajas) y C (bloque RECEPTOR ampliado) del
 * ticket "FEX #131": solo presentación, no toca `unidad_codigo` guardado ni la
 * cabecera del receptor de CCF/NC/Factura consumidor final.
 */
class PresentacionCajaYReceptorFexTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte();
        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        foreach (['01', '03', '11'] as $tipo) {
            Correlativo::create(['tipo_dte' => $tipo, 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        }
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole('facturacion');
    }

    /** Cliente de exportación con los mismos datos del ejemplo real (Carolinas Wholesale). */
    private function clienteCarolinas(): Cliente
    {
        $usa = \App\Models\Pais::where('codigo', 'US')->first();

        return Cliente::factory()->exportacion()->create([
            'nombre' => 'CAROLINAS WHOLESALE LLC',
            'num_documento' => '17169433',
            'correo' => 'carolinaswholesalellc@aol.com',
            'direccion' => '13340 Mid Atlantic Blvd. Laurel, MD 20708 EEUU',
            'telefono' => null,
            'pais_id' => $usa->id,
        ]);
    }

    private function fexConLineaLibre(Cliente $cliente, int $cajas = 16, float $precioCaja = 2160 / 16): Dte
    {
        $dte = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::FacturaExportacion,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'tipo_item_expor' => 1, 'recinto_fiscal' => '08', 'tipo_regimen' => 'EX-1', 'regimen' => '1000.000', 'cod_incoterms' => '09',
        ]);
        app(DteBorradorService::class)->agregarLineaLibre($dte, [
            'descripcion' => 'Caja de alfeñique',
            'unidad_codigo' => '99',
            'cantidad' => $cajas,
            'precio_unitario' => $precioCaja,
        ]);

        return $dte->refresh();
    }

    private function generar(Dte $dte): Dte
    {
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    /** Renderiza facturacion.pdf a HTML (mismo patrón que DteExportacionDatosVistaTest). */
    private function htmlPdf(Dte $dte): string
    {
        $dte->load(['cliente.pais', 'cliente.actividadEconomica', 'clienteSucursal', 'lineas', 'establecimiento.empresa', 'puntoVenta', 'dteRelacionado']);
        $emisor = $dte->establecimiento?->empresa;
        $datosExportacion = \App\Support\Dte\DatosExportacionPresentacion::resolver($dte);
        $datosReceptor = \App\Support\Dte\ReceptorExportacionPresentacion::resolver($dte);

        return view('facturacion.pdf', compact('dte', 'emisor', 'datosExportacion', 'datosReceptor'))->render();
    }

    // --- Item 8: unidad 99 se conserva internamente ---

    public function test_unidad_99_se_conserva_internamente_en_la_linea(): void
    {
        $dte = $this->fexConLineaLibre($this->clienteCarolinas());

        $this->assertSame('99', $dte->lineas->first()->unidad_codigo);
    }

    // --- Item 9/10: se muestra Caja/Cajas y NO "99" crudo ---

    public function test_pdf_muestra_cajas_y_no_el_codigo_99_crudo(): void
    {
        $dte = $this->generar($this->fexConLineaLibre($this->clienteCarolinas(), cajas: 16));

        $html = $this->htmlPdf($dte);

        $this->assertStringContainsString('>Cajas<', $html);
        $this->assertStringNotContainsString('>99<', $html);
    }

    public function test_imprimir_muestra_caja_singular_para_una_sola_caja(): void
    {
        $dte = $this->generar($this->fexConLineaLibre($this->clienteCarolinas(), cajas: 1, precioCaja: 2160));

        $html = $this->actingAs($this->usuario())
            ->get(route('facturacion.imprimir', $dte))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>Caja<', $html);
        $this->assertStringNotContainsString('>99<', $html);
    }

    public function test_present_99_no_aparece_en_pdf_ni_impresion(): void
    {
        $dte = $this->generar($this->fexConLineaLibre($this->clienteCarolinas()));

        $pdfHtml = $this->htmlPdf($dte);
        $imprimirHtml = $this->actingAs($this->usuario())->get(route('facturacion.imprimir', $dte))->getContent();

        $this->assertStringNotContainsString('Present. 99', $pdfHtml);
        $this->assertStringNotContainsString('Present. 99', $imprimirHtml);
    }

    // --- CCF: NO cambia el comportamiento existente (unidad normal, no 99) ---

    public function test_ccf_no_se_ve_afectado_por_la_etiqueta_de_caja(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);
        $dte = $this->generar($dte);

        $html = $this->htmlPdf($dte);

        $this->assertStringNotContainsString('>Cajas<', $html);
        $this->assertStringNotContainsString('>Caja<', $html);
    }

    /**
     * Regresión: UnidadMedidaSeeder mapea "Bolsa" (unidad real de productos de
     * Dulces La Negrita) al mismo código MH "99" que usan las cajas de FEX,
     * porque CAT-014 no tiene un código propio de bolsa/empaque. Un CCF con un
     * producto vendido por Bolsa NO debe mostrar "Caja"/"Cajas": debe seguir
     * mostrando "Bolsa", igual que antes de este cambio.
     */
    public function test_ccf_con_producto_por_bolsa_codigo_99_muestra_bolsa_no_caja(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ]);
        $unidadBolsa = \App\Models\UnidadMedida::where('nombre', 'Bolsa')->first();
        $this->assertSame('99', $unidadBolsa->codigo, 'Precondición del test: Bolsa debe mapear a código 99.');
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value, 'unidad_medida_id' => $unidadBolsa->id]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);
        $dte = $this->generar($dte);

        $this->assertSame('99', $dte->lineas->first()->unidad_codigo);

        $html = $this->htmlPdf($dte);

        $this->assertStringContainsString('>Bolsa<', $html);
        $this->assertStringNotContainsString('>Cajas<', $html);
        $this->assertStringNotContainsString('>Caja<', $html);
    }

    /** Regresión: el concepto manual de NC (pronto pago/descuento) también usa código 99, y no es una caja. */
    public function test_concepto_manual_de_nc_con_codigo_99_no_muestra_caja(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $ccf = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($ccf, $producto, cantidad: 2);
        $ccf = $this->generar($ccf);
        $ccf->estado = \App\Enums\EstadoDte::Aceptado;
        $ccf->save();

        $nc = app(DteBorradorService::class)->crearNotaCredito($ccf, [
            'tipo' => \App\Enums\TipoNotaCredito::ProntoPago->value,
        ]);
        app(DteBorradorService::class)->agregarConceptoNotaCredito($nc, ['descripcion' => 'Pronto pago', 'monto' => 5]);
        $nc->refresh();

        $this->assertSame('99', $nc->lineas->first()->unidad_codigo);
        $this->assertNull($nc->lineas->first()->producto_id);

        $html = view('facturacion.imprimir', [
            'dte' => $nc->load(['cliente', 'clienteSucursal', 'lineas', 'dteRelacionado']),
            'emisor' => $this->estab->empresa,
            'logoSrc' => null,
            'datosExportacion' => null,
            'datosReceptor' => null,
        ])->render();

        $this->assertStringNotContainsString('>Cajas<', $html);
        $this->assertStringNotContainsString('>Caja<', $html);
    }

    /** FEX MANUAL (no desde Lista) con un producto real de catálogo: muestra su unidad real, no Caja. */
    public function test_fex_manual_con_producto_catalogo_no_muestra_caja(): void
    {
        $dte = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::FacturaExportacion,
            'cliente_id' => $this->clienteCarolinas()->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'tipo_item_expor' => 1, 'recinto_fiscal' => '08', 'tipo_regimen' => 'EX-1', 'regimen' => '1000.000', 'cod_incoterms' => '09',
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 3);
        $dte = $this->generar($dte);

        $this->assertNotNull($dte->lineas->first()->producto_id);

        $html = $this->htmlPdf($dte);

        $this->assertStringNotContainsString('>Cajas<', $html);
        $this->assertStringNotContainsString('>Caja<', $html);
    }

    // --- Editor y ficha/show también deben mostrar Caja/Cajas (no solo PDF/impresión) ---

    public function test_editor_de_fex_desde_lista_muestra_caja_cajas(): void
    {
        $dte = $this->fexConLineaLibre($this->clienteCarolinas(), cajas: 3, precioCaja: 100);

        $html = $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('3 Cajas', $html);
        $this->assertStringNotContainsString('>99<', $html);
    }

    public function test_show_de_fex_desde_lista_muestra_caja_cajas(): void
    {
        $dte = $this->generar($this->fexConLineaLibre($this->clienteCarolinas(), cajas: 1, precioCaja: 2160));

        $html = $this->actingAs($this->usuario())
            ->get(route('facturacion.show', $dte))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>Caja<', $html);
        $this->assertStringContainsString('Presentación', $html);
        $this->assertStringNotContainsString('>99<', $html);
    }

    // --- Items 21-27: bloque RECEPTOR ampliado (destino, correo, documento, actividad, dirección, teléfono) ---

    public function test_ficha_show_de_fex_muestra_destino_correo_documento_actividad_y_direccion(): void
    {
        $dte = $this->generar($this->fexConLineaLibre($this->clienteCarolinas()));

        $this->actingAs($this->usuario())
            ->get(route('facturacion.show', $dte))
            ->assertOk()
            ->assertSee('Receptor')
            ->assertSee('CAROLINAS WHOLESALE LLC')
            ->assertSee('Destino')
            ->assertSee('Estados Unidos')
            ->assertSee('17169433')
            ->assertSee('carolinaswholesalellc@aol.com')
            ->assertSee('13340 Mid Atlantic Blvd. Laurel, MD 20708 EEUU')
            ->assertDontSee('Teléfono');
    }

    public function test_pdf_de_fex_muestra_destino_y_correo(): void
    {
        $dte = $this->generar($this->fexConLineaLibre($this->clienteCarolinas()));

        $html = $this->htmlPdf($dte);

        $this->assertStringContainsString('Destino', $html);
        $this->assertStringContainsString('Estados Unidos', $html);
        $this->assertStringContainsString('carolinaswholesalellc@aol.com', $html);
    }

    public function test_impresion_de_fex_muestra_destino_y_correo(): void
    {
        $dte = $this->generar($this->fexConLineaLibre($this->clienteCarolinas()));

        $this->actingAs($this->usuario())
            ->get(route('facturacion.imprimir', $dte))
            ->assertOk()
            ->assertSee('Destino')
            ->assertSee('Estados Unidos')
            ->assertSee('carolinaswholesalellc@aol.com');
    }

    public function test_telefono_se_muestra_solo_si_existe(): void
    {
        $usa = \App\Models\Pais::where('codigo', 'US')->first();
        $conTelefono = Cliente::factory()->exportacion()->create(['pais_id' => $usa->id, 'telefono' => '2222-3333']);
        $dte = $this->generar($this->fexConLineaLibre($conTelefono));

        $this->actingAs($this->usuario())
            ->get(route('facturacion.show', $dte))
            ->assertOk()
            ->assertSee('Teléfono')
            ->assertSee('2222-3333');
    }

    // --- Items 28-30: CCF / Factura 01 / NC no muestran "Destino" ---

    public function test_ccf_no_muestra_destino_en_la_ficha(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);
        $dte = $this->generar($dte);

        $this->actingAs($this->usuario())
            ->get(route('facturacion.show', $dte))
            ->assertOk()
            ->assertDontSee('Destino');
    }

    public function test_factura_consumidor_final_no_muestra_destino_en_la_ficha(): void
    {
        $dte = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::Factura,
            'cliente_id' => null,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);
        $dte = $this->generar($dte);

        $this->actingAs($this->usuario())
            ->get(route('facturacion.show', $dte))
            ->assertOk()
            ->assertDontSee('Destino');
    }
}
