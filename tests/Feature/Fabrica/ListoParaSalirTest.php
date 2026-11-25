<?php

namespace Tests\Feature\Fabrica;

use App\Enums\EstadoPedidoSala;
use App\Enums\OrigenPedidoSala;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\PedidoSala;
use App\Models\PedidoSalaEnvio;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\PedidosFabrica;
use App\Services\Rutas\PedidosOficina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * La caja armada que todavía no sale (Melqui, 24/11/2026): el papel «Orden para la caja»,
 * que sale de lo marcado sin mandar nada, y «Listo para salir», que ellas marcan cuando la
 * caja quedó como va a salir. Cada tarde, la persona que se regresa de la fábrica se lleva
 * los listos. Todos los datos son inventados.
 */
class ListoParaSalirTest extends TestCase
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

        $this->fabrica = User::factory()->create(['activo' => true, 'name' => 'Pedidos Inventada'])->assignRole('fabrica');
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

    private function version(PedidoSala $pedido): string
    {
        return PedidoSala::query()->with(app(PedidosFabrica::class)->relaciones())->findOrFail($pedido->id)->versionProduccion();
    }

    /** @param  array<int, string>  $cantidades  [producto_id => cantidad] */
    private function mandar(PedidoSala $pedido, array $cantidades, array $extra = [], ?string $version = null): TestResponse
    {
        $porLinea = [];
        foreach ($cantidades as $producto => $cantidad) {
            $porLinea[$this->linea($pedido, Producto::findOrFail($producto))] = $cantidad;
        }

        return $this->post(route('fabrica.pedidos.mandar', $pedido), $extra + [
            'clave' => (string) Str::uuid(), 'version' => $version ?? $this->version($pedido), 'cantidades' => $porLinea,
        ]);
    }

    // ══════════════════════════════ 1 y 2. «Imprimir para la caja»: la «Orden para la caja»

    public function test_la_orden_para_la_caja_sale_de_lo_marcado_y_no_manda_nada(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '5'], extra: ['notas' => 'Cambiar dos por avería']);
        $g = $this->linea($pedido, $this->galleta);
        $c = $this->linea($pedido, $this->canilla);
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$g => '7', $c => '0'], 'motivos' => [$c => 'No hay canillas']])->assertOk();
        $antes = Activity::count();

        // Se puede sacar las veces que haga falta.
        foreach ([1, 2] as $vez) {
            $html = $this->get(route('fabrica.pedidos.caja', $pedido))->assertOk()
                ->assertSee('<title>Orden para la caja · Súper Ficticio Centro</title>', false)
                ->assertSeeInOrder(['ORDEN PARA LA CAJA', 'No es factura · Sin valor tributario', 'Pedido N.º', (string) $pedido->id])
                ->assertSee('Todavía no salió')->assertDontSee('HOJA DE DESPACHO')
                ->assertSee('Fecha del pedido')->assertSee('09/11/2026')
                ->assertSee('Súper Ficticio Centro')->assertSee('Calle de Prueba 123')->assertSee('Ruta Inventada')
                ->assertSee('Cambiar dos por avería')
                ->assertSeeInOrder(['Va', 'Falta', 'G01', 'Galleta de prueba', '7', '3', 'C01', 'Canilla de prueba', '0', '5', 'No hay canillas'])
                ->assertSee('window.print()', false)
                ->assertDontSee('7.77')->assertDontSee('fábrica')
                ->getContent();
            $this->assertStringContainsString('class="v num-grande">'.$pedido->id.'<', $html, 'el N.º de pedido va grande');
            // La barra «Volver al pedido» / «Imprimir» se ve en pantalla y no sale en el papel.
            $this->assertMatchesRegularExpression('/<div class="no-print">\s*<a href="'.preg_quote(route('fabrica.pedidos.show', $pedido), '/').'">Volver al pedido<\/a>/', $html);
            $this->assertMatchesRegularExpression('/@media print \{(?:(?!@media).)*\.no-print \{ display: none; \}/s', $html);
        }

        // No registra envío, no cambia el estado, no llega a facturación ni deja auditoría.
        $this->assertSame(0, PedidoSalaEnvio::count());
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->refresh()->estado);
        $this->assertSame(0, PedidoSalaEnvio::query()->porFacturar()->count());
        $this->assertSame($antes, Activity::count());
    }

    public function test_sin_marcar_nada_la_orden_lleva_todo_lo_que_falta(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '5']);
        $this->mandar($pedido, [$this->galleta->id => '4', $this->canilla->id => '5'], ['sigue' => '1'])->assertSessionHasNoErrors();

        // Lo que ya salió no va en esta caja: solo lo que falta, todo como va.
        $this->get(route('fabrica.pedidos.caja', $pedido))->assertOk()
            ->assertSee('Ya salió una parte de este pedido')
            ->assertSeeInOrder(['Galleta de prueba', '6'])
            ->assertDontSee('Canilla de prueba');
        $this->assertNull($pedido->lineas()->whereKey($this->linea($pedido, $this->galleta))->value('va_borrador'));
    }

    public function test_la_hoja_tiene_imprimir_para_la_caja_que_guarda_antes_de_salir(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);

        $html = $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()->getContent();

        // Blanco, junto a «Dejar para después». Guarda la hoja SIEMPRE antes de salir, aunque no
        // se haya tocado nada (sucio = true): con el papel pegado en la caja, el pedido queda
        // «Armando» y el vendedor ya no lo cambia.
        $this->assertMatchesRegularExpression(
            '/Dejar para después<\/a>\s*<a href="'.preg_quote(route('fabrica.pedidos.caja', $pedido), '/').'" @click\.prevent="sucio = true; salir\(\$el\.href\)"\s+class="[^"]*bg-white[^"]*">Imprimir para la caja<\/a>/',
            $html,
        );

        // Ese guardado es la hoja tal como está (todo lo que falta) y desde ahí el vendedor no corrige.
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$this->linea($pedido, $this->galleta) => '10']])->assertOk();
        $this->assertTrue($pedido->refresh()->fabricaEmpezo());
        $this->assertFalse($this->vendedor->can('corregir', $pedido));
        $this->get(route('fabrica.pedidos.caja', $pedido))->assertOk()->assertSeeInOrder(['Galleta de prueba', '10', '0']);
    }

    public function test_la_orden_para_la_caja_es_solo_de_pedidos_por_mandar_y_de_quien_arma(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);

        // El vendedor no la ve; sin sesión, al login.
        $this->actingAs($this->vendedor)->get(route('fabrica.pedidos.caja', $pedido))->assertForbidden();
        auth()->logout();
        $this->get(route('fabrica.pedidos.caja', $pedido))->assertRedirect(route('login'));

        // Ya resuelto: a la lista con el aviso, como la hoja.
        $this->actingAs($this->fabrica);
        $this->mandar($pedido, [$this->galleta->id => '10'])->assertSessionHasNoErrors();
        $this->get(route('fabrica.pedidos.caja', $pedido))
            ->assertRedirect(route('fabrica.pedidos.index'))->assertSessionHas('status', 'Ese pedido ya está resuelto.');

        // El pedido web tiene su propio papel.
        $web = $this->pedido([$this->galleta->id => '1'], extra: ['origen' => OrigenPedidoSala::Web]);
        $this->get(route('fabrica.pedidos.caja', $web))->assertNotFound();
    }

    // ══════════════════════════════ 3. «Listo para salir»

    /** Marcarlo lleva la versión de la hoja (recién abierta, salvo que se diga otra); quitarlo, no. */
    private function listo(PedidoSala $pedido, bool $listo = true, ?string $version = null): TestResponse
    {
        return $this->patch(route('fabrica.pedidos.listo', $pedido), $listo
            ? ['listo' => '1', 'version' => $version ?? $this->version($pedido)]
            : ['listo' => '0']);
    }

    /** @return list<array{descripcion: string, quien: ?int, cuando: string}> */
    private function auditoriaListo(PedidoSala $pedido): array
    {
        return Activity::query()->where('log_name', 'pedido_sala')->where('subject_id', $pedido->id)
            ->whereIn('description', [PedidosFabrica::AUDITORIA_LISTO, PedidosFabrica::AUDITORIA_NO_LISTO])
            ->orderBy('id')->get()
            ->map(fn (Activity $a) => ['descripcion' => $a->description, 'quien' => $a->causer_id, 'cuando' => $a->created_at->format('Y-m-d H:i')])
            ->all();
    }

    public function test_listo_para_salir_queda_con_quien_y_cuando_y_se_quita_con_otro_toque(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);

        $this->listo($pedido)->assertRedirect(route('fabrica.pedidos.index'))
            ->assertSessionHas('status', 'El pedido de Súper Ficticio Centro quedó en «Listos para salir».')
            ->assertSessionHas('deshacer', ['tipo' => 'listo', 'pedido' => $pedido->id]);
        $this->assertSame(EstadoPedidoSala::Listo, $pedido->refresh()->estado);
        $this->assertTrue($pedido->estaPorMandar(), 'listo sigue por mandar: todavía no salió');

        // En la lista, el aviso trae «Deshacer» (sin confirmar), que lo quita.
        $this->get(route('fabrica.pedidos.index'))->assertOk()
            ->assertSee('action="'.route('fabrica.pedidos.listo', $pedido).'"', false)
            ->assertSee('name="listo" value="0"', false)->assertSee('Deshacer</button>', false);

        // «Deshacer» desde la lista: se queda en la lista.
        $this->travelTo('2026-11-09 11:30:00');
        $this->listo($pedido, false)->assertRedirect(route('fabrica.pedidos.index'))
            ->assertSessionHas('status', 'El pedido de Súper Ficticio Centro ya no está en «Listos para salir».');
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->refresh()->estado);

        // «Quitar de listos» desde la hoja: se queda en la hoja, para seguir armando.
        $this->travelTo('2026-11-09 12:00:00');
        $this->listo($pedido);
        $this->get(route('fabrica.pedidos.show', $pedido))->assertOk();
        $this->listo($pedido, false)->assertRedirect(route('fabrica.pedidos.show', $pedido));

        $this->assertSame([
            ['descripcion' => PedidosFabrica::AUDITORIA_LISTO, 'quien' => $this->fabrica->id, 'cuando' => '2026-11-09 09:00'],
            ['descripcion' => PedidosFabrica::AUDITORIA_NO_LISTO, 'quien' => $this->fabrica->id, 'cuando' => '2026-11-09 11:30'],
            ['descripcion' => PedidosFabrica::AUDITORIA_LISTO, 'quien' => $this->fabrica->id, 'cuando' => '2026-11-09 12:00'],
            ['descripcion' => PedidosFabrica::AUDITORIA_NO_LISTO, 'quien' => $this->fabrica->id, 'cuando' => '2026-11-09 12:00'],
        ], $this->auditoriaListo($pedido));
    }

    public function test_el_resto_de_un_pedido_mandado_en_parte_tambien_se_marca_listo_y_vuelve_a_en_parte(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '5']);
        $this->mandar($pedido, [$this->galleta->id => '10', $this->canilla->id => '0'], ['sigue' => '1'])->assertSessionHasNoErrors();

        $this->listo($pedido)->assertSessionHasNoErrors();
        $pedido->refresh();
        $this->assertSame(EstadoPedidoSala::Listo, $pedido->estado);
        $this->assertTrue($pedido->enParte(), 'sigue siendo el resto de un envío anterior');

        $this->listo($pedido, false)->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::Parcial, $pedido->refresh()->estado);
    }

    public function test_el_doble_toque_no_repite_nada(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);

        $this->listo($pedido);
        $this->listo($pedido)->assertSessionHas('status', 'El pedido de Súper Ficticio Centro ya estaba en «Listos para salir».');
        $this->assertCount(1, $this->auditoriaListo($pedido));

        $this->listo($pedido, false);
        $this->listo($pedido, false)->assertSessionHas('status', 'El pedido de Súper Ficticio Centro ya no estaba en «Listos para salir».');
        $this->assertCount(2, $this->auditoriaListo($pedido));
    }

    public function test_listo_con_la_hoja_vieja_no_pasa_si_el_vendedor_corrigio_el_pedido(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);
        // Abren la hoja y arman la caja sin tocar nada: no se guarda nada y el vendedor todavía
        // puede corregir. El formulario de «Listo para salir» lleva la versión de la hoja.
        $vieja = $this->version($pedido);
        $html = $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/x-ref="listo"[^>]*>(?:(?!<\/form>).)*name="version" value="'.$vieja.'"/s', $html);

        // Mientras arman, el vendedor sube las galletas de 10 a 20.
        $pedido->lineas()->update(['cantidad' => '20']);

        $this->listo($pedido, version: $vieja)->assertSessionHasErrors(['pedido' => PedidosFabrica::CAMBIO]);
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->refresh()->estado);
        $this->assertSame([], $this->auditoriaListo($pedido));
        // Tampoco guarda la caja de la hoja vieja: nada queda marcado ni «Armando».
        $this->assertSame(0, $pedido->lineas()->whereNotNull('va_borrador')->count());
        $this->assertNull($pedido->armando_desde);
        // Sin la versión, tampoco: la pantalla quedó vieja.
        $this->patch(route('fabrica.pedidos.listo', $pedido), ['listo' => '1'])->assertSessionHasErrors('version');

        // Con la hoja abierta de nuevo (ya dice 20), sí, y la caja queda con las 20.
        $this->listo($pedido)->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::Listo, $pedido->refresh()->estado);
        $this->assertSame('20.0000', (string) $pedido->lineas()->value('va_borrador'));
    }

    public function test_listo_con_la_hoja_vieja_no_pasa_si_otra_tableta_mando_una_parte(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '5']);
        $vieja = $this->version($pedido);
        // Otra tableta manda las galletas; lo que falta va en otro envío.
        $this->mandar($pedido, [$this->galleta->id => '10', $this->canilla->id => '0'], ['sigue' => '1'])->assertSessionHasNoErrors();

        $this->listo($pedido, version: $vieja)->assertSessionHasErrors(['pedido' => PedidosFabrica::CAMBIO]);
        $this->assertSame(EstadoPedidoSala::Parcial, $pedido->refresh()->estado);
    }

    public function test_no_se_marca_listo_con_la_caja_vacia_y_si_se_vacia_deja_de_estar_listo(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '5']);
        $g = $this->linea($pedido, $this->galleta);
        $c = $this->linea($pedido, $this->canilla);
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$g => '0', $c => '0'], 'motivos' => [$c => 'No hay']])->assertOk();

        // No va nada: no hay caja que llevarse. Para eso está «No mandar nada de este pedido».
        $this->listo($pedido)->assertSessionHasErrors(['pedido' => PedidosFabrica::CAJA_VACIA]);
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->refresh()->estado);
        $this->assertSame([], $this->auditoriaListo($pedido));

        // Lista con las galletas; si después ponen todo en 0, ya no queda una caja esperando.
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$g => '10', $c => '0']])->assertOk();
        $this->listo($pedido)->assertSessionHasNoErrors();
        $this->travelTo('2026-11-09 10:00:00');
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$g => '0', $c => '0']])->assertOk();
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->refresh()->estado);
        $this->assertSame([
            ['descripcion' => PedidosFabrica::AUDITORIA_LISTO, 'quien' => $this->fabrica->id, 'cuando' => '2026-11-09 09:00'],
            ['descripcion' => PedidosFabrica::AUDITORIA_NO_LISTO, 'quien' => $this->fabrica->id, 'cuando' => '2026-11-09 10:00'],
        ], $this->auditoriaListo($pedido));
    }

    public function test_cambiar_lo_marcado_no_lo_saca_de_listos_pero_borrar_lo_marcado_si(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '5']);
        $c = $this->linea($pedido, $this->canilla);
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$c => '5']])->assertOk();
        $this->listo($pedido);

        // Ajustar la caja (por ejemplo, ya no hay canillas) la deja lista.
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$c => '0'], 'motivos' => [$c => 'No hay']])->assertOk();
        $this->assertSame(EstadoPedidoSala::Listo, $pedido->refresh()->estado);
        $this->get(route('fabrica.pedidos.caja', $pedido))->assertOk()->assertSeeInOrder(['Canilla de prueba', '0', '5', 'No hay']);

        // «Borrar lo marcado» vuelve a cargar todo lo que falta: la caja se revisa de nuevo.
        $this->patch(route('fabrica.pedidos.borrador.borrar', $pedido))->assertSessionHas('status', 'Se borró lo marcado.');
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->refresh()->estado);
    }

    public function test_imprimir_y_mandar_un_pedido_listo_funciona_igual_que_hoy(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);
        // Otra pantalla abierta desde antes de marcarlo: listo no la deja vieja.
        $version = $this->version($pedido);
        $this->listo($pedido);

        $this->mandar($pedido, [$this->galleta->id => '10'], version: $version)->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Salió el pedido de Súper Ficticio Centro.');
        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);
        $this->assertSame(1, PedidoSalaEnvio::query()->porFacturar()->count());
    }

    public function test_mandar_un_listo_con_lo_que_falta_en_otro_envio_lo_saca_de_listos(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '5']);
        $this->listo($pedido);

        $this->mandar($pedido, [$this->galleta->id => '10', $this->canilla->id => '0'], ['sigue' => '1'])->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Salió una parte de Súper Ficticio Centro. Lo que falta sigue pendiente.');
        $this->assertSame(EstadoPedidoSala::Parcial, $pedido->refresh()->estado);
    }

    public function test_no_mandar_nada_de_un_listo_lo_cierra_como_siempre(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);
        $this->listo($pedido);

        $this->patch(route('fabrica.pedidos.cerrar', $pedido), ['version' => $this->version($pedido), 'motivo' => 'El cliente canceló'])
            ->assertSessionHas('status', 'No se manda nada del pedido de Súper Ficticio Centro.');
        $this->assertSame(EstadoPedidoSala::NoSeTrae, $pedido->refresh()->estado);
    }

    public function test_corregir_el_envio_de_un_resto_listo_deja_marcado_que_lo_que_falta_va_en_otro_envio(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '5']);
        $this->mandar($pedido, [$this->galleta->id => '10', $this->canilla->id => '0'], ['sigue' => '1'])->assertSessionHasNoErrors();
        $this->listo($pedido);

        $this->patch(route('fabrica.pedidos.corregir-envio', [$pedido, PedidoSalaEnvio::sole()]))->assertSessionHasNoErrors();

        $pedido->refresh();
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->estado);
        $this->assertTrue($pedido->armando_sigue);
    }

    public function test_listo_es_de_quien_arma_y_solo_con_pedidos_por_mandar(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);

        // El vendedor no puede marcarlo; sin sesión, al login.
        $this->actingAs($this->vendedor);
        $this->listo($pedido)->assertForbidden();
        auth()->logout();
        $this->listo($pedido)->assertRedirect(route('login'));
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->refresh()->estado);

        $this->actingAs($this->fabrica);
        // Sin decir qué: no cambia nada.
        $this->patch(route('fabrica.pedidos.listo', $pedido), [])->assertSessionHasErrors('listo');

        // Ya mandado o web: no.
        $this->mandar($pedido, [$this->galleta->id => '10'])->assertSessionHasNoErrors();
        $this->listo($pedido)->assertSessionHasErrors(['pedido' => PedidosFabrica::CERRADO]);
        $web = $this->pedido([$this->galleta->id => '1'], extra: ['origen' => OrigenPedidoSala::Web]);
        $this->listo($web)->assertSessionHasErrors('pedido');
        $this->assertSame(EstadoPedidoSala::Registrado, $web->refresh()->estado);
        $this->assertSame([], $this->auditoriaListo($pedido));
    }

    /**
     * Melqui, 25/11/2026: «Listo para salir» guarda SIEMPRE lo que hay en la caja, aunque no
     * hayan tocado nada en la hoja, como «Imprimir para la caja». Así queda anotado qué va en
     * la caja y una corrección posterior de la oficina se compara contra eso.
     */
    public function test_listo_para_salir_guarda_lo_que_hay_en_la_caja_aunque_no_hayan_tocado_nada(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '5']);
        $g = $this->linea($pedido, $this->galleta);
        $c = $this->linea($pedido, $this->canilla);

        // La hoja recién abierta, sin tocar nada: la caja lleva todo lo que falta.
        $this->listo($pedido)->assertSessionHasNoErrors();

        $pedido->refresh();
        $this->assertSame(EstadoPedidoSala::Listo, $pedido->estado);
        $this->assertSame('10.0000', (string) $pedido->lineas()->whereKey($g)->value('va_borrador'));
        $this->assertSame('5.0000', (string) $pedido->lineas()->whereKey($c)->value('va_borrador'));
        // Queda «Armando», con quién, como el primer guardado de la hoja (una sola vez).
        $this->assertNotNull($pedido->armando_desde);
        $this->assertSame($this->fabrica->id, $pedido->armando_por);
        $this->assertSame(1, Activity::where('subject_id', $pedido->id)->where('description', 'empezó a armar el pedido')->count());

        // La oficina sube las galletas a 12: la caja deja de estar lista y la hoja compara contra
        // lo que hay en la caja (10), no contra el pedido nuevo.
        $oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        app(PedidosOficina::class)->corregir($pedido, [
            'version' => $this->version($pedido), 'motivo' => 'El vendedor se equivocó.', 'cantidades' => [$g => '12'],
        ], $oficina);
        $pedido->refresh();
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->estado);
        $galleta = $pedido->lineas()->findOrFail($g);
        $this->assertSame('10.0000', $galleta->vaEnLaCaja());
        $this->assertSame('2.0000', $galleta->faltaEnLaCaja());
        $this->get(route('fabrica.pedidos.caja', $pedido))->assertOk()
            ->assertSeeInOrder(['Galleta de prueba', '10', '2', 'Canilla de prueba', '5']);
    }

    public function test_listo_para_salir_no_pisa_lo_que_ya_marcaron(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '5']);
        $g = $this->linea($pedido, $this->galleta);
        $c = $this->linea($pedido, $this->canilla);
        // Tocaron solo las canillas (no hay); las galletas, como venían.
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), ['cantidades' => [$c => '0'], 'motivos' => [$c => 'No hay canillas']])->assertOk();

        $this->listo($pedido)->assertSessionHasNoErrors();

        $this->assertSame('10.0000', (string) $pedido->lineas()->whereKey($g)->value('va_borrador'));
        $canilla = $pedido->lineas()->findOrFail($c);
        $this->assertSame('0.0000', (string) $canilla->va_borrador);
        $this->assertSame('No hay canillas', $canilla->motivo_borrador);
        $this->assertSame(1, Activity::where('subject_id', $pedido->id)->where('description', 'empezó a armar el pedido')->count());
    }

    public function test_el_vendedor_ya_no_corrige_un_pedido_listo(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);
        $this->listo($pedido);

        $this->assertTrue($pedido->refresh()->fabricaEmpezo());
        $this->assertFalse($this->vendedor->can('corregir', $pedido));
    }

    public function test_la_hoja_tiene_listo_para_salir_que_guarda_antes_y_luego_quitar_de_listos(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);

        $html = $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()->getContent();
        // Blanco; primero termina de guardar lo marcado y después manda su propio formulario.
        $this->assertMatchesRegularExpression('/<button type="button" @click="salir\(\(\) => \$refs\.listo\.submit\(\)\)"[^>]*class="[^"]*bg-white[^"]*">\s*Listo para salir\s*<\/button>/', $html);
        $this->assertMatchesRegularExpression('/<form method="POST" action="'.preg_quote(route('fabrica.pedidos.listo', $pedido), '/').'" x-ref="listo"[^>]*>.*?name="listo" value="1"/s', $html);

        $this->listo($pedido);
        $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()
            ->assertSee('Listo para salir</span>', false)
            ->assertSee('Quitar de listos')->assertSee('name="listo" value="0"', false);
    }

    /**
     * En el celular, la barra fija de abajo apilaba cuatro botones y tapaba casi media pantalla.
     * Ahí lleva solo «Dejar para después» e «Imprimir y mandar», como antes; los dos nuevos van
     * arriba de la barra, sin fijar, con la misma acción. Desde la tableta (sm), en la barra.
     */
    public function test_en_el_celular_la_barra_fija_de_la_hoja_lleva_solo_dos_botones(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);
        $html = $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()->getContent();
        $inicio = strpos($html, '<div class="sticky bottom-0');
        $this->assertNotFalse($inicio);
        $barra = substr($html, $inicio, strpos($html, '</form>', $inicio) - $inicio);
        $arriba = substr($html, 0, $inicio);

        // Las etiquetas <a>/<button> con su texto (los atributos pueden llevar «=>» de Alpine).
        $botones = function (string $trozo): array {
            preg_match_all('/<(?:a|button)\b((?:[^>"]|"[^"]*")*)>\s*(Imprimir para la caja|Listo para salir)\s*</', $trozo, $m, PREG_SET_ORDER);

            return array_map(fn ($b) => ['texto' => $b[2], 'clases' => preg_match('/class="([^"]*)"/', $b[1], $c) ? $c[1] : ''], $m);
        };

        $enLaBarra = $botones($barra);
        $this->assertSame(['Imprimir para la caja', 'Listo para salir'], array_column($enLaBarra, 'texto'));
        foreach ($enLaBarra as $boton) {
            $this->assertStringContainsString('hidden sm:inline-flex', $boton['clases'], $boton['texto'].' no va en la barra fija del celular');
        }
        $this->assertStringNotContainsString('hidden', implode(' ', $this->clasesDe($barra, 'Dejar para después')));

        // En el celular, arriba de la barra y sin fijar.
        $this->assertSame(1, preg_match('/<div class="[^"]*\bsm:hidden\b[^"]*">((?:(?!<\/div>).)*)<\/div>/s', $arriba, $celular));
        $this->assertSame(['Imprimir para la caja', 'Listo para salir'], array_column($botones($celular[1]), 'texto'));
        $this->assertStringContainsString('@click.prevent="sucio = true; salir($el.href)"', $celular[1]);
        $this->assertStringContainsString('@click="salir(() => $refs.listo.submit())"', $celular[1]);
    }

    /** @return list<string> las clases de la etiqueta con ese texto */
    private function clasesDe(string $html, string $texto): array
    {
        $this->assertSame(1, preg_match('/<(?:a|button)\b((?:[^>"]|"[^"]*")*)>\s*'.preg_quote($texto, '/').'\s*</', $html, $m), 'Falta «'.$texto.'».');

        return preg_match('/class="([^"]*)"/', $m[1], $c) ? explode(' ', $c[1]) : [];
    }

    // ══════════════════════════════ 4. la lista: «Listos para salir» arriba

    /** Lo que hay dentro de la sección con ese título (aria-label). */
    private function seccion(string $html, string $titulo): string
    {
        $this->assertSame(1, preg_match('/<section aria-label="'.preg_quote($titulo, '/').'"[^>]*>(.*?)<\/section>/s', $html, $m), 'Falta «'.$titulo.'».');

        return $m[1];
    }

    public function test_la_lista_pone_arriba_los_listos_para_salir_y_debajo_lo_demas_como_siempre(): void
    {
        $tercera = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Sala Armándose', 'codigo' => '9003', 'ruta_id' => $this->ruta->id]);
        $cuarta = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Sala Sin Tocar', 'codigo' => '9004', 'ruta_id' => $this->ruta->id]);
        $listoViejo = $this->pedido([$this->canilla->id => '6'], $this->otraSala, '2026-11-06');
        $listoHoy = $this->pedido([$this->galleta->id => '24']);
        $armando = $this->pedido([$this->galleta->id => '3'], $tercera);
        $pendienteViejo = $this->pedido([$this->galleta->id => '2'], $cuarta, '2026-11-07');
        $pendienteHoy = $this->pedido([$this->canilla->id => '1'], $cuarta);
        $this->patchJson(route('fabrica.pedidos.borrador', $listoHoy), ['cantidades' => [$this->linea($listoHoy, $this->galleta) => '20']])->assertOk();
        $this->patchJson(route('fabrica.pedidos.borrador', $armando), ['cantidades' => [$this->linea($armando, $this->galleta) => '1']])->assertOk();
        $this->listo($listoHoy);
        $this->listo($listoViejo);

        $html = $this->get(route('fabrica.pedidos.index'))->assertOk()
            ->assertSee('Por mandar (5)')
            ->assertSeeInOrder(['Listos para salir', 'De días anteriores', 'De hoy'])
            ->getContent();

        $listos = $this->seccion($html, 'Listos para salir');
        // Del más viejo al más nuevo, con la misma tarjeta; el de otro día dice de cuándo es.
        $this->assertTrue(strpos($listos, 'pedido-'.$listoViejo->id) < strpos($listos, 'pedido-'.$listoHoy->id));
        $this->assertStringContainsString('hace 3 días', $listos);
        $this->assertStringNotContainsString('pedido-'.$armando->id, $listos);
        $this->assertStringNotContainsString('pedido-'.$pendienteViejo->id, $listos);
        // Ya no dice «Armando» y sin colores llamativos (Melqui sacó el amarillo el 17/11/2026).
        $this->assertStringNotContainsString('Armando', $listos);
        foreach (['amber', 'yellow', 'green', 'red'] as $color) {
            $this->assertStringNotContainsString('bg-'.$color, $listos);
        }

        // Debajo, lo demás como siempre, sin repetir los listos.
        $viejos = $this->seccion($html, 'De días anteriores');
        $this->assertStringContainsString('pedido-'.$pendienteViejo->id, $viejos);
        $this->assertStringNotContainsString('pedido-'.$listoViejo->id, $viejos);
        $hoy = $this->seccion($html, 'De hoy');
        $this->assertStringContainsString('pedido-'.$armando->id, $hoy);
        $this->assertStringContainsString('Armando', $hoy);
        $this->assertStringContainsString('pedido-'.$pendienteHoy->id, $hoy);
        $this->assertStringNotContainsString('pedido-'.$listoHoy->id, $hoy);
    }

    public function test_el_resto_listo_de_un_pedido_mandado_en_parte_lo_dice_en_su_tarjeta(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10', $this->canilla->id => '5']);
        $this->mandar($pedido, [$this->galleta->id => '10', $this->canilla->id => '0'], ['sigue' => '1'])->assertSessionHasNoErrors();
        $this->listo($pedido);

        $listos = $this->seccion($this->get(route('fabrica.pedidos.index'))->getContent(), 'Listos para salir');
        $this->assertStringContainsString('Mandado en parte', $listos);
        $this->assertStringContainsString('1 producto', $listos);
    }

    public function test_arriba_de_la_hoja_los_listos_dicen_listo_y_no_armando(): void
    {
        $pedido = $this->pedido([$this->galleta->id => '10']);
        $listo = $this->pedido([$this->canilla->id => '6'], $this->otraSala);
        $this->patchJson(route('fabrica.pedidos.borrador', $listo), ['cantidades' => [$this->linea($listo, $this->canilla) => '6']])->assertOk();
        $this->listo($listo);

        $html = $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/aria-label="Otros pedidos"[^>]*>(.*?)<\/nav>/s', $html, $fila));
        $this->assertStringContainsString('Tienda Inventada Norte', $fila[1]);
        $this->assertStringContainsString('Listo para salir', $fila[1]);
        $this->assertStringNotContainsString('Armando', $fila[1]);
    }
}
