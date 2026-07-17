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
use Tests\TestCase;

/**
 * Muestra en ficha/PDF/imprimir los datos FEX (tipo 11) ya guardados en el DTE
 * (tipo de ítem, recinto fiscal, tipo de régimen, régimen, incoterm), resueltos a
 * etiquetas legibles vía App\Support\Dte\DatosExportacionPresentacion. Solo
 * presentación: no recalcula nada fiscal, no toca el serializador ni los gates de
 * bloqueo de producción del commit 04d32ff.
 */
class DteExportacionDatosVistaTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    private DteGeneracionService $generacion;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();

        $this->borradores = app(DteBorradorService::class);
        $this->generacion = app(DteGeneracionService::class);

        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        foreach (['01', '03', '05', '11'] as $tipo) {
            Correlativo::create(['tipo_dte' => $tipo, 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        }
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function producto(): Producto
    {
        return Producto::factory()->create(['nombre' => 'Dulce de leche artesanal', 'precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
    }

    /** @param array<string, mixed> $extra */
    private function borradorConLinea(TipoDte $tipo, ?Cliente $cliente, array $extra = []): Dte
    {
        $base = [
            'tipo_dte' => $tipo,
            'cliente_id' => $cliente,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ];
        if ($tipo === TipoDte::FacturaExportacion) {
            $base += [
                'tipo_item_expor' => 1,
                'recinto_fiscal' => '01',
                'tipo_regimen' => 'EX-1',
                'regimen' => '1000.000',
                'cod_incoterms' => '09',
            ];
        }
        $dte = $this->borradores->crearBorrador(array_merge($base, $extra));
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(), cantidad: 10);

        return $dte->refresh();
    }

    private function generar(Dte $dte): Dte
    {
        $this->generacion->generar($dte);

        return $dte->refresh();
    }

    /** Renderiza facturacion.pdf a HTML (mismo patrón que DtePdfPreliminarTest). */
    private function htmlPdf(Dte $dte): string
    {
        $dte->load(['cliente', 'clienteSucursal', 'lineas', 'establecimiento.empresa', 'puntoVenta', 'dteRelacionado']);
        $emisor = $dte->establecimiento?->empresa;
        $datosExportacion = \App\Support\Dte\DatosExportacionPresentacion::resolver($dte);

        return view('facturacion.pdf', compact('dte', 'emisor', 'datosExportacion'))->render();
    }

    // --- FEX: aparece en las 3 superficies ---

    public function test_pdf_fex_muestra_incoterm_regimen_y_recinto(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $fex = $this->generar($this->borradorConLinea(TipoDte::FacturaExportacion, $cliente));

        $html = $this->htmlPdf($fex);

        $this->assertStringContainsString('Datos de exportación', $html);
        $this->assertStringContainsString('Bienes', $html);
        $this->assertStringContainsString('01 — Terrestre San Bartolo', $html);
        $this->assertStringContainsString('EX-1 — Exportación Definitiva', $html);
        $this->assertStringContainsString('1000.000 — Exportación Definitiva, Régimen Común', $html);
        $this->assertStringContainsString('09 — FOB-Libre a bordo', $html);
    }

    public function test_imprimir_fex_muestra_incoterm_regimen_y_recinto(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $fex = $this->generar($this->borradorConLinea(TipoDte::FacturaExportacion, $cliente));

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.imprimir', $fex))
            ->assertOk()
            ->assertSee('Datos de exportación')
            ->assertSee('01 — Terrestre San Bartolo')
            ->assertSee('EX-1 — Exportación Definitiva')
            ->assertSee('1000.000 — Exportación Definitiva, Régimen Común')
            ->assertSee('09 — FOB-Libre a bordo');
    }

    public function test_ficha_show_fex_muestra_la_seccion(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $fex = $this->generar($this->borradorConLinea(TipoDte::FacturaExportacion, $cliente));

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $fex))
            ->assertOk()
            ->assertSee('Datos de exportación')
            ->assertSee('Aduana de salida')
            ->assertSee('01 — Terrestre San Bartolo')
            ->assertSee('EX-1 — Exportación Definitiva')
            ->assertSee('1000.000 — Exportación Definitiva, Régimen Común')
            ->assertSee('09 — FOB-Libre a bordo');
    }

    // --- CCF / Factura consumidor final: NO deben mostrar el bloque ---

    public function test_pdf_ccf_no_muestra_el_bloque(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $ccf = $this->generar($this->borradorConLinea(TipoDte::CreditoFiscal, $cliente));

        $html = $this->htmlPdf($ccf);

        $this->assertStringNotContainsString('Datos de exportación', $html);
    }

    public function test_imprimir_ccf_no_muestra_el_bloque(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $ccf = $this->generar($this->borradorConLinea(TipoDte::CreditoFiscal, $cliente));

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.imprimir', $ccf))
            ->assertOk()
            ->assertDontSee('Datos de exportación');
    }

    public function test_show_ccf_no_muestra_el_bloque(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $ccf = $this->generar($this->borradorConLinea(TipoDte::CreditoFiscal, $cliente));

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertDontSee('Datos de exportación');
    }

    public function test_pdf_factura_consumidor_final_no_muestra_el_bloque(): void
    {
        $factura = $this->generar($this->borradorConLinea(TipoDte::Factura, null));

        $html = $this->htmlPdf($factura);

        $this->assertStringNotContainsString('Datos de exportación', $html);
    }

    public function test_imprimir_factura_consumidor_final_no_muestra_el_bloque(): void
    {
        $factura = $this->generar($this->borradorConLinea(TipoDte::Factura, null));

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.imprimir', $factura))
            ->assertOk()
            ->assertDontSee('Datos de exportación');
    }

    public function test_show_factura_consumidor_final_no_muestra_el_bloque(): void
    {
        $factura = $this->generar($this->borradorConLinea(TipoDte::Factura, null));

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $factura))
            ->assertOk()
            ->assertDontSee('Datos de exportación');
    }

    // --- Helper de presentación: null para tipos que no son FEX ---

    public function test_helper_devuelve_null_para_ccf(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $ccf = $this->generar($this->borradorConLinea(TipoDte::CreditoFiscal, $cliente));

        $this->assertNull(\App\Support\Dte\DatosExportacionPresentacion::resolver($ccf));
    }

    public function test_helper_resuelve_etiquetas_reales_para_fex(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $fex = $this->generar($this->borradorConLinea(TipoDte::FacturaExportacion, $cliente));

        $datos = \App\Support\Dte\DatosExportacionPresentacion::resolver($fex);

        $this->assertSame('Bienes', $datos['tipo_item']);
        $this->assertSame('01 — Terrestre San Bartolo', $datos['recinto_fiscal']);
        $this->assertSame('EX-1 — Exportación Definitiva', $datos['tipo_regimen']);
        $this->assertSame('1000.000 — Exportación Definitiva, Régimen Común', $datos['regimen']);
        $this->assertSame('09 — FOB-Libre a bordo', $datos['incoterm']);
    }
}
