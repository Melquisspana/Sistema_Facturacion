<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Pais;
use App\Models\PedidoSala;
use App\Models\PedidoSalaEnvio;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\FacturarPedido;
use App\Services\Rutas\ResumenDiarioPedidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Tienda nueva (Melqui, 10/11/2026): el vendedor escribe solo el nombre al sacar el pedido;
 * la oficina, al facturar, da de alta el cliente y la sala con el formulario de Clientes y
 * la enlaza al pedido. No se factura sin sala enlazada. Datos ficticios.
 */
class TiendaNuevaTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private User $oficina;

    private User $admin;

    private User $fabrica;

    private PersonalRuta $persona;

    private Producto $galleta;

    private Ruta $ruta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09 09:00:00');
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->admin = User::factory()->create(['activo' => true])->assignRole('administrador');
        $this->fabrica = User::factory()->create(['activo' => true])->assignRole('fabrica');
        $this->persona = PersonalRuta::create(['nombre' => 'Vendedor de prueba', 'user_id' => $this->vendedor->id]);
        $this->ruta = Ruta::create(['nombre' => 'Ruta de prueba']);

        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa', 'activo' => true]);
        PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01', 'precio_unitario' => 1.00]);
    }

    /** Un pedido de tienda nueva que ya salió de la fábrica, listo para facturar salvo la sala. */
    private function pedidoConEnvio(string $nombre = 'Tienda Nueva de Prueba'): PedidoSala
    {
        $pedido = PedidoSala::create([
            'cliente_sucursal_id' => null, 'sala_nueva_nombre' => $nombre,
            'sacado_por_id' => $this->persona->id, 'registrado_por' => $this->vendedor->id,
            'fecha' => '2026-11-09', 'estado' => EstadoPedidoSala::Enviado,
        ]);
        $linea = $pedido->lineas()->create(['producto_id' => $this->galleta->id, 'cantidad' => '6']);
        $envio = $pedido->envios()->create([
            'numero' => 1, 'clave' => (string) Str::uuid(), 'fecha' => '2026-11-09', 'registrado_por' => $this->fabrica->id,
        ]);
        $envio->lineas()->create(['pedido_sala_linea_id' => $linea->id, 'cantidad' => '6']);

        return $pedido;
    }

    private function salaDePrueba(): ClienteSucursal
    {
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba', 'requiere_orden_compra' => false]);

        return $cliente->sucursales()->create(['nombre' => 'Sala de prueba Norte', 'codigo' => '9001', 'ruta_id' => $this->ruta->id]);
    }

    // ------------------------------------------------------------------ el vendedor

    public function test_el_vendedor_saca_el_pedido_de_una_tienda_nueva_solo_con_el_nombre(): void
    {
        $this->actingAs($this->vendedor);
        $this->get(route('rutas.sacar-pedido.index', ['q' => 'chapina']))->assertOk()
            ->assertSee('Tienda nueva')
            ->assertSee('action="'.route('rutas.sacar-pedido.tienda-nueva.create').'"', false);
        $this->get(route('rutas.sacar-pedido.tienda-nueva.create', ['nombre' => '  Tienda   La Chapina ']))->assertOk()
            ->assertSee('Tienda nueva')->assertSee('value="Tienda La Chapina"', false)
            ->assertSee('action="'.route('rutas.sacar-pedido.tienda-nueva.store').'"', false);

        $this->post(route('rutas.sacar-pedido.tienda-nueva.store'), [
            'sala_nueva_nombre' => '  Tienda   La Chapina ', 'cantidades' => [$this->galleta->id => '12'],
        ])->assertSessionHasNoErrors()->assertRedirect(route('rutas.sacar-pedido.index'));

        $pedido = PedidoSala::sole();
        $this->assertNull($pedido->cliente_sucursal_id);
        $this->assertSame('Tienda La Chapina', $pedido->sala_nueva_nombre);
        $this->assertTrue($pedido->esTiendaNueva());
        $this->assertSame($this->persona->id, $pedido->sacado_por_id);
        // Se suma a su viaje como cualquier pedido.
        $this->assertTrue(SalidaRuta::sole()->is($pedido->salida));
        $this->get(route('rutas.sacar-pedido.index'))->assertOk()->assertSee('Tienda La Chapina (tienda nueva)');
    }

    public function test_la_tienda_nueva_pide_el_nombre(): void
    {
        $this->actingAs($this->vendedor);
        $this->post(route('rutas.sacar-pedido.tienda-nueva.store'), ['sala_nueva_nombre' => ' ', 'cantidades' => [$this->galleta->id => '12']])
            ->assertSessionHasErrors('sala_nueva_nombre');
        $this->post(route('rutas.sacar-pedido.tienda-nueva.store'), ['sala_nueva_nombre' => str_repeat('a', 101), 'cantidades' => [$this->galleta->id => '12']])
            ->assertSessionHasErrors('sala_nueva_nombre');
        $this->assertSame(0, PedidoSala::count());
    }

    public function test_el_vendedor_corrige_y_anula_el_pedido_de_la_tienda_nueva(): void
    {
        $this->actingAs($this->vendedor);
        $this->post(route('rutas.sacar-pedido.tienda-nueva.store'), ['sala_nueva_nombre' => 'Tienda La Chapina', 'cantidades' => [$this->galleta->id => '12']]);
        $pedido = PedidoSala::sole();

        $this->get(route('rutas.sacar-pedido.edit', $pedido))->assertOk()->assertSee('value="Tienda La Chapina"', false);
        $this->put(route('rutas.sacar-pedido.update', $pedido), ['sala_nueva_nombre' => 'Tienda La Chapina 2', 'cantidades' => [$this->galleta->id => '8']])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Pedido de «Tienda La Chapina 2 (tienda nueva)» corregido.');
        $this->assertSame('Tienda La Chapina 2', $pedido->refresh()->sala_nueva_nombre);
        $this->assertEquals(8, (float) $pedido->lineas()->sole()->cantidad);

        $this->patch(route('rutas.sacar-pedido.anular', $pedido))
            ->assertSessionHas('status', 'Pedido de «Tienda La Chapina 2 (tienda nueva)» anulado. Si hace falta, sacalo de nuevo.');
        $this->assertSame(EstadoPedidoSala::Anulado, $pedido->refresh()->estado);
    }

    // ------------------------------------------------------- fábrica, hoja y correo

    public function test_la_fabrica_la_hoja_la_lista_y_el_correo_muestran_el_nombre_de_la_tienda_nueva(): void
    {
        $pedido = $this->pedidoConEnvio();
        $porMandar = PedidoSala::create([
            'cliente_sucursal_id' => null, 'sala_nueva_nombre' => 'Tienda Por Mandar', 'sacado_por_id' => $this->persona->id,
            'registrado_por' => $this->vendedor->id, 'fecha' => '2026-11-09', 'estado' => EstadoPedidoSala::Registrado,
        ]);
        $porMandar->lineas()->create(['producto_id' => $this->galleta->id, 'cantidad' => '3']);

        $this->actingAs($this->fabrica)->get(route('fabrica.pedidos.index'))->assertOk()->assertSee('Tienda Por Mandar (tienda nueva)');
        $this->actingAs($this->fabrica)->get(route('fabrica.pedidos.show', $porMandar))->assertOk()->assertSee('Tienda Por Mandar (tienda nueva)');

        $this->actingAs($this->oficina);
        $this->get(route('rutas.pedidos.index'))->assertOk()->assertSee('Tienda Nueva de Prueba (tienda nueva)');
        $this->get(route('rutas.pedidos.index', ['sala' => 'Nueva de Prueba']))->assertOk()
            ->assertSee('Tienda Nueva de Prueba (tienda nueva)')->assertDontSee('Tienda Por Mandar');
        $this->get(route('rutas.pedidos.show', $pedido))->assertOk()->assertSee('Tienda Nueva de Prueba (tienda nueva)');
        $this->get(route('rutas.pedidos.hoja', $pedido))->assertOk()->assertSee('Tienda Nueva de Prueba (tienda nueva)');

        $contenido = app(ResumenDiarioPedidos::class)->contenido(Carbon::parse('2026-11-08 17:00:00'), Carbon::parse('2026-11-09 17:00:00'));
        $this->assertArrayHasKey('Tienda Nueva de Prueba (tienda nueva)', $contenido['salas']);
    }

    // ------------------------------------------------------------- facturar

    public function test_llego_de_la_fabrica_no_ofrece_facturar_sino_dar_de_alta_la_tienda(): void
    {
        $pedido = $this->pedidoConEnvio();
        $this->actingAs($this->oficina)->get(route('facturacion.llego-de-fabrica', ['fecha' => '2026-11-09']))->assertOk()
            ->assertSee('Tienda Nueva de Prueba (tienda nueva)')
            ->assertSee('href="'.route('facturacion.pedidos.tienda', $pedido).'"', false)
            ->assertSee('Dar de alta la tienda')
            ->assertDontSee('href="'.e(route('facturacion.create-ccf', ['pedido' => $pedido->id, 'fecha' => '2026-11-09'])).'"', false);
    }

    public function test_no_se_crea_el_ccf_sin_la_sala_enlazada(): void
    {
        $pedido = $this->pedidoConEnvio();
        $this->actingAs($this->oficina);

        $this->get(route('facturacion.create-ccf', ['pedido' => $pedido->id]))->assertOk()
            ->assertSee(FacturarPedido::SIN_SALA)
            ->assertSee('href="'.route('facturacion.pedidos.tienda', $pedido).'"', false);

        $this->post(route('facturacion.pedidos.facturar', $pedido), ['numero_orden_compra' => ''])
            ->assertSessionHasErrors(['pedido' => FacturarPedido::SIN_SALA]);
        $this->assertSame(0, Dte::withTrashed()->count());

        // El servicio tampoco lo deja, aunque lo llamen sin pasar por el formulario.
        try {
            app(FacturarPedido::class)->crear($pedido, [], $this->oficina);
            $this->fail('Se facturó un pedido sin sala.');
        } catch (ValidationException $e) {
            $this->assertSame([FacturarPedido::SIN_SALA], $e->errors()['pedido']);
        }
        $this->assertSame(0, Dte::withTrashed()->count());
        $this->assertNull(PedidoSalaEnvio::sole()->dte_id);
    }

    public function test_quien_no_factura_no_ve_si_falta_la_sala(): void
    {
        $pedido = $this->pedidoConEnvio();
        $this->actingAs($this->fabrica)->post(route('facturacion.pedidos.facturar', $pedido), [])->assertForbidden();
    }

    // ------------------------------------------------------------- enlazar

    public function test_la_oficina_enlaza_una_sala_que_ya_existe_y_despues_factura(): void
    {
        $pedido = $this->pedidoConEnvio();
        $sala = $this->salaDePrueba();
        $this->actingAs($this->oficina);

        $this->get(route('facturacion.pedidos.tienda', [$pedido, 'q' => 'Norte']))->assertOk()
            ->assertSee('Tienda Nueva de Prueba')->assertSee('Sala de prueba Norte');
        $this->post(route('facturacion.pedidos.tienda.enlazar', $pedido), ['cliente_sucursal_id' => $sala->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('facturacion.create-ccf', ['pedido' => $pedido->id]))
            ->assertSessionHas('status', 'Listo: el pedido de «Tienda Nueva de Prueba» quedó enlazado a la sala «Sala de prueba Norte». Ya podés facturarlo.');

        $pedido->refresh();
        $this->assertSame($sala->id, $pedido->cliente_sucursal_id);
        $this->assertSame($this->ruta->id, $pedido->ruta_id);
        $this->assertFalse($pedido->esTiendaNueva());
        // El nombre que escribió el vendedor queda para la historia.
        $this->assertSame('Tienda Nueva de Prueba', $pedido->sala_nueva_nombre);
        $log = Activity::where('log_name', 'pedido_sala')->where('description', 'enlazó la tienda nueva a su sala')->sole();
        $this->assertSame($this->oficina->id, $log->causer_id);
        $this->assertEquals(['sala_nueva_nombre' => 'Tienda Nueva de Prueba', 'cliente_sucursal_id' => $sala->id, 'cliente_id' => $sala->cliente_id], $log->properties->all());

        $this->post(route('facturacion.pedidos.facturar', $pedido), ['numero_orden_compra' => ''])->assertSessionHasNoErrors();
        $this->assertSame($sala->id, Dte::sole()->cliente_sucursal_id);
    }

    public function test_no_se_enlaza_dos_veces_ni_a_una_sala_inactiva_ni_un_pedido_anulado(): void
    {
        $pedido = $this->pedidoConEnvio();
        $sala = $this->salaDePrueba();
        $inactiva = $sala->cliente->sucursales()->create(['nombre' => 'Sala de prueba cerrada', 'codigo' => '9002', 'activo' => false]);
        $this->actingAs($this->oficina);

        $this->post(route('facturacion.pedidos.tienda.enlazar', $pedido), ['cliente_sucursal_id' => $inactiva->id])
            ->assertSessionHasErrors('cliente_sucursal_id');
        $this->assertNull($pedido->refresh()->cliente_sucursal_id);

        $this->post(route('facturacion.pedidos.tienda.enlazar', $pedido), ['cliente_sucursal_id' => $sala->id])->assertSessionHasNoErrors();
        $otra = $sala->cliente->sucursales()->create(['nombre' => 'Sala de prueba Sur', 'codigo' => '9003']);
        $this->post(route('facturacion.pedidos.tienda.enlazar', $pedido), ['cliente_sucursal_id' => $otra->id])
            ->assertSessionHasErrors(['pedido' => 'Este pedido ya tiene su sala: «Sala de prueba Norte».']);
        $this->assertSame($sala->id, $pedido->refresh()->cliente_sucursal_id);

        $anulado = $this->pedidoConEnvio('Tienda Anulada');
        $anulado->update(['estado' => EstadoPedidoSala::Anulado]);
        $this->post(route('facturacion.pedidos.tienda.enlazar', $anulado), ['cliente_sucursal_id' => $otra->id])
            ->assertSessionHasErrors('pedido');
        $this->assertNull($anulado->refresh()->cliente_sucursal_id);
    }

    public function test_el_vendedor_y_la_fabrica_no_enlazan(): void
    {
        $pedido = $this->pedidoConEnvio();
        $sala = $this->salaDePrueba();
        foreach ([$this->vendedor, $this->fabrica] as $usuario) {
            $this->actingAs($usuario)->get(route('facturacion.pedidos.tienda', $pedido))->assertForbidden();
            $this->actingAs($usuario)->post(route('facturacion.pedidos.tienda.enlazar', $pedido), ['cliente_sucursal_id' => $sala->id])->assertForbidden();
        }
        $this->assertNull($pedido->refresh()->cliente_sucursal_id);
    }

    public function test_dar_de_alta_la_tienda_con_el_formulario_de_clientes_vuelve_enlazando(): void
    {
        $pedido = $this->pedidoConEnvio();
        $sv = Pais::firstOrCreate(['codigo' => 'SV'], ['nombre' => 'El Salvador', 'activo' => true]);
        $this->actingAs($this->admin);

        // Desde la pantalla del pedido, al alta del cliente con el nombre ya escrito.
        $alta = route('clientes.create', ['pedido_sala' => $pedido->id]);
        $this->get(route('facturacion.pedidos.tienda', $pedido))->assertOk()->assertSee('href="'.e($alta).'"', false);
        $this->get($alta)->assertOk()->assertSee('value="Tienda Nueva de Prueba"', false)
            ->assertSee('name="pedido_sala" value="'.$pedido->id.'"', false);

        // Guardar el cliente lleva a la sala, y guardar la sala enlaza y vuelve a facturar.
        $respuesta = $this->post(route('clientes.store'), [
            'nombre' => 'Tienda Nueva de Prueba', 'tipo_cliente' => 'consumidor_final', 'pais_id' => $sv->id, 'activo' => '1', 'pedido_sala' => $pedido->id,
        ]);
        $cliente = Cliente::where('nombre', 'Tienda Nueva de Prueba')->first();
        $this->assertNotNull($cliente, 'No se guardó el cliente: '.json_encode(session('errors')?->all()));
        $respuesta->assertRedirect(route('clientes.sucursales.create', [$cliente, 'pedido_sala' => $pedido->id]));
        $this->get(route('clientes.sucursales.create', [$cliente, 'pedido_sala' => $pedido->id]))->assertOk()
            ->assertSee('value="Tienda Nueva de Prueba"', false)->assertSee('name="pedido_sala" value="'.$pedido->id.'"', false);

        $dep = Departamento::firstOrCreate(['codigo' => '12'], ['nombre' => 'San Miguel', 'activo' => true]);
        $distrito = Distrito::create(['departamento_id' => $dep->id, 'municipio' => 'San Miguel Centro', 'nombre' => 'San Miguel']);
        $this->post(route('clientes.sucursales.store', $cliente), [
            'nombre' => 'Tienda Nueva de Prueba', 'departamento_id' => $dep->id, 'distrito_id' => $distrito->id,
            'telefono' => '77777777', 'activo' => '1', 'pedido_sala' => $pedido->id,
        ])->assertSessionHasNoErrors()->assertRedirect(route('facturacion.create-ccf', ['pedido' => $pedido->id]));

        $sala = $cliente->sucursales()->sole();
        $this->assertSame($sala->id, $pedido->refresh()->cliente_sucursal_id);
    }

    public function test_sin_permiso_de_clientes_la_pantalla_dice_a_quien_pedirle_el_alta(): void
    {
        $pedido = $this->pedidoConEnvio();
        $this->actingAs($this->oficina)->get(route('facturacion.pedidos.tienda', $pedido))->assertOk()
            ->assertDontSee(route('clientes.create', ['pedido_sala' => $pedido->id]))
            ->assertSee('Pedile a quien maneja Clientes que la dé de alta');
    }

    public function test_antes_de_la_tienda_nueva_ofrece_las_parecidas(): void
    {
        $existente = $this->salaDePrueba();
        $existente->update(['nombre' => 'Chapina de prueba Norte']);
        $this->actingAs($this->vendedor);

        $this->get(route('rutas.sacar-pedido.tienda-nueva.create', ['nombre' => 'Tienda La Chapina']))->assertOk()
            ->assertSee('¿Es alguna de estas?')->assertSee('Chapina de prueba Norte')
            ->assertSee('href="'.route('rutas.sacar-pedido.create', $existente).'"', false)
            ->assertSee('Si no es ninguna, seguí abajo: se guarda como tienda nueva.');
        $this->get(route('rutas.sacar-pedido.tienda-nueva.create', ['nombre' => 'Tienda Zapote']))->assertOk()
            ->assertDontSee('¿Es alguna de estas?');
    }
}
