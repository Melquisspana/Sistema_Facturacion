<?php

namespace Tests\Feature\Rutas;

use App\Facades\Ajustes;
use App\Http\Controllers\Configuracion\OpcionesModuloController;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\PedidosSala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * «Salas donde no se sacan pedidos» (el dueño, 12/11/2026): la bodega central de un cliente
 * solo recibe notas de crédito de PPQ. Se eligen en Configuración › Rutas; esas salas no
 * salen en «Sacar pedido» y el servidor no deja sacar un pedido en ellas aunque llegue la
 * URL a mano. Lo demás (facturar, NC, PPQ) no cambia. Vacío = como antes. Datos ficticios.
 */
class SalasSinPedidosTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE = 'pedidos.salas_sin_pedidos';

    private User $vendedor;

    private ClienteSucursal $bodega;

    private ClienteSucursal $sala;

    private Producto $galleta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09 09:00:00');
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        PersonalRuta::create(['nombre' => 'Carlos Ficticio', 'user_id' => $this->vendedor->id]);
        $cliente = Cliente::factory()->create(['nombre' => 'Cliente de prueba']);
        $this->bodega = $cliente->sucursales()->create(['nombre' => 'Bodega de prueba Central', 'codigo' => '9001']);
        $this->sala = $cliente->sucursales()->create(['nombre' => 'Sala de prueba Centro', 'codigo' => '9002']);
        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01']);
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function campo(): string
    {
        return OpcionesModuloController::campo(self::CLAVE);
    }

    private function ocultarBodega(): void
    {
        Ajustes::guardarComoSistema(self::CLAVE, [(string) $this->bodega->id]);
    }

    // ------------------------------------------------------------- el ajuste

    public function test_se_eligen_por_nombre_entre_las_salas_activas_en_el_grupo_de_pedidos(): void
    {
        $apagada = $this->sala->cliente->sucursales()->create(['nombre' => 'Sala de prueba apagada', 'activo' => false]);

        $this->actingAs($this->admin())->get(route('configuracion.opciones.rutas'))->assertOk()
            ->assertSeeInOrder(['Pedidos', 'Salas donde no se sacan pedidos', 'Bodega de prueba Central', 'Sala de prueba Centro'])
            ->assertSee('name="'.$this->campo().'[]" value="'.$this->bodega->id.'"', false)
            ->assertDontSee('Sala de prueba apagada')
            ->assertDontSee('value="'.$apagada->id.'"', false);
    }

    public function test_se_guarda_con_confirmacion_mostrando_los_nombres(): void
    {
        $admin = $this->admin();
        $campo = $this->campo();

        $this->actingAs($admin)->put(route('configuracion.opciones.rutas.update'), [$campo => ['', (string) $this->bodega->id]])
            ->assertOk()
            ->assertSee('Esto es lo que va a cambiar')
            ->assertSee('Bodega de prueba Central')
            ->assertSee('name="'.$campo.'[]" value="'.$this->bodega->id.'"', false);
        $this->assertSame([], Ajustes::lista(self::CLAVE));

        $this->actingAs($admin)->put(route('configuracion.opciones.rutas.update'), [$campo => ['', (string) $this->bodega->id], 'confirmacion' => '1'])
            ->assertRedirect(route('configuracion.opciones.rutas'));
        $this->assertSame([(string) $this->bodega->id], Ajustes::lista(self::CLAVE));

        // La pantalla la muestra marcada.
        $this->actingAs($admin)->get(route('configuracion.opciones.rutas'))->assertOk()
            ->assertSee('value="'.$this->bodega->id.'" checked', false);
    }

    public function test_sin_marcar_ninguna_vacia_la_lista_y_sin_cambios_no_pide_nada(): void
    {
        $admin = $this->admin();
        $campo = $this->campo();

        // Nada marcado y nada guardado: no hay nada que cambiar.
        $this->actingAs($admin)->put(route('configuracion.opciones.rutas.update'), [$campo => ['']])
            ->assertRedirect(route('configuracion.opciones.rutas'))->assertSessionHas('status', 'No había nada que cambiar en Rutas.');

        $this->ocultarBodega();
        $this->actingAs($admin)->put(route('configuracion.opciones.rutas.update'), [$campo => [''], 'confirmacion' => '1'])
            ->assertRedirect(route('configuracion.opciones.rutas'));
        $this->assertSame([], Ajustes::lista(self::CLAVE));
    }

    public function test_el_servidor_exige_salas_que_existan_y_esten_activas(): void
    {
        $apagada = $this->sala->cliente->sucursales()->create(['nombre' => 'Sala de prueba apagada', 'activo' => false]);
        $admin = $this->admin();

        foreach ([[(string) $apagada->id], ['999999'], ['abc'], [(string) $this->bodega->id, (string) $this->bodega->id]] as $valor) {
            $this->actingAs($admin)->put(route('configuracion.opciones.rutas.update'), [$this->campo() => $valor, 'confirmacion' => '1'])
                ->assertSessionHasErrors($this->campo());
        }
        $this->assertSame([], Ajustes::lista(self::CLAVE));
    }

    // -------------------------------------------------------- sacar pedido

    public function test_vacio_es_como_antes(): void
    {
        $this->actingAs($this->vendedor)->getJson(route('rutas.sacar-pedido.salas', ['q' => 'de prueba']))->assertOk()
            ->assertJsonPath('salas.0.nombre', 'Bodega de prueba Central');
    }

    public function test_la_sala_no_aparece_en_la_busqueda_ni_en_las_parecidas(): void
    {
        $this->ocultarBodega();
        $this->actingAs($this->vendedor);

        $this->assertSame(['Sala de prueba Centro'], array_column(
            $this->getJson(route('rutas.sacar-pedido.salas', ['q' => 'de prueba']))->assertOk()->json('salas'), 'nombre'));
        $this->get(route('rutas.sacar-pedido.index', ['q' => 'de prueba']))->assertOk()
            ->assertSee('Sala de prueba Centro')->assertDontSee('Bodega de prueba Central');
        $this->get(route('rutas.sacar-pedido.tienda-nueva.create', ['nombre' => 'Tienda Bodega Central']))->assertOk()
            ->assertDontSee('Bodega de prueba Central');
    }

    public function test_el_servidor_no_deja_sacar_el_pedido_aunque_llegue_la_url(): void
    {
        $this->ocultarBodega();
        $this->actingAs($this->vendedor);

        $this->get(route('rutas.sacar-pedido.create', $this->bodega))->assertNotFound();
        $this->post(route('rutas.sacar-pedido.store', $this->bodega), ['cantidades' => [$this->galleta->id => '12']])
            ->assertSessionHasErrors(['lineas' => PedidosSala::SALA_SIN_PEDIDOS]);
        $this->assertDatabaseCount('pedidos_sala', 0);

        // El servicio, por si alguien lo llama sin pasar por el formulario.
        try {
            app(PedidosSala::class)->guardar($this->bodega, ['lineas' => [['producto_id' => $this->galleta->id, 'cantidad' => '12']]], $this->vendedor);
            $this->fail('Tenía que frenar.');
        } catch (ValidationException $e) {
            $this->assertSame([PedidosSala::SALA_SIN_PEDIDOS], $e->errors()['lineas']);
        }

        // Las demás salas, como siempre.
        $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => '12']])
            ->assertSessionHasNoErrors();
        $this->assertSame([$this->sala->id], PedidoSala::pluck('cliente_sucursal_id')->all());
    }
}
