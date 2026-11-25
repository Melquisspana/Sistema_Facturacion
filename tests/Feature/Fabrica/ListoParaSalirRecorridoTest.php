<?php

namespace Tests\Feature\Fabrica;

use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoSalidaRuta;
use App\Enums\PropositoSalida;
use App\Enums\RolEnSalida;
use App\Mail\Pedidos\ResumenDiarioPedidosCorreo;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\PdfFabricaDiario;
use App\Services\Rutas\PedidosFabrica;
use App\Services\Rutas\PedidosOficina;
use App\Support\Correo\CandadoCorreoReal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * El recorrido de punta a punta de «Listo para salir» (Melqui, 25/11/2026): la PC de pedidos
 * abre la hoja, no toca nada y marca la caja lista; eso guarda lo que va en la caja y la
 * oficina ve el mismo nombre en la hoja de pedido, el detalle, «Viajes», «Llegó de pedidos» y
 * el correo de las 18:00. Después la oficina corrige una cantidad, se toca dos veces y se
 * quita de listos. Todo por las pantallas, como lo usan. Todos los datos son inventados.
 */
class ListoParaSalirRecorridoTest extends TestCase
{
    use RefreshDatabase;

    private User $fabrica;

    private User $vendedor;

    private User $oficina;

    private User $jefe;

    private PersonalRuta $persona;

    private Ruta $ruta;

    private SalidaRuta $viaje;

    private ClienteSucursal $sala;

    private ClienteSucursal $otraSala;

    private Producto $galleta;

    private Producto $canilla;

    protected function setUp(): void
    {
        parent::setUp();
        // Lunes.
        $this->travelTo('2026-11-09 09:00:00');
        config()->set('planta.enabled', false);
        config()->set('pedidos.correo_resumen_diario', 'oficina@ejemplo.test');
        config(['dte.ambiente' => '01', 'rutas.ambiente_ccf' => null]);

        $this->fabrica = User::factory()->create(['activo' => true, 'name' => 'Pedidos Inventada'])->assignRole('fabrica');
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->jefe = User::factory()->create(['activo' => true])->assignRole('jefe_vendedores');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos Inventado', 'user_id' => $this->vendedor->id]);
        $this->ruta = Ruta::create(['nombre' => 'Ruta Inventada']);
        $this->viaje = SalidaRuta::create([
            'ruta_id' => $this->ruta->id, 'fecha_inicio' => '2026-11-09', 'estado' => EstadoSalidaRuta::EnCurso,
            'proposito' => PropositoSalida::Ambos, 'automatica' => true, 'suma_pedidos' => true,
        ]);
        $this->viaje->participantes()->create(['rutas_personal_id' => $this->persona->id, 'rol' => RolEnSalida::Responsable]);
        $this->sala = Cliente::factory()->create(['nombre' => 'Súper Ficticio'])
            ->sucursales()->create(['nombre' => 'Súper Ficticio Centro', 'codigo' => '9001', 'ruta_id' => $this->ruta->id]);
        $this->otraSala = Cliente::factory()->create(['nombre' => 'Tienda Inventada'])
            ->sucursales()->create(['nombre' => 'Tienda Inventada Norte', 'codigo' => '9002', 'ruta_id' => $this->ruta->id]);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01', 'precio_unitario' => 7.77]);
        $this->canilla = Producto::factory()->create(['nombre' => 'Canilla de prueba', 'codigo' => 'C01', 'precio_unitario' => 3.33]);
    }

