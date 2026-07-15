<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
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
use App\Services\Dte\DteStateMachine;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DteCcfUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(CatalogosMhSeeder::class);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta, correlativo: Correlativo} */
    private function emisor(): array
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        $correlativo = Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
        ]);

        return compact('estab', 'pv', 'correlativo');
    }

    /** @param array<string, mixed> $override */
    private function datosCcf(Cliente $cliente, Establecimiento $estab, PuntoVenta $pv, array $override = []): array
    {
        return array_merge([
            'tipo_dte' => '03',
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'condicion_operacion' => 1,
            'aplica_retencion' => 0,
            'descuento_global' => 0,
        ], $override);
    }

    private function borradorCcf(Cliente $cliente, Establecimiento $estab, PuntoVenta $pv): Dte
    {
        return app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => \App\Enums\TipoDte::CreditoFiscal,
            'cliente_id' => $cliente,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);
    }

    public function test_invitado_es_redirigido_al_login(): void
    {
        $this->get(route('facturacion.index'))->assertRedirect('/login');
    }

    public function test_administrador_puede_abrir_listado(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.index'))
            ->assertOk();
    }

    public function test_facturacion_puede_crear_ccf_borrador(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-ccf'), $this->datosCcf($cliente, $estab, $pv))
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', [
            'tipo_dte' => '03',
            'estado' => 'borrador',
            'cliente_id' => $cliente->id,
        ]);
    }

    public function test_consulta_no_puede_crear_ccf(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $consulta = $this->usuario('consulta');

        $this->actingAs($consulta)->get(route('facturacion.create-ccf'))->assertForbidden();
        $this->actingAs($consulta)->post(route('facturacion.store-ccf'), $this->datosCcf($cliente, $estab, $pv))->assertForbidden();

        $this->assertDatabaseCount('dtes', 0);
    }

    public function test_ccf_con_orden_compra_requerida_falla_sin_numero(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['requiere_orden_compra' => true]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-ccf'), $this->datosCcf($cliente, $estab, $pv))
            ->assertSessionHasErrors(['numero_orden_compra' => 'Este cliente requiere número de orden de compra para emitir CCF.']);

        $this->assertDatabaseCount('dtes', 0);
    }

    public function test_ccf_con_orden_compra_requerida_pasa_con_numero(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['requiere_orden_compra' => true]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-ccf'), $this->datosCcf($cliente, $estab, $pv, ['numero_orden_compra' => 'OC-99']))
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', ['cliente_id' => $cliente->id, 'numero_orden_compra' => 'OC-99']);
    }

    public function test_cliente_sin_requerir_orden_compra_crea_ccf_sin_numero(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['requiere_orden_compra' => false]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-ccf'), $this->datosCcf($cliente, $estab, $pv))
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', ['cliente_id' => $cliente->id, 'numero_orden_compra' => null]);
    }

    public function test_numero_orden_compra_se_guarda_en_dte_no_en_cliente(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['requiere_orden_compra' => true]);
        $facturacion = $this->usuario('facturacion');

        // Cada CCF pide su propio número.
        $this->actingAs($facturacion)
            ->post(route('facturacion.store-ccf'), $this->datosCcf($cliente, $estab, $pv, ['numero_orden_compra' => 'OC-A']))
            ->assertRedirect();
        $this->actingAs($facturacion)
            ->post(route('facturacion.store-ccf'), $this->datosCcf($cliente, $estab, $pv, ['numero_orden_compra' => 'OC-B']))
            ->assertRedirect();

        // Dos DTE, cada uno con su número.
        $this->assertDatabaseHas('dtes', ['cliente_id' => $cliente->id, 'numero_orden_compra' => 'OC-A']);
        $this->assertDatabaseHas('dtes', ['cliente_id' => $cliente->id, 'numero_orden_compra' => 'OC-B']);

        // El cliente NO almacena ningún número de orden (solo la configuración).
        $cliente->refresh();
        $this->assertFalse(array_key_exists('numero_orden_compra', $cliente->getAttributes()));
        $this->assertTrue((bool) $cliente->requiere_orden_compra);
    }

    public function test_selector_de_clientes_muestra_nombre_comercial(): void
    {
        $this->emisor();
        Cliente::factory()->contribuyente()->create([
            'nombre' => 'Calleja S.A. de C.V.',
            'nombre_comercial' => 'Selectos Santa Rosa',
        ]);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-ccf'))
            ->assertOk()
            ->assertSee('Calleja')
            ->assertSee('Selectos Santa Rosa'); // permite distinguir la sala
    }

    public function test_agregar_linea_actualiza_totales(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = $this->borradorCcf($cliente, $estab, $pv);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.lineas.store', $dte), ['producto_id' => $producto->id, 'cantidad' => 10])
            ->assertRedirect();

        $dte->refresh();
        $this->assertSame('100.00', $dte->total_gravado);
        $this->assertSame('13.00', $dte->iva);
        $this->assertSame('113.00', $dte->total_pagar);
    }

    public function test_editar_linea_actualiza_totales(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = $this->borradorCcf($cliente, $estab, $pv);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $linea = app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);

        $this->actingAs($this->usuario('facturacion'))
            ->patch(route('facturacion.lineas.update', [$dte, $linea]), ['cantidad' => 5])
            ->assertRedirect();

        $dte->refresh();
        $this->assertSame('50.00', $dte->total_gravado);
        $this->assertSame('56.50', $dte->total_pagar);
    }

    public function test_eliminar_linea_actualiza_totales(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = $this->borradorCcf($cliente, $estab, $pv);
        $svc = app(DteBorradorService::class);
        $p1 = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $p2 = Producto::factory()->create(['precio_unitario' => 5, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $linea1 = $svc->agregarLineaDesdeProducto($dte, $p1, cantidad: 1);
        $svc->agregarLineaDesdeProducto($dte, $p2, cantidad: 1);

        $this->actingAs($this->usuario('facturacion'))
            ->delete(route('facturacion.lineas.destroy', [$dte, $linea1]))
            ->assertRedirect();

        $dte->refresh();
        $this->assertCount(1, $dte->lineas);
        $this->assertSame('5.00', $dte->total_gravado);
        $this->assertSame('5.65', $dte->total_pagar);
    }

    public function test_con_varios_establecimientos_los_selects_siguen_visibles_y_requeridos(): void
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab1 = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $estab2 = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M002', 'nombre' => 'Sucursal Centro', 'activo' => true]);
        PuntoVenta::create(['establecimiento_id' => $estab1->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        PuntoVenta::create(['establecimiento_id' => $estab2->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'activo' => true]);
        Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-ccf'))
            ->assertOk()
            ->assertSee('Establecimiento emisor')       // selects visibles
            ->assertSee('Punto de venta emisor')
            ->assertSee('— Seleccione —')                // placeholder del select
            ->assertSee('Casa Matriz')
            ->assertSee('Sucursal Centro');
    }

    public function test_un_establecimiento_con_varios_pv_oculta_estab_pero_muestra_pv(): void
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'activo' => true]);
        Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-ccf'))
            ->assertOk()
            ->assertDontSee('Establecimiento emisor')    // establecimiento único → auto/oculto
            ->assertSee('type="hidden" name="establecimiento_id" value="'.$estab->id.'"', false)
            ->assertSee('Punto de venta emisor')         // varios PV → visible y a elegir
            ->assertSee('Caja 1')
            ->assertSee('Caja 2');
    }

    public function test_sin_establecimiento_o_punto_venta_muestra_mensaje(): void
    {
        // No se crea emisor: sin establecimientos/puntos de venta.
        Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-ccf'))
            ->assertOk()
            ->assertSee('Falta configuración del emisor');
    }

    public function test_ccf_permite_seleccionar_sucursal(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $sucursal = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id, 'nombre' => 'Selectos Santa Rosa']);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-ccf'), $this->datosCcf($cliente, $estab, $pv, ['cliente_sucursal_id' => $sucursal->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', [
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => $sucursal->id,
        ]);
    }

    public function test_buscador_de_clientes_incluye_nombre_de_sucursal(): void
    {
        $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja S.A. de C.V.']);
        ClienteSucursal::factory()->create(['cliente_id' => $cliente->id, 'nombre' => 'Selectos Merliot']);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-ccf'))
            ->assertOk()
            ->assertSee('Calleja')
            ->assertSee('Selectos Merliot');
    }

    public function test_sucursal_que_requiere_oc_bloquea_sin_numero(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        // El cliente NO la requiere, pero la sucursal SÍ.
        $cliente = Cliente::factory()->contribuyente()->create(['requiere_orden_compra' => false]);
        $sucursal = ClienteSucursal::factory()->create([
            'cliente_id' => $cliente->id,
            'requiere_orden_compra' => true,
        ]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-ccf'), $this->datosCcf($cliente, $estab, $pv, ['cliente_sucursal_id' => $sucursal->id]))
            ->assertSessionHasErrors('numero_orden_compra');

        $this->assertDatabaseCount('dtes', 0);
    }

    public function test_sucursal_que_requiere_oc_pasa_con_numero(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['requiere_orden_compra' => false]);
        $sucursal = ClienteSucursal::factory()->create([
            'cliente_id' => $cliente->id,
            'requiere_orden_compra' => true,
        ]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-ccf'), $this->datosCcf($cliente, $estab, $pv, [
                'cliente_sucursal_id' => $sucursal->id,
                'numero_orden_compra' => 'OC-SALA-1',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', [
            'cliente_sucursal_id' => $sucursal->id,
            'numero_orden_compra' => 'OC-SALA-1',
        ]);
    }

    public function test_no_se_puede_editar_dte_no_borrador(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = $this->borradorCcf($cliente, $estab, $pv);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        app(DteStateMachine::class)->transicionar($dte, EstadoDte::Generado);

        $facturacion = $this->usuario('facturacion');
        $this->actingAs($facturacion)->get(route('facturacion.edit', $dte))->assertForbidden();
        $this->actingAs($facturacion)
            ->post(route('facturacion.lineas.store', $dte), ['producto_id' => $producto->id, 'cantidad' => 1])
            ->assertForbidden();
    }

    // --- UX: correlativo automático, descuento/condición desde cliente/sala ---

    public function test_crear_ccf_no_muestra_selector_de_correlativo(): void
    {
        $this->emisor();
        Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-ccf'))
            ->assertOk()
            ->assertDontSee('name="correlativo_id"', false)
            ->assertDontSee('name="descuento_global"', false);
    }

    public function test_emisor_unico_se_autoselecciona_y_oculta_ambos_selects(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-ccf'))
            ->assertOk()
            // Ni labels ni placeholders de select: el usuario no debe tocar estos campos.
            ->assertDontSee('Establecimiento emisor')
            ->assertDontSee('Punto de venta emisor')
            ->assertDontSee('— Seleccione —')
            ->assertSee('Emisor:')
            ->assertSee($estab->nombre)
            ->assertSee('Punto de venta:')
            ->assertSee($pv->nombre)
            // Los IDs viajan en inputs ocultos para que el backend los valide/asigne.
            ->assertSee('type="hidden" name="establecimiento_id" value="'.$estab->id.'"', false)
            ->assertSee('type="hidden" name="punto_venta_id" value="'.$pv->id.'"', false);
    }

    public function test_emisor_unico_crea_borrador_con_ids_autoseleccionados(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();

        // Simula el envío del formulario con los inputs ocultos (sin selección manual).
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-ccf'), [
                'tipo_dte' => '03',
                'cliente_id' => $cliente->id,
                'establecimiento_id' => $estab->id,
                'punto_venta_id' => $pv->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', [
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'estado' => 'borrador',
        ]);
    }

    public function test_crear_ccf_toma_descuento_default_del_cliente(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['descuento_global_default' => 5]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-ccf'), $this->datosCcf($cliente, $estab, $pv))
            ->assertRedirect();

        // El descuento del cliente es un PORCENTAJE (5 = 5%), no un monto fijo.
        $dte = Dte::where('cliente_id', $cliente->id)->firstOrFail();
        $this->assertSame('5.00', $dte->descuento_porcentaje_aplicado);
    }

    public function test_crear_ccf_toma_descuento_default_de_sucursal_si_existe(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['descuento_global_default' => 5]);
        $sucursal = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id, 'descuento_global_default' => 10]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-ccf'), $this->datosCcf($cliente, $estab, $pv, ['cliente_sucursal_id' => $sucursal->id]))
            ->assertRedirect();

        $dte = Dte::where('cliente_id', $cliente->id)->firstOrFail();
        $this->assertSame('10.00', $dte->descuento_porcentaje_aplicado); // la sala manda (10%)
    }

    public function test_crear_ccf_toma_condicion_default_del_cliente(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['condicion_operacion_default' => 1]); // Contado

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-ccf'), $this->datosCcf($cliente, $estab, $pv))
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', ['cliente_id' => $cliente->id, 'condicion_operacion' => 1]);
    }

    public function test_crear_ccf_toma_condicion_default_de_sucursal_si_existe(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['condicion_operacion_default' => 2]); // Crédito
        $sucursal = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id, 'condicion_operacion_default' => 1]); // Contado

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-ccf'), $this->datosCcf($cliente, $estab, $pv, ['cliente_sucursal_id' => $sucursal->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', ['cliente_sucursal_id' => $sucursal->id, 'condicion_operacion' => 1]);
    }

    public function test_contribuyente_sin_default_queda_credito(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(); // sin condición default

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-ccf'), $this->datosCcf($cliente, $estab, $pv))
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', ['cliente_id' => $cliente->id, 'condicion_operacion' => 2]); // Crédito
    }

    public function test_al_crear_redirige_a_edicion_con_agregar_productos(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $facturacion = $this->usuario('facturacion');

        $respuesta = $this->actingAs($facturacion)
            ->post(route('facturacion.store-ccf'), $this->datosCcf($cliente, $estab, $pv));

        $dte = Dte::where('cliente_id', $cliente->id)->firstOrFail();
        $respuesta->assertRedirect(route('facturacion.edit', $dte));

        $this->actingAs($facturacion)
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertSee('Productos disponibles')
            ->assertSee('Primero agregue productos al borrador.');
    }

    // --- Retención de IVA automática (sin checkbox manual) ---

    public function test_crear_ccf_no_muestra_checkbox_de_retencion(): void
    {
        $this->emisor();
        Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-ccf'))
            ->assertOk()
            ->assertDontSee('name="aplica_retencion"', false)
            ->assertDontSee('Aplica retención de IVA')
            ->assertSee('Cliente agente de retención');
    }

    private function ccfConLinea(Cliente $cliente, Establecimiento $estab, PuntoVenta $pv, int $cantidad, float $precio = 10): Dte
    {
        $dte = $this->borradorCcf($cliente, $estab, $pv);
        $producto = Producto::factory()->create(['precio_unitario' => $precio, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: $cantidad);

        return $dte->refresh();
    }

    public function test_no_agente_no_aplica_retencion_aunque_pase_de_100(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['es_agente_retencion' => false]);

        $dte = $this->ccfConLinea($cliente, $estab, $pv, cantidad: 20); // base 200

        $this->assertFalse((bool) $dte->aplica_retencion_iva);
        $this->assertSame('0.00', $dte->iva_retenido);
    }

    public function test_agente_con_base_menor_o_igual_a_100_no_aplica(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['es_agente_retencion' => true]);

        $dte = $this->ccfConLinea($cliente, $estab, $pv, cantidad: 10); // base 100 (no > 100)

        $this->assertFalse((bool) $dte->aplica_retencion_iva);
        $this->assertSame('0.00', $dte->iva_retenido);
    }

    public function test_agente_con_base_mayor_a_100_aplica_1pct(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['es_agente_retencion' => true]);

        $dte = $this->ccfConLinea($cliente, $estab, $pv, cantidad: 11); // base 110

        $this->assertTrue((bool) $dte->aplica_retencion_iva);
        $this->assertSame('110.00', $dte->total_gravado);
        $this->assertSame('1.10', $dte->iva_retenido); // 110 × 1%
    }

    public function test_umbral_se_evalua_despues_del_descuento(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        // Cliente agente con descuento 20% → base 120 − 24 = 96 (base neta ≤ 100: no aplica).
        $cliente = Cliente::factory()->contribuyente()->create([
            'es_agente_retencion' => true, 'descuento_global_default' => 20,
        ]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-ccf'), $this->datosCcf($cliente, $estab, $pv))
            ->assertRedirect();
        $dte = Dte::where('cliente_id', $cliente->id)->firstOrFail();

        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 12); // gravado 120, 20% → desc 24 → neto 96

        $dte->refresh();
        $this->assertSame('24.00', $dte->descuento_gravado);
        $this->assertFalse((bool) $dte->aplica_retencion_iva); // base neta 96 no supera 100
    }

    public function test_request_no_puede_forzar_retencion(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        // Cliente NO agente, pero el request intenta forzar aplica_retencion=1.
        $cliente = Cliente::factory()->contribuyente()->create(['es_agente_retencion' => false]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-ccf'), $this->datosCcf($cliente, $estab, $pv, ['aplica_retencion' => 1]))
            ->assertRedirect();
        $dte = Dte::where('cliente_id', $cliente->id)->firstOrFail();

        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 50); // base 500

        $dte->refresh();
        $this->assertFalse((bool) $dte->aplica_retencion_iva); // no agente → nunca aplica
        $this->assertSame('0.00', $dte->iva_retenido);
    }
}
