<?php

namespace Tests\Feature\Dte;

use App\Enums\MotivoAnulacion;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoNotaCredito;
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
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
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
        $base = [
            'tipo_dte' => $tipo,
            'cliente_id' => $cliente,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
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
            ->assertSee('OC-555')               // orden de compra vinculada
            ->assertDontSee('Retención IVA 1%'); // la NC por monto nunca retiene
    }

    /**
     * CCF ACEPTADO con 5 % de descuento global: es el documento del que la NC HEREDA el
     * criterio de retención. Con $agenteRetencion = false el cliente no es sujeto de
     * retención, así que el CCF legítimamente no retiene y la NC tampoco puede inventarla.
     */
    private function ccfAceptado(float $precio, bool $agenteRetencion = true): Dte
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create([
            'es_agente_retencion' => $agenteRetencion,
            'descuento_global_default' => 5,
        ]);

        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);
        $this->borradores->agregarLineaDesdeProducto($ccf, $this->producto($precio), cantidad: 1);
        app(DteGeneracionService::class)->generar($ccf);

        return $this->aceptarCcf($ccf->refresh());
    }

    /** NC por avería sobre ese CCF, con una sola línea del precio indicado. */
    private function ncAveria(Dte $ccf, float $precio): Dte
    {
        $nc = $this->borradores->crearNotaCredito($ccf, [
            'tipo' => TipoNotaCredito::Averia->value,
            'motivo' => 'Producto averiado',
        ]);
        $this->borradores->agregarLineaDesdeProducto($nc, $this->producto($precio), cantidad: 1);

        return $nc->refresh();
    }

    /**
     * CASO A: la NC retiene de verdad, así que la fila debe verse. Antes el partial
     * restaba la retención del total pero nunca imprimía la fila (la mostraba solo en
     * CCF), y en pantalla el total parecía no cuadrar.
     */
    public function test_nota_credito_con_retencion_muestra_la_fila_de_retencion(): void
    {
        $ccf = $this->ccfAceptado(112.25);
        $this->assertTrue((bool) $ccf->aplica_retencion_iva, 'El CCF original debe haber retenido.');

        $nc = $this->ncAveria($ccf, 112.25);

        // El dato viene del backend; la vista solo lo imprime.
        $this->assertTrue((bool) $nc->aplica_retencion_iva);
        $this->assertSame('1.07', (string) $nc->iva_retenido);
        $this->assertSame('119.43', (string) $nc->total_pagar);

        $this->verShow($nc)->assertOk()
            ->assertSee('Descuento aplicado')
            ->assertSee('-$5.61')            // descuento 5 %
            ->assertSee('$106.64')           // base gravada neta
            ->assertSee('$13.86')            // IVA 13 %
            ->assertSee('Retención IVA 1%')
            ->assertSee('-$1.07')            // retención realmente aplicada
            ->assertSee('Total a acreditar')
            ->assertSee('$119.43');

        // Las cifras mostradas explican por completo el total: 106.64 + 13.86 − 1.07.
        $this->assertSame(
            119.43,
            round(106.64 + 13.86 - (float) $nc->iva_retenido, 2)
        );
    }

    /**
     * CASO B: el CCF original NO retuvo (cliente que no es agente de retención), así que
     * la NC tampoco retiene. La fila debe OMITIRSE por completo: nunca se imprime un
     * "Retención IVA 1% $0.00" ficticio. Mismas cifras del CASO A para que el contraste
     * sea exacto: lo único que cambia es la retención.
     */
    public function test_nota_credito_sin_retencion_no_muestra_la_fila(): void
    {
        $ccf = $this->ccfAceptado(112.25, agenteRetencion: false);
        $this->assertFalse((bool) $ccf->aplica_retencion_iva, 'El CCF original no debe retener.');
        $this->assertSame('0.00', (string) $ccf->iva_retenido);

        $nc = $this->ncAveria($ccf, 112.25);

        // 112.25 − 5 % = 106.64 de base neta → IVA 13.86 → total 120.50, SIN retención.
        $this->assertFalse((bool) $nc->aplica_retencion_iva);
        $this->assertSame('0.00', (string) $nc->iva_retenido);
        $this->assertSame('120.50', (string) $nc->total_pagar);

        $this->verShow($nc)->assertOk()
            ->assertSee('-$5.61')            // descuento 5 %
            ->assertSee('$106.64')           // base gravada neta
            ->assertSee('$13.86')            // IVA 13 %
            ->assertSee('Total a acreditar')
            ->assertSee('$120.50')
            ->assertDontSee('Retención IVA 1%');

        // Sin retención el total es exactamente base + IVA.
        $this->assertSame(120.50, round(106.64 + 13.86, 2));
    }

    /**
     * La regla es HERENCIA del CCF, no un umbral por NC: una devolución parcial pequeña
     * sobre un CCF que sí retuvo debe seguir reversando su parte proporcional, y la fila
     * debe verse aunque la NC individual quede por debajo de los $100.
     */
    public function test_nota_credito_parcial_pequena_hereda_la_retencion_del_ccf(): void
    {
        $ccf = $this->ccfAceptado(112.25);
        $nc = $this->ncAveria($ccf, 20);

        // 20.00 − 5 % = 19.00 de base neta (bajo el umbral, pero el CCF ya lo superó).
        $this->assertTrue((bool) $nc->aplica_retencion_iva);
        $this->assertSame('0.19', (string) $nc->iva_retenido);

        $this->verShow($nc)->assertOk()
            ->assertSee('Retención IVA 1%')
            ->assertSee('-$0.19');
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
