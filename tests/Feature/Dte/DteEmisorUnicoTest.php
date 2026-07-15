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
 * Resolución automática del EMISOR (establecimiento/punto de venta) cuando solo hay
 * UNA opción activa, transversal a los 4 formularios de creación (CCF, Factura
 * consumidor final, Nota de crédito, Exportación). Cubre tanto la UI (selects
 * ocultos) como el backend (ResuelveEmisorUnico vía CrearBorradorRequest::
 * prepareForValidation() y, para NC independiente que no usa ese FormRequest,
 * la misma resolución aplicada directamente en el controller). Si hay más de una
 * opción y no se envía valor, debe seguir fallando (sin auto-resolver ambigüedad).
 */
class DteEmisorUnicoTest extends TestCase
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

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisorUnico(): array
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        foreach (['01', '03', '05', '11'] as $t) {
            Correlativo::create(['tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        }

        return compact('estab', 'pv');
    }

    /** Dos establecimientos activos (cada uno con su propio PV): ambigüedad real. */
    private function emisorAmbiguo(): void
    {
        $this->crearEmisorDte('M001', 'P001');
        $this->crearEmisorDte('M002', 'P002');
    }

    private function ccfAceptado(array $emisor): Dte
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id,
            'establecimiento_id' => $emisor['estab']->id, 'punto_venta_id' => $emisor['pv']->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);
        app(DteGeneracionService::class)->generar($dte);

        return $this->aceptarCcf($dte->refresh());
    }

    // --- 1. CCF ---

    public function test_ccf_con_emisor_unico_no_exige_selects_y_crea_correctamente(): void
    {
        $emisor = $this->emisorUnico();
        $cliente = Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-ccf'))
            ->assertOk()
            ->assertDontSee('Establecimiento emisor')
            ->assertDontSee('Punto de venta emisor');

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-ccf'), ['tipo_dte' => '03', 'cliente_id' => $cliente->id])
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect();

        $dte = Dte::where('tipo_dte', '03')->latest('id')->firstOrFail();
        $this->assertSame($emisor['estab']->id, $dte->establecimiento_id);
        $this->assertSame($emisor['pv']->id, $dte->punto_venta_id);
    }

    // --- 2. Factura consumidor final ---

    public function test_factura_con_emisor_unico_no_exige_selects_y_crea_correctamente(): void
    {
        $emisor = $this->emisorUnico();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-factura'))
            ->assertOk()
            ->assertDontSee('Establecimiento emisor')
            ->assertDontSee('Punto de venta emisor');

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-factura'), ['tipo_dte' => '01'])
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect();

        $dte = Dte::where('tipo_dte', '01')->latest('id')->firstOrFail();
        $this->assertSame($emisor['estab']->id, $dte->establecimiento_id);
        $this->assertSame($emisor['pv']->id, $dte->punto_venta_id);
    }

    // --- 3. Nota de crédito (independiente, vía CCF aceptado) ---

    public function test_nc_con_emisor_unico_no_exige_selects_y_crea_correctamente(): void
    {
        $emisor = $this->emisorUnico();
        $ccf = $this->ccfAceptado($emisor);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-nota-credito'))
            ->assertOk()
            ->assertDontSee('Establecimiento emisor')
            ->assertDontSee('Punto de venta emisor');

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-nota-credito'), [
                'tipo' => 'pronto_pago',
                'dte_relacionado_id' => $ccf->id,
            ])
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect();

        $nc = Dte::where('tipo_dte', '05')->latest('id')->firstOrFail();
        $this->assertSame($emisor['estab']->id, $nc->establecimiento_id);
        $this->assertSame($emisor['pv']->id, $nc->punto_venta_id);
    }

    // --- 4. Factura de exportación (sigue en revisión; solo se prueba creación del borrador) ---

    public function test_exportacion_con_emisor_unico_no_exige_selects_y_crea_correctamente(): void
    {
        $emisor = $this->emisorUnico();
        $cliente = Cliente::factory()->exportacion()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-exportacion'))
            ->assertOk()
            ->assertDontSee('Establecimiento emisor')
            ->assertDontSee('Punto de venta emisor');

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-exportacion'), [
                'tipo_dte' => '11',
                'cliente_id' => $cliente->id,
                'tipo_item_expor' => 1,
                'recinto_fiscal' => '01',
                'tipo_regimen' => 'EX-1',
                'regimen' => '1000.000',
                'cod_incoterms' => '09',
            ])
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect();

        $dte = Dte::where('tipo_dte', '11')->latest('id')->firstOrFail();
        $this->assertSame($emisor['estab']->id, $dte->establecimiento_id);
        $this->assertSame($emisor['pv']->id, $dte->punto_venta_id);
    }

    // --- 5. Con más de una opción: selects visibles y obligatorios (sin auto-resolver) ---

    public function test_con_varios_establecimientos_los_selects_aparecen_y_siguen_siendo_obligatorios(): void
    {
        $this->emisorAmbiguo();
        $cliente = Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-ccf'))
            ->assertOk()
            ->assertSee('Establecimiento emisor')
            ->assertSee('Punto de venta emisor');

        // Sin enviar establecimiento_id/punto_venta_id: sigue fallando (no hay una única
        // opción real que el backend pueda resolver por sí solo).
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-ccf'), ['tipo_dte' => '03', 'cliente_id' => $cliente->id])
            ->assertSessionHasErrors(['establecimiento_id', 'punto_venta_id']);

        $this->assertDatabaseCount('dtes', 0);
    }
}
