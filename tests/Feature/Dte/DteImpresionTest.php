<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DteImpresionTest extends TestCase
{
    use \Tests\Concerns\RepresentacionPdfDte;
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    private DteGeneracionService $generacion;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
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

    public function test_imprimir_ccf_generado(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja S.A. de C.V.']);
        $ccf = $this->generar($this->borradorConLinea(TipoDte::CreditoFiscal, $cliente));

        $this->assertImprimeElPdf($ccf, $this->usuario('facturacion'));

        $html = $this->htmlDelPdf($ccf);
        $this->assertStringContainsString('Documento PRELIMINAR', $html);
        $this->assertStringContainsString('Calleja S.A. de C.V.', $html);
        $this->assertStringContainsString('Dulce de leche artesanal', $html);
        $this->assertStringContainsString('113.00', $html); // total con IVA
        // Nota exclusiva de la Factura de consumidor final.
        $this->assertStringNotContainsString('Precios con IVA incluido.', $html);
    }

    public function test_imprimir_factura_generada(): void
    {
        $factura = $this->generar($this->borradorConLinea(TipoDte::Factura, null));

        $this->assertImprimeElPdf($factura, $this->usuario('facturacion'));

        $html = $this->htmlDelPdf($factura);
        $this->assertStringContainsString('Factura', $html);
        $this->assertStringContainsString('Consumidor final', $html);
        $this->assertStringContainsString('Consumidor final sin identificar.', $html);
        $this->assertStringContainsString('Precios con IVA incluido.', $html);
    }

    public function test_imprimir_exportacion_generada(): void
    {
        $cliente = Cliente::factory()->exportacion()->create(['nombre' => 'Sweet Imports LLC']);
        $fex = $this->generar($this->borradorConLinea(TipoDte::FacturaExportacion, $cliente));

        $this->assertImprimeElPdf($fex, $this->usuario('facturacion'));
        $this->assertStringContainsString('Sweet Imports LLC', $this->htmlDelPdf($fex));
    }

    public function test_imprimir_nota_credito_generada(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $ccf = $this->aceptarCcf($this->generar($this->borradorConLinea(TipoDte::CreditoFiscal, $cliente))); // la NC exige CCF aceptado
        $nc = $this->borradores->crearNotaCredito($ccf);
        $this->borradores->acreditarLinea($nc, $ccf->lineas()->first(), cantidad: 4);
        $nc = $this->generar($nc);

        $this->assertImprimeElPdf($nc, $this->usuario('jefatura'));
        // Documento original relacionado, por su N° oficial del MH.
        $this->assertStringContainsString($ccf->numero_control, $this->htmlDelPdf($nc));
    }

    public function test_invitado_no_puede_imprimir(): void
    {
        $ccf = $this->generar($this->borradorConLinea(TipoDte::CreditoFiscal, Cliente::factory()->contribuyente()->create()));

        $this->get(route('facturacion.imprimir', $ccf))->assertRedirect('/login');
    }

    public function test_borrador_muestra_marca_de_borrador(): void
    {
        $ccf = $this->borradorConLinea(TipoDte::CreditoFiscal, Cliente::factory()->contribuyente()->create());

        $this->assertImprimeElPdf($ccf, $this->usuario('facturacion'));
        $this->assertStringContainsString('BORRADOR', $this->htmlDelPdf($ccf));
    }

    public function test_imprimir_muestra_departamento_municipio_distrito(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $olocuilta = \App\Models\Distrito::where('nombre', 'Olocuilta')->firstOrFail();
        $sucursal = \App\Models\ClienteSucursal::create([
            'cliente_id' => $cliente->id,
            'nombre' => 'Súper Selectos Olocuilta',
            'departamento_id' => $olocuilta->departamento_id,
            // El receptor del CCF toma el municipio (CAT-013) de la sala: sin él, el
            // JSON saldría con el campo vacío. La etiqueta impresa sigue viniendo del
            // distrito (municipio 2024), así que las aserciones no cambian.
            'municipio_id' => \App\Models\Municipio::where('departamento_id', $olocuilta->departamento_id)
                ->where('nombre', 'Olocuilta')->firstOrFail()->id,
            'distrito_id' => $olocuilta->id,
            // El receptor del JSON usa la sala: el schema exige complemento no vacío.
            'direccion' => 'Km 30 Carretera a Olocuilta',
            'activo' => true,
        ]);

        $ccf = $this->generar($this->borradorConLinea(
            TipoDte::CreditoFiscal, $cliente, ['cliente_sucursal_id' => $sucursal->id],
        ));

        $this->assertImprimeElPdf($ccf, $this->usuario('facturacion'));

        $html = $this->htmlDelPdf($ccf);
        $this->assertStringContainsString('Departamento: La Paz', $html);
        $this->assertStringContainsString('Municipio: La Paz Oeste', $html);
        $this->assertStringContainsString('Distrito: Olocuilta', $html);
    }
}
