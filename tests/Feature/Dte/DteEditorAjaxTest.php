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
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Editor de borrador tipo "carrito" (sin recargar): las rutas de líneas devuelven JSON
 * cuando la petición lo pide (fetch), con el panel del carrito re-renderizado + las
 * cantidades por producto. El fallback redirect (sin JS) lo cubren los otros tests.
 */
class DteEditorAjaxTest extends TestCase
{
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(CatalogosMhSeeder::class);

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $this->pv = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function borrador(): Dte
    {
        return app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create()->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ]);
    }

    private function producto(string $nombre, ?string $barra = null, float $precio = 10): Producto
    {
        return Producto::factory()->create([
            'nombre' => $nombre, 'codigo_barra' => $barra,
            'precio_unitario' => $precio, 'tipo_impuesto' => TipoImpuesto::Gravado->value, 'activo' => true,
        ]);
    }

    public function test_agregar_por_cantidad_devuelve_json_con_carrito_y_cantidades(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador();
        $p = $this->producto('CANILLITAS', '7412201700031');

        $resp = $this->actingAs($u)->postJson(route('facturacion.productos.cantidad', [$dte, $p]), ['cantidad' => 3]);

        $resp->assertOk()
            ->assertJson(['ok' => true, 'sin_lineas' => false])
            ->assertJsonStructure(['ok', 'message', 'resumen_html', 'cantidades', 'sin_lineas'])
            ->assertJsonPath('cantidades.'.$p->id, 3);
        // El HTML del carrito re-renderizado trae el producto y el total con IVA.
        $this->assertStringContainsString('CANILLITAS', $resp->json('resumen_html'));
        $this->assertStringContainsString('33.90', $resp->json('resumen_html'));
        $this->assertDatabaseCount('dte_lineas', 1);
    }

    public function test_escanear_devuelve_json_y_suma_uno_sin_duplicar(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador();
        $p = $this->producto('SEMILLA', '7412201700178');

        $this->actingAs($u)->postJson(route('facturacion.productos.escanear', $dte), ['codigo_barra' => '7412201700178'])
            ->assertOk()->assertJsonPath('cantidades.'.$p->id, 1);
        $this->actingAs($u)->postJson(route('facturacion.productos.escanear', $dte), ['codigo_barra' => '7412201700178'])
            ->assertOk()->assertJsonPath('cantidades.'.$p->id, 2);

        $this->assertDatabaseCount('dte_lineas', 1); // una sola línea, cantidad 2
    }

    public function test_actualizar_y_quitar_linea_por_json(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador();
        $p = $this->producto('CANILLITAS', '7412201700031');
        $this->actingAs($u)->postJson(route('facturacion.productos.cantidad', [$dte, $p]), ['cantidad' => 2])->assertOk();
        $linea = $dte->lineas()->first();

        // Actualizar cantidad por JSON.
        $this->actingAs($u)->patchJson(route('facturacion.lineas.update', [$dte, $linea]), ['cantidad' => 5])
            ->assertOk()->assertJsonPath('cantidades.'.$p->id, 5)->assertJsonPath('sin_lineas', false);

        // Quitar por JSON: carrito vacío.
        $this->actingAs($u)->deleteJson(route('facturacion.lineas.destroy', [$dte, $linea]))
            ->assertOk()->assertJsonPath('sin_lineas', true);
        $this->assertDatabaseCount('dte_lineas', 0);
    }

    public function test_eliminar_linea_actualiza_el_total_sin_contar_la_eliminada(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador();
        $a = $this->producto('PROD A', '7412201700031', precio: 10);
        $b = $this->producto('PROD B', '7412201700178', precio: 10);

        $this->actingAs($u)->postJson(route('facturacion.productos.cantidad', [$dte, $a]), ['cantidad' => 2])->assertOk();
        $rB = $this->actingAs($u)->postJson(route('facturacion.productos.cantidad', [$dte, $b]), ['cantidad' => 3])->assertOk();
        // A(2)+B(3) a $10 => gravado 50, IVA 6.50, total 56.50.
        $this->assertStringContainsString('56.50', $rB->json('resumen_html'));

        $lineaB = $dte->lineas()->where('producto_id', $b->id)->first();
        $resp = $this->actingAs($u)->deleteJson(route('facturacion.lineas.destroy', [$dte, $lineaB]));

        $resp->assertOk()->assertJsonPath('sin_lineas', false);
        $html = $resp->json('resumen_html');
        // Tras eliminar B queda A(2) => total 22.60. El total viejo (56.50) NO debe aparecer:
        // si aparece, el panel está mostrando un total desfasado (bug de consistencia).
        $this->assertStringContainsString('22.60', $html);
        $this->assertStringNotContainsString('56.50', $html);
        // Catálogo: B ya no está en cantidades; A sigue en 2.
        $resp->assertJsonPath('cantidades.'.$a->id, 2);
        $this->assertArrayNotHasKey((string) $b->id, (array) $resp->json('cantidades'));
        $this->assertDatabaseCount('dte_lineas', 1);
    }

    public function test_actualizar_cantidad_por_json_recalcula_el_total(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador();
        $a = $this->producto('PROD A', '7412201700031', precio: 10);
        $this->actingAs($u)->postJson(route('facturacion.productos.cantidad', [$dte, $a]), ['cantidad' => 2])->assertOk();
        $linea = $dte->lineas()->first();

        // Subir a 4 => gravado 40, IVA 5.20, total 45.20 (sin desfase con el valor anterior 22.60).
        $resp = $this->actingAs($u)->patchJson(route('facturacion.lineas.update', [$dte, $linea]), ['cantidad' => 4]);
        $resp->assertOk();
        $this->assertStringContainsString('45.20', $resp->json('resumen_html'));
        $this->assertStringNotContainsString('22.60', $resp->json('resumen_html'));
    }

    public function test_producto_sin_precio_devuelve_422_sin_recargar(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador();
        $p = $this->producto('SIN PRECIO', '7412201700031', precio: 0);

        $this->actingAs($u)->postJson(route('facturacion.productos.cantidad', [$dte, $p]), ['cantidad' => 3])
            ->assertStatus(422)
            ->assertJson(['ok' => false])
            ->assertJsonValidationErrors('cantidad');
        $this->assertDatabaseCount('dte_lineas', 0);
    }

    public function test_codigo_barra_inexistente_devuelve_422(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador();

        $this->actingAs($u)->postJson(route('facturacion.productos.escanear', $dte), ['codigo_barra' => '0000000000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('codigo_barra');
    }

    public function test_ccf_no_editable_no_permite_editar_lineas_por_json(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador();
        $p = $this->producto('CANILLITAS', '7412201700031');
        $this->actingAs($u)->postJson(route('facturacion.productos.cantidad', [$dte, $p]), ['cantidad' => 1])->assertOk();
        $linea = $dte->lineas()->first();

        // Pasa a GENERADO: ya no es borrador editable.
        $dte->update(['estado' => EstadoDte::Generado]);

        $this->actingAs($u)->postJson(route('facturacion.productos.cantidad', [$dte, $p]), ['cantidad' => 9])->assertForbidden();
        $this->actingAs($u)->patchJson(route('facturacion.lineas.update', [$dte, $linea]), ['cantidad' => 9])->assertForbidden();
        $this->actingAs($u)->deleteJson(route('facturacion.lineas.destroy', [$dte, $linea]))->assertForbidden();
    }

    public function test_fallback_sin_json_sigue_redirigiendo(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador();
        $p = $this->producto('CANILLITAS', '7412201700031');

        // POST normal (sin Accept: application/json) -> redirect como siempre.
        $this->actingAs($u)->post(route('facturacion.productos.cantidad', [$dte, $p]), ['cantidad' => 2])
            ->assertRedirect();
        $this->assertDatabaseCount('dte_lineas', 1);
    }
}
