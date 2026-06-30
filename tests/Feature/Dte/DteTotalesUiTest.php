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
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Presentación de la sección "Totales" del borrador (3 bloques: Ventas ·
 * Descuentos e impuestos · Total final). Solo UI: no toca cálculos.
 */
class DteTotalesUiTest extends TestCase
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

    private function producto(float $precio): Producto
    {
        return Producto::factory()->create(['precio_unitario' => $precio, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
    }

    private function borrador(TipoDte $tipo, ?Cliente $cliente, array $emisor): Dte
    {
        return $this->borradores->crearBorrador([
            'tipo_dte' => $tipo,
            'cliente_id' => $cliente,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);
    }

    private function ver(Dte $dte)
    {
        return $this->actingAs($this->usuario('facturacion'))->get(route('facturacion.edit', $dte));
    }

    // --- Etiquetas y bloques nuevos ---

    public function test_totales_ccf_muestran_bloques_y_etiquetas_claras(): void
    {
        $emisor = $this->emisor();
        $dte = $this->borrador(TipoDte::CreditoFiscal, Cliente::factory()->contribuyente()->create(), $emisor);
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(1.50), cantidad: 1);

        $this->ver($dte->refresh())
            ->assertOk()
            ->assertSee('Resumen de ventas')
            ->assertSee('Venta gravada')
            ->assertSee('Subtotal bruto')
            ->assertSee('Descuento aplicado')
            ->assertSee('Base gravada neta')
            ->assertSee('IVA 13%')
            ->assertSee('Total a pagar');
    }

    public function test_totales_muestran_porcentaje_de_descuento_si_existe(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['descuento_global_default' => 5]);
        $dte = $this->borrador(TipoDte::CreditoFiscal, $cliente, $emisor);
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(1.50), cantidad: 1);

        $this->ver($dte->refresh())
            ->assertOk()
            ->assertSee('Descuento aplicado')
            ->assertSee('(5%)'); // porcentaje visible
    }

    public function test_ccf_retencion_no_aplica_muestra_mensaje_gris(): void
    {
        $emisor = $this->emisor();
        // Agente de retención pero base pequeña (≤ 100) → no aplica.
        $cliente = Cliente::factory()->contribuyente()->create(['es_agente_retencion' => true]);
        $dte = $this->borrador(TipoDte::CreditoFiscal, $cliente, $emisor);
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(1.50), cantidad: 1); // base 1.50

        $this->ver($dte->refresh())
            ->assertOk()
            ->assertSee('Retención IVA 1%')
            ->assertSee('No aplica: la base gravada neta no supera $100.00');
    }

    public function test_ccf_retencion_aplica_muestra_retencion_1pct(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['es_agente_retencion' => true]);
        $dte = $this->borrador(TipoDte::CreditoFiscal, $cliente, $emisor);
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(10.00), cantidad: 11); // base 110 > 100

        $this->ver($dte->refresh())
            ->assertOk()
            ->assertSee('Retención IVA 1%')
            ->assertDontSee('No aplica: la base gravada neta no supera');
    }

    public function test_factura_01_muestra_nota_de_iva_incluido(): void
    {
        $emisor = $this->emisor();
        $dte = $this->borrador(TipoDte::Factura, Cliente::factory()->contribuyente()->create(), $emisor);
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(1.13), cantidad: 1);

        $this->ver($dte->refresh())
            ->assertOk()
            ->assertSee('el precio ya incluye IVA')
            ->assertSee('Subtotal bruto')
            ->assertSee('Total a pagar');
    }

    public function test_exportacion_muestra_flete_seguro_e_iva_cero(): void
    {
        $emisor = $this->emisor();
        $dte = $this->borrador(TipoDte::FacturaExportacion, Cliente::factory()->exportacion()->create(), $emisor);
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(5.00), cantidad: 2);

        $this->ver($dte->refresh())
            ->assertOk()
            ->assertSee('Venta exportación')
            ->assertSee('Flete')
            ->assertSee('Seguro')
            ->assertSee('0% — $0.00')      // IVA 0%
            ->assertSee('Total a pagar');
    }

    public function test_nota_credito_muestra_total_a_acreditar(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();

        // Toda NC requiere un CCF aceptado relacionado.
        $ccf = $this->borrador(TipoDte::CreditoFiscal, $cliente, $emisor);
        $this->borradores->agregarLineaDesdeProducto($ccf, $this->producto(10), cantidad: 2);
        app(\App\Services\Dte\DteGeneracionService::class)->generar($ccf);
        $ccf = $this->aceptarCcf($ccf->refresh());

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-nota-credito'), [
                'tipo' => 'pronto_pago',
                'dte_relacionado_id' => $ccf->id,
                'establecimiento_id' => $emisor['estab']->id,
                'punto_venta_id' => $emisor['pv']->id,
                'motivo' => 'Pronto pago',
            ])->assertRedirect();

        $nc = Dte::where('tipo_dte', '05')->firstOrFail();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.edit', $nc))
            ->assertOk()
            ->assertSee('Total a acreditar')
            ->assertDontSee('Total a pagar');
    }
}