    /** @param  array<int, string>  $cantidades  [producto_id => cantidad] */
    private function pedido(array $cantidades, ?ClienteSucursal $sala = null): PedidoSala
    {
        $pedido = $this->viaje->pedidos()->create([
            'cliente_sucursal_id' => ($sala ?? $this->sala)->id, 'ruta_id' => $this->ruta->id,
            'sacado_por_id' => $this->persona->id, 'registrado_por' => $this->vendedor->id,
            'fecha' => '2026-11-09', 'estado' => EstadoPedidoSala::Registrado,
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

    private function vaBorrador(PedidoSala $pedido, Producto $producto): ?string
    {
        $va = $pedido->lineas()->where('producto_id', $producto->id)->value('va_borrador');

        return $va === null ? null : (string) $va;
    }

    /** La PC de pedidos abre la hoja y no toca nada: la versión es la que lleva su formulario de «Listo para salir». */
    private function abrirHoja(PedidoSala $pedido): string
    {
        $html = $this->actingAs($this->fabrica)->get(route('fabrica.pedidos.show', $pedido))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/x-ref="listo"[^>]*>(?:(?!<\/form>).)*name="version" value="([0-9a-f]{64})"/s', $html, $m), 'La hoja no trae el formulario de «Listo para salir» con su versión.');

        return $m[1];
    }

    /** «Listo para salir» desde la hoja, con la versión con que se abrió. */
    private function tocarListo(PedidoSala $pedido, string $version): TestResponse
    {
        return $this->actingAs($this->fabrica)->patch(route('fabrica.pedidos.listo', $pedido), ['listo' => '1', 'version' => $version]);
    }

    /** «Quitar de listos» desde la hoja (sin versión, como el formulario). */
    private function quitarDeListos(PedidoSala $pedido): TestResponse
    {
        $this->abrirHoja($pedido);

        return $this->actingAs($this->fabrica)->patch(route('fabrica.pedidos.listo', $pedido), ['listo' => '0']);
    }

    /** «Imprimir y mandar» desde la hoja; con `sigue`, lo que falta va en otro envío. */
    private function mandar(PedidoSala $pedido, array $cantidades, bool $sigue): TestResponse
    {
        $porLinea = [];
        foreach ($cantidades as $producto => $cantidad) {
            $porLinea[$this->linea($pedido, Producto::findOrFail($producto))] = $cantidad;
        }
        $version = PedidoSala::query()->with(app(PedidosFabrica::class)->relaciones())->findOrFail($pedido->id)->versionProduccion();

        return $this->actingAs($this->fabrica)->post(route('fabrica.pedidos.mandar', $pedido), [
            'clave' => (string) Str::uuid(), 'version' => $version, 'cantidades' => $porLinea,
        ] + ($sigue ? ['sigue' => '1'] : []));
    }

    /** La oficina abre «Corregir», cambia las cantidades y guarda, con la versión del formulario. */
    private function oficinaCorrige(PedidoSala $pedido, array $cantidades): TestResponse
    {
        $html = $this->actingAs($this->oficina)->get(route('rutas.pedidos.corregir', $pedido))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/name="version" value="([0-9a-f]{64})"/', $html, $m), 'El formulario de «Corregir» no trae la versión.');
        $porLinea = [];
        foreach ($cantidades as $producto => $cantidad) {
            $porLinea[$this->linea($pedido, Producto::findOrFail($producto))] = $cantidad;
        }

        return $this->put(route('rutas.pedidos.corregir.guardar', $pedido), [
            'version' => $m[1], 'cantidades' => $porLinea, 'motivo' => 'El vendedor se equivocó.',
        ]);
    }

    /** Lo que hay dentro de la sección de la lista con ese título; null si no está. */
    private function seccion(string $html, string $titulo): ?string
    {
        return preg_match('/<section aria-label="'.preg_quote($titulo, '/').'"[^>]*>(.*?)<\/section>/s', $html, $m) ? $m[1] : null;
    }

    private function listaFabrica(): string
    {
        return $this->actingAs($this->fabrica)->get(route('fabrica.pedidos.index'))->assertOk()->getContent();
    }

    /** «Viajes», tal como lo ve el jefe de vendedores. */
    private function viajes(): TestResponse
    {
        return $this->actingAs($this->jefe)->get(route('rutas.viajes'))->assertOk();
    }

    /**
     * El correo de las 18:00 de ese día: [cuerpo, PDF], en texto y con los espacios juntos.
     *
     * @return array{0: string, 1: string}
     */
    private function correo(): array
    {
        Mail::fake();
        $this->app->instance(CandadoCorreoReal::class, new class extends CandadoCorreoReal
        {
            public function debeSimular(): bool
            {
                return false;
            }
        });
        $this->travelTo('2026-11-09 18:00:00');
        $this->artisan('pedidos:correo-diario')->assertSuccessful();
        $correos = Mail::sent(ResumenDiarioPedidosCorreo::class);
        $this->assertCount(1, $correos);
        $correo = $correos->first();
        $junto = fn (string $s) => preg_replace('/\s+/u', ' ', $s);

        return [$junto($correo->render()), $junto(html_entity_decode(strip_tags(app(PdfFabricaDiario::class)->html($correo->contenido))))];
    }

    private function cuantas(PedidoSala $pedido, string $descripcion): int
    {
        return Activity::query()->where('log_name', 'pedido_sala')->where('subject_id', $pedido->id)->where('description', $descripcion)->count();
    }

    // ══════════════════════════════ 1. marcarlo sin tocar la hoja y lo que ve la oficina

    public function test_listo_sin_tocar_la_hoja_guarda_lo_que_va_y_la_oficina_lo_ve_como_listo_para_salir(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '5']);
        // El resto de un pedido que ya salió en parte: las galletas fueron todas.
        $resto = $this->pedido([$this->galleta->id => '4', $this->canilla->id => '6'], $this->otraSala);
        $this->mandar($resto, [$this->galleta->id => '4', $this->canilla->id => '0'], sigue: true)->assertSessionHasNoErrors();

        // Abren la hoja, no tocan nada y marcan «Listo para salir».
        foreach ([$pedido, $resto] as $p) {
            $this->tocarListo($p, $this->abrirHoja($p))->assertSessionHasNoErrors()
                ->assertRedirect(route('fabrica.pedidos.index'))
                ->assertSessionHas('status', 'El pedido de '.$p->nombreSala().' quedó en «Listos para salir».');
            $this->assertSame(EstadoPedidoSala::Listo, $p->refresh()->estado);
            // Queda «Armando», con quién: el vendedor ya no lo corrige.
            $this->assertNotNull($p->armando_desde);
            $this->assertSame($this->fabrica->id, $p->armando_por);
            $this->assertFalse($this->vendedor->can('corregir', $p));
            $this->assertSame(1, $this->cuantas($p, 'empezó a armar el pedido'));
        }

        // Lo que va: todo lo que faltaba. Lo que ya salió entero no se anota.
        $this->assertSame('10.0000', $this->vaBorrador($pedido, $this->galleta));
        $this->assertSame('5.0000', $this->vaBorrador($pedido, $this->canilla));
        $this->assertNull($this->vaBorrador($resto, $this->galleta));
        $this->assertSame('6.0000', $this->vaBorrador($resto, $this->canilla));

        // La fábrica: los dos arriba, en «Listos para salir»; la orden para la caja lleva todo.
        $listos = $this->seccion($this->listaFabrica(), 'Listos para salir');
        $this->assertNotNull($listos);
        $this->assertStringContainsString('pedido-'.$pedido->id, $listos);
        $this->assertStringContainsString('pedido-'.$resto->id, $listos);
        $this->actingAs($this->fabrica)->get(route('fabrica.pedidos.caja', $pedido))->assertOk()
            ->assertSeeInOrder(['Galleta de prueba', '10', '0', 'Canilla de prueba', '5', '0']);

        // La oficina: la hoja de pedido y el detalle dicen «Listo para salir».
        $this->actingAs($this->oficina);
        $this->get(route('rutas.pedidos.hoja', $pedido))->assertOk()
            ->assertSee('Listo para salir')->assertSee('Todavía no se despachó nada de este pedido.')->assertDontSee('Listo en fábrica');
        $this->get(route('rutas.pedidos.show', $pedido))->assertOk()
            ->assertSee('Listo para salir')->assertDontSee('Listo en fábrica');
        $this->get(route('rutas.pedidos.hoja', $resto))->assertOk()
            ->assertSee('Listo para salir')->assertSeeInOrder(['Canilla de prueba', '6', 'Va en otro envío', '6'])->assertDontSee('Listo en fábrica');
        // La lista de pedidos: el filtro lo trae con el mismo nombre.
        $this->get(route('rutas.pedidos.index', ['estado' => EstadoPedidoSala::Listo->value]))->assertOk()
            ->assertSee('Súper Ficticio Centro')->assertSee('Tienda Inventada Norte')
            ->assertSee('>Listo para salir</option>', false)->assertDontSee('Listo en fábrica');
        // «Llegó de pedidos»: el resto que espera, con el mismo nombre; el que no salió nada, no está.
        $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSeeInOrder(['Tienda Inventada Norte', 'Listo para salir', 'Canilla de prueba', '6', 'Va en otro envío', '6'])
            ->assertDontSee('Súper Ficticio Centro')->assertDontSee('Listo en fábrica');

        // «Viajes»: los dos en fábrica, «Listo para salir».
        $this->viajes()->assertSeeInOrder(['Súper Ficticio Centro', 'Listo para salir'])
            ->assertSeeInOrder(['Tienda Inventada Norte', 'Listo para salir'])->assertDontSee('Listo en fábrica');

        // El vendedor: ya está en despacho.
        $this->actingAs($this->vendedor)->get(route('rutas.sacar-pedido.index'))->assertOk()
            ->assertSee('Ya está en despacho')->assertDontSee('Listo en fábrica');

        // El correo de las 18:00: «Listos para salir», con lo que va en cada caja.
        [$cuerpo, $pdf] = $this->correo();
        $this->assertMatchesRegularExpression('/Listos para salir.*Súper Ficticio Centro: pedido N\.º '.$pedido->id.'/s', $cuerpo);
        $this->assertMatchesRegularExpression('/Listos para salir.*Tienda Inventada Norte: pedido N\.º '.$resto->id.'/s', $cuerpo);
        $this->assertStringContainsString('Pedido N.º '.$pedido->id.' del 09/11/2026: Galleta de prueba 10, Canilla de prueba 5', $pdf);
        $this->assertStringContainsString('Pedido N.º '.$resto->id.' del 09/11/2026: Canilla de prueba 6', $pdf);
        foreach (['Listo en fábrica', 'Armados', 'esperando salir'] as $viejo) {
            $this->assertStringNotContainsString($viejo, $cuerpo);
            $this->assertStringNotContainsString($viejo, $pdf);
        }
        $this->assertStringNotContainsString('Por mandar:', $cuerpo);
    }

    // ══════════════════════════════ 2. la oficina corrige una cantidad

    public function test_si_la_oficina_sube_una_cantidad_sale_de_listos_y_la_caja_sigue_con_lo_que_tenia(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '5']);
        $this->tocarListo($pedido, $this->abrirHoja($pedido))->assertSessionHasNoErrors();

