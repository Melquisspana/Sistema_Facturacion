<?php

namespace Tests\Feature\Rutas;

use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La sesión vence a media tarea (30 minutos sin tocar nada en producción): el formulario
 * llega con un token que ya no vale. Antes era la página «419» y se perdía lo escrito; ahora
 * vuelve al formulario con lo escrito y el aviso, sin guardar nada. Datos ficticios.
 */
class SesionVencidaRutasTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private ClienteSucursal $sala;

    private Producto $galleta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-14 09:00:00');
        // En las pruebas Laravel no revisa el token: acá sí, como en el servidor.
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        PersonalRuta::create(['nombre' => 'Carlos Ficticio', 'user_id' => $this->vendedor->id]);
        $this->sala = Cliente::factory()->create(['nombre' => 'Cliente de prueba'])->sucursales()->create(['nombre' => 'Sala de prueba', 'codigo' => '7001']);
        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01']);
    }

    /** El pedido con un token vencido, desde el formulario de la sala. */
    private function guardarConTokenVencido(array $cabeceras)
    {
        return $this->actingAs($this->vendedor)->withHeaders($cabeceras)->post(route('rutas.sacar-pedido.store', $this->sala), [
            '_token' => 'token-de-una-sesion-vencida',
            'cantidades' => [$this->galleta->id => '12'],
            'notas' => 'Dejar en bodega',
        ]);
    }

    public function test_con_la_sesion_vencida_vuelve_al_formulario_con_lo_escrito(): void
    {
        $formulario = route('rutas.sacar-pedido.create', $this->sala);

        $this->guardarConTokenVencido(['Referer' => $formulario, 'Origin' => rtrim(url('/'), '/')])
            ->assertRedirect($formulario)
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'sesión'))
            ->assertSessionHasInput('cantidades', [$this->galleta->id => '12'])
            ->assertSessionHasInput('notas', 'Dejar en bodega')
            ->assertSessionMissing('_old_input._token');
        $this->assertSame(0, PedidoSala::count(), 'No se guarda nada sin un token válido.');

        $this->actingAs($this->vendedor)->get($formulario)->assertOk()
            ->assertSee('value="12"', false)->assertSee('Dejar en bodega');
    }

    public function test_desde_otro_sitio_o_por_fetch_sigue_siendo_419(): void
    {
        // Otro sitio no puede dejar datos escritos en el formulario de nadie.
        $this->guardarConTokenVencido(['Referer' => 'https://otro.example/x', 'Origin' => 'https://otro.example'])
            ->assertStatus(419)->assertSessionMissing('_old_input');
        $this->guardarConTokenVencido(['Referer' => route('rutas.sacar-pedido.create', $this->sala), 'Origin' => 'https://otro.example'])
            ->assertStatus(419)->assertSessionMissing('_old_input');
        $this->guardarConTokenVencido([])->assertStatus(419);
        $this->guardarConTokenVencido(['Referer' => route('rutas.sacar-pedido.create', $this->sala), 'Accept' => 'application/json'])
            ->assertStatus(419);
        $this->assertSame(0, PedidoSala::count());
    }
}
