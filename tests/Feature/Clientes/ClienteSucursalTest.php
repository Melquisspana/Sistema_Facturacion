<?php

namespace Tests\Feature\Clientes;

use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\User;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ClienteSucursalTest extends TestCase
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

    private function datosSucursal(array $override = []): array
    {
        $distrito = \App\Models\Distrito::where('nombre', 'Olocuilta')->first()
            ?? \App\Models\Distrito::firstOrFail();

        return array_merge([
            'nombre' => 'Selectos Santa Rosa',
            // Ubicación administrativa 2024 (obligatoria): departamento → municipio → distrito.
            'departamento_id' => $distrito->departamento_id,
            'municipio_2024' => $distrito->municipio,
            'distrito_id' => $distrito->id,
            'activo' => '1',
            'requiere_orden_compra' => '', // hereda del cliente
        ], $override);
    }

    public function test_sucursal_exige_distrito(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('clientes.sucursales.store', $cliente), $this->datosSucursal(['distrito_id' => '', 'departamento_id' => '']))
            ->assertSessionHasErrors(['departamento_id', 'distrito_id']);

        $this->assertDatabaseCount('cliente_sucursales', 0);
    }

    public function test_sucursal_guarda_distrito(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $distrito = \App\Models\Distrito::where('nombre', 'Olocuilta')->firstOrFail();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('clientes.sucursales.store', $cliente), $this->datosSucursal())
            ->assertRedirect(route('clientes.show', $cliente));

        $this->assertDatabaseHas('cliente_sucursales', [
            'cliente_id' => $cliente->id,
            'distrito_id' => $distrito->id,
        ]);
    }

    public function test_distrito_debe_pertenecer_al_departamento(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        // Olocuilta (La Paz) pero declarando un departamento distinto (San Salvador).
        $olocuilta = \App\Models\Distrito::where('nombre', 'Olocuilta')->firstOrFail();
        $sanSalvador = \App\Models\Departamento::where('codigo', '06')->firstOrFail();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('clientes.sucursales.store', $cliente), $this->datosSucursal([
                'departamento_id' => $sanSalvador->id,
                'distrito_id' => $olocuilta->id,
            ]))
            ->assertSessionHasErrors('distrito_id');

        $this->assertDatabaseCount('cliente_sucursales', 0);
    }

    public function test_cliente_puede_tener_varias_sucursales(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        ClienteSucursal::factory()->count(3)->create(['cliente_id' => $cliente->id]);

        $this->assertCount(3, $cliente->refresh()->sucursales);
    }

    public function test_calleja_varias_salas_mismo_cliente_fiscal(): void
    {
        // Un solo cliente fiscal (mismo NIT/NRC), varias salas.
        $calleja = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja S.A. de C.V.']);

        foreach (['Selectos Santa Rosa', 'Selectos Merliot', 'Selectos Cojutepeque'] as $sala) {
            ClienteSucursal::factory()->create(['cliente_id' => $calleja->id, 'nombre' => $sala]);
        }

        $this->assertSame(1, Cliente::where('num_documento', $calleja->num_documento)->count());
        $this->assertCount(3, $calleja->refresh()->sucursales);
    }

    public function test_facturacion_puede_crear_sucursal(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('clientes.sucursales.store', $cliente), $this->datosSucursal())
            ->assertRedirect(route('clientes.show', $cliente));

        $this->assertDatabaseHas('cliente_sucursales', [
            'cliente_id' => $cliente->id,
            'nombre' => 'Selectos Santa Rosa',
            'requiere_orden_compra' => null, // heredó del cliente
        ]);
    }

    public function test_sucursal_puede_requerir_orden_compra_propia(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create(['requiere_orden_compra' => false]);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.sucursales.store', $cliente), $this->datosSucursal(['requiere_orden_compra' => '1']))
            ->assertRedirect();

        $sucursal = $cliente->sucursales()->firstOrFail();
        $this->assertTrue($sucursal->requiere_orden_compra);
        $this->assertTrue($sucursal->requiereOrdenCompra());
    }

    public function test_consulta_no_puede_crear_sucursal(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario('consulta'))
            ->post(route('clientes.sucursales.store', $cliente), $this->datosSucursal())
            ->assertForbidden();

        $this->assertDatabaseCount('cliente_sucursales', 0);
    }

    public function test_no_se_gestiona_sucursal_de_otro_cliente(): void
    {
        $clienteA = Cliente::factory()->contribuyente()->create();
        $clienteB = Cliente::factory()->contribuyente()->create();
        $sucursalB = ClienteSucursal::factory()->create(['cliente_id' => $clienteB->id]);

        // Intentar inactivar la sucursal de B usando la ruta de A → 404.
        $this->actingAs($this->usuario('administrador'))
            ->patch(route('clientes.sucursales.toggle-activo', [$clienteA, $sucursalB]))
            ->assertNotFound();
    }

    public function test_sucursal_se_puede_inactivar(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $sucursal = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id, 'activo' => true]);

        $this->actingAs($this->usuario('facturacion'))
            ->patch(route('clientes.sucursales.toggle-activo', [$cliente, $sucursal]))
            ->assertRedirect();

        $this->assertFalse($sucursal->refresh()->activo);
    }
}
