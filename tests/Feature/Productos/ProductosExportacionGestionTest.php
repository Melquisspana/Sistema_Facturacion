<?php

namespace Tests\Feature\Productos;

use App\Models\Cliente;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionClienteProducto;
use App\Models\ExportacionProducto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Gestión del catálogo de productos de exportación: búsqueda, filtros, paginación,
 * ficha con clientes y precios, y —lo importante— ARCHIVAR en vez de borrar.
 *
 * El borrado físico era el único riesgo de pérdida vigente del módulo:
 * `exportacion_cliente_productos` tiene la FK con `cascadeOnDelete`, así que borrar
 * un producto se llevaba por delante, sin aviso, los precios negociados con cada
 * importador. Esos precios no se pueden reconstruir desde el precio base.
 */
class ProductosExportacionGestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion', 'jefatura'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol = 'administrador'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function producto(array $extra = []): ExportacionProducto
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

    // ------------------------------------------------ búsqueda, filtros, paginación

    public function test_busqueda_por_nombre_espanol_ingles_codigo_y_empaque(): void
    {
        $this->producto(['nombre_es' => 'Caja de camote', 'nombre_en' => 'Sweet potato candy', 'codigo' => 'EXP-CAM', 'unidad' => 'Bolsa polipropileno']);
        $this->producto(['nombre_es' => 'Caja de nance', 'nombre_en' => 'Yellow cherry candy', 'codigo' => 'EXP-NAN', 'unidad' => 'Caja de cartón']);

        $usuario = $this->usuario();

        foreach (['camote', 'Sweet potato', 'EXP-CAM', 'polipropileno'] as $termino) {
            $this->actingAs($usuario)->get(route('productos.exportacion.index', ['q' => $termino]))->assertOk()
                ->assertSee('Caja de camote')
                ->assertDontSee('Caja de nance');
        }
    }

    public function test_filtro_de_estado_tiene_tres_posiciones_y_activos_es_la_predeterminada(): void
    {
        $this->producto(['nombre_es' => 'PRODUCTO ACTIVO', 'activo' => true]);
        $this->producto(['nombre_es' => 'PRODUCTO ARCHIVADO', 'activo' => false]);

        $usuario = $this->usuario();

        // Por defecto: solo activos.
        $this->actingAs($usuario)->get(route('productos.exportacion.index'))->assertOk()
            ->assertSee('PRODUCTO ACTIVO')
            ->assertDontSee('PRODUCTO ARCHIVADO');

        // Solo archivados: la posición que el filtro anterior («incluir inactivos») no tenía.
        $this->actingAs($usuario)->get(route('productos.exportacion.index', ['activo' => '0']))->assertOk()
            ->assertSee('PRODUCTO ARCHIVADO')
            ->assertDontSee('PRODUCTO ACTIVO');

        // Todos.
        $this->actingAs($usuario)->get(route('productos.exportacion.index', ['activo' => '']))->assertOk()
            ->assertSee('PRODUCTO ACTIVO')
            ->assertSee('PRODUCTO ARCHIVADO');
    }

    public function test_el_listado_pagina_de_quince_en_quince(): void
    {
        for ($i = 1; $i <= 17; $i++) {
            $this->producto(['nombre_es' => sprintf('Producto %02d', $i)]);
        }

        $primera = $this->actingAs($this->usuario())->get(route('productos.exportacion.index'))->assertOk();
        $primera->assertSee('Producto 01');
        $primera->assertDontSee('Producto 17');

        $this->actingAs($this->usuario())->get(route('productos.exportacion.index', ['page' => 2]))->assertOk()
            ->assertSee('Producto 17');
    }

    // --------------------------------------------------- ficha: clientes y precios

    public function test_la_ficha_muestra_los_clientes_que_lo_compran_y_su_precio(): void
    {
        $producto = $this->producto(['precio_caja' => 100.00]);

        $clienteDte = Cliente::factory()->exportacion()->create(['nombre' => 'CAROLINAS WHOLESALE LLC']);
        $perfil = ExportacionCliente::create(['cliente_id' => $clienteDte->id, 'nombre' => 'CAROLINAS', 'activo' => true]);
        ExportacionClienteProducto::create([
            'exportacion_cliente_id' => $perfil->id,
            'exportacion_producto_id' => $producto->id,
            'precio_caja' => 120.50,
            'activo' => true,
        ]);

        $this->actingAs($this->usuario())->get(route('productos.exportacion.show', $producto))->assertOk()
            ->assertSee('CAROLINAS WHOLESALE LLC')
            ->assertSee('120.50')
            // Diferencia contra el precio base, que es la pregunta real al mirar la ficha.
            ->assertSee('20.50');
    }

    // ------------------------------------------------------ archivar, no borrar

    public function test_no_se_puede_borrar_un_producto_con_precios_de_cliente(): void
    {
        $producto = $this->producto();
        $perfil = ExportacionCliente::create(['nombre' => 'CAROLINAS', 'activo' => true]);
        $asignacion = ExportacionClienteProducto::create([
            'exportacion_cliente_id' => $perfil->id,
            'exportacion_producto_id' => $producto->id,
            'precio_caja' => 120.50,
            'activo' => true,
        ]);

        $this->actingAs($this->usuario())
            ->delete(route('productos.exportacion.destroy', $producto))
            ->assertRedirect(route('productos.exportacion.show', $producto))
            ->assertSessionHas('error');

        // Ni el producto ni —sobre todo— el precio negociado desaparecieron.
        $this->assertNotNull(ExportacionProducto::find($producto->id));
        $this->assertNotNull(ExportacionClienteProducto::find($asignacion->id));
        $this->assertSame('120.50', (string) $asignacion->fresh()->precio_caja);
    }

    public function test_no_se_puede_borrar_un_producto_que_aparece_en_una_lista(): void
    {
        $producto = $this->producto();
        $lista = Exportacion::create([
            'cliente_nombre' => 'CLIENTE', 'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-09-01', 'estado' => Exportacion::ESTADO_BORRADOR,
        ]);
        $lista->items()->create([
            'exportacion_producto_id' => $producto->id,
            'nombre_es' => 'Caja', 'nombre_en' => 'Box', 'unidad' => 'Bolsa',
            'unidades_por_caja' => 216, 'cantidad_cajas' => 5, 'precio_caja' => 259.20,
            'gramos_por_unidad' => 45, 'onzas_por_unidad' => 1.59,
            'peso_neto_caja_kg' => 10.3, 'peso_bruto_caja_kg' => 11.3,
            'peso_neto_caja_lb' => 22.71, 'peso_bruto_caja_lb' => 24.91,
        ]);

        $this->actingAs($this->usuario())
            ->delete(route('productos.exportacion.destroy', $producto))
            ->assertSessionHas('error');

        $this->assertNotNull(ExportacionProducto::find($producto->id));
    }

    public function test_archivar_conserva_el_historico_y_lo_saca_de_las_listas_nuevas(): void
    {
        $producto = $this->producto(['nombre_es' => 'PRODUCTO A ARCHIVAR']);
        $perfil = ExportacionCliente::create(['nombre' => 'CAROLINAS', 'activo' => true]);
        $asignacion = ExportacionClienteProducto::create([
            'exportacion_cliente_id' => $perfil->id,
            'exportacion_producto_id' => $producto->id,
            'precio_caja' => 120.50,
            'activo' => true,
        ]);

        $this->actingAs($this->usuario())
            ->patch(route('productos.exportacion.toggle-activo', $producto))
            ->assertSessionHas('status');

        $producto->refresh();
        $this->assertFalse($producto->activo);

        // El precio del cliente sigue exactamente igual.
        $this->assertNotNull(ExportacionClienteProducto::find($asignacion->id));
        $this->assertSame('120.50', (string) $asignacion->fresh()->precio_caja);

        // Y ya no se ofrece al armar una lista nueva.
        $this->actingAs($this->usuario())->get(route('facturacion.listas.create'))->assertOk()
            ->assertDontSee('PRODUCTO A ARCHIVAR');

        // Reactivarlo lo devuelve sin recargar nada.
        $this->actingAs($this->usuario())->patch(route('productos.exportacion.toggle-activo', $producto));
        $this->assertTrue($producto->fresh()->activo);
    }

    public function test_un_producto_sin_referencias_si_se_puede_borrar(): void
    {
        $producto = $this->producto();

        $this->actingAs($this->usuario())
            ->delete(route('productos.exportacion.destroy', $producto))
            ->assertRedirect(route('productos.exportacion.index'))
            ->assertSessionHas('status');

        $this->assertNull(ExportacionProducto::find($producto->id));
    }

    // ------------------------------------------------------------------ permisos

    public function test_lectura_sin_gestion_no_ve_las_acciones_de_escritura(): void
    {
        $producto = $this->producto();
        $jefa = $this->usuario('jefatura');

        $this->actingAs($jefa)->get(route('productos.exportacion.index'))->assertOk()
            ->assertDontSee(route('productos.exportacion.create'), false);

        $this->actingAs($jefa)->get(route('productos.exportacion.show', $producto))->assertOk()
            ->assertDontSee(route('productos.exportacion.edit', $producto), false);

        $this->actingAs($jefa)->get(route('productos.exportacion.create'))->assertForbidden();
        $this->actingAs($jefa)->delete(route('productos.exportacion.destroy', $producto))->assertForbidden();
    }
}
