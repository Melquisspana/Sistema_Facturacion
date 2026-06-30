<?php

namespace Tests\Feature\Dte;

use App\Enums\MotivoAnulacion;
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
use App\Services\Dte\DteAnulacionService;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El partial único de Totales (facturacion.partials.totales) se reutiliza en
 * edit, show y edit-nc, con el mismo visual y labels adaptados por tipo.
 */
class DteTotalesPartialTest extends TestCase
{
    use RefreshDatabase;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(CatalogosMhSeeder::class);
        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        foreach (['01', '03', '05', '11'] as $t) {
            Correlativo::create(['tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        }

        return compact('estab', 'pv');
    }

    private function producto(float $precio = 10): Producto
    {
        return Producto::factory()->create(['precio_unitario' => $precio, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
    }

    private function borrador(TipoDte $tipo, ?Cliente $cliente, array $emisor, array $extra = []): Dte
    {
        $dte = $this->borradores->crearBorrador(array_merge([
            'tipo_dte' => $tipo,
            'cliente_id' => $cliente,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ], $extra));
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(10), cantidad: 2);

        return $dte->refresh();
    }

    private function verShow(Dte $dte)
    {
        return $this->actingAs($this->usuario('facturacion'))->get(route('facturacion.show', $dte));
    }

    public function test_ccf_show_usa_el_partial_de_totales(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->borrador(TipoDte::CreditoFiscal, Cliente::factory()->contribuyente()->create(), $emisor);
        app(DteGeneracionService::class)->generar($ccf);

        $this->verShow($ccf->refresh())->assertOk()
            ->assertSee('Resumen de ventas')
            ->assertSee('Subtotal bruto')
            ->assertSee('Base gravada neta')
            ->assertSee('IVA 13%')
            ->assertSee('Total a pagar');
    }

    public function test_ccf_edit_usa_el_partial_de_totales(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->borrador(TipoDte::CreditoFiscal, Cliente::factory()->contribuyente()->create(), $emisor);

        $this->actingAs($this->usuario('facturacion'))->get(route('facturacion.edit', $ccf))
            ->assertOk()
            ->assertSee('Resumen de ventas')
            ->assertSee('Subtotal bruto')
            ->assertSee('Total a pagar');
    }

    public function test_factura_show_muestra_aviso_de_iva_incluido(): void
    {
        $emisor = $this->emisor();
        $factura = $this->borrador(TipoDte::Factura, Cliente::factory()->contribuyente()->create(), $emisor);

        $this->verShow($factura)->assertOk()
            ->assertSee('el precio ya incluye IVA')
            ->assertSee('Total a pagar')
            ->assertDontSee('Retención IVA 1%');
    }

    public function test_exportacion_show_muestra_iva_cero_flete_y_seguro(): void
    {
        $emisor = $this->emisor();
        $fex = $this->borrador(TipoDte::FacturaExportacion, Cliente::factory()->exportacion()->create(), $emisor);

        $this->verShow($fex)->assertOk()
            ->assertSee('Exportación con IVA 0%')
            ->assertSee('Flete')
            ->assertSee('Seguro')
            ->assertSee('Total a pagar');
    }

    public function test_nota_credito_show_muestra_total_a_acreditar_relacionado_y_orden(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['requiere_orden_compra' => true]);
        $ccf = $this->borrador(TipoDte::CreditoFiscal, $cliente, $emisor, ['numero_orden_compra' => 'OC-555']);
        app(DteGeneracionService::class)->generar($ccf);
        $ccf = $this->aceptarCcf($ccf->refresh()); // la NC exige un CCF ACEPTADO por Hacienda

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'pronto_pago', 'motivo' => 'Ajuste']);

        $this->verShow($nc)->assertOk()
            ->assertSee('Total a acreditar')
            ->assertDontSee('Total a pagar')
            ->assertSee($ccf->numero_interno)   // documento relacionado
            ->assertSee('OC-555');              // orden de compra vinculada
    }

    public function test_documento_anulado_muestra_totales_y_badge(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->borrador(TipoDte::CreditoFiscal, Cliente::factory()->contribuyente()->create(), $emisor);
        app(DteGeneracionService::class)->generar($ccf);
        app(DteAnulacionService::class)->anular($ccf->refresh(), MotivoAnulacion::cases()[0], 'Prueba', $this->usuario('administrador'));

        $this->verShow($ccf->refresh())->assertOk()
            ->assertSee('Total a pagar')                                  // sigue mostrando montos
            ->assertSee('Documento anulado / invalidado internamente');   // badge
    }
}
