<?php

namespace Tests\Feature\Fabrica;

use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoSalidaRuta;
use App\Enums\PropositoSalida;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\PedidoSala;
use App\Models\PedidoSalaEnvio;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\PedidosFabrica;
use App\Support\NombreEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * La pantalla de la fábrica a pantalla completa (12/11/2026): sin el menú del sistema, la
 * lista solo con los nombres, el pedido abierto con los datos de la sala y los otros
 * pedidos arriba, «Imprimir y mandar» y la hoja de cada envío. Todos los datos son
 * inventados.
 */
class PantallaFabricaTest extends TestCase
{
    use RefreshDatabase;

    private User $fabrica;

    private User $vendedor;

    private PersonalRuta $persona;

    private Ruta $ruta;

    private ClienteSucursal $sala;

    private ClienteSucursal $otraSala;

    private Producto $galleta;

    private Producto $canilla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09 09:00:00');
        config()->set('planta.enabled', false);

        $this->fabrica = User::factory()->create(['activo' => true, 'name' => 'Fábrica Inventada'])->assignRole('fabrica');
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos Inventado', 'user_id' => $this->vendedor->id]);
        $this->ruta = Ruta::create(['nombre' => 'Ruta Inventada']);
        $this->sala = Cliente::factory()->create(['nombre' => 'Súper Ficticio'])
            ->sucursales()->create(['nombre' => 'Súper Ficticio Centro', 'codigo' => '9001', 'ruta_id' => $this->ruta->id, 'direccion' => 'Calle de Prueba 123']);
        $this->otraSala = Cliente::factory()->create(['nombre' => 'Tienda Inventada'])
            ->sucursales()->create(['nombre' => 'Tienda Inventada Norte', 'codigo' => '9002', 'ruta_id' => $this->ruta->id]);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01', 'precio_unitario' => 7.77]);
        $this->canilla = Producto::factory()->create(['nombre' => 'Canilla de prueba', 'codigo' => 'C01', 'precio_unitario' => 3.33]);

