<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\PrecioProductoResolver;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Los productos reales de Calleja aparecen en el catálogo "Productos disponibles"
 * al editar un CCF de Calleja, con el precio especial Calleja como precio aplicado.
 */
class ProductosCallejaCatalogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(DatosInicialesNegritaSeeder::class);
    }

    private function ccfDeCalleja(): \App\Models\Dte
    {
        $calleja = Cliente::where('nombre', 'Calleja, S.A. de C.V.')->firstOrFail();
        $estab = Establecimiento::where('codigo', 'M001')->firstOrFail();
        $pv = PuntoVenta::where('codigo', 'P001')->firstOrFail();

        return app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $calleja,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'numero_orden_compra' => 'OC-123', // Calleja exige orden de compra
        ]);
    }

    public function test_catalogo_ccf_calleja_muestra_productos_con_precio_calleja(): void
    {
        $dte = $this->ccfDeCalleja();

        $this->actingAs(User::factory()->create()->assignRole('facturacion'))
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertSee('Productos disponibles')
            ->assertSee('CANILLITAS')
            ->assertSee('7412201700031')   // código de barra
            ->assertSee('1.0500')          // precio especial Calleja (4 decimales)
            ->assertSee('especial')        // marcado como precio especial
            ->assertSee('LECHE DE BURRA')
            ->assertSee('MIX');
    }

    public function test_precio_aplicado_para_calleja_es_el_especial(): void
    {
        $calleja = Cliente::where('nombre', 'Calleja, S.A. de C.V.')->firstOrFail();
        $resolver = app(PrecioProductoResolver::class);

        $canillitas = Producto::where('codigo_barra', '7412201700031')->firstOrFail();
        $this->assertSame('1.0500', $resolver->resolver($canillitas, $calleja->id, null));

        $leche = Producto::where('codigo_barra', '7412201700024')->firstOrFail();
        $this->assertSame('1.0900', $resolver->resolver($leche, $calleja->id, null));
    }
}
