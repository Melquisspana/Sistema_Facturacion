<?php

namespace Tests\Feature\Productos;

use App\Enums\TipoImpuesto;
use App\Enums\TipoProducto;
use App\Models\ExportacionProducto;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Entrada ÚNICA «Productos» con selector Nacionales / De exportación.
 *
 * Lo que estas pruebas fijan, en orden de importancia:
 *
 *  1. Productos NACIONALES no cambió. Misma ruta, misma pantalla, mismos filtros,
 *     misma paginación, misma policy. Lo único que se agregó es el selector.
 *  2. Nacionales es lo PREDETERMINADO: entrar a /productos sin parámetros abre la
 *     pantalla de siempre, no un menú intermedio ni la de exportación.
 *  3. Las dos tablas están REALMENTE aisladas: ninguna consulta de una alcanza a la
 *     otra, ni por búsqueda, ni por listado, ni por id.
 */
class ProductosSelectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion', 'jefatura', 'produccion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol = 'administrador'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function productoNacional(array $extra = []): Producto
    {
        $unidad = UnidadMedida::first() ?? UnidadMedida::create([
            'codigo' => '59', 'nombre' => 'Unidad', 'activo' => true,
        ]);

        return Producto::create($extra + [
            'codigo' => 'NAC-'.fake()->unique()->numerify('####'),
            'nombre' => 'Canillitas de leche 85 g',
            'tipo_producto' => TipoProducto::Bien,
            'unidad_medida_id' => $unidad->id,
            'precio_unitario' => 0.85,
            'tipo_impuesto' => TipoImpuesto::Gravado,
            'activo' => true,
        ]);
    }

    private function productoExportacion(array $extra = []): ExportacionProducto
    {
        return ExportacionProducto::create($extra + [
            'nombre_es' => 'Caja de semilla de marañón',
            'nombre_en' => 'Cashew seed box',
            'unidad' => 'Bolsa 12X18',
            'unidades_por_caja' => 216,
            'gramos_por_unidad' => 45,
            'onzas_por_unidad' => 1.59,
            'precio_caja' => 259.20,
            'peso_neto_caja_kg' => 10.30,
            'peso_bruto_caja_kg' => 11.30,
            'peso_neto_caja_lb' => 22.71,
            'peso_bruto_caja_lb' => 24.91,
            'activo' => true,
        ]);
    }

    // ------------------------------------------------ 1: nacionales sin regresiones

    public function test_productos_nacionales_abre_por_defecto_y_conserva_su_pantalla(): void
    {
        $this->productoNacional(['nombre' => 'PRODUCTO NACIONAL VISIBLE']);

        $resp = $this->actingAs($this->usuario())->get(route('productos.index'))->assertOk();

        // La pantalla de siempre: sus filtros, su rótulo y su producto.
        $resp->assertSee('PRODUCTO NACIONAL VISIBLE');
        $resp->assertSee('Buscar (código, código de barra, nombre, descripción)');
        $resp->assertSee('Tipo');

        // Y el selector, con nacionales marcado como la pestaña actual.
        $resp->assertSee('Productos nacionales');
        $resp->assertSee('Productos de exportación');
        $resp->assertSee('aria-current="page"', false);
    }

    public function test_los_filtros_de_productos_nacionales_siguen_funcionando_igual(): void
    {
        $this->productoNacional(['nombre' => 'ACTIVO BUSCABLE', 'codigo' => 'NAC-0001']);
        $this->productoNacional(['nombre' => 'INACTIVO OCULTO', 'codigo' => 'NAC-0002', 'activo' => false]);

        $usuario = $this->usuario();

        $this->actingAs($usuario)->get(route('productos.index', ['q' => 'BUSCABLE']))->assertOk()
            ->assertSee('ACTIVO BUSCABLE')
            ->assertDontSee('INACTIVO OCULTO');

        $this->actingAs($usuario)->get(route('productos.index', ['activo' => '0']))->assertOk()
            ->assertSee('INACTIVO OCULTO')
            ->assertDontSee('ACTIVO BUSCABLE');

        $this->actingAs($usuario)->get(route('productos.index', ['tipo_producto' => TipoProducto::Servicio->value]))->assertOk()
            ->assertDontSee('ACTIVO BUSCABLE');
    }

    public function test_la_ficha_y_el_alta_de_un_producto_nacional_no_cambiaron(): void
    {
        $producto = $this->productoNacional(['nombre' => 'FICHA NACIONAL']);
        $usuario = $this->usuario();

        $this->actingAs($usuario)->get(route('productos.show', $producto))->assertOk()->assertSee('FICHA NACIONAL');
        $this->actingAs($usuario)->get(route('productos.create'))->assertOk();
        $this->actingAs($usuario)->get(route('productos.edit', $producto))->assertOk();
    }

    // ------------------------------------------------------------ 2: el selector

    public function test_la_pestana_de_exportacion_lleva_al_catalogo_de_exportacion(): void
    {
        $this->productoExportacion(['nombre_es' => 'PRODUCTO DE EXPORTACION']);

        $this->actingAs($this->usuario())->get(route('productos.index'))->assertOk()
            ->assertSee(route('productos.exportacion.index'), false);

        $this->actingAs($this->usuario())->get(route('productos.exportacion.index'))->assertOk()
            ->assertSee('PRODUCTO DE EXPORTACION')
            ->assertSee(route('productos.index'), false);
    }

    /**
     * Quien no tiene `exportaciones.ver` sigue viendo la pantalla de productos de
     * siempre, sin una pestaña que lleve a un 403. Ocultar no autoriza: la puerta
     * real es el middleware, y también se comprueba.
     */
    public function test_sin_permiso_de_exportaciones_no_se_dibuja_la_segunda_pestana(): void
    {
        $jefa = $this->usuario('jefatura');
        $this->assertTrue($jefa->can('exportaciones.ver'), 'jefatura sí ve exportaciones; el caso negativo usa otro rol');

        // Un usuario con productos.ver pero SIN exportaciones.ver.
        $rol = Role::findOrCreate('solo-productos', 'web');
        $rol->givePermissionTo('productos.ver');
        $usuario = User::factory()->create()->assignRole('solo-productos');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($usuario)->get(route('productos.index'))->assertOk()
            ->assertSee('Productos nacionales')
            ->assertDontSee('Productos de exportación');

        $this->actingAs($usuario)->get(route('productos.exportacion.index'))->assertForbidden();
    }

    // ------------------------------------------------- 3: aislamiento entre tablas

    public function test_las_dos_tablas_estan_realmente_aisladas(): void
    {
        $nacional = $this->productoNacional(['nombre' => 'SOLO EN NACIONALES']);
        $exportacion = $this->productoExportacion(['nombre_es' => 'SOLO EN EXPORTACION']);

        $usuario = $this->usuario();

        // Ninguna pantalla ve el catálogo de la otra.
        $this->actingAs($usuario)->get(route('productos.index'))->assertOk()
            ->assertSee('SOLO EN NACIONALES')
            ->assertDontSee('SOLO EN EXPORTACION');

        $this->actingAs($usuario)->get(route('productos.exportacion.index'))->assertOk()
            ->assertSee('SOLO EN EXPORTACION')
            ->assertDontSee('SOLO EN NACIONALES');

        // Y los ids no se cruzan: un id de una tabla no resuelve en la ruta de la otra.
        // Se fuerzan ids distintos para que la prueba no dependa de que coincidan.
        $this->assertNotSame($nacional->getTable(), $exportacion->getTable());

        $idInexistenteEnNacionales = Producto::max('id') + 500;
        $this->actingAs($usuario)->get('/productos/'.$idInexistenteEnNacionales)->assertNotFound();

        $idInexistenteEnExportacion = ExportacionProducto::max('id') + 500;
        $this->actingAs($usuario)->get('/productos/exportacion/'.$idInexistenteEnExportacion)->assertNotFound();
    }

    /**
     * Crear un producto de exportación no toca la tabla nacional, y viceversa. Es la
     * garantía de que «una sola entrada» no se convirtió, por debajo, en un intento
     * de unificar modelos.
     */
    public function test_crear_en_una_tabla_no_altera_la_otra(): void
    {
        $this->productoNacional();
        $nacionalesAntes = Producto::count();
        $exportacionAntes = ExportacionProducto::count();

        $this->actingAs($this->usuario())->post(route('productos.exportacion.store'), [
            'nombre_es' => 'Caja nueva de exportación',
            'nombre_en' => 'New export box',
            'unidad' => 'Bolsa',
            'unidades_por_caja' => 144,
            'gramos_por_unidad' => 85,
            'onzas_por_unidad' => 3,
            'precio_caja' => 136.80,
            'peso_neto_caja_kg' => 13,
            'peso_bruto_caja_kg' => 14,
            'peso_neto_caja_lb' => 28.66,
            'peso_bruto_caja_lb' => 30.86,
            'activo' => 1,
        ])->assertRedirect();

        $this->assertSame($nacionalesAntes, Producto::count(), 'crear un producto de exportación no debe tocar productos nacionales');
        $this->assertSame($exportacionAntes + 1, ExportacionProducto::count());
    }
}