        $this->actingAs($this->fabrica);
    }

    /** @param  array<int, string>  $cantidades  [producto_id => cantidad] */
    private function pedido(array $cantidades, ?ClienteSucursal $sala = null, string $fecha = '2026-11-09', array $extra = []): PedidoSala
    {
        $pedido = PedidoSala::create($extra + [
            'cliente_sucursal_id' => ($sala ?? $this->sala)->id, 'ruta_id' => $this->ruta->id,
            'sacado_por_id' => $this->persona->id, 'registrado_por' => $this->vendedor->id,
            'fecha' => $fecha, 'estado' => EstadoPedidoSala::Registrado,
        ]);
        foreach ($cantidades as $producto => $cantidad) {
            $pedido->lineas()->create(['producto_id' => $producto, 'cantidad' => $cantidad]);
        }

        return $pedido;
    }

    private function linea(PedidoSala $pedido, Producto $producto): int
    {
        return $pedido->lineas()->where('producto_id', $producto->id)->value('id');
    }

    /** @param  array<int, string>  $cantidades  [producto_id => cantidad] */
    private function mandar(PedidoSala $pedido, array $cantidades, array $extra = []): TestResponse
    {
        $porLinea = [];
        foreach ($cantidades as $producto => $cantidad) {
            $porLinea[$this->linea($pedido, Producto::findOrFail($producto))] = $cantidad;
        }
        $version = PedidoSala::query()->with(app(PedidosFabrica::class)->relaciones())->findOrFail($pedido->id)->versionProduccion();

        return $this->post(route('fabrica.pedidos.mandar', $pedido), $extra + [
            'clave' => (string) Str::uuid(), 'version' => $version, 'cantidades' => $porLinea,
        ]);
    }

    /** Lo que hay dentro del primer elemento con ese aria-label. */
    private function bloque(string $html, string $etiqueta): string
    {
        $this->assertSame(1, preg_match('/aria-label="'.preg_quote($etiqueta, '/').'"[^>]*>(.*?)<\/nav>/s', $html, $m), 'Falta «'.$etiqueta.'».');

        return $m[1];
    }

    // ══════════════════════════════ 1. pantalla completa

    public function test_la_fabrica_ve_solo_su_pantalla_sin_menu_con_su_nombre_y_salir(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '5']);

        $this->post('/logout');
        $this->post('/login', ['email' => $this->fabrica->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertRedirect(route('fabrica.pedidos.index'));

        foreach ([route('fabrica.pedidos.index'), route('fabrica.pedidos.show', $pedido), route('fabrica.pedidos.index', ['ver' => 'hechos'])] as $url) {
            $this->get($url)->assertOk()
                ->assertDontSee('id="sidebar-principal"', false)->assertDontSee('data-area-selector', false)
                ->assertSee(NombreEmpresa::actual())->assertSee('Fábrica Inventada')
                ->assertSee('Salir</button>', false)->assertSee('action="'.route('logout').'"', false)
                ->assertDontSee('Volver al sistema');
        }
    }

    public function test_quien_tiene_otras_areas_ve_volver_al_sistema(): void
    {
        $this->actingAs(User::factory()->create(['activo' => true])->assignRole('administrador'));

        $this->get(route('fabrica.pedidos.index'))->assertOk()
            ->assertDontSee('id="sidebar-principal"', false)
            ->assertSee('Volver al sistema')->assertSee('href="'.route('dashboard').'"', false);
    }

    // ══════════════════════════════ 2. la lista

    public function test_la_lista_muestra_solo_los_nombres_como_botones(): void
    {
        $viejo = $this->pedido([$this->canilla->id => '6'], $this->otraSala, '2026-11-06');
        $pedido = $this->pedido([$this->galleta->id => '24', $this->canilla->id => '6']);
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$this->linea($pedido, $this->galleta) => '20']])->assertOk();

        $html = $this->get(route('fabrica.pedidos.index'))->assertOk()
            ->assertSee('Por mandar (2)')->assertSee('Hechos hoy')
            ->assertSee('href="'.route('fabrica.pedidos.show', $pedido).'"', false)
            ->assertSee('Súper Ficticio Centro')->assertSee('Ruta Inventada · 2 productos · 09:00')
            ->assertSee('Armando')->assertSee('hace 3 días')
            ->assertDontSee('Galleta de prueba')->assertDontSee('Canilla de prueba')
            ->assertDontSee('Se manda todo')->assertDontSee('Faltan cosas')->assertDontSee('No se trae')->assertDontSee('Mandar lo marcado')
            ->assertDontSee('action="'.route('fabrica.pedidos.mandar', $pedido).'"', false)
            ->assertSee('location.reload()', false)
            ->getContent();
        // Del más viejo al más nuevo.
        $this->assertTrue(strpos($html, 'pedido-'.$viejo->id) < strpos($html, 'pedido-'.$pedido->id));
    }

    // ══════════════════════════════ 3 y 4. el pedido abierto

    public function test_el_pedido_abierto_muestra_los_datos_de_la_sala_y_arriba_los_otros_pedidos(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '24'], extra: ['numero_orden_compra' => 'OC-555', 'notas' => 'Entregar por la puerta de atrás']);
        $sinArmar = $this->pedido([$this->canilla->id => '6'], $this->otraSala);
        $tercera = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Sala Armándose', 'codigo' => '9003', 'ruta_id' => $this->ruta->id]);
        $armando = $this->pedido([$this->canilla->id => '3'], $tercera);
        $this->patchJson(route('fabrica.pedidos.borrador', $armando), ['cantidades' => [$this->linea($armando, $this->canilla) => '1']])->assertOk();
        // Uno ya mandado no aparece arriba.
        $mandado = $this->pedido([$this->galleta->id => '1'], Cliente::factory()->create()->sucursales()->create(['nombre' => 'Sala Ya Mandada', 'codigo' => '9004']));
        $this->mandar($mandado, [$this->galleta->id => '1'])->assertSessionHasNoErrors();

        $html = $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()
            ->assertSee('Qué va a Súper Ficticio Centro')
            ->assertSee('Calle de Prueba 123')->assertSee('Ruta Inventada')->assertSee('OC-555')
            ->assertSee('Carlos Inventado')->assertSee('09/11/2026 09:00')->assertSee('Entregar por la puerta de atrás')
            ->assertSee('Galleta de prueba')->assertSee('Todo')->assertSee('Nada')->assertSee('Por qué no va (opcional)')
            ->assertSee('Imprimir y mandar')->assertSee('Dejar para después')
            ->assertSee('action="'.route('fabrica.pedidos.cerrar', $pedido).'"', false)->assertSee('No se trae')
            ->assertDontSee('7.77')
            ->getContent();

        $fila = $this->bloque($html, 'Otros pedidos');
        $this->assertStringContainsString('href="'.route('fabrica.pedidos.index').'"', $fila);
        $this->assertStringContainsString('Todos', $fila);
        $this->assertStringContainsString('salir($el.href)', $fila);
        $this->assertStringNotContainsString('Súper Ficticio Centro', $fila);
        $this->assertStringNotContainsString('Sala Ya Mandada', $fila);
        // Los que se están armando, primero.
        $this->assertTrue(strpos($fila, route('fabrica.pedidos.show', $armando)) < strpos($fila, route('fabrica.pedidos.show', $sinArmar)));
        $this->assertStringContainsString('Sala Armándose', $fila);
        $this->assertStringContainsString('Tienda Inventada Norte', $fila);
    }

    public function test_no_se_trae_queda_dentro_del_pedido_abierto(): void
    {
        $parcial = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '4']);
        $this->mandar($parcial, [$this->galleta->id => '10'], ['sigue' => '1'])->assertSessionHasNoErrors();

        $this->get(route('fabrica.pedidos.show', $parcial))->assertOk()
            ->assertSee('Lo que falta no se trae')->assertSee('Imprimir y mandar');
    }

    // ══════════════════════════════ 5. imprimir y mandar

    public function test_imprimir_y_mandar_registra_el_envio_como_antes_y_abre_su_hoja(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);

        $respuesta = $this->mandar($pedido, [$this->galleta->id => '12'])->assertSessionHasNoErrors();
        $envio = PedidoSalaEnvio::sole();
        $respuesta->assertRedirect(route('fabrica.pedidos.imprimir', [$pedido, $envio]))
            ->assertSessionHas('status', 'Salió el pedido de Súper Ficticio Centro.');
        $this->assertSame(1, $envio->numero);
        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);
        $this->assertTrue(Activity::where('log_name', 'pedido_sala')->where('description', 'mandó todo el pedido')->exists());

        // Imprimir no cambia nada, y al volver a la lista sigue el aviso con «Deshacer».
        $this->get(route('fabrica.pedidos.imprimir', [$pedido, $envio]))->assertOk()->assertSee('window.print()', false);
        $this->assertSame(1, PedidoSalaEnvio::count());
        $this->get(route('fabrica.pedidos.index'))->assertSee('Salió el pedido de Súper Ficticio Centro.')->assertSee('Deshacer</button>', false);
    }

    public function test_la_hoja_dice_lo_que_va_lo_que_queda_y_lo_que_no_va_sin_precios(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '24', $this->canilla->id => '6'], extra: ['numero_orden_compra' => 'OC-555']);
        $c = $this->linea($pedido, $this->canilla);
        $this->mandar($pedido, [$this->galleta->id => '24', $this->canilla->id => '0'], ['motivos' => [$c => 'No salió del horno'], 'sigue' => '1'])
            ->assertSessionHasNoErrors();
        $envio = PedidoSalaEnvio::sole();

        $this->get(route('fabrica.pedidos.imprimir', [$pedido, $envio]))->assertOk()
            ->assertSee(NombreEmpresa::actual())->assertSee('Envío 1')
            ->assertSee('Súper Ficticio Centro')->assertSee('Calle de Prueba 123')->assertSee('Ruta Inventada')->assertSee('OC-555')
            ->assertSee('Carlos Inventado')->assertSee('09/11/2026 09:00')
            ->assertSeeInOrder(['Lo que va', 'G01', 'Galleta de prueba', '24', 'Queda pendiente', 'C01', 'Canilla de prueba', '6', 'No salió del horno'])
            ->assertDontSee('No va:')
            ->assertDontSee('7.77')->assertDontSee('3.33')->assertDontSee('$');

        // Sin «va en otro envío», lo que falta no va, con su motivo.
        $otro = $this->pedido([$this->galleta->id => '5', $this->canilla->id => '2'], $this->otraSala);
        $this->mandar($otro, [$this->galleta->id => '3', $this->canilla->id => '0'], ['motivos' => [$this->linea($otro, $this->canilla) => 'Sin canillas']])
            ->assertSessionHasNoErrors();
        $segundo = $otro->envios()->sole();
        $this->get(route('fabrica.pedidos.imprimir', [$otro, $segundo]))->assertOk()
            ->assertSeeInOrder(['Lo que va', 'Galleta de prueba', '3', 'No va', 'Galleta de prueba', '2', 'Canilla de prueba', '2', 'Sin canillas'])
            ->assertDontSee('Queda pendiente');
    }

    public function test_la_reimpresion_de_un_envio_anterior_lo_muestra_como_salio(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);
        $g = $this->linea($pedido, $this->galleta);
        $this->mandar($pedido, [$this->galleta->id => '4'], ['motivos' => [$g => 'Faltó masa'], 'sigue' => '1'])->assertSessionHasNoErrors();
        $primero = $pedido->envios()->where('numero', 1)->sole();
        $hoja1 = route('fabrica.pedidos.imprimir', [$pedido, $primero]);

        // Recién salido: van 4 y quedan 6, con el motivo de ese envío.
        $this->get($hoja1)->assertOk()
            ->assertSeeInOrder(['Lo que va', 'Galleta de prueba', '4', 'Queda pendiente', 'Galleta de prueba', '6', 'Faltó masa']);

        // Sale el envío 2 con las 6. Reimpreso, el envío 1 sigue diciendo que no fueron 6;
        // los motivos de hoy no se mezclan con los de ese envío.
        $this->travelTo('2026-11-10 08:00:00');
        $this->mandar($pedido, [$this->galleta->id => '6'])->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);
        $this->get($hoja1)->assertOk()
            ->assertSeeInOrder(['Lo que va', 'Galleta de prueba', '4', 'No fue en este envío', 'Galleta de prueba', '6'])
            ->assertSee('Después de este salieron otros envíos.')
            ->assertDontSee('Queda pendiente')->assertDontSee('No va<', false);

        // El envío 2 completó el pedido: no queda nada.
        $this->get(route('fabrica.pedidos.imprimir', [$pedido, $pedido->envios()->where('numero', 2)->sole()]))->assertOk()
            ->assertSeeInOrder(['Lo que va', 'Galleta de prueba', '6'])
            ->assertDontSee('No fue en este envío')->assertDontSee('Queda pendiente');
    }

    public function test_si_hubo_un_envio_despues_aunque_se_haya_deshecho_no_muestra_motivos(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);
        $g = $this->linea($pedido, $this->galleta);
        $this->mandar($pedido, [$this->galleta->id => '4'], ['motivos' => [$g => 'Faltó masa'], 'sigue' => '1'])->assertSessionHasNoErrors();
        $this->mandar($pedido, [$this->galleta->id => '2'], ['motivos' => [$g => 'Motivo del envío dos'], 'sigue' => '1'])->assertSessionHasNoErrors();
        $segundo = $pedido->envios()->where('numero', 2)->sole();
        $this->patch(route('fabrica.pedidos.anular-envio', [$pedido, $segundo]))->assertSessionHasNoErrors();

        $this->get(route('fabrica.pedidos.imprimir', [$pedido, $pedido->envios()->where('numero', 1)->sole()]))->assertOk()
            ->assertSeeInOrder(['Lo que va', 'Galleta de prueba', '4', 'No fue en este envío', 'Galleta de prueba', '6'])
            ->assertSee('Después de este salieron otros envíos.')
            ->assertDontSee('Motivo del envío dos')->assertDontSee('Faltó masa')->assertDontSee('Queda pendiente');
    }

    public function test_cambiar_de_pedido_solo_se_va_si_lo_marcado_se_guardo(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '24']);
        $g = $this->linea($pedido, $this->galleta);

        // Los guardados van en fila; no se sale mientras se manda (ni al revés); y si el
        // guardado falló, se queda y avisa arriba.
        $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()
            ->assertSee('const p = this.cola.then(() => this.guardarAhora());', false)
            ->assertSee('while (this.sucio && ! this.error) await this.enviarBorrador();', false)
            ->assertSee('if (this.error) { this.aviso = true; this.saliendo = false; return; }', false)
            ->assertSee('if (this.enviando || this.saliendo) return;', false)
            ->assertSee(':disabled="enviando || saliendo"', false)
            ->assertSee('No se pudo guardar lo marcado. Revisá la conexión y probá de nuevo.')
            ->assertSee('x-show="aviso"', false);

        // Los casos en que el servidor rechaza el guardado y la hoja no debe irse.
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$g => '25']])->assertStatus(422);
        auth()->logout();
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$g => '20']])->assertStatus(401);
        $this->assertNull($pedido->lineas()->whereKey($g)->value('va_borrador'));
    }

    public function test_la_hoja_solo_de_envios_vigentes_de_ese_pedido_y_con_permiso(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $otro = $this->pedido([$this->galleta->id => '3'], $this->otraSala);
        $this->mandar($pedido, [$this->galleta->id => '12'])->assertSessionHasNoErrors();
        $envio = PedidoSalaEnvio::sole();
        $url = route('fabrica.pedidos.imprimir', [$pedido, $envio]);

        // El envío de otro pedido en la URL: 404.
        $this->get(route('fabrica.pedidos.imprimir', [$otro, $envio]))->assertNotFound();

        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
        foreach (['vendedor', 'jefe_vendedores', 'facturacion', 'contabilidad'] as $rol) {
            $this->actingAs(User::factory()->create(['activo' => true])->assignRole($rol))->get($url)->assertForbidden();
        }

        // Un envío deshecho ya no se manda: no se imprime.
        $this->actingAs($this->fabrica)->patch(route('fabrica.pedidos.anular-envio', [$pedido, $envio]))->assertSessionHasNoErrors();
        $this->get($url)->assertNotFound();
    }

    public function test_hechos_hoy_tiene_imprimir_de_nuevo(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12']);
        $this->mandar($pedido, [$this->galleta->id => '12'])->assertSessionHasNoErrors();
        $envio = PedidoSalaEnvio::sole();

        $this->get(route('fabrica.pedidos.index', ['ver' => 'hechos']))->assertOk()
            ->assertSee('Imprimir de nuevo')->assertSee('href="'.route('fabrica.pedidos.imprimir', [$pedido, $envio]).'"', false);

        // La oficina ve los mismos envíos sin ese botón.
        $this->actingAs(User::factory()->create(['activo' => true])->assignRole('facturacion'));
        $this->get(route('rutas.pedidos.show', $pedido))->assertOk()->assertSee('Envío 1')->assertDontSee('Imprimir de nuevo');
    }

    public function test_en_monitor_cada_producto_es_un_renglon_y_la_lista_usa_el_ancho(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '12', $this->canilla->id => '6']);
        $this->pedido([$this->galleta->id => '3'], $this->otraSala);

        // Un renglón por producto: nombre y código | Pidió | Va, Todo, Nada; el motivo abajo.
        $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()
            ->assertSee('max-w-full mx-auto', false)
            ->assertSee('lg:grid lg:grid-cols-2', false)->assertSee('xl:grid-cols-2', false)
            ->assertSeeInOrder(['Galleta de prueba', 'G01', 'Pidió 12', 'Va', 'Todo', 'Nada', 'Por qué no va'])
            ->assertSee('sm:grid-cols-2 lg:grid-cols-6', false);

        $this->get(route('fabrica.pedidos.index'))->assertOk()
            ->assertSee('max-w-full mx-auto', false)->assertSee('lg:grid-cols-4', false);
    }

    // ══════════════════════════════ 7. la zona de la tienda nueva

    public function test_la_tienda_nueva_muestra_la_zona_del_viaje(): void
    {
        $viaje = SalidaRuta::create([
            'ruta_id' => $this->ruta->id, 'fecha_inicio' => '2026-11-09', 'estado' => EstadoSalidaRuta::EnCurso,
            'proposito' => PropositoSalida::Ambos, 'automatica' => true, 'suma_pedidos' => true, 'created_by' => $this->vendedor->id,
        ]);
        $pedido = PedidoSala::create([
            'cliente_sucursal_id' => null, 'sala_nueva_nombre' => 'Tienda Nueva de Prueba', 'ruta_id' => null, 'salida_ruta_id' => $viaje->id,
            'sacado_por_id' => $this->persona->id, 'registrado_por' => $this->vendedor->id, 'fecha' => '2026-11-09', 'estado' => EstadoPedidoSala::Registrado,
        ]);
        $pedido->lineas()->create(['producto_id' => $this->galleta->id, 'cantidad' => '6']);

        $this->get(route('fabrica.pedidos.index'))->assertOk()->assertSee('Tienda Nueva de Prueba (tienda nueva)')
            ->assertSee('Ruta Inventada · 1 producto · 09:00')->assertDontSee('Sin zona');
        $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()->assertSee('Ruta Inventada')->assertDontSee('Sin zona');
        $this->mandar($pedido, [$this->galleta->id => '6'])->assertSessionHasNoErrors();
        $this->get(route('fabrica.pedidos.imprimir', [$pedido, $pedido->envios()->sole()]))->assertOk()->assertSee('Ruta Inventada');

        // Solo se muestra: el pedido sigue sin zona propia.
        $this->assertNull($pedido->refresh()->ruta_id);
    }
}
