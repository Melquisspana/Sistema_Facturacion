<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
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

class DteNotaCreditoIndependienteTest extends TestCase
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
        foreach (['03', '05'] as $t) {
            Correlativo::create(['tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        }

        return compact('estab', 'pv');
    }

    private function ccfGenerado(array $emisor, ?Cliente $cliente = null): Dte
    {
        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente ?? Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);
        app(DteGeneracionService::class)->generar($dte);

        // La NC exige un CCF ACEPTADO por Hacienda (regla de negocio).
        return $this->aceptarCcf($dte);
    }

    public function test_existe_boton_nueva_nota_de_credito(): void
    {
        $this->emisor();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.index'))
            ->assertOk()
            ->assertSee('Nueva nota de crédito');
    }

    public function test_se_puede_crear_nc_pronto_pago_desde_ccf_aceptado(): void
    {
        // Regla obligatoria: pronto pago (como toda NC) requiere un CCF aceptado relacionado.
        $emisor = $this->emisor();
        $ccf = $this->ccfGenerado($emisor);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-nota-credito'), [
                'tipo' => 'pronto_pago',
                'dte_relacionado_id' => $ccf->id,
                'establecimiento_id' => $emisor['estab']->id,
                'punto_venta_id' => $emisor['pv']->id,
                'motivo' => 'Pronto pago',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', [
            'tipo_dte' => '05', 'estado' => 'borrador', 'tipo_nota_credito' => 'pronto_pago',
            'cliente_id' => $ccf->cliente_id, 'dte_relacionado_id' => $ccf->id,
        ]);
    }

    public function test_nc_pronto_pago_sin_ccf_relacionado_falla(): void
    {
        // Sin CCF aceptado relacionado no se puede crear NINGUNA NC (ni pronto pago).
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-nota-credito'), [
                'tipo' => 'pronto_pago',
                'cliente_id' => $cliente->id,
                'establecimiento_id' => $estab->id,
                'punto_venta_id' => $pv->id,
                'motivo' => 'Pronto pago',
            ])
            ->assertSessionHasErrors('dte_relacionado_id');

        $this->assertDatabaseMissing('dtes', ['tipo_dte' => '05']);
    }

    public function test_se_puede_seleccionar_ccf_relacionado_manualmente(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfGenerado($emisor);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-nota-credito'), [
                'tipo' => 'devolucion_producto',
                'dte_relacionado_id' => $ccf->id,
                'establecimiento_id' => $emisor['estab']->id,
                'punto_venta_id' => $emisor['pv']->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', [
            'tipo_dte' => '05', 'dte_relacionado_id' => $ccf->id, 'cliente_id' => $ccf->cliente_id,
        ]);
    }

    public function test_nc_independiente_sin_cliente_ni_ccf_falla(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-nota-credito'), [
                'tipo' => 'pronto_pago',
                'establecimiento_id' => $estab->id,
                'punto_venta_id' => $pv->id,
            ])
            ->assertSessionHasErrors('cliente_id');

        $this->assertDatabaseCount('dtes', 0);
    }

    public function test_crear_nc_desde_ccf_preselecciona_el_ccf(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfGenerado($emisor);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-nota-credito', ['ccf' => $ccf->id]))
            ->assertOk()
            ->assertSee($ccf->numero_interno)        // CCF aparece como opción/preseleccionado
            ->assertSee('value="'.$ccf->id.'"', false);
    }

    public function test_listado_nc_excluye_ccf_mock_y_muestra_solo_reales(): void
    {
        $emisor = $this->emisor();
        $real = $this->ccfGenerado($emisor); // aceptado REAL (sello real + fecha_procesamiento_mh)

        // CCF aceptado solo localmente / MOCK: sello "MOCK-…" y sin fecha_procesamiento_mh.
        $mock = $this->ccfGenerado($emisor);
        $mock->sello_recepcion = 'MOCK-SIMULADO-XYZ';
        $mock->fecha_procesamiento_mh = null;
        $mock->save();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-nota-credito'))
            ->assertOk()
            ->assertSee($real->numero_interno)       // CCF real disponible
            ->assertDontSee($mock->numero_interno);  // CCF mock NO se ofrece
    }

    public function test_consulta_no_puede_crear_nc_independiente(): void
    {
        $this->actingAs($this->usuario('consulta'))
            ->get(route('facturacion.create-nota-credito'))
            ->assertForbidden();
    }

    // --- Salas por documento + orden de compra vinculada ---

    private function ccfConOrden(array $emisor, string $oc, ?Cliente $cliente = null, ?\App\Models\ClienteSucursal $sucursal = null): Dte
    {
        $cliente ??= Cliente::factory()->contribuyente()->create();
        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente,
            'cliente_sucursal_id' => $sucursal?->id,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
            'numero_orden_compra' => $oc,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);
        app(DteGeneracionService::class)->generar($dte);

        // La NC exige un CCF ACEPTADO por Hacienda (regla de negocio).
        return $this->aceptarCcf($dte);
    }

    public function test_formulario_nc_es_similar_a_ccf(): void
    {
        $this->emisor();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-nota-credito'))
            ->assertOk()
            ->assertSee('Cliente (contribuyente) / sala')
            ->assertSee('Establecimiento emisor')
            ->assertSee('Punto de venta emisor')
            ->assertSee('Tipo de nota de crédito')
            ->assertSee('CCF relacionado');
    }

    public function test_ccf_no_permite_oficina_central(): void
    {
        // El CCF no admite salas Oficina Central (permite_ccf=false). La NC ya no elige sala
        // propia: hereda la del CCF relacionado (regla obligatoria de CCF aceptado).
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $oficina = \App\Models\ClienteSucursal::factory()->create([
            'cliente_id' => $cliente->id, 'nombre' => 'Oficina Central',
            'permite_ccf' => false, 'permite_nota_credito' => true,
        ]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-ccf'), [
                'tipo_dte' => '03', 'cliente_id' => $cliente->id, 'cliente_sucursal_id' => $oficina->id,
                'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            ])
            ->assertSessionHasErrors('cliente_sucursal_id');
        $this->assertDatabaseMissing('dtes', ['tipo_dte' => '03']);
    }

    public function test_nc_vinculada_a_ccf_copia_orden_de_compra(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfConOrden($emisor, 'OC-2026-001');

        // Desde CCF (flujo del show).
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.nota-credito.store', $ccf), ['tipo' => 'devolucion_producto'])
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', ['tipo_dte' => '05', 'dte_relacionado_id' => $ccf->id, 'numero_orden_compra' => 'OC-2026-001']);
    }

    public function test_nc_independiente_con_ccf_copia_orden_y_no_la_puede_forzar(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfConOrden($emisor, 'OC-REAL');

        // El request intenta forzar otra orden distinta a la del CCF.
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-nota-credito'), [
                'tipo' => 'devolucion_producto', 'dte_relacionado_id' => $ccf->id,
                'establecimiento_id' => $emisor['estab']->id, 'punto_venta_id' => $emisor['pv']->id,
                'numero_orden_compra' => 'OC-FALSA',
            ])
            ->assertRedirect();

        $nc = Dte::where('tipo_dte', '05')->latest('id')->firstOrFail();
        $this->assertSame('OC-REAL', $nc->numero_orden_compra); // copiada del CCF, no la forzada
    }

    public function test_nc_sin_orden_en_ccf_no_guarda_orden(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfGenerado($emisor); // sin orden de compra

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'devolucion_producto']);

        $this->assertNull($nc->numero_orden_compra);
    }

    public function test_devolucion_independiente_exige_ccf_relacionado(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-nota-credito'), [
                'tipo' => 'devolucion_producto', 'cliente_id' => $cliente->id,
                'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            ])
            ->assertSessionHasErrors('dte_relacionado_id');
    }

    public function test_impresion_nc_muestra_sala_tipo_motivo_y_orden(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $sucursal = \App\Models\ClienteSucursal::factory()->create(['cliente_id' => $cliente->id, 'nombre' => 'Selectos Santa Rosa']);
        $ccf = $this->ccfConOrden($emisor, 'OC-IMPRESA', $cliente, $sucursal);

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'pronto_pago', 'motivo' => 'Pronto pago Calleja']);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.imprimir', $nc))
            ->assertOk()
            ->assertSee('Selectos Santa Rosa')   // sala
            ->assertSee('Pronto pago')            // tipo
            ->assertSee('Pronto pago Calleja')    // motivo
            ->assertSee('OC-IMPRESA');            // orden de compra vinculada
    }
}
