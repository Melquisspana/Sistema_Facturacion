<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
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

class DteFacturaUiTest extends TestCase
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

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);

        return compact('estab', 'pv');
    }

    /** @param array<string, mixed> $override */
    private function datosFactura(Establecimiento $estab, PuntoVenta $pv, array $override = []): array
    {
        return array_merge([
            'tipo_dte' => '01',
            'condicion_operacion' => 1,
            'descuento_global' => 0,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ], $override);
    }

    private function borradorFactura(Establecimiento $estab, PuntoVenta $pv): Dte
    {
        return app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::Factura,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);
    }

    public function test_administrador_puede_crear_factura(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.store-factura'), $this->datosFactura($estab, $pv))
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', ['tipo_dte' => '01', 'estado' => 'borrador']);
    }

    public function test_facturacion_puede_crear_factura(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-factura'), $this->datosFactura($estab, $pv))
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', ['tipo_dte' => '01']);
    }

    public function test_consulta_no_puede_crear_factura(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $consulta = $this->usuario('consulta');

        $this->actingAs($consulta)->get(route('facturacion.create-factura'))->assertForbidden();
        $this->actingAs($consulta)->post(route('facturacion.store-factura'), $this->datosFactura($estab, $pv))->assertForbidden();

        $this->assertDatabaseCount('dtes', 0);
    }

    public function test_factura_puede_crearse_sin_cliente(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-factura'), $this->datosFactura($estab, $pv))
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', ['tipo_dte' => '01', 'cliente_id' => null]);
    }

    public function test_factura_no_exige_orden_de_compra(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        // Aunque el cliente la requiera para CCF, la Factura 01 no la pide.
        $cliente = Cliente::factory()->contribuyente()->create(['requiere_orden_compra' => true]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-factura'), $this->datosFactura($estab, $pv, ['cliente_id' => $cliente->id]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('dtes', ['tipo_dte' => '01', 'cliente_id' => $cliente->id, 'numero_orden_compra' => null]);
    }

    public function test_factura_no_aplica_retencion(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['es_agente_retencion' => true]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-factura'), $this->datosFactura($estab, $pv, ['cliente_id' => $cliente->id]))
            ->assertRedirect();

        $dte = Dte::where('tipo_dte', '01')->firstOrFail();
        $this->assertFalse((bool) $dte->aplica_retencion_iva);

        $producto = Producto::factory()->create(['precio_unitario' => 11.30, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);

        $dte->refresh();
        $this->assertSame('0.00', $dte->iva_retenido);
    }

    public function test_factura_no_aplica_retencion_aunque_cliente_sea_agente(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['es_agente_retencion' => true]);
        $dte = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::Factura,
            'cliente_id' => $cliente,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);

        $producto = Producto::factory()->create(['precio_unitario' => 11.30, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 50); // base > 100

        $dte->refresh();
        $this->assertFalse((bool) $dte->aplica_retencion_iva);
        $this->assertSame('0.00', $dte->iva_retenido);
    }

    public function test_agregar_gravado_calcula_iva_incluido(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $dte = $this->borradorFactura($estab, $pv);
        $producto = Producto::factory()->create(['precio_unitario' => 1.13, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.lineas.store', $dte), ['producto_id' => $producto->id, 'cantidad' => 1])
            ->assertRedirect();

        $dte->refresh();
        $this->assertSame('1.00', $dte->total_gravado); // base sin IVA
        $this->assertSame('0.13', $dte->iva);
        $this->assertSame('1.13', $dte->total_pagar);   // IVA incluido, no se suma aparte
    }

    public function test_factura_no_suma_iva_dos_veces(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $dte = $this->borradorFactura($estab, $pv);
        $producto = Producto::factory()->create(['precio_unitario' => 11.30, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.lineas.store', $dte), ['producto_id' => $producto->id, 'cantidad' => 10])
            ->assertRedirect();

        $dte->refresh();
        $this->assertSame('100.00', $dte->total_gravado);
        $this->assertSame('13.00', $dte->iva);
        $this->assertSame('113.00', $dte->total_pagar); // 100 + 13, no 113 + 13
    }

    public function test_no_se_puede_editar_factura_no_borrador(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $dte = $this->borradorFactura($estab, $pv);

        app(DteStateMachine::class)->transicionar($dte, EstadoDte::Generado);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.edit', $dte))
            ->assertForbidden();
    }

    // --- Factura habilitada operativamente: aparece como opción NORMAL, igual que CCF ---

    public function test_pagina_creacion_no_muestra_avisos_de_flujo_pendiente(): void
    {
        $this->emisor();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.create-factura'))
            ->assertOk()
            ->assertDontSee('Flujo pendiente de validación para producción real. No emitir sin revisión técnica.')
            ->assertDontSee('En revisión')
            ->assertDontSee('Validada en APITEST')
            ->assertDontSee('Producción bloqueada');
    }

    public function test_listado_muestra_factura_como_opcion_normal_sin_badge(): void
    {
        $this->emisor();

        $html = $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Nueva factura consumidor final', $html);
        $this->assertStringNotContainsString('En revisión', $html);
    }

    /**
     * Factura ahora puede llegar al MISMO flujo de "Generar y transmitir producción"
     * que CCF (antes exclusivo de CreditoFiscal en DtePolicy). No emite nada: solo
     * confirma que la política de autorización ya no la bloquea por tipo.
     */
    public function test_factura_puede_llegar_al_flujo_de_preparacion_productiva_del_ccf(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        // ambiente 01 explícito: la Policy exige que el DOCUMENTO sea de producción
        // (no solo el sistema) para ser candidato a "Generar y transmitir producción".
        $dte = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::Factura,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'ambiente' => '01',
        ]);
        $admin = $this->usuario('administrador');

        $this->assertTrue($admin->can('generarTransmitirProduccion', $dte));
    }
}
