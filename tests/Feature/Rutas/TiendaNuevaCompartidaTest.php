<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoSalidaRuta;
use App\Enums\PropositoSalida;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Pais;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\PedidosSala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * La tienda nueva compartida (Melqui, 13/11/2026): el vendedor ve las tiendas nuevas que
 * anotaron hoy (él u otros) y, si es una de esas, su pedido va con el mismo nombre. La sala
 * se registra recién cuando Facturación la da de alta, y ese alta enlaza todos los pedidos
 * de la tienda con ese nombre. Datos ficticios.
 */
class TiendaNuevaCompartidaTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private User $otroVendedor;

    private User $oficina;

    private PersonalRuta $persona;

    private PersonalRuta $otraPersona;

    private Producto $galleta;

    private Ruta $ruta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09 09:00:00');
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->otroVendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->persona = PersonalRuta::create(['nombre' => 'Vendedor de prueba', 'user_id' => $this->vendedor->id]);
        $this->otraPersona = PersonalRuta::create(['nombre' => 'Ana de prueba', 'user_id' => $this->otroVendedor->id]);
        $this->ruta = Ruta::create(['nombre' => 'Ruta de prueba']);
        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01', 'precio_unitario' => 1.00]);
    }

    private function tiendaNueva(string $nombre, PersonalRuta $persona, string $fecha = '2026-11-09', array $mas = []): PedidoSala
    {
        $pedido = PedidoSala::create($mas + [
            'cliente_sucursal_id' => null, 'sala_nueva_nombre' => $nombre,
            'sacado_por_id' => $persona->id, 'registrado_por' => $persona->user_id,
            'fecha' => $fecha, 'estado' => EstadoPedidoSala::Enviado, 'notas' => 'Nota privada de '.$persona->nombre,
        ]);
        $linea = $pedido->lineas()->create(['producto_id' => $this->galleta->id, 'cantidad' => '6']);
        $envio = $pedido->envios()->create([
            'numero' => 1, 'clave' => (string) Str::uuid(), 'fecha' => $fecha, 'registrado_por' => $this->oficina->id,
        ]);
        $envio->lineas()->create(['pedido_sala_linea_id' => $linea->id, 'cantidad' => '6']);

        return $pedido;
    }

    private function sala(): ClienteSucursal
    {
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba', 'requiere_orden_compra' => false]);

        return $cliente->sucursales()->create(['nombre' => 'Sala de prueba Norte', 'codigo' => '9001', 'ruta_id' => $this->ruta->id]);
    }

    private function viaje(): SalidaRuta
    {
        return SalidaRuta::create([
            'ruta_id' => $this->ruta->id, 'fecha_inicio' => '2026-11-09', 'estado' => EstadoSalidaRuta::EnCurso,
            'proposito' => PropositoSalida::Ambos, 'automatica' => true, 'suma_pedidos' => true, 'created_by' => $this->vendedor->id,
        ]);
    }

    // ------------------------------------------------------------------ el vendedor

    public function test_ofrece_las_tiendas_nuevas_anotadas_hoy_y_no_las_de_ayer_ni_las_enlazadas(): void
    {
        $this->tiendaNueva('Tienda La Chapina', $this->otraPersona);
        $this->tiendaNueva('Chapina de Ayer', $this->otraPersona, '2026-11-08');
        $this->tiendaNueva('Chapina Anulada', $this->otraPersona, mas: ['estado' => EstadoPedidoSala::Anulado]);
        $this->tiendaNueva('Chapina Enlazada', $this->otraPersona, mas: ['cliente_sucursal_id' => $this->sala()->id]);
        $this->actingAs($this->vendedor);

        $this->get(route('rutas.sacar-pedido.tienda-nueva.create', ['nombre' => 'chapina']))->assertOk()
            ->assertSee('¿Es alguna de estas?')
            ->assertSee('Tienda La Chapina')
            ->assertSee('Tienda nueva · anotada hoy por Ana de prueba')
            ->assertSee('href="'.e(route('rutas.sacar-pedido.tienda-nueva.create', ['nombre' => 'Tienda La Chapina'])).'"', false)
            ->assertDontSee('Chapina de Ayer')
            ->assertDontSee('Chapina Anulada')
            ->assertDontSee('Chapina Enlazada')
            // Del otro vendedor, solo el nombre de la tienda y quién la anotó.
            ->assertDontSee('Nota privada');
    }

    public function test_la_suya_de_hoy_lleva_a_corregirla(): void
    {
        $suya = PedidoSala::create([
            'sala_nueva_nombre' => 'Tienda La Chapina', 'sacado_por_id' => $this->persona->id, 'registrado_por' => $this->vendedor->id,
            'fecha' => '2026-11-09', 'estado' => EstadoPedidoSala::Registrado,
        ]);
        $this->actingAs($this->vendedor);

        $this->get(route('rutas.sacar-pedido.tienda-nueva.create', ['nombre' => 'La Chapina']))->assertOk()
            ->assertSee('Tienda nueva · anotada hoy por vos')
            ->assertSee('href="'.route('rutas.sacar-pedido.edit', $suya).'"', false);
    }

    public function test_elegirla_guarda_el_pedido_con_el_mismo_nombre_sin_crear_sala(): void
    {
        $this->tiendaNueva('Tienda La Chapina', $this->otraPersona);
        $this->actingAs($this->vendedor);

        $this->get(route('rutas.sacar-pedido.tienda-nueva.create', ['nombre' => 'Tienda La Chapina']))->assertOk()
            ->assertSee('value="Tienda La Chapina"', false);
        $this->post(route('rutas.sacar-pedido.tienda-nueva.store'), [
            'sala_nueva_nombre' => 'Tienda La Chapina', 'cantidades' => [$this->galleta->id => '12'],
        ])->assertSessionHasNoErrors()->assertRedirect(route('rutas.sacar-pedido.index'));

        $suyo = PedidoSala::where('sacado_por_id', $this->persona->id)->sole();
        $this->assertSame('Tienda La Chapina', $suyo->sala_nueva_nombre);
        $this->assertNull($suyo->cliente_sucursal_id);
        $this->assertSame(0, ClienteSucursal::count());
        $this->assertSame(0, Cliente::count());
    }

    // ------------------------------------------------------------------ Facturación

    public function test_el_alta_de_la_tienda_enlaza_todos_los_pedidos_con_ese_nombre(): void
    {
        $abierto = $this->tiendaNueva('Tienda La Chapina', $this->otraPersona);
        $otroDeHoy = $this->tiendaNueva('tienda  la chapina ', $this->persona);
        $deAyer = $this->tiendaNueva('Tienda La Chapina', $this->persona, '2026-11-08');
        $otroNombre = $this->tiendaNueva('Tienda Zapote', $this->persona);
        $anulado = $this->tiendaNueva('Tienda La Chapina', $this->persona, mas: ['estado' => EstadoPedidoSala::Anulado]);
        $sv = Pais::firstOrCreate(['codigo' => 'SV'], ['nombre' => 'El Salvador', 'activo' => true]);
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba']);
        $dep = Departamento::firstOrCreate(['codigo' => '12'], ['nombre' => 'San Miguel', 'activo' => true]);
        $distrito = Distrito::create(['departamento_id' => $dep->id, 'municipio' => 'San Miguel Centro', 'nombre' => 'San Miguel']);
        $this->actingAs($this->oficina);

        // La pantalla del alta y el formulario de la sala dicen que van todos.
        $this->get(route('facturacion.pedidos.tienda', $abierto))->assertOk()
            ->assertSee('Hay 2 pedidos más de «Tienda La Chapina»')
            ->assertSee('N.º '.$otroDeHoy->id)->assertSee('N.º '.$deAyer->id)->assertDontSee('N.º '.$otroNombre->id);
        $this->get(route('clientes.sucursales.create', [$cliente, 'pedido_sala' => $abierto->id]))->assertOk()
            ->assertSee('y a los otros 2 pedidos de esa tienda nueva');

        $this->post(route('clientes.sucursales.store', $cliente), [
            'nombre' => 'Tienda La Chapina', 'departamento_id' => $dep->id, 'distrito_id' => $distrito->id,
            'telefono' => '77777777', 'activo' => '1', 'pedido_sala' => $abierto->id,
        ])->assertSessionHasNoErrors()->assertRedirect(route('facturacion.create-ccf', ['pedido' => $abierto->id]))
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'También quedaron enlazados 2 pedidos más de «Tienda La Chapina»')
                && ! str_contains($s, 'Ojo') && ! str_contains($s, 'No se enlazaron'));

        $sala = $cliente->sucursales()->sole();
        foreach ([$abierto, $otroDeHoy, $deAyer] as $pedido) {
            $this->assertSame($sala->id, $pedido->refresh()->cliente_sucursal_id);
        }
        $this->assertNull($otroNombre->refresh()->cliente_sucursal_id);
        $this->assertNull($anulado->refresh()->cliente_sucursal_id);
        $this->assertSame($sv->id, $sala->pais_id);

        // Una auditoría por cada pedido enlazado, con su nombre.
        $logs = Activity::where('log_name', 'pedido_sala')->where('description', PedidosSala::AUDITORIA_ENLACE)->get();
        $this->assertEqualsCanonicalizing([$abierto->id, $otroDeHoy->id, $deAyer->id], $logs->pluck('subject_id')->all());
        $this->assertTrue($logs->every(fn (Activity $l) => $l->causer_id === $this->oficina->id && $l->properties['cliente_sucursal_id'] === $sala->id));
        $this->assertSame('tienda  la chapina ', $logs->firstWhere('subject_id', $otroDeHoy->id)->properties['sala_nueva_nombre']);
    }

    public function test_uno_que_no_se_puede_enlazar_no_frena_a_los_demas_y_se_avisa(): void
    {
        $sala = $this->sala();
        $viaje = $this->viaje();
        // La sala ya tiene su pedido en ese viaje: el de la tienda nueva de ese viaje queda afuera.
        $this->tiendaNueva('Pedido ya de la sala', $this->persona, mas: ['cliente_sucursal_id' => $sala->id, 'salida_ruta_id' => $viaje->id]);
        $abierto = $this->tiendaNueva('Tienda La Chapina', $this->otraPersona);
        $enElViaje = $this->tiendaNueva('Tienda La Chapina', $this->persona, '2026-11-08', ['salida_ruta_id' => $viaje->id]);
        $otro = $this->tiendaNueva('Tienda La Chapina', $this->persona, '2026-11-07');
        $this->actingAs($this->oficina);

        $this->post(route('facturacion.pedidos.tienda.enlazar', $abierto), ['cliente_sucursal_id' => $sala->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('facturacion.create-ccf', ['pedido' => $abierto->id]))
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'También quedó enlazado 1 pedido más de «Tienda La Chapina»: N.º '.$otro->id)
                && str_contains($s, 'No se enlazaron: N.º '.$enElViaje->id.', de Vendedor de prueba: «Sala de prueba Norte» ya tiene otro pedido en ese viaje.'));

        $this->assertSame($sala->id, $abierto->refresh()->cliente_sucursal_id);
        $this->assertSame($sala->id, $otro->refresh()->cliente_sucursal_id);
        $this->assertNull($enElViaje->refresh()->cliente_sucursal_id);
        $this->assertSame(2, Activity::where('description', PedidosSala::AUDITORIA_ENLACE)->count());
    }

    public function test_llego_de_pedidos_avisa_que_hay_varios_con_el_mismo_nombre(): void
    {
        $this->tiendaNueva('Tienda La Chapina', $this->otraPersona);
        $this->tiendaNueva('Tienda La Chapina', $this->persona);
        $this->tiendaNueva('Tienda Zapote', $this->persona);
        $this->actingAs($this->oficina);

        $this->get(route('facturacion.llego-de-fabrica', ['fecha' => '2026-11-09']))->assertOk()
            ->assertSeeInOrder(['Tienda La Chapina', 'Hay 1 pedido más de esta tienda nueva con el mismo nombre', 'Tienda La Chapina', 'Hay 1 pedido más'])
            ->assertSee('Tienda Zapote');
        $this->assertSame(2, substr_count($this->get(route('facturacion.llego-de-fabrica', ['fecha' => '2026-11-09']))->getContent(), 'con el mismo nombre'));
    }
}
