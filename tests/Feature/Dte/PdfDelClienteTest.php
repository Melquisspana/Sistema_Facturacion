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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\PreparaEmisorDte;
use Tests\Concerns\RepresentacionPdfDte;
use Tests\TestCase;

/**
 * Lo que ve el cliente en la representación gráfica, revisado con la primera Factura (01)
 * real de la tienda web:
 *
 *   · la columna «Código» sigue con el código de barras, en todos los tipos; el código
 *     interno solo de respaldo si no hay barras, y «—» si no hay ninguno. Decisión de
 *     Melqui (19/11/2026): en Calleja comparan las barras con la orden de compra;
 *   · el «Estado técnico (interno)» no sale en el PDF que llega al cliente; solo en la
 *     copia de la oficina (ver, imprimir y descargar desde Facturación);
 *   · sin descuento no se imprime «Descuento global -$0.00».
 */
class PdfDelClienteTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;
    use RepresentacionPdfDte;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedCatalogosDte();

        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        foreach (['01', '03', '11'] as $tipo) {
            Correlativo::create([
                'tipo_dte' => $tipo, 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
            ]);
        }
    }

    public static function tipos(): array
    {
        return [
            'Factura' => [TipoDte::Factura],
            'CCF' => [TipoDte::CreditoFiscal],
            'Exportación' => [TipoDte::FacturaExportacion],
        ];
    }

    private function generado(TipoDte $tipo, ?Cliente $cliente = null): Dte
    {
        $cliente ??= match ($tipo) {
            TipoDte::Factura => null,
            TipoDte::FacturaExportacion => Cliente::factory()->exportacion()->create(),
            default => Cliente::factory()->contribuyente()->create(),
        };
        $datos = [
            'tipo_dte' => $tipo, 'cliente_id' => $cliente?->id,
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
        ];
        if ($tipo === TipoDte::FacturaExportacion) {
            $datos += ['tipo_item_expor' => 1, 'recinto_fiscal' => '01', 'tipo_regimen' => 'EX-1', 'regimen' => '1000.000', 'cod_incoterms' => '09'];
        }
        $producto = Producto::factory()->create([
            'codigo' => '990001', 'codigo_barra' => '7400000000017',
            'precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);

        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador($datos);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 3);
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    /** HTML de la plantilla que rindió de verdad la ruta (la respuesta es un PDF binario). */
    private function htmlDeLaRuta(string $ruta, Dte $dte): string
    {
        $vistas = [];
        View::composer('facturacion.pdf', function ($vista) use (&$vistas) {
            $vistas[] = $vista;
        });

        $this->get(route($ruta, $dte))->assertOk();
        $this->assertCount(1, $vistas, "La ruta {$ruta} no rindió la plantilla del PDF.");

        return $vistas[0]->render();
    }

    #[DataProvider('tipos')]
    public function test_la_columna_codigo_muestra_el_codigo_de_barras(TipoDte $tipo): void
    {
        $dte = $this->generado($tipo);
        $json = json_decode(Storage::disk('local')->get($dte->json_generado_path), true);
        $this->assertSame('990001', $json['cuerpoDocumento'][0]['codigo'], 'El JSON lleva el código del producto.');

        $html = $this->htmlDelPdf($dte);

        $this->assertStringContainsString('<span class="cod">7400000000017</span>', $html);
        // Un solo código por línea: con barras no se imprime también el interno.
        $this->assertStringNotContainsString('<span class="cod">990001</span>', $html);
    }

    public function test_sin_barras_la_columna_muestra_el_codigo_interno_de_respaldo(): void
    {
        $dte = $this->generado(TipoDte::Factura);
        $dte->lineas()->update(['codigo_barra' => null]);

        $html = $this->htmlDelPdf($dte->refresh());

        $this->assertStringContainsString('<span class="cod">990001</span>', $html);
        $this->assertStringNotContainsString('7400000000017', $html);
    }

    public function test_sin_barras_ni_codigo_la_columna_muestra_una_raya(): void
    {
        $dte = $this->generado(TipoDte::Factura);
        $dte->lineas()->update(['codigo_barra' => null, 'codigo' => null]);

        $html = $this->htmlDelPdf($dte->refresh());

        $this->assertStringNotContainsString('class="cod"', $html);
        $this->assertMatchesRegularExpression('#<td class="ci">1</td>\s*<td>\s*<span class="dash">—</span>\s*</td>#u', $html);
    }

    #[DataProvider('tipos')]
    public function test_el_pdf_del_cliente_no_lleva_el_estado_tecnico_interno(TipoDte $tipo): void
    {
        $html = $this->htmlDelPdf($this->generado($tipo));

        $this->assertStringNotContainsString('Estado técnico (interno):', $html);
        $this->assertStringNotContainsString('class="tech"', $html);
        $this->assertStringNotContainsString('JSON generado:', $html);
        $this->assertStringNotContainsString('Firmado localmente:', $html);
    }

    public function test_la_copia_de_la_oficina_conserva_el_estado_tecnico(): void
    {
        $dte = $this->generado(TipoDte::Factura);
        $this->actingAs(User::factory()->create()->assignRole('facturacion'));

        foreach (['facturacion.pdf', 'facturacion.imprimir', 'facturacion.pdf.descargar'] as $ruta) {
            $html = $this->htmlDeLaRuta($ruta, $dte);

            $this->assertStringContainsString('Estado técnico (interno):', $html, "Falta en {$ruta}.");
            $this->assertStringContainsString('JSON generado: <strong>sí</strong>', $html, "Falta en {$ruta}.");
        }
    }

    #[DataProvider('tipos')]
    public function test_sin_descuento_no_imprime_descuento_global_en_cero(TipoDte $tipo): void
    {
        $dte = $this->generado($tipo);
        $this->assertSame(0.0, (float) $dte->total_descuento, 'El documento de prueba no debe tener descuento.');

        $html = $this->htmlDelPdf($dte);

        $this->assertStringNotContainsString('Descuento global', $html);
        $this->assertStringNotContainsString('-$0.00', $html);
    }

    public function test_con_descuento_si_imprime_el_descuento_global(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create(['descuento_global_default' => 5]);
        $dte = $this->generado(TipoDte::CreditoFiscal, $cliente);
        $this->assertGreaterThan(0, (float) $dte->total_descuento);

        $html = $this->htmlDelPdf($dte);

        $this->assertStringContainsString('Descuento global (5%)', $html);
        $this->assertStringContainsString('-$'.number_format((float) $dte->total_descuento, 2), $html);
    }
}
