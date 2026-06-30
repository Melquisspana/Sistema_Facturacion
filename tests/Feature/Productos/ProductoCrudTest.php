<?php

namespace Tests\Feature\Productos;

use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\User;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductoCrudTest extends TestCase
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

    private function datosProductoValido(array $override = []): array
    {
        return array_merge([
            'codigo' => 'DUL-001',
            'nombre' => 'Dulce de leche artesanal',
            'tipo_producto' => '1',
            'unidad_medida_id' => UnidadMedida::where('nombre', 'Unidad')->value('id'),
            'precio_unitario' => '1.50',
            'tipo_impuesto' => 'gravado',
            'maneja_inventario' => '0',
            'activo' => '1',
        ], $override);
    }

    public function test_invitado_es_redirigido_al_login(): void
    {
        $this->get(route('productos.index'))->assertRedirect('/login');
    }

    public function test_consulta_puede_listar_pero_no_crear(): void
    {
        $consulta = $this->usuario('consulta');

        $this->actingAs($consulta)->get(route('productos.index'))->assertOk();
        $this->actingAs($consulta)->get(route('productos.create'))->assertForbidden();
        $this->actingAs($consulta)->post(route('productos.store'), $this->datosProductoValido())->assertForbidden();

        $this->assertDatabaseCount('productos', 0);
    }

    public function test_contador_no_puede_crear(): void
    {
        $this->actingAs($this->usuario('contador'))
            ->post(route('productos.store'), $this->datosProductoValido())
            ->assertForbidden();
    }

    public function test_administrador_crea_producto_y_se_audita(): void
    {
        $admin = $this->usuario('administrador');

        $response = $this->actingAs($admin)->post(route('productos.store'), $this->datosProductoValido([
            'nombre' => 'Paleta de coco',
        ]));

        $producto = Producto::firstOrFail();
        $response->assertRedirect(route('productos.show', $producto));

        $this->assertDatabaseHas('productos', ['nombre' => 'Paleta de coco', 'codigo' => 'DUL-001']);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'producto',
            'description' => 'creó el producto',
            'subject_type' => Producto::class,
            'subject_id' => $producto->id,
            'causer_id' => $admin->id,
        ]);
    }

    public function test_facturacion_puede_crear(): void
    {
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('productos.store'), $this->datosProductoValido())
            ->assertRedirect();

        $this->assertDatabaseCount('productos', 1);
    }

    public function test_codigo_duplicado_es_rechazado(): void
    {
        $admin = $this->usuario('administrador');
        Producto::factory()->create(['codigo' => 'REP-1']);

        $this->actingAs($admin)
            ->post(route('productos.store'), $this->datosProductoValido(['codigo' => 'REP-1']))
            ->assertSessionHasErrors('codigo');
    }

    public function test_codigo_barra_duplicado_es_rechazado_cuando_viene_lleno(): void
    {
        $admin = $this->usuario('administrador');
        Producto::factory()->create(['codigo' => 'A-1', 'codigo_barra' => '7501234567890']);

        $this->actingAs($admin)
            ->post(route('productos.store'), $this->datosProductoValido([
                'codigo' => 'A-2',
                'codigo_barra' => '7501234567890',
            ]))
            ->assertSessionHasErrors('codigo_barra');
    }

    public function test_codigo_barra_vacio_no_choca_con_otros_vacios(): void
    {
        $admin = $this->usuario('administrador');
        Producto::factory()->create(['codigo' => 'B-1', 'codigo_barra' => null]);

        $this->actingAs($admin)
            ->post(route('productos.store'), $this->datosProductoValido([
                'codigo' => 'B-2',
                'codigo_barra' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('productos', 2);
    }

    public function test_precio_negativo_es_rechazado(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('productos.store'), $this->datosProductoValido(['precio_unitario' => '-5']))
            ->assertSessionHasErrors('precio_unitario');

        $this->assertDatabaseCount('productos', 0);
    }

    public function test_tipo_impuesto_invalido_es_rechazado(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('productos.store'), $this->datosProductoValido(['tipo_impuesto' => 'inventado']))
            ->assertSessionHasErrors('tipo_impuesto');
    }

    public function test_tipo_producto_invalido_es_rechazado(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('productos.store'), $this->datosProductoValido(['tipo_producto' => '9']))
            ->assertSessionHasErrors('tipo_producto');
    }

    public function test_unidad_medida_invalida_es_rechazada(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('productos.store'), $this->datosProductoValido(['unidad_medida_id' => 99999]))
            ->assertSessionHasErrors('unidad_medida_id');
    }

    public function test_administrador_edita_producto(): void
    {
        $admin = $this->usuario('administrador');
        $producto = Producto::factory()->create(['nombre' => 'Viejo']);

        $this->actingAs($admin)->put(route('productos.update', $producto), $this->datosProductoValido([
            'codigo' => $producto->codigo,
            'nombre' => 'Nuevo',
        ]))->assertRedirect(route('productos.show', $producto));

        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'nombre' => 'Nuevo']);
    }

    public function test_toggle_activo(): void
    {
        $admin = $this->usuario('administrador');
        $producto = Producto::factory()->create(['activo' => true]);

        $this->actingAs($admin)->patch(route('productos.toggle-activo', $producto))->assertRedirect();

        $this->assertFalse($producto->fresh()->activo);
    }

    public function test_soft_delete(): void
    {
        $admin = $this->usuario('administrador');
        $producto = Producto::factory()->create();

        $this->actingAs($admin)->delete(route('productos.destroy', $producto))->assertRedirect(route('productos.index'));

        $this->assertSoftDeleted('productos', ['id' => $producto->id]);
    }

    public function test_consulta_no_puede_eliminar(): void
    {
        $producto = Producto::factory()->create();

        $this->actingAs($this->usuario('consulta'))
            ->delete(route('productos.destroy', $producto))
            ->assertForbidden();
    }

    public function test_admin_puede_ver_formularios(): void
    {
        $admin = $this->usuario('administrador');
        $producto = Producto::factory()->create();

        $this->actingAs($admin)->get(route('productos.create'))->assertOk()->assertSee('Tipo de producto');
        $this->actingAs($admin)->get(route('productos.edit', $producto))->assertOk();
        $this->actingAs($admin)->get(route('productos.show', $producto))->assertOk()->assertSee('Historial de auditoría');
    }

    public function test_busqueda_por_codigo(): void
    {
        $admin = $this->usuario('administrador');
        Producto::factory()->create(['codigo' => 'ABC-123', 'nombre' => 'Caramelo']);
        Producto::factory()->create(['codigo' => 'XYZ-999', 'nombre' => 'Chocolate']);

        $this->actingAs($admin)->get(route('productos.index', ['q' => 'ABC-123']))
            ->assertOk()
            ->assertSee('Caramelo')
            ->assertDontSee('Chocolate');
    }
}
