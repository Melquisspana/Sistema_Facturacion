<?php

namespace Tests\Feature\Auditoria;

use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Producto;
use App\Models\ProductoPrecioCliente;
use App\Models\User;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cierre del gap de auditoría: precios por cliente, salas/sucursales e
 * importaciones CSV quedan registrados (spatie/activitylog). Sin tocar facturación.
 */
class AuditoriaDatosTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(CatalogosMhSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) { @unlink($ruta); }
        parent::tearDown();
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function calleja(): Cliente
    {
        return Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
    }

    private function csv(string $contenido): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('datos.csv', $contenido);
    }

    private function csvSalas(): string
    {
        return "No.,Nombre comercial,Dirección,Distrito,Municipio,Departamento\n".
               "1,Súper Selectos Escalón,Col. Escalón,Centro,San Salvador,San Salvador\n".
               "2,Súper Selectos Soyapango,Av. Roosevelt,Norte,Soyapango,San Salvador\n";
    }

    private function csvPrecios(): string
    {
        return "Código interno,Código de barra,Descripción de producto,Factor de empaque,Fecha de inicio,Costo nuevo / unidad libra / precio\n".
               "79873,7412201700031,CANILLITAS,BOLSA,2026-01-01,1.0500\n";
    }

    // --- A. Precios por cliente ---

    public function test_crear_precio_especial_genera_log(): void
    {
        $admin = $this->usuario('administrador');
        $cliente = $this->calleja();
        $producto = Producto::factory()->create(['precio_unitario' => 1.00]);

        $this->actingAs($admin);
        $precio = ProductoPrecioCliente::create([
            'producto_id' => $producto->id, 'cliente_id' => $cliente->id, 'precio' => 0.90, 'activo' => true,
        ]);

        $act = Activity::where('log_name', 'precio_producto')
            ->where('subject_type', ProductoPrecioCliente::class)->where('subject_id', $precio->id)->latest('id')->first();
        $this->assertNotNull($act);
        $this->assertSame('creó un precio especial', $act->description);
        $this->assertSame($admin->id, $act->causer_id);
    }

    public function test_actualizar_precio_especial_genera_log_con_cambios(): void
    {
        $admin = $this->usuario('administrador');
        $cliente = $this->calleja();
        $producto = Producto::factory()->create(['precio_unitario' => 1.00]);

        $this->actingAs($admin);
        $precio = ProductoPrecioCliente::create(['producto_id' => $producto->id, 'cliente_id' => $cliente->id, 'precio' => 1.00, 'activo' => true]);
        $precio->update(['precio' => 0.85, 'activo' => false]); // cambio de precio + desactivación

        $act = Activity::where('log_name', 'precio_producto')->where('description', 'actualizó un precio especial')
            ->where('subject_id', $precio->id)->latest('id')->first();
        $this->assertNotNull($act);
        $cambios = $act->changes();
        $this->assertArrayHasKey('precio', $cambios['attributes']);   // valor nuevo
        $this->assertArrayHasKey('precio', $cambios['old']);          // valor anterior
        $this->assertArrayHasKey('activo', $cambios['attributes']);
        $this->assertSame($admin->id, $act->causer_id);
    }

    // --- B. Salas / sucursales ---

    public function test_crear_sala_genera_log(): void
    {
        $admin = $this->usuario('administrador');
        $cliente = $this->calleja();

        $this->actingAs($admin);
        $sala = ClienteSucursal::create(['cliente_id' => $cliente->id, 'nombre' => 'Sala Centro', 'activo' => true]);

        $act = Activity::where('log_name', 'sucursal')->where('subject_id', $sala->id)->latest('id')->first();
        $this->assertNotNull($act);
        $this->assertSame('creó la sala/sucursal', $act->description);
        $this->assertSame($admin->id, $act->causer_id);
    }

    public function test_actualizar_sala_genera_log_con_cambios(): void
    {
        $admin = $this->usuario('administrador');
        $cliente = $this->calleja();

        $this->actingAs($admin);
        $sala = ClienteSucursal::create(['cliente_id' => $cliente->id, 'nombre' => 'Sala Vieja', 'activo' => true, 'permite_ccf' => true]);
        $sala->update(['nombre' => 'Sala Nueva', 'permite_ccf' => false, 'descuento_global_default' => 7]);

        $act = Activity::where('log_name', 'sucursal')->where('description', 'actualizó la sala/sucursal')
            ->where('subject_id', $sala->id)->latest('id')->first();
        $this->assertNotNull($act);
        $cambios = $act->changes();
        $this->assertArrayHasKey('nombre', $cambios['attributes']);
        $this->assertArrayHasKey('permite_ccf', $cambios['attributes']);
        $this->assertArrayHasKey('descuento_global_default', $cambios['attributes']);
        $this->assertSame($admin->id, $act->causer_id);
    }

    // --- C. Importaciones CSV ---

    public function test_importar_salas_registra_resumen_de_auditoria(): void
    {
        $admin = $this->usuario('administrador');
        $cliente = $this->calleja();

        $this->actingAs($admin)
            ->post(route('importaciones.salas.importar'), ['cliente_id' => $cliente->id, 'archivo' => $this->csv($this->csvSalas())])
            ->assertRedirect();

        $act = Activity::where('log_name', 'importacion')->latest('id')->first();
        $this->assertNotNull($act);
        $this->assertSame($admin->id, $act->causer_id);
        $this->assertSame($cliente->id, $act->subject_id); // cliente afectado
        $this->assertSame('salas', $act->getExtraProperty('tipo'));
        $this->assertSame(2, $act->getExtraProperty('creadas'));
        $this->assertSame('datos.csv', $act->getExtraProperty('archivo'));
    }

    public function test_importar_precios_registra_resumen_de_auditoria(): void
    {
        $admin = $this->usuario('administrador');
        $cliente = $this->calleja();

        $this->actingAs($admin)
            ->post(route('importaciones.precios.importar'), ['cliente_id' => $cliente->id, 'archivo' => $this->csv($this->csvPrecios())])
            ->assertRedirect();

        $act = Activity::where('log_name', 'importacion')->latest('id')->first();
        $this->assertNotNull($act);
        $this->assertSame('precios', $act->getExtraProperty('tipo'));
        $this->assertSame(1, $act->getExtraProperty('creadas'));
        $this->assertSame($admin->id, $act->causer_id);
    }

    // --- D. Seguridad: consulta/contador no pueden modificar estos datos ---

    public function test_consulta_no_puede_crear_precio(): void
    {
        $cliente = $this->calleja();
        $producto = Producto::factory()->create(['precio_unitario' => 1.00]);

        $this->actingAs($this->usuario('consulta'))
            ->post(route('productos.precios.store', $producto), ['cliente_id' => $cliente->id, 'precio' => 0.50, 'activo' => '1'])
            ->assertForbidden();
    }

    public function test_consulta_y_contador_no_pueden_crear_sala(): void
    {
        $cliente = $this->calleja();

        $this->actingAs($this->usuario('consulta'))
            ->get(route('clientes.sucursales.create', $cliente))->assertForbidden();
        $this->actingAs($this->usuario('contador'))
            ->get(route('clientes.sucursales.create', $cliente))->assertForbidden();
    }
}
