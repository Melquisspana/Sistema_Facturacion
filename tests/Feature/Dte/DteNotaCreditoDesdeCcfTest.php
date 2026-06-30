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
 * Crear NC desde la vista de un CCF generado: el formulario pregunta el TIPO (no
 * asume devolución) y el flujo de edición depende del tipo elegido.
 */
class DteNotaCreditoDesdeCcfTest extends TestCase
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

    /** CCF generado con cliente, sala y orden de compra. */
    private function ccfGenerado(array $emisor): Dte
    {
        $cliente = Cliente::factory()->contribuyente()->create(['requiere_orden_compra' => true]);
        $sucursal = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id, 'nombre' => 'Súper Selectos Atiquizaya']);

        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => $sucursal->id,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
            'numero_orden_compra' => 'OC-777',
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 3);
        app(DteGeneracionService::class)->generar($dte);

        // La NC solo se crea desde un CCF ACEPTADO por Hacienda (regla de negocio).
        return $this->aceptarCcf($dte);
    }

    private function crearNcDesdeCcf(Dte $ccf, string $tipo): Dte
    {
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.nota-credito.store', $ccf), ['tipo' => $tipo])
            ->assertRedirect();

        return Dte::where('tipo_dte', '05')->where('tipo_nota_credito', $tipo)->latest('id')->firstOrFail();
    }

    private function editHtml(Dte $nc)
    {
        return $this->actingAs($this->usuario('facturacion'))->get(route('facturacion.edit', $nc));
    }

    // --- Formulario en el CCF ---

    public function test_show_ccf_muestra_selector_de_tipo_con_opcion_vacia(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfGenerado($emisor);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertSee('Tipo de nota de crédito')
            ->assertSee('— Seleccione —')
            ->assertSee('Avería')
            ->assertSee('Pronto pago');
    }

    public function test_crear_nc_desde_ccf_sin_tipo_muestra_error(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfGenerado($emisor);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.nota-credito.store', $ccf), ['tipo' => ''])
            ->assertSessionHasErrors(['tipo' => 'Seleccione el tipo de nota de crédito.']);

        $this->assertDatabaseMissing('dtes', ['tipo_dte' => '05', 'dte_relacionado_id' => $ccf->id]);
    }

    // --- Flujo según tipo ---

    public function test_devolucion_redirige_a_lineas_originales(): void
    {
        $emisor = $this->emisor();
        $nc = $this->crearNcDesdeCcf($this->ccfGenerado($emisor), 'devolucion_producto');

        $this->editHtml($nc)->assertOk()
            ->assertSee('Líneas del documento original')
            ->assertDontSee('Productos para nota de crédito por avería')
            ->assertDontSee('Agregar concepto de ajuste');
    }

    public function test_faltante_redirige_a_lineas_originales(): void
    {
        $emisor = $this->emisor();
        $nc = $this->crearNcDesdeCcf($this->ccfGenerado($emisor), 'faltante_entrega');

        $this->editHtml($nc)->assertOk()->assertSee('Líneas del documento original');
    }

    public function test_averia_redirige_a_catalogo_de_productos(): void
    {
        $emisor = $this->emisor();
        $nc = $this->crearNcDesdeCcf($this->ccfGenerado($emisor), 'averia');

        $this->editHtml($nc)->assertOk()
            ->assertSee('Productos para nota de crédito por avería')
            ->assertDontSee('Líneas del documento original');
    }

    public function test_pronto_pago_redirige_a_conceptos_manuales(): void
    {
        $emisor = $this->emisor();
        $nc = $this->crearNcDesdeCcf($this->ccfGenerado($emisor), 'pronto_pago');

        $this->editHtml($nc)->assertOk()
            ->assertSee('Agregar concepto de ajuste')
            ->assertDontSee('Líneas del documento original');
    }

    // --- Copias del CCF ---

    public function test_nc_desde_ccf_copia_relacionado_cliente_sala_y_orden(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfGenerado($emisor);
        $nc = $this->crearNcDesdeCcf($ccf, 'averia');

        $this->assertSame($ccf->id, $nc->dte_relacionado_id);
        $this->assertSame($ccf->cliente_id, $nc->cliente_id);
        $this->assertSame($ccf->cliente_sucursal_id, $nc->cliente_sucursal_id);
        $this->assertSame('OC-777', $nc->numero_orden_compra);
        $this->assertNotSame($nc->id, $nc->dte_relacionado_id);
    }

    public function test_averia_y_pronto_pago_no_exigen_lineas_originales(): void
    {
        $emisor = $this->emisor();

        $averia = $this->crearNcDesdeCcf($this->ccfGenerado($emisor), 'averia');
        $pronto = $this->crearNcDesdeCcf($this->ccfGenerado($emisor), 'pronto_pago');

        // Recién creadas no tienen líneas (no se fuerza acreditar el original).
        $this->assertCount(0, $averia->lineas);
        $this->assertCount(0, $pronto->lineas);
        // Y sí quedan vinculadas al CCF.
        $this->assertNotNull($averia->dte_relacionado_id);
        $this->assertNotNull($pronto->dte_relacionado_id);
    }
}
