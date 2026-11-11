<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Sacar pedido sin salida armada (Melqui, 10/11/2026): el vendedor busca cualquier sala, saca
 * el pedido y el pedido se suma solo a su viaje. El inicio muestra el viaje y los dos botones.
 * Datos ficticios.
 */
class SacarPedidoSinSalidaTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private PersonalRuta $persona;

    private ClienteSucursal $sala;

    private ClienteSucursal $otraSala;

    private Producto $galleta;

    protected function setUp(): void
    {
        parent::setUp();
        // Lunes.
        $this->travelTo('2026-11-09 09:00:00');
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos Ficticio', 'user_id' => $this->vendedor->id]);

        $dep = Departamento::firstOrCreate(['codigo' => '12'], ['nombre' => 'San Miguel', 'activo' => true]);
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $cliente = Cliente::factory()->create(['nombre' => 'Cliente de prueba']);
        $this->sala = $cliente->sucursales()->create(['nombre' => 'Sala de prueba Oriente', 'codigo' => '7001', 'ruta_id' => $ruta->id, 'departamento_id' => $dep->id]);
        // Sin ruta: cualquier sala sirve, no solo las de una ruta.
        $this->otraSala = $cliente->sucursales()->create(['nombre' => 'Tienda de prueba Centro', 'codigo' => '7002', 'departamento_id' => $dep->id]);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01']);
        $this->actingAs($this->vendedor);
    }

    private function sacar(ClienteSucursal $sala, array $cantidades = []): TestResponse
    {
        return $this->post(route('rutas.sacar-pedido.store', $sala), ['cantidades' => $cantidades ?: [$this->galleta->id => '12']]);
    }

    public function test_el_vendedor_entra_al_inicio_sin_viaje_con_los_dos_botones(): void
    {
        $this->get('/dashboard')->assertRedirect(route('rutas.mi-salida.index'));
        $this->get(route('rutas.mi-salida.index'))->assertOk()
            ->assertSee('Todavía no tenés un viaje abierto')
            ->assertSee('Se abre solo con el primer pedido que saqués.')
            ->assertSee('href="'.route('rutas.sacar-pedido.index').'"', false)
            ->assertSee('href="'.route('rutas.por-entregar.index').'"', false)
            ->assertDontSee('¿Mañana seguís en la misma zona?');
    }

    public function test_busca_cualquier_sala_y_saca_el_pedido_sin_salida_armada(): void
    {
        $this->assertSame(0, SalidaRuta::count());
        $this->get(route('rutas.sacar-pedido.index', ['q' => 'tienda']))->assertOk()
            ->assertSee('Tienda de prueba Centro')->assertDontSee('Sala de prueba Oriente')
            ->assertSee(route('rutas.sacar-pedido.create', $this->otraSala));
        $this->get(route('rutas.sacar-pedido.index', ['q' => 'x']))->assertOk()->assertSee('Escribí al menos 2 letras.');

        $this->get(route('rutas.sacar-pedido.create', $this->otraSala))->assertOk()
            ->assertSee('Sugerido')->assertSee('Galleta de prueba')->assertSee('Tu primer pedido abre tu viaje.');
        $this->sacar($this->otraSala)->assertSessionHasNoErrors()
            ->assertRedirect(route('rutas.sacar-pedido.index'))
            ->assertSessionHas('status', 'Pedido de «Tienda de prueba Centro» guardado. Se abrió tu viaje: Lunes · Sin zona.');

        $pedido = PedidoSala::sole();
        $viaje = SalidaRuta::sole();
        $this->assertTrue($viaje->esViaje());
        $this->assertSame($viaje->id, $pedido->salida_ruta_id);
        $this->assertSame($this->persona->id, $pedido->sacado_por_id);
        $this->assertSame($this->vendedor->id, $pedido->registrado_por);
        $this->assertNull($pedido->ruta_id);
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->estado);
        $actividad = Activity::where('log_name', 'pedido_sala')->sole();
        $this->assertSame('sacó el pedido de la sala', $actividad->description);
        $this->assertSame($viaje->id, $actividad->properties['salida_ruta_id']);
        $this->assertSame($this->galleta->id, $actividad->properties['lineas'][0]['producto_id']);

        // El segundo pedido, en una sala de ruta, va al mismo viaje y le da nombre.
        $this->sacar($this->sala)->assertSessionHas('status', 'Pedido de «Sala de prueba Oriente» guardado.');
        $this->assertSame(1, SalidaRuta::count());
        $this->assertSame($viaje->id, PedidoSala::latest('id')->first()->salida_ruta_id);

        // Una vez por sala: el segundo intento no crea otro.
        $this->sacar($this->sala)->assertSessionHasErrors('lineas');
        $this->get(route('rutas.sacar-pedido.create', $this->sala))->assertRedirect(route('rutas.sacar-pedido.edit', PedidoSala::latest('id')->first()));
        $this->assertSame(2, PedidoSala::count());

        // La lista muestra los pedidos de hoy con Corregir.
        $this->get(route('rutas.sacar-pedido.index'))->assertOk()
            ->assertSee('Tus pedidos de hoy')->assertSee('Tienda de prueba Centro')->assertSee('Corregir');
    }

    public function test_el_inicio_muestra_el_viaje_y_terminar_el_dia(): void
    {
        $this->sacar($this->sala)->assertSessionHasNoErrors();
        $this->travelTo('2026-11-10 08:00:00');
        $this->sacar($this->otraSala)->assertSessionHasNoErrors();
        $viaje = SalidaRuta::sole();

        $this->get(route('rutas.mi-salida.index'))->assertOk()
            ->assertSee('Tu viaje')->assertSee('Lunes · San Miguel')->assertSee('Día 2 · 2 salas con pedido')
            // El viaje no tiene hoja propia: lo suyo está en «Sacar pedido» y «Por entregar».
            ->assertDontSee('href="'.route('rutas.mi-salida.show', $viaje).'"', false)
            ->assertSee('href="'.route('rutas.mi-salida.dia').'"', false);
        $this->get(route('rutas.mi-salida.show', $viaje))->assertRedirect(route('rutas.mi-salida.index'));
        // La pregunta está en «Terminar el día», con el resumen y el PDF.
        $this->get(route('rutas.mi-salida.dia'))->assertOk()
            ->assertSee('¿Mañana seguís en la misma zona?')
            ->assertSee('action="'.route('rutas.mi-salida.terminar-dia', $viaje).'"', false);
        // Lo sacado hoy está en «Sacar pedido».
        $this->get(route('rutas.sacar-pedido.index'))->assertOk()->assertSee('Lunes · San Miguel')
            ->assertSee('Tus pedidos de hoy');

        $this->post(route('rutas.mi-salida.terminar-dia', $viaje), ['sigue' => '0'])
            ->assertRedirect(route('rutas.mi-salida.index'))
            ->assertSessionHas('status', 'Listo. Este viaje ya no suma pedidos; queda abierto para entregar.');
        $this->assertFalse($viaje->refresh()->suma_pedidos);
        $this->get(route('rutas.mi-salida.index'))->assertOk()->assertSee('Lunes · San Miguel')->assertSee('Ya no suma pedidos');
        $this->get(route('rutas.mi-salida.dia'))->assertOk()->assertDontSee('¿Mañana seguís en la misma zona?');
        $this->post(route('rutas.mi-salida.terminar-dia', $viaje), ['sigue' => 'quizas'])->assertSessionHasErrors('sigue');

        // El próximo pedido abre otro viaje.
        $this->travelTo('2026-11-11 08:00:00');
        $this->sacar($this->sala)->assertSessionHas('status', fn ($m) => str_contains($m, 'Se abrió tu viaje: Miércoles · San Miguel.'));
        $this->assertSame(2, SalidaRuta::count());
    }

    public function test_validaciones_del_pedido_intactas(): void
    {
        $inactivo = Producto::factory()->create(['activo' => false]);
        foreach ([
            [[$this->galleta->id => ''], 'lineas'],
            [[$this->galleta->id => '0'], 'lineas.0.cantidad'],
            [[$this->galleta->id => '-3'], 'lineas.0.cantidad'],
            [[$this->galleta->id => '100000'], 'lineas.0.cantidad'],
            [[$inactivo->id => '2'], 'lineas.0.producto_id'],
        ] as [$cantidades, $campo]) {
            $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => $cantidades])->assertSessionHasErrors($campo);
        }
        // Un error no deja un viaje vacío.
        $this->assertSame(0, PedidoSala::count());
        $this->assertSame(0, SalidaRuta::count());

        // La sala cerrada no se busca ni se usa.
        $cerrada = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Sala de prueba cerrada', 'activo' => false]);
        $this->get(route('rutas.sacar-pedido.index', ['q' => 'cerrada']))->assertOk()->assertDontSee('Sala de prueba cerrada');
        $this->get(route('rutas.sacar-pedido.create', $cerrada))->assertNotFound();
        $this->sacar($cerrada)->assertNotFound();
        $this->assertSame(0, SalidaRuta::count());
    }

    public function test_corregir_y_anular_solo_quien_lo_saco_el_mismo_dia(): void
    {
        $this->sacar($this->sala)->assertSessionHasNoErrors();
        $pedido = PedidoSala::sole();
        $this->put(route('rutas.sacar-pedido.update', $pedido), ['cantidades' => [$this->galleta->id => '4']])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Pedido de «Sala de prueba Oriente» corregido.');
        $this->assertSame('4.0000', $pedido->refresh()->lineas->sole()->cantidad);
        $this->assertSame(1, SalidaRuta::count(), 'Corregir no abre otro viaje.');

        $otro = User::factory()->create(['activo' => true])->assignRole('vendedor');
        PersonalRuta::create(['nombre' => 'Ana Ficticia', 'user_id' => $otro->id]);
        $this->actingAs($otro);
        $this->get(route('rutas.sacar-pedido.edit', $pedido))->assertForbidden();
        $this->patch(route('rutas.sacar-pedido.anular', $pedido))->assertForbidden();
        // El otro vendedor no saca un segundo pedido de la misma sala el mismo día.
        $this->sacar($this->sala)->assertSessionHasErrors(['lineas' => 'El pedido de «Sala de prueba Oriente» ya lo sacó hoy Carlos Ficticio.']);

        $this->actingAs($this->vendedor);
        $this->patch(route('rutas.sacar-pedido.anular', $pedido))->assertRedirect(route('rutas.sacar-pedido.index'));
        $this->assertSame(EstadoPedidoSala::Anulado, $pedido->refresh()->estado);
        $this->assertTrue(Activity::where('description', 'anuló el pedido de la sala')->exists());

        $this->travelTo('2026-11-10 09:00:00');
        $this->sacar($this->sala)->assertSessionHasNoErrors();
        $ultimo = PedidoSala::latest('id')->first();
        // Otro día lo sigue corrigiendo mientras no saque el de la sala siguiente.
        $this->travelTo('2026-11-11 09:00:00');
        $this->put(route('rutas.sacar-pedido.update', $ultimo), ['cantidades' => [$this->galleta->id => '2']])->assertRedirect(route('rutas.sacar-pedido.index'))->assertSessionHasNoErrors();
        $this->sacar($this->otraSala)->assertSessionHasNoErrors();
        $this->put(route('rutas.sacar-pedido.update', $ultimo), ['cantidades' => [$this->galleta->id => '1']])->assertForbidden();
        $this->patch(route('rutas.sacar-pedido.anular', $ultimo))->assertForbidden();
        $this->get(route('rutas.sacar-pedido.edit', $ultimo))->assertRedirect(route('rutas.sacar-pedido.index'))
            ->assertSessionHas('error', 'Ya sacaste el pedido de otra sala: este lo corrige la oficina.');
    }

    public function test_permisos_vendedor_si_fabrica_no(): void
    {
        $fabrica = User::factory()->create(['activo' => true])->assignRole('fabrica');
        $this->actingAs($fabrica);
        $this->get(route('rutas.sacar-pedido.index'))->assertForbidden();
        $this->get(route('rutas.sacar-pedido.create', $this->sala))->assertForbidden();
        $this->sacar($this->sala)->assertForbidden();

        $oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->actingAs($oficina)->sacar($this->sala)->assertForbidden();

        // Vendedor sin ficha de Rutas: se le dice qué hacer y no saca.
        $suelto = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->actingAs($suelto);
        $this->get(route('rutas.sacar-pedido.index'))->assertOk()->assertSee('Tu usuario no está enlazado a una persona de Rutas.');
        $this->sacar($this->sala)->assertForbidden();

        // Terminar el día del viaje de otro: no.
        $this->actingAs($this->vendedor)->sacar($this->sala)->assertSessionHasNoErrors();
        $viaje = SalidaRuta::sole();
        $this->actingAs($suelto)->post(route('rutas.mi-salida.terminar-dia', $viaje), ['sigue' => '0'])->assertForbidden();
        $this->assertTrue($viaje->refresh()->suma_pedidos);
        $this->assertSame(1, PedidoSala::count());

        auth()->logout();
        $this->sacar($this->sala)->assertRedirect(route('login'));
    }
}
