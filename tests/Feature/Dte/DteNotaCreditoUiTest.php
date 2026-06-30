<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteStateMachine;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DteNotaCreditoUiTest extends TestCase
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
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        Correlativo::create(['tipo_dte' => '05', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);

        return compact('estab', 'pv');
    }

    /** CCF generado (no borrador) con una línea gravada 10 × 10. */
    private function ccfAceptado(): Dte
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();

        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($ccf, $producto, cantidad: 10);

        app(DteGeneracionService::class)->generar($ccf);

        // La NC solo se crea desde un CCF ACEPTADO por Hacienda (regla de negocio).
        return $this->aceptarCcf($ccf);
    }

    public function test_boton_crear_nc_aparece_en_ccf_aceptado_para_gestor(): void
    {
        $ccf = $this->ccfAceptado();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertSee('Crear nota de crédito');
    }

    public function test_boton_no_aparece_en_ccf_solo_generado(): void
    {
        // Regla de negocio: la NC solo se crea desde un CCF ACEPTADO, no generado.
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($ccf, $producto, cantidad: 10);
        app(DteGeneracionService::class)->generar($ccf);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $ccf->refresh()))
            ->assertOk()
            ->assertDontSee('Crear nota de crédito');
    }

    public function test_boton_no_aparece_en_ccf_borrador(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertDontSee('Crear nota de crédito');
    }

    public function test_boton_no_aparece_para_consulta(): void
    {
        $ccf = $this->ccfAceptado();

        $this->actingAs($this->usuario('consulta'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertDontSee('Crear nota de crédito');
    }

    public function test_crear_nc_hereda_cliente_y_guarda_relacion(): void
    {
        $ccf = $this->ccfAceptado();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.nota-credito.store', $ccf), ['tipo' => 'devolucion_producto', 'motivo' => 'Devolución'])
            ->assertRedirect();

        $this->assertDatabaseHas('dtes', [
            'tipo_dte' => '05',
            'estado' => 'borrador',
            'cliente_id' => $ccf->cliente_id,
            'dte_relacionado_id' => $ccf->id,
        ]);
    }

    public function test_acreditar_linea_parcial_por_la_ui(): void
    {
        $ccf = $this->ccfAceptado();
        $lineaOriginal = $ccf->lineas()->first();
        $nc = $this->borradores->crearNotaCredito($ccf);
        $facturacion = $this->usuario('facturacion');

        $this->actingAs($facturacion)
            ->post(route('facturacion.acreditar', [$nc, $lineaOriginal]), ['cantidad' => 4])
            ->assertRedirect();

        $nc->refresh();
        $this->assertSame('40.00', $nc->total_gravado);
        $this->assertSame('5.20', $nc->iva);
        $this->assertSame('45.20', $nc->total_pagar);
        $this->assertSame($lineaOriginal->id, $nc->lineas->first()->dte_linea_original_id);
    }

    public function test_no_permite_acreditar_mas_que_el_saldo(): void
    {
        $ccf = $this->ccfAceptado();
        $lineaOriginal = $ccf->lineas()->first();
        $nc = $this->borradores->crearNotaCredito($ccf);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.acreditar', [$nc, $lineaOriginal]), ['cantidad' => 11]) // original 10
            ->assertSessionHasErrors('cantidad');

        $this->assertCount(0, $nc->refresh()->lineas);
    }

    public function test_nota_credito_se_puede_generar(): void
    {
        $ccf = $this->ccfAceptado();
        $lineaOriginal = $ccf->lineas()->first();
        $nc = $this->borradores->crearNotaCredito($ccf);
        $this->borradores->acreditarLinea($nc, $lineaOriginal, cantidad: 5);
        $facturacion = $this->usuario('facturacion');

        $this->actingAs($facturacion)
            ->post(route('facturacion.generar', $nc))
            ->assertRedirect(route('facturacion.show', $nc));

        $nc->refresh();
        $this->assertSame(EstadoDte::Generado, $nc->estado);
        $this->assertStringStartsWith('INT-05-', $nc->numero_interno);
    }

    public function test_nota_credito_generada_es_inmutable(): void
    {
        $ccf = $this->ccfAceptado();
        $lineaOriginal = $ccf->lineas()->first();
        $nc = $this->borradores->crearNotaCredito($ccf);
        $this->borradores->acreditarLinea($nc, $lineaOriginal, cantidad: 5);
        app(DteGeneracionService::class)->generar($nc);

        // Editar una NC generada → 403.
        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.edit', $nc->refresh()))
            ->assertForbidden();
    }

    public function test_listado_muestra_nota_credito(): void
    {
        $ccf = $this->ccfAceptado();
        $nc = $this->borradores->crearNotaCredito($ccf);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.index'))
            ->assertOk()
            ->assertSee('Nota de Crédito')        // tipo en el listado
            ->assertSee($ccf->numero_control);     // relación con el original (N° oficial MH)
    }

    public function test_nc_pronto_pago_agrega_concepto_por_la_ui(): void
    {
        $ccf = $this->ccfAceptado();
        $facturacion = $this->usuario('facturacion');

        // Crear NC de pronto pago desde el CCF.
        $this->actingAs($facturacion)
            ->post(route('facturacion.nota-credito.store', $ccf), ['tipo' => 'pronto_pago', 'motivo' => 'Pronto pago'])
            ->assertRedirect();

        $nc = Dte::where('tipo_dte', '05')->latest('id')->firstOrFail();

        $this->actingAs($facturacion)
            ->post(route('facturacion.conceptos.store', $nc), ['descripcion' => 'Descuento por pronto pago', 'monto' => 25, 'tipo_impuesto' => 'gravado'])
            ->assertRedirect();

        $nc->refresh();
        $this->assertCount(1, $nc->lineas);
        $this->assertNull($nc->lineas->first()->producto_id);
        $this->assertSame('25.00', $nc->total_gravado);
    }

    public function test_nc_vacia_no_se_genera(): void
    {
        $ccf = $this->ccfAceptado();
        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'pronto_pago']);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.generar', $nc))
            ->assertSessionHasErrors('generar');

        $this->assertSame(EstadoDte::Borrador, $nc->refresh()->estado);
    }

    public function test_impresion_muestra_tipo_y_motivo_de_nc(): void
    {
        $ccf = $this->ccfAceptado();
        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'pronto_pago', 'motivo' => 'Pronto pago Calleja']);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.imprimir', $nc))
            ->assertOk()
            ->assertSee('Pronto pago')
            ->assertSee('Pronto pago Calleja')
            ->assertSee('Pendiente validación contra esquema oficial MH');
    }
}
