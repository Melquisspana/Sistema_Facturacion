<?php

namespace Tests\Feature\Rutas;

use App\Models\Cliente;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\PersonalRuta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * «Sacar pedido»: la sala se busca mientras se escribe (12/11/2026, pedido del dueño: «si
 * busco santa me muestre santa elena y todos los que sean así»). La lista sale de una ruta
 * JSON con el mismo permiso de la pantalla, con límite de pedidos, un tope de resultados y
 * solo lo que la lista muestra. Sin JavaScript, el botón «Buscar» sigue como antes.
 * Datos ficticios; los lugares son de El Salvador.
 */
class BuscarSalaMientrasEscribeTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        PersonalRuta::create(['nombre' => 'Carlos Ficticio', 'user_id' => $this->vendedor->id]);
        $this->cliente = Cliente::factory()->create(['nombre' => 'Cliente de prueba']);
    }

    private function sala(string $nombre, array $extra = []): int
    {
        return $this->cliente->sucursales()->create(['nombre' => $nombre] + $extra)->id;
    }

    private function buscar(string $texto): array
    {
        return $this->actingAs($this->vendedor)->getJson(route('rutas.sacar-pedido.salas', ['q' => $texto]))
            ->assertOk()->json('salas');
    }

    public function test_la_lista_trae_las_salas_que_contienen_el_texto_y_abre_su_pedido(): void
    {
        $elena = $this->sala('Sala de prueba Santa Elena', ['codigo' => '7001']);
        $this->sala('Súper de prueba Santa Ana');
        $this->sala('Sala de prueba Usulután');
        $this->sala('Sala de prueba Santa Rosa apagada', ['activo' => false]);

        // Solo las activas que contienen el texto, por nombre, sin importar mayúsculas.
        $this->assertSame(['Sala de prueba Santa Elena', 'Súper de prueba Santa Ana'], array_column($this->buscar('santa'), 'nombre'));
        $this->assertSame(['Sala de prueba Santa Elena', 'Súper de prueba Santa Ana'], array_column($this->buscar('SANTA'), 'nombre'));
        // Con la tilde escrita igual.
        $this->assertSame(['Sala de prueba Usulután'], array_column($this->buscar('Usulután'), 'nombre'));

        // Cada fila: lo que se lee y adónde lleva. Nada de datos fiscales ni precios.
        $fila = $this->buscar('elena')[0];
        $this->assertSame(['nombre', 'detalle', 'url'], array_keys($fila));
        $this->assertSame('7001 · Cliente de prueba', $fila['detalle']);
        $this->assertSame(route('rutas.sacar-pedido.create', $elena), $fila['url']);
        $this->get($fila['url'])->assertOk()->assertSee('Pedido de Sala de prueba Santa Elena');

        $this->assertSame([], $this->buscar('Sala de prueba Morazán'));
    }

    /**
     * Sin tildes encuentra lo que tiene tildes y al revés. Lo hace la collation de MySQL
     * (utf8mb4_unicode_ci), la misma del buscador de la oficina; SQLite no la tiene, así que
     * esta parte la confirma la CI con MySQL.
     */
    public function test_sin_tildes_encuentra_lo_que_tiene_tildes(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Las tildes las iguala la collation de MySQL; lo prueba la CI.');
        }
        $this->sala('Sala de prueba Usulután');
        $this->sala('Súper de prueba Santa Ana');

        $this->assertSame(['Sala de prueba Usulután'], array_column($this->buscar('usulutan'), 'nombre'));
        $this->assertSame(['Súper de prueba Santa Ana'], array_column($this->buscar('super'), 'nombre'));
        $this->assertSame(['Súper de prueba Santa Ana'], array_column($this->buscar('sánta ána'), 'nombre'));
    }

    public function test_busca_tambien_por_pueblo_como_el_boton_buscar(): void
    {
        $dep = Departamento::firstOrCreate(['codigo' => '06'], ['nombre' => 'San Salvador', 'activo' => true]);
        $distrito = Distrito::create(['departamento_id' => $dep->id, 'municipio' => 'San Salvador Este', 'nombre' => 'Ilopango']);
        $this->sala('Sala de prueba Oriente', ['distrito_id' => $distrito->id]);

        $fila = $this->buscar('ilopango')[0];
        $this->assertSame('Sala de prueba Oriente', $fila['nombre']);
        $this->assertSame('Ilopango · Cliente de prueba', $fila['detalle']);
    }

    public function test_trae_como_mucho_diez(): void
    {
        foreach (range(1, 15) as $n) {
            $this->sala('Sala de prueba Santa '.str_pad((string) $n, 2, '0', STR_PAD_LEFT));
        }

        $salas = $this->buscar('santa');
        $this->assertCount(10, $salas);
        $this->assertSame('Sala de prueba Santa 01', $salas[0]['nombre']);
    }

    public function test_pide_de_2_a_100_letras(): void
    {
        $this->actingAs($this->vendedor);
        foreach (['', 's', ' s ', str_repeat('a', 101)] as $texto) {
            $this->getJson(route('rutas.sacar-pedido.salas', ['q' => $texto]))->assertUnprocessable()->assertJsonValidationErrors('q');
        }
        $this->getJson(route('rutas.sacar-pedido.salas', ['q' => ['santa']]))->assertUnprocessable();
    }

    public function test_el_mismo_permiso_de_la_pantalla_y_con_limite_de_pedidos(): void
    {
        $this->sala('Sala de prueba Santa Elena');
        $url = route('rutas.sacar-pedido.salas', ['q' => 'santa']);

        $this->getJson($url)->assertUnauthorized();
        foreach (['facturacion', 'contabilidad', 'produccion'] as $rol) {
            $this->actingAs(User::factory()->create(['activo' => true])->assignRole($rol))->getJson($url)->assertForbidden();
        }
        foreach (['vendedor', 'jefe_vendedores', 'administrador'] as $rol) {
            $this->actingAs(User::factory()->create(['activo' => true])->assignRole($rol))->getJson($url)->assertOk();
        }

        $middleware = Route::getRoutes()->getByName('rutas.sacar-pedido.salas')->gatherMiddleware();
        $this->assertContains('permission:rutas.pedidos.registrar', $middleware);
        $this->assertContains('throttle:60,1', $middleware);
    }

    /** Sin JavaScript (o si falla) el formulario sigue: «Buscar» recarga con la lista. */
    public function test_sin_javascript_el_boton_buscar_sigue_andando(): void
    {
        $this->sala('Sala de prueba Santa Elena');

        $this->actingAs($this->vendedor)->get(route('rutas.sacar-pedido.index', ['q' => 'santa']))->assertOk()
            ->assertSee('action="'.route('rutas.sacar-pedido.index').'"', false)
            ->assertSee('name="q"', false)
            ->assertSee('>Buscar</button>', false)
            ->assertSee('Sala de prueba Santa Elena')
            // La pantalla le pasa a Alpine la ruta de la lista.
            ->assertSee(route('rutas.sacar-pedido.salas'), false);
    }
}
