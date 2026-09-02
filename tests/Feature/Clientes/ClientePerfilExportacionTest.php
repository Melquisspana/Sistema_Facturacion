<?php

namespace Tests\Feature\Clientes;

use App\Models\Cliente;
use App\Models\ExportacionClienteProducto;
use App\Models\ExportacionProducto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Perfil de exportación DENTRO de la ficha del cliente.
 *
 * La regla que estas pruebas protegen es que el cliente sigue siendo UNO: no hay un
 * segundo directorio, no se duplica el nombre ni la dirección fiscal, y habilitar o
 * deshabilitar la exportación no crea ni borra registros de cliente.
 */
class ClientePerfilExportacionTest extends TestCase
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
            'nombre_es' => 'Caja de camote',
            'nombre_en' => 'Sweet potato candy box',
            'unidad' => 'Bolsa',
            'unidades_por_caja' => 144,
            'gramos_por_unidad' => 85,
            'onzas_por_unidad' => 3,
            'precio_caja' => 144.00,
            'peso_neto_caja_kg' => 19.40,
            'peso_bruto_caja_kg' => 20.40,
            'peso_neto_caja_lb' => 42.77,
            'peso_bruto_caja_lb' => 44.97,
            'activo' => true,
        ]);
    }

    // ------------------------------------------- cliente normal vs de exportación

    public function test_un_cliente_nacional_no_muestra_el_bloque_de_exportacion(): void
    {
        $nacional = Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario())->get(route('clientes.show', $nacional))->assertOk()
            ->assertDontSee('Habilitar para exportación')
            ->assertDontSee('FDA del importador');
    }

    public function test_un_cliente_de_exportacion_ofrece_habilitarlo_sin_duplicarlo(): void
    {
        $cliente = Cliente::factory()->exportacion()->create(['nombre' => 'SOLFI GROUP INC']);
        $clientesAntes = Cliente::count();

        $this->actingAs($this->usuario())->get(route('clientes.show', $cliente))->assertOk()
            ->assertSee('Habilitar para exportación');

        $this->actingAs($this->usuario())
            ->post(route('clientes.exportacion.habilitar', $cliente))
            ->assertRedirect(route('clientes.show', $cliente))
            ->assertSessionHas('status');

        // No nació ningún cliente nuevo: solo un perfil colgando del que ya existía.
        $this->assertSame($clientesAntes, Cliente::count());
        $this->assertSame(1, $cliente->exportacionClientes()->count());

        $perfil = $cliente->exportacionClientes()->first();
        $this->assertTrue($perfil->activo);
        $this->assertSame($cliente->id, $perfil->cliente_id);
        // El nombre legal siempre resuelve al directorio, no a una copia editable.
        $this->assertSame('SOLFI GROUP INC', $perfil->nombreLegal());
    }

    public function test_deshabilitar_conserva_perfil_precios_e_historico(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $producto = $this->producto();

        $this->actingAs($this->usuario())->post(route('clientes.exportacion.habilitar', $cliente));
        $perfil = $cliente->exportacionClientes()->first();

        ExportacionClienteProducto::create([
            'exportacion_cliente_id' => $perfil->id,
            'exportacion_producto_id' => $producto->id,
            'precio_caja' => 150.00,
            'activo' => true,
        ]);

        $this->actingAs($this->usuario())
            ->post(route('clientes.exportacion.deshabilitar', $cliente))
            ->assertSessionHas('status');

        $perfil->refresh();
        $this->assertFalse($perfil->activo);
        $this->assertSame(1, $perfil->productos()->count(), 'deshabilitar no puede borrar la lista de precios');

        // Y volver a habilitarlo reutiliza el MISMO perfil, no crea otro.
        $this->actingAs($this->usuario())->post(route('clientes.exportacion.habilitar', $cliente));
        $this->assertSame(1, $cliente->exportacionClientes()->count());
        $this->assertTrue($perfil->fresh()->activo);
    }

    public function test_habilitar_esta_reservado_a_clientes_de_tipo_exportacion(): void
    {
        $nacional = Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario())
            ->post(route('clientes.exportacion.habilitar', $nacional))
            ->assertSessionHasErrors('tipo_cliente');

        $this->assertSame(0, $nacional->exportacionClientes()->count());
    }

    // ------------------------------------- solo los campos internacionales que faltan

    public function test_solo_se_piden_los_campos_que_el_directorio_no_tiene(): void
    {
        $cliente = Cliente::factory()->exportacion()->create([
            'nombre' => 'DIAMOND ROCKS FOODS IMPORTS INC.',
            'direccion' => '1199 SUNRISE HWY. COPIAGUE NY 11726',
        ]);
        $this->actingAs($this->usuario())->post(route('clientes.exportacion.habilitar', $cliente));

        $resp = $this->actingAs($this->usuario())->get(route('clientes.show', $cliente))->assertOk();

        // Los tres campos propios del embarque, y ninguno más.
        $resp->assertSee('FDA del importador');
        $resp->assertSee('Contacto del embarque');
        $resp->assertSee('Dirección de entrega o bodega');

        // El nombre y el documento NO se vuelven a pedir dentro del bloque: se leen
        // de la ficha, que es la fuente de verdad.
        $resp->assertDontSee('name="nombre"', false);
        $resp->assertDontSee('name="num_documento"', false);
    }

    public function test_guardar_el_perfil_no_toca_los_datos_del_cliente(): void
    {
        $cliente = Cliente::factory()->exportacion()->create([
            'nombre' => 'NOMBRE LEGAL', 'direccion' => 'DIRECCION FISCAL',
        ]);
        $this->actingAs($this->usuario())->post(route('clientes.exportacion.habilitar', $cliente));

        $this->actingAs($this->usuario())->put(route('clientes.exportacion.update', $cliente), [
            'fda_reg_number' => '99887766',
            'contacto' => 'smartinez@diamondrockfoods.com',
            'direccion' => 'BODEGA 456',
        ])->assertRedirect(route('clientes.show', $cliente));

        $cliente->refresh();
        $this->assertSame('NOMBRE LEGAL', $cliente->nombre);
        $this->assertSame('DIRECCION FISCAL', $cliente->direccion);

        $perfil = $cliente->exportacionClientes()->first();
        $this->assertSame('99887766', $perfil->fda_reg_number);
        $this->assertSame('BODEGA 456', $perfil->direccion);
        // El nombre operativo se mantiene alineado con el del directorio.
        $this->assertSame('NOMBRE LEGAL', $perfil->nombre);
    }

    // -------------------------------------------------------- lista de precios

    public function test_la_lista_de_precios_se_administra_desde_la_ficha(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $producto = $this->producto(['nombre_es' => 'CAJA DE CAMOTE']);
        $this->actingAs($this->usuario())->post(route('clientes.exportacion.habilitar', $cliente));

        $this->actingAs($this->usuario())->post(route('clientes.exportacion.productos.store', $cliente), [
            'exportacion_producto_id' => $producto->id,
            'precio_caja' => 160.00,
        ])->assertSessionHas('status');

        $asignacion = ExportacionClienteProducto::firstOrFail();
        $this->assertSame('160.00', (string) $asignacion->precio_caja);

        $this->actingAs($this->usuario())->get(route('clientes.show', $cliente))->assertOk()
            ->assertSee('CAJA DE CAMOTE')
            ->assertSee('160.00');

        // Cambiar el precio.
        $this->actingAs($this->usuario())->patch(route('clientes.exportacion.productos.update', [$cliente, $asignacion]), [
            'precio_caja' => 175.25,
            'confirmar_cero' => 1,
        ])->assertSessionHas('status');
        $this->assertSame('175.25', (string) $asignacion->fresh()->precio_caja);

        // Deshabilitar sin perder el precio.
        $this->actingAs($this->usuario())->patch(route('clientes.exportacion.productos.update', [$cliente, $asignacion]), [
            'toggle_activo' => 1,
        ]);
        $this->assertFalse($asignacion->fresh()->activo);
        $this->assertSame('175.25', (string) $asignacion->fresh()->precio_caja);
    }

    public function test_un_precio_en_cero_exige_confirmacion_explicita(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $producto = $this->producto();
        $this->actingAs($this->usuario())->post(route('clientes.exportacion.habilitar', $cliente));

        $this->actingAs($this->usuario())->post(route('clientes.exportacion.productos.store', $cliente), [
            'exportacion_producto_id' => $producto->id,
            'precio_caja' => 0,
        ])->assertSessionHasErrors('precio_caja');

        $this->assertSame(0, ExportacionClienteProducto::count());

        $this->actingAs($this->usuario())->post(route('clientes.exportacion.productos.store', $cliente), [
            'exportacion_producto_id' => $producto->id,
            'precio_caja' => 0,
            'confirmar_cero' => 1,
        ])->assertSessionHas('status');

        $this->assertSame(1, ExportacionClienteProducto::count());
    }

    public function test_no_se_puede_tocar_el_precio_de_otro_cliente(): void
    {
        $clienteA = Cliente::factory()->exportacion()->create();
        $clienteB = Cliente::factory()->exportacion()->create();
        $producto = $this->producto();

        $this->actingAs($this->usuario())->post(route('clientes.exportacion.habilitar', $clienteA));
        $this->actingAs($this->usuario())->post(route('clientes.exportacion.habilitar', $clienteB));

        $perfilB = $clienteB->exportacionClientes()->first();
        $deB = ExportacionClienteProducto::create([
            'exportacion_cliente_id' => $perfilB->id,
            'exportacion_producto_id' => $producto->id,
            'precio_caja' => 100,
            'activo' => true,
        ]);

        $this->actingAs($this->usuario())
            ->patch(route('clientes.exportacion.productos.update', [$clienteA, $deB]), ['precio_caja' => 1])
            ->assertNotFound();

        $this->assertSame('100.00', (string) $deB->fresh()->precio_caja);
    }

    // -------------------------------------------------------------------- permisos

    public function test_sin_permiso_de_gestion_el_bloque_es_de_solo_lectura(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $this->actingAs($this->usuario())->post(route('clientes.exportacion.habilitar', $cliente));

        $jefa = $this->usuario('jefatura');

        $this->actingAs($jefa)->get(route('clientes.show', $cliente))->assertOk()
            ->assertSee('Exportación')
            ->assertDontSee('Guardar datos de exportación');

        $this->actingAs($jefa)->put(route('clientes.exportacion.update', $cliente), [])->assertForbidden();
        $this->actingAs($jefa)->post(route('clientes.exportacion.deshabilitar', $cliente))->assertForbidden();
    }
}