        $this->oficinaCorrige($pedido, [$this->galleta->id => '12'])->assertSessionHasNoErrors()
            ->assertRedirect(route('rutas.pedidos.show', $pedido));

        // Deja de estar lista; lo que hay en la caja no cambia: faltan 2 galletas.
        $pedido->refresh();
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->estado);
        $this->assertNotNull($pedido->armando_desde);
        $this->assertSame('10.0000', $this->vaBorrador($pedido, $this->galleta));
        $this->assertSame('5.0000', $this->vaBorrador($pedido, $this->canilla));
        $correccion = Activity::query()->where('subject_id', $pedido->id)->where('description', PedidosOficina::AUDITORIA_CORRIGE)->sole();
        $this->assertSame(EstadoPedidoSala::Listo->value, $correccion->properties['estado_anterior']);
        $this->assertSame(EstadoPedidoSala::Registrado->value, $correccion->properties['estado_nuevo']);

        // La oficina ya no lo ve listo.
        $this->actingAs($this->oficina)->get(route('rutas.pedidos.show', $pedido))->assertOk()
            ->assertSee('Pendiente')->assertDontSee('Listo para salir');
        $this->viajes()->assertSeeInOrder(['Súper Ficticio Centro', 'En armado'])->assertDontSee('Listo para salir');

        // La fábrica: fuera de «Listos para salir», en «De hoy» como «Armando»; la orden para la
        // caja muestra lo que hay (10) y lo que falta (2).
        $html = $this->listaFabrica();
        $this->assertStringNotContainsString('pedido-'.$pedido->id, (string) $this->seccion($html, 'Listos para salir'));
        $hoy = (string) $this->seccion($html, 'De hoy');
        $this->assertStringContainsString('pedido-'.$pedido->id, $hoy);
        $this->assertStringContainsString('Armando', $hoy);
        $this->actingAs($this->fabrica)->get(route('fabrica.pedidos.caja', $pedido))->assertOk()
            ->assertSeeInOrder(['Galleta de prueba', '10', '2', 'Canilla de prueba', '5', '0']);

        // Si la vuelven a marcar sin tocar nada, la caja sale como la ven: 10 galletas, faltan 2.
        $this->tocarListo($pedido, $this->abrirHoja($pedido))->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::Listo, $pedido->refresh()->estado);
        $this->assertSame('10.0000', $this->vaBorrador($pedido, $this->galleta));
        $this->assertSame(1, $this->cuantas($pedido, 'empezó a armar el pedido'));
        [, $pdf] = $this->correo();
        $this->assertStringContainsString('Pedido N.º '.$pedido->id.' del 09/11/2026: Galleta de prueba 10, Canilla de prueba 5', $pdf);
    }

    public function test_si_la_oficina_baja_una_cantidad_la_caja_baja_con_ella_y_sale_de_listos(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '5']);
        $this->tocarListo($pedido, $this->abrirHoja($pedido))->assertSessionHasNoErrors();

        $this->oficinaCorrige($pedido, [$this->galleta->id => '8'])->assertSessionHasNoErrors();

        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->refresh()->estado);
        // No queda en la caja más de lo que se pidió.
        $this->assertSame('8.0000', $this->vaBorrador($pedido, $this->galleta));
        $this->actingAs($this->fabrica)->get(route('fabrica.pedidos.caja', $pedido))->assertOk()
            ->assertSeeInOrder(['Galleta de prueba', '8', '0', 'Canilla de prueba', '5', '0']);
    }

    public function test_si_la_oficina_corrige_el_resto_listo_vuelve_a_mandado_en_parte(): void
    {
        $resto = $this->pedido([$this->galleta->id => '4', $this->canilla->id => '6'], $this->otraSala);
        $this->mandar($resto, [$this->galleta->id => '4', $this->canilla->id => '0'], sigue: true)->assertSessionHasNoErrors();
        $this->tocarListo($resto, $this->abrirHoja($resto))->assertSessionHasNoErrors();

        $this->oficinaCorrige($resto, [$this->canilla->id => '8'])->assertSessionHasNoErrors();

        $this->assertSame(EstadoPedidoSala::Parcial, $resto->refresh()->estado);
        $this->assertSame('6.0000', $this->vaBorrador($resto, $this->canilla));
        $this->actingAs($this->oficina)->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSeeInOrder(['Tienda Inventada Norte', 'Mandado en parte', 'Canilla de prueba', '8', 'Va en otro envío', '8'])
            ->assertDontSee('Listo para salir');
    }

    // ══════════════════════════════ 3. doble toque

    public function test_el_doble_toque_de_listo_y_de_quitar_no_repite_nada_ni_cambia_la_caja(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '5']);
        $version = $this->abrirHoja($pedido);

        // El mismo formulario dos veces seguidas.
        $this->tocarListo($pedido, $version)->assertSessionHasNoErrors()->assertSessionHas('deshacer', ['tipo' => 'listo', 'pedido' => $pedido->id]);
        $this->tocarListo($pedido, $version)->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'El pedido de Súper Ficticio Centro ya estaba en «Listos para salir».')
            ->assertSessionMissing('deshacer');

        $this->assertSame(EstadoPedidoSala::Listo, $pedido->refresh()->estado);
        $this->assertSame(1, $this->cuantas($pedido, PedidosFabrica::AUDITORIA_LISTO));
        $this->assertSame(1, $this->cuantas($pedido, 'empezó a armar el pedido'));
        $this->assertSame('10.0000', $this->vaBorrador($pedido, $this->galleta));
        $this->assertSame('5.0000', $this->vaBorrador($pedido, $this->canilla));

        $this->quitarDeListos($pedido)->assertSessionHasNoErrors();
        $this->quitarDeListos($pedido)->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'El pedido de Súper Ficticio Centro ya no estaba en «Listos para salir».');
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->refresh()->estado);
        $this->assertSame(1, $this->cuantas($pedido, PedidosFabrica::AUDITORIA_NO_LISTO));
        $this->assertSame('10.0000', $this->vaBorrador($pedido, $this->galleta));
    }

    // ══════════════════════════════ 4. quitar de listos

    public function test_quitar_de_listos_no_toca_la_caja_y_la_oficina_lo_ve_otra_vez_pendiente(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '5']);
        $resto = $this->pedido([$this->galleta->id => '4', $this->canilla->id => '6'], $this->otraSala);
        $this->mandar($resto, [$this->galleta->id => '4', $this->canilla->id => '0'], sigue: true)->assertSessionHasNoErrors();
        foreach ([$pedido, $resto] as $p) {
            $this->tocarListo($p, $this->abrirHoja($p))->assertSessionHasNoErrors();
        }

        // Desde la hoja: se queda en la hoja para seguir armando.
        foreach ([$pedido, $resto] as $p) {
            $this->quitarDeListos($p)->assertSessionHasNoErrors()->assertRedirect(route('fabrica.pedidos.show', $p));
        }

        // Vuelve a como estaba según sus envíos; lo que hay en la caja queda anotado y sigue «Armando».
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->refresh()->estado);
        $this->assertSame(EstadoPedidoSala::Parcial, $resto->refresh()->estado);
        $this->assertNotNull($pedido->armando_desde);
        $this->assertSame('10.0000', $this->vaBorrador($pedido, $this->galleta));
        $this->assertSame('6.0000', $this->vaBorrador($resto, $this->canilla));
        $this->assertFalse($this->vendedor->can('corregir', $pedido));

        // La fábrica: sin grupo de listos; el que no salió nada, abajo como «Armando».
        $html = $this->listaFabrica();
        $this->assertNull($this->seccion($html, 'Listos para salir'));
        $hoy = (string) $this->seccion($html, 'De hoy');
        $this->assertStringContainsString('pedido-'.$pedido->id, $hoy);
        $this->assertStringContainsString('Armando', $hoy);

        // La oficina y «Viajes» ya no lo ven listo.
        $this->actingAs($this->oficina);
        $this->get(route('rutas.pedidos.show', $pedido))->assertOk()->assertSee('Pendiente')->assertDontSee('Listo para salir');
        $this->get(route('rutas.pedidos.hoja', $resto))->assertOk()->assertSee('Mandado en parte')->assertDontSee('Listo para salir');
        $this->get(route('facturacion.llego-de-fabrica'))->assertOk()
            ->assertSeeInOrder(['Tienda Inventada Norte', 'Mandado en parte'])->assertDontSee('Listo para salir');
        $this->viajes()->assertSeeInOrder(['Súper Ficticio Centro', 'En armado'])
            ->assertSeeInOrder(['Tienda Inventada Norte', 'Mandado en parte'])->assertDontSee('Listo para salir');

        // El correo: los dos en lo que sigue por mandar, ninguno en «Listos para salir».
        [$cuerpo, $pdf] = $this->correo();
        $this->assertStringNotContainsString('Listos para salir', $cuerpo);
        $this->assertStringNotContainsString('Listos para salir', $pdf);
        $this->assertStringContainsString('Por mandar: 2 pedidos', $cuerpo);
    }
}
