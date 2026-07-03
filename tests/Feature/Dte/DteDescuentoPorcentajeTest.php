<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
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

/**
 * El descuento global del cliente/sucursal es un PORCENTAJE (0–100), no un monto:
 * "5" significa 5%. El monto se calcula sobre el subtotal y se prorratea; nunca
 * supera el subtotal (pct ≤ 100), así que no dispara "descuento mayor al subtotal".
 */
class DteDescuentoPorcentajeTest extends TestCase
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

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        foreach (['01', '03', '11'] as $t) {
            Correlativo::create(['tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        }

        return compact('estab', 'pv');
    }

    private function producto(float $precio, TipoImpuesto $impuesto = TipoImpuesto::Gravado): Producto
    {
        return Producto::factory()->create(['precio_unitario' => $precio, 'tipo_impuesto' => $impuesto->value]);
    }

    private function borrador(TipoDte $tipo, ?Cliente $cliente, array $emisor, ?ClienteSucursal $sucursal = null): Dte
    {
        return $this->borradores->crearBorrador([
            'tipo_dte' => $tipo,
            'cliente_id' => $cliente,
            'cliente_sucursal_id' => $sucursal?->id,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);
    }

    // --- 5 significa 5%, no $5 ---

    public function test_cliente_descuento_5_significa_5_por_ciento_sobre_subtotal_100(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['descuento_global_default' => 5]);
        $dte = $this->borrador(TipoDte::CreditoFiscal, $cliente, $emisor);

        // Subtotal gravado = 100.00 (100 × 1.00).
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(1.00), cantidad: 100);

        $dte->refresh();
        $this->assertSame('5.00', $dte->descuento_porcentaje_aplicado);
        $this->assertSame('100.00', $dte->subtotal);
        $this->assertSame('5.00', $dte->total_descuento);        // 5% de 100 = 5.00 (no $5 fijo arbitrario)
        $this->assertSame('5.00', $dte->descuento_global);       // monto calculado
        $this->assertSame('5.00', $dte->descuento_gravado);
    }

    public function test_subtotal_2_72_con_5_por_ciento_no_falla_y_descuenta_0_14(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['descuento_global_default' => 5]);
        $dte = $this->borrador(TipoDte::CreditoFiscal, $cliente, $emisor);

        // Subtotal 2.72 (2 × 1.36). Antes esto tiraba "descuento mayor al subtotal".
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(1.36), cantidad: 2);

        $dte->refresh();
        $this->assertSame('2.72', $dte->subtotal);
        // round(2.72 × 5 / 100, 2) = round(0.136, 2) = 0.14
        $this->assertSame('0.14', $dte->total_descuento);
        $this->assertSame('0.14', $dte->descuento_gravado);
    }

    public function test_descuento_porcentual_se_prorratea_entre_buckets(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['descuento_global_default' => 5]);
        $dte = $this->borrador(TipoDte::CreditoFiscal, $cliente, $emisor);

        // Gravado 60 + Exento 40 = subtotal 100; 5% = 5.00 → 3.00 / 2.00.
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(60.00, TipoImpuesto::Gravado), cantidad: 1);
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(40.00, TipoImpuesto::Exento), cantidad: 1);

        $dte->refresh();
        $this->assertSame('5.00', $dte->total_descuento);
        $this->assertSame('3.00', $dte->descuento_gravado);
        $this->assertSame('2.00', $dte->descuento_exento);
    }

    public function test_sucursal_tiene_prioridad_sobre_cliente(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['descuento_global_default' => 5]);
        $sucursal = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id, 'descuento_global_default' => 10]);
        $dte = $this->borrador(TipoDte::CreditoFiscal, $cliente, $emisor, $sucursal);

        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(1.00), cantidad: 100); // subtotal 100

        $dte->refresh();
        $this->assertSame('10.00', $dte->descuento_porcentaje_aplicado); // la sala manda (10%)
        $this->assertSame('10.00', $dte->total_descuento);
    }

    public function test_recalcula_descuento_al_eliminar_lineas(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['descuento_global_default' => 5]);
        $dte = $this->borrador(TipoDte::CreditoFiscal, $cliente, $emisor);

        $l1 = $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(1.00), cantidad: 100); // 100
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(1.00), cantidad: 100);       // +100 = 200
        $this->assertSame('10.00', $dte->refresh()->total_descuento); // 5% de 200

        $this->borradores->eliminarLinea($l1);
        $this->assertSame('5.00', $dte->refresh()->total_descuento);  // 5% de 100
    }

    // --- Factura 01 y Exportación usan el descuento porcentual ---

    public function test_factura_01_usa_descuento_porcentual(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['descuento_global_default' => 5]);
        $dte = $this->borrador(TipoDte::Factura, $cliente, $emisor);

        // Factura: precio incluye IVA. 100 × 1.13 = 113.00 subtotal con IVA.
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(1.13), cantidad: 100);

        $dte->refresh();
        $this->assertSame('5.00', $dte->descuento_porcentaje_aplicado);
        $this->assertSame('113.00', $dte->subtotal);
        $this->assertSame('5.65', $dte->total_descuento); // round(113 × 5 / 100, 2)
    }

    public function test_exportacion_usa_descuento_porcentual(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->exportacion()->create(['descuento_global_default' => 5]);
        $dte = $this->borrador(TipoDte::FacturaExportacion, $cliente, $emisor);

        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(1.00), cantidad: 100); // exportación 100

        $dte->refresh();
        $this->assertSame('5.00', $dte->descuento_porcentaje_aplicado);
        $this->assertSame('5.00', $dte->total_descuento); // 5% de la base de exportación
        $this->assertSame('95.00', $dte->total_exportacion);
    }

    // --- Retención evaluada DESPUÉS del descuento ---

    public function test_retencion_evalua_base_gravada_neta_despues_del_descuento(): void
    {
        $emisor = $this->emisor();
        // Agente de retención + 5% de descuento.
        $cliente = Cliente::factory()->contribuyente()->create([
            'es_agente_retencion' => true, 'descuento_global_default' => 5,
        ]);
        $dte = $this->borrador(TipoDte::CreditoFiscal, $cliente, $emisor);

        // Base bruta 104 > 100, pero base NETA = 104 − 5.20 = 98.80 ≤ 100 → NO retiene.
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(1.04), cantidad: 100);

        $dte->refresh();
        $this->assertSame('5.20', $dte->descuento_gravado);
        $this->assertFalse((bool) $dte->aplica_retencion_iva);
        $this->assertSame('0.00', $dte->iva_retenido);
    }

    public function test_retencion_aplica_si_base_neta_supera_umbral_tras_descuento(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create([
            'es_agente_retencion' => true, 'descuento_global_default' => 5,
        ]);
        $dte = $this->borrador(TipoDte::CreditoFiscal, $cliente, $emisor);

        // Base bruta 110; neta = 110 − 5.50 = 104.50 > 100 → retiene 1% de 104.50.
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(1.10), cantidad: 100);

        $dte->refresh();
        $this->assertSame('5.50', $dte->descuento_gravado);
        $this->assertTrue((bool) $dte->aplica_retencion_iva);
        $this->assertSame('1.05', $dte->iva_retenido); // round(104.50 × 0.01, 2)
    }

    // --- Documento generado: inmutable aunque cambie el porcentaje del cliente ---

    public function test_documento_generado_congela_descuento_aunque_cambie_el_cliente(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['descuento_global_default' => 5]);
        $dte = $this->borrador(TipoDte::CreditoFiscal, $cliente, $emisor);
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(1.00), cantidad: 100); // subtotal 100

        app(DteGeneracionService::class)->generar($dte->refresh());
        $dte->refresh();
        $this->assertSame('5.00', $dte->descuento_porcentaje_aplicado);
        $this->assertSame('5.00', $dte->descuento_global);

        // El cliente sube su porcentaje DESPUÉS: el documento generado no cambia.
        $cliente->update(['descuento_global_default' => 50]);
        $this->assertSame('5.00', $dte->refresh()->descuento_porcentaje_aplicado);
        $this->assertSame('5.00', $dte->descuento_global);
    }

    // --- Crear CCF muestra "Descuento aplicado: 5%" ---

    public function test_crear_ccf_muestra_descuento_en_porcentaje(): void
    {
        $this->emisor();
        Cliente::factory()->contribuyente()->create(['descuento_global_default' => 5]);

        $this->actingAs(User::factory()->create()->assignRole('facturacion'))
            ->get(route('facturacion.create-ccf'))
            ->assertOk()
            ->assertSee('Descuento aplicado:')
            ->assertSee("descuento + '%'", false) // se muestra como porcentaje, no como $
            ->assertDontSee("'$' + descuento", false);
    }
}
