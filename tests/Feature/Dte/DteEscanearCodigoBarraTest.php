<?php

namespace Tests\Feature\Dte;

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
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Modo escáner: agregar productos al borrador por código de barras. Reusa el mismo
 * motor que "auto-agregar por cantidad" (idempotente por producto, snapshot de precio,
 * recálculo de totales); no cambia reglas fiscales ni firma/transmisión/PDF.
 */
class DteEscanearCodigoBarraTest extends TestCase
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

    private function borrador(?Cliente $cliente = null): Dte
    {
        return app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => ($cliente ?? Cliente::factory()->contribuyente()->create())->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ]);
    }

    private function producto(string $nombre, string $barra, float $precio = 10, bool $activo = true): Producto
    {
        return Producto::factory()->create([
            'nombre' => $nombre, 'codigo_barra' => $barra,
            'precio_unitario' => $precio, 'tipo_impuesto' => TipoImpuesto::Gravado->value, 'activo' => $activo,
        ]);
    }

    private function escanear(User $u, Dte $dte, string $codigo)
    {
        return $this->actingAs($u)->post(route('facturacion.productos.escanear', $dte), ['codigo_barra' => $codigo]);
    }

    // --- Escanear agrega / incrementa ---

    public function test_escanear_codigo_existente_agrega_el_producto(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador();
        $this->producto('CANILLITAS', '7412201700031');

        $this->escanear($u, $dte, '7412201700031')->assertRedirect()->assertSessionHasNoErrors();

        $dte->refresh();
        $this->assertCount(1, $dte->lineas);
        $this->assertSame(1, (int) $dte->lineas->first()->cantidad);
        $this->assertSame('CANILLITAS', $dte->lineas->first()->descripcion);
    }

    public function test_escanear_el_mismo_codigo_de_nuevo_suma_uno_sin_duplicar(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador();
        $this->producto('CANILLITAS', '7412201700031');

        $this->escanear($u, $dte, '7412201700031')->assertRedirect();
        $this->escanear($u, $dte, '7412201700031')->assertRedirect();
        $this->escanear($u, $dte, '7412201700031')->assertRedirect();

        $dte->refresh();
        $this->assertCount(1, $dte->lineas); // NO duplica línea
        $this->assertSame(3, (int) $dte->lineas->first()->cantidad); // 3 escaneos = cantidad 3
        $this->assertDatabaseCount('dte_lineas', 1);
    }

    public function test_escanear_no_vuelve_a_resolver_el_precio_al_incrementar(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador();
        $producto = $this->producto('CANILLITAS', '7412201700031', precio: 10);

        $this->escanear($u, $dte, '7412201700031')->assertRedirect();
        // El precio general cambia DESPUÉS del primer escaneo.
        $producto->update(['precio_unitario' => 99]);
        $this->escanear($u, $dte, '7412201700031')->assertRedirect();

        $dte->refresh();
        $this->assertSame(2, (int) $dte->lineas->first()->cantidad);
        $this->assertSame('10.000000', (string) $dte->lineas->first()->precio_unitario); // snapshot original
    }

    // --- Código inexistente / producto inactivo / sin precio ---

    public function test_codigo_inexistente_muestra_error_suave_y_no_agrega(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador();

        $this->escanear($u, $dte, '0000000000000')
            ->assertRedirect()
            ->assertSessionHasErrors('codigo_barra');

        $this->assertDatabaseCount('dte_lineas', 0);
    }

    public function test_producto_inactivo_no_se_agrega_y_avisa(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador();
        $this->producto('DESCONTINUADO', '1111111111111', activo: false);

        $this->escanear($u, $dte, '1111111111111')
            ->assertRedirect()
            ->assertSessionHasErrors('codigo_barra');

        $this->assertDatabaseCount('dte_lineas', 0);
    }

    public function test_producto_sin_precio_aplicable_no_se_agrega(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador();
        $this->producto('SIN PRECIO', '2222222222222', precio: 0);

        $this->escanear($u, $dte, '2222222222222')
            ->assertRedirect()
            ->assertSessionHasErrors('codigo_barra');

        $this->assertDatabaseCount('dte_lineas', 0);
    }

    // --- Totales se recalculan ---

    public function test_totales_se_recalculan_tras_escanear(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador();
        $this->producto('CANILLITAS', '7412201700031', precio: 10);

        $this->escanear($u, $dte, '7412201700031');
        $this->escanear($u, $dte, '7412201700031');
        $this->escanear($u, $dte, '7412201700031');

        $dte->refresh();
        $this->assertSame('30.00', $dte->total_gravado); // 3 × 10
        $this->assertSame('33.90', $dte->total_pagar);    // + IVA 13%
    }

    // --- Permisos ---

    public function test_solo_gestores_pueden_escanear(): void
    {
        $dte = $this->borrador();
        $this->producto('CANILLITAS', '7412201700031');

        $this->escanear($this->usuario('consulta'), $dte, '7412201700031')->assertForbidden();

        $this->assertDatabaseCount('dte_lineas', 0);
    }

    // --- Nota de crédito: no aplica el modo escáner ---

    public function test_no_permite_escanear_en_nota_de_credito(): void
    {
        $u = $this->usuario('facturacion');
        $cliente = Cliente::factory()->contribuyente()->create();
        $ccf = $this->aceptarCcf($this->borrador($cliente));
        $nc = app(DteBorradorService::class)->crearNotaCredito($ccf);
        $this->producto('CANILLITAS', '7412201700031');

        $this->escanear($u, $nc, '7412201700031')
            ->assertRedirect()
            ->assertSessionHasErrors('codigo_barra');

        $this->assertDatabaseCount('dte_lineas', 0);
    }

    // --- Vista: campo de escaneo presente ---

    public function test_la_edicion_muestra_el_campo_de_escaneo(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador();

        $this->actingAs($u)->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertSee('Escanear código de barras')
            ->assertSee(route('facturacion.productos.escanear', $dte), false);
    }
}
