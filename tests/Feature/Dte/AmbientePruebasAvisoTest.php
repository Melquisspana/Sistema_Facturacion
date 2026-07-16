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
 * Advertencia "AMBIENTE DE PRUEBAS" en PDF, impresión y ficha. Debe aparecer
 * SIEMPRE que $dte->ambiente === '00', sin importar sello/estado (bug real:
 * DTE #127 y #130 quedaron aceptados con sello real de APITEST y no se
 * distinguían visualmente de un documento de producción). Para ambiente '01'
 * no debe aparecer y el resto del documento debe verse exactamente igual.
 */
class AmbientePruebasAvisoTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private const AVISO = 'AMBIENTE DE PRUEBAS';

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
            foreach (['00', '01'] as $amb) {
                Correlativo::create([
                    'tipo_dte' => $tipo, 'establecimiento_id' => $this->estab->id,
                    'punto_venta_id' => $this->pv->id, 'ambiente' => $amb,
                    'ultimo_numero' => 0, 'activo' => true,
                ]);
            }
        }
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function producto(): Producto
    {
        return Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
    }

    private function dte(TipoDte $tipo, string $ambiente, ?Cliente $cliente = null): Dte
    {
        $base = [
            'tipo_dte' => $tipo,
            'ambiente' => $ambiente,
            'cliente_id' => $cliente,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ];
        if ($tipo === TipoDte::FacturaExportacion) {
            $base += [
                'tipo_item_expor' => 1, 'recinto_fiscal' => '01',
                'tipo_regimen' => 'EX-1', 'regimen' => '1000.000', 'cod_incoterms' => '09',
            ];
        }
        $dte = $this->borradores->crearBorrador($base);
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(), cantidad: 10);
        $this->generacion->generar($dte->refresh());

        return $dte->refresh();
    }

    private function pdfHtml(Dte $dte): string
    {
        $dte->load(['cliente', 'clienteSucursal', 'lineas', 'establecimiento.empresa', 'puntoVenta', 'dteRelacionado']);
        $emisor = $dte->establecimiento?->empresa;

        return view('facturacion.pdf', compact('dte', 'emisor'))->render();
    }

    // --- Factura tipo 01, ambiente 00 ---

    public function test_factura_ambiente_00_muestra_aviso_en_pdf_imprimir_y_show(): void
    {
        $factura = $this->dte(TipoDte::Factura, '00');

        $this->assertStringContainsString(self::AVISO, $this->pdfHtml($factura));

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.imprimir', $factura))
            ->assertOk()->assertSee(self::AVISO);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $factura))
            ->assertOk()->assertSee(self::AVISO);
    }

    public function test_factura_ambiente_00_con_sello_apitest_sigue_mostrando_aviso(): void
    {
        $factura = $this->aceptarCcf($this->dte(TipoDte::Factura, '00')); // sello REAL, no mock

        $this->assertNotNull($factura->sello_recepcion);
        $this->assertStringContainsString(self::AVISO, $this->pdfHtml($factura));

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.imprimir', $factura))
            ->assertOk()->assertSee(self::AVISO);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $factura))
            ->assertOk()->assertSee(self::AVISO);
    }

    // --- FEX tipo 11, ambiente 00 ---

    public function test_fex_ambiente_00_muestra_aviso_en_pdf_imprimir_y_show(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $fex = $this->aceptarCcf($this->dte(TipoDte::FacturaExportacion, '00', $cliente));

        $this->assertStringContainsString(self::AVISO, $this->pdfHtml($fex));

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.imprimir', $fex))
            ->assertOk()->assertSee(self::AVISO);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $fex))
            ->assertOk()->assertSee(self::AVISO);
    }

    // --- Ambiente 01 (producción): NO debe aparecer, para ningún tipo ---

    public function test_ccf_ambiente_01_aceptado_no_muestra_aviso(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $ccf = $this->aceptarCcf($this->dte(TipoDte::CreditoFiscal, '01', $cliente));

        $this->assertStringNotContainsString(self::AVISO, $this->pdfHtml($ccf));

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.imprimir', $ccf))
            ->assertOk()->assertDontSee(self::AVISO);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()->assertDontSee(self::AVISO);
    }

    public function test_nota_credito_ambiente_01_no_muestra_aviso(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $ccf = $this->aceptarCcf($this->dte(TipoDte::CreditoFiscal, '01', $cliente));
        $nc = $this->borradores->crearNotaCredito($ccf); // hereda ambiente del CCF original (01)
        $this->borradores->acreditarLinea($nc, $ccf->lineas()->first(), cantidad: 4);
        $this->generacion->generar($nc->refresh());
        $nc->refresh();

        $this->assertSame('01', $nc->ambiente->value);
        $this->assertStringNotContainsString(self::AVISO, $this->pdfHtml($nc));

        $this->actingAs($this->usuario('consulta'))
            ->get(route('facturacion.imprimir', $nc))
            ->assertOk()->assertDontSee(self::AVISO);
    }

    public function test_factura_ambiente_01_no_muestra_aviso(): void
    {
        // Aunque el guard de producción bloquee la TRANSMISIÓN real de tipo 01,
        // el aviso visual depende únicamente de $dte->ambiente, no del guard.
        $factura = $this->dte(TipoDte::Factura, '01');

        $this->assertStringNotContainsString(self::AVISO, $this->pdfHtml($factura));
    }
}
