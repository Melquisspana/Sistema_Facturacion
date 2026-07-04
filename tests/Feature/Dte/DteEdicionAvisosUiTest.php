<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Avisos de la edición del borrador CCF (solo presentación, sin tocar la generación):
 *  - Confirm de "Generar" con resumen (cliente/sala/OC/productos/total) y botón
 *    deshabilitado cuando el borrador no tiene líneas.
 *  - Aviso SUAVE de OC duplicada (misma OC en otro CCF emitido del cliente), con link
 *    y sin bloquear nada.
 */
class DteEdicionAvisosUiTest extends TestCase
{
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Unidad mínima para ProductoFactory (productos.unidad_medida_id es NOT NULL);
        // no hace falta sembrar catálogos completos: aquí no se genera JSON.
        \App\Models\UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Matriz', 'activo' => true]);
        $this->pv = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente Prueba SA']);
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole('facturacion');
    }

    /** Borrador CCF del cliente de prueba, opcionalmente con OC, sala y una línea 2 × $10. */
    private function borrador(?string $oc = null, bool $conLinea = true, ?int $salaId = null): Dte
    {
        $b = app(DteBorradorService::class);
        $dte = $b->crearBorrador(array_filter([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $this->cliente->id,
            'cliente_sucursal_id' => $salaId,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'numero_orden_compra' => $oc,
        ], fn ($v) => $v !== null));

        if ($conLinea) {
            $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
            $b->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);
        }

        return $dte->refresh();
    }

    /** Otro CCF del cliente con la OC dada, en el estado indicado (fixture directo, sin generar). */
    private function otroCcf(string $oc, EstadoDte $estado, ?Cliente $cliente = null): Dte
    {
        $otro = $this->borrador($oc);
        if ($cliente) {
            $otro->cliente_id = $cliente->id;
        }
        $otro->estado = $estado;
        Dte::withoutEvents(fn () => $otro->save());

        return $otro->refresh();
    }

    // --- P0.4: confirm con resumen + botón deshabilitado sin líneas ---

    public function test_confirm_de_generar_incluye_cliente_sala_oc_lineas_y_total(): void
    {
        $sala = ClienteSucursal::factory()->create(['cliente_id' => $this->cliente->id, 'nombre' => 'Sala Uno']);
        $dte = $this->borrador('OC-TEST-1', conLinea: true, salaId: $sala->id);

        $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            // Fragmentos del confirm (van en el onsubmit vía @js; se asevera el texto plano ASCII).
            ->assertSee('Cliente: Cliente Prueba SA', false)
            ->assertSee('Sala: Sala Uno', false)
            ->assertSee('Orden de compra: OC-TEST-1', false)
            ->assertSee('Productos: 1', false)
            ->assertSee('Total a pagar: $22.60', false)
            // Con líneas, el botón NO está deshabilitado.
            ->assertDontSee('cursor-not-allowed');
    }

    public function test_sin_lineas_el_boton_generar_esta_deshabilitado(): void
    {
        $dte = $this->borrador(oc: null, conLinea: false);

        $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertSee('Agregá al menos un producto para generar')
            ->assertSee('disabled', false)
            ->assertSee('cursor-not-allowed');
    }

    // --- P0.5: aviso suave de OC duplicada ---

    public function test_oc_repetida_en_ccf_emitido_muestra_banner_con_link(): void
    {
        $otro = $this->otroCcf('OC-REP-1', EstadoDte::Generado);
        $dte = $this->borrador('OC-REP-1');

        $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertSee('ya se usó en el CCF')
            ->assertSee(route('facturacion.show', $otro), false); // link al documento existente
    }

    public function test_oc_nueva_no_muestra_banner(): void
    {
        $this->otroCcf('OC-VIEJA', EstadoDte::Generado);
        $dte = $this->borrador('OC-NUEVA');

        $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertDontSee('ya se usó en el CCF');
    }

    public function test_oc_repetida_solo_en_otro_borrador_no_muestra_banner(): void
    {
        $this->otroCcf('OC-REP-2', EstadoDte::Borrador);
        $dte = $this->borrador('OC-REP-2');

        $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertDontSee('ya se usó en el CCF');
    }

    public function test_oc_repetida_en_invalidado_no_muestra_banner(): void
    {
        $this->otroCcf('OC-REP-3', EstadoDte::Invalidado);
        $dte = $this->borrador('OC-REP-3');

        $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertDontSee('ya se usó en el CCF');
    }

    public function test_oc_repetida_de_otro_cliente_no_muestra_banner(): void
    {
        $otroCliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Otro Cliente SA']);
        $this->otroCcf('OC-REP-4', EstadoDte::Generado, $otroCliente);
        $dte = $this->borrador('OC-REP-4');

        $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertDontSee('ya se usó en el CCF');
    }

    public function test_sin_oc_no_muestra_banner(): void
    {
        // Otro CCF emitido sin OC y borrador sin OC: no hay nada que comparar.
        $otro = $this->borrador();
        $otro->estado = EstadoDte::Generado;
        Dte::withoutEvents(fn () => $otro->save());
        $dte = $this->borrador();

        $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertDontSee('ya se usó en el CCF');
    }
}
