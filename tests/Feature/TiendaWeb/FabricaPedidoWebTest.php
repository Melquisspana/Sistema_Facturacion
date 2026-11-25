<?php

namespace Tests\Feature\TiendaWeb;

use App\Enums\EstadoDte;
use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoPedidoWeb;
use App\Enums\OrigenPedidoSala;
use App\Enums\TipoDte;
use App\Facades\Ajustes;
use App\Mail\Pedidos\ResumenDiarioPedidosCorreo;
use App\Models\Dte;
use App\Models\PedidoSala;
use App\Models\PedidoSalaEnvio;
use App\Models\PedidoWeb;
use App\Models\Producto;
use App\Models\User;
use App\Services\Alertas\Detectores\PedidosWebParaRevisar;
use App\Services\Dte\DtePdfService;
use App\Services\Rutas\PdfFabricaDiario;
use App\Services\Rutas\PedidosFabrica;
use App\Services\Rutas\ResumenDiarioPedidos;
use App\Services\TiendaWeb\InformarEstadoPedidoWeb;
use App\Services\TiendaWeb\RespuestaAvisoPedidoWeb;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

class FabricaPedidoWebTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private User $fabrica;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-16 09:00:00');
        $this->seedCatalogosDte();
        $this->fabrica = User::factory()->create(['activo' => true])->assignRole('fabrica');
        $this->actingAs($this->fabrica);
        Ajustes::guardarComoSistema('tienda.url', 'https://tienda.example.test');
        Ajustes::guardarComoSistema('tienda.secreto', str_repeat('inventado', 8));
    }

    private function pedido(array $extra = [], bool $aceptada = true): PedidoSala
    {
        $sala = PedidoSala::create(['origen' => OrigenPedidoSala::Web, 'fecha' => '2026-11-16', 'estado' => EstadoPedidoSala::Registrado]);
        $producto = Producto::factory()->create(['nombre' => 'Pepiayote inventado']);
        $sala->lineas()->create(['producto_id' => $producto->id, 'cantidad' => 12]);
        $n = PedidoWeb::count() + 1;
        $web = PedidoWeb::create($extra + [
            'pedido_sala_id' => $sala->id, 'web_id' => $n, 'codigo' => 'LN-TEST-0000-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'idioma' => 'es', 'pagado_en' => now(), 'total_centavos' => 98765, 'moneda' => 'USD',
            'cliente_nombre' => 'Compradora Inventada', 'cliente_correo' => 'compradora@example.test', 'cliente_telefono' => 'telefono-secreto',
            'direccion' => 'direccion-secreta', 'entrega_tipo' => 'recoger', 'pasarela' => 'prueba',
            'estado' => $aceptada ? EstadoPedidoWeb::Facturado : EstadoPedidoWeb::Facturando,
            'informado_preparando_en' => now(),
            'lineas' => [['producto' => 'pepi', 'nombre' => 'Pepiayote inventado', 'cantidad' => 12, 'precio' => 98765]],
            'cajitas_detalle' => [['n' => 1, 'clase' => 'surtido', 'surtido' => 'La clásica', 'tam' => 12, 'cantidad' => 2, 'contenido' => ['pepi' => 6], 'precio' => 98765]],
        ]);
        $emisor = $this->crearEmisorDte('M'.str_pad((string) $n, 3, '0', STR_PAD_LEFT));
        $dte = Dte::withoutEvents(fn () => Dte::create([
            'tipo_dte' => TipoDte::Factura, 'estado' => $aceptada ? EstadoDte::Aceptado : EstadoDte::Enviado,
            'establecimiento_id' => $emisor['estab']->id, 'fecha_emision' => '2026-11-16', 'hora_emision' => '09:00:00',
            'receptor_nombre' => 'Compradora Inventada', 'receptor_correo' => 'compradora@example.test',
        ]));
        $web->update(['dte_id' => $dte->id]);

        return $sala->load(app(PedidosFabrica::class)->relaciones());
    }

    private function datos(PedidoSala $pedido, string $cantidad = '12'): array
    {
        return ['clave' => (string) Str::uuid(), 'version' => $pedido->versionProduccion(), 'cantidades' => [$pedido->lineas->first()->id => $cantidad]];
    }

    private function mandar(PedidoSala $pedido): PedidoSalaEnvio
    {
        return app(PedidosFabrica::class)->mandar($pedido, $this->datos($pedido), $this->fabrica);
    }

    public function test_tarjeta_y_show_con_regalo_cajitas_y_sin_precios_ni_controles_parciales(): void
    {
        $pedido = $this->pedido(['regalo' => ['nombre' => 'Destinataria Ficticia', 'mensaje' => 'Un mensaje inventado']]);
        $this->get(route('fabrica.pedidos.index'))->assertOk()->assertSee('WEB')->assertSee('🎁 Regalo')->assertSee('Para recoger · 2 cajitas · 09:00');
        $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()
            ->assertSee('Pedido web · Compradora I.')->assertSee('WEB')->assertSee('🎁 Regalo')
            ->assertSee('Cajita 1 · La clásica (12) × 2')->assertSee('Pepiayote inventado')->assertSee('Factura lista')
            ->assertDontSee('98765')->assertDontSee('telefono-secreto')->assertDontSee('direccion-secreta')
            ->assertDontSee('>Todo<', false)->assertDontSee('>Nada<', false)->assertDontSee('No mandar nada')->assertDontSee('va_borrador');
    }

    public function test_esperando_factura_no_deja_mandar(): void
    {
        $pedido = $this->pedido(aceptada: false);
        $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()->assertSee('Esperando factura')->assertSee('disabled', false)->assertSee('La factura sale sola en unos minutos');
        $this->post(route('fabrica.pedidos.mandar', $pedido), $this->datos($pedido))->assertSessionHasErrors('pedido');
        $this->assertDatabaseCount('pedido_sala_envios', 0);
    }

    public function test_facturado_con_dte_no_aceptado_tampoco_sale(): void
    {
        $pedido = $this->pedido(aceptada: false);
        $pedido->pedidoWeb->update(['estado' => EstadoPedidoWeb::Facturado]);
        $this->post(route('fabrica.pedidos.mandar', $pedido), $this->datos($pedido))->assertSessionHasErrors('pedido');
    }

    public function test_incompleto_cierre_y_borrador_se_rechazan(): void
    {
        $pedido = $this->pedido();
        $this->post(route('fabrica.pedidos.mandar', $pedido), $this->datos($pedido, '11'))->assertSessionHasErrors('pedido');
        $this->patch(route('fabrica.pedidos.cerrar', $pedido), ['version' => $pedido->versionProduccion(), 'motivo' => 'Inventado'])
            ->assertSessionHasErrors(['pedido' => 'Los pedidos web se resuelven en la oficina']);
        $this->patchJson(route('fabrica.pedidos.borrador', $pedido), $this->datos($pedido))->assertUnprocessable();
        $this->assertDatabaseCount('pedido_sala_envios', 0);
    }

    public function test_mandar_aceptado_redirige_a_factura_y_anota_lista(): void
    {
        $pedido = $this->pedido(['entrega_tipo' => 'ruta']);
        $respuesta = $this->post(route('fabrica.pedidos.mandar', $pedido), $this->datos($pedido));
        $envio = $pedido->envios()->firstOrFail();
        $respuesta->assertRedirect(route('fabrica.pedidos.imprimir-web', [$pedido, $envio]));
        $this->assertSame('en_ruta', $pedido->pedidoWeb->refresh()->estado_por_informar);
        $this->assertNotNull($pedido->pedidoWeb->lista_en);
        $this->get(route('fabrica.pedidos.index', ['ver' => 'hechos']))->assertOk()->assertSee(route('fabrica.pedidos.imprimir-web', [$pedido, $envio]), false);
    }

    public function test_imprimir_factura_usa_representacion_oficial_y_window_print(): void
    {
        $pedido = $this->pedido();
        $envio = $this->mandar($pedido);
        $html = app(DtePdfService::class)->html($pedido->pedidoWeb->dte()->firstOrFail());
        $this->get(route('fabrica.pedidos.imprimir-web', [$pedido, $envio]))->assertOk()
            ->assertSee(explode('</body>', $html)[0], false)->assertSee('window.print()', false)->assertSee('Volver a Pedidos')
            // La factura impresa va en la caja del cliente: sin el estado técnico interno.
            ->assertDontSee('Estado técnico (interno):')->assertDontSee('JSON generado:');
    }

    public function test_imprimir_regalo_sin_comprador_ni_precios_y_texto_escapado(): void
    {
        $pedido = $this->pedido(['regalo' => ['nombre' => 'Destinataria Ficticia', 'mensaje' => '<script>inventado</script>']]);
        $envio = $this->mandar($pedido);
        $this->get(route('fabrica.pedidos.imprimir-web', [$pedido, $envio]))->assertOk()->assertSee('Un regalo para')->assertSee('Destinataria Ficticia')
            ->assertSee('<script>inventado</script>')->assertDontSee('<script>inventado</script>', false)
            ->assertDontSee('Compradora')->assertDontSee('98765')->assertDontSee('precio')->assertSee('window.print()', false);
    }

    public function test_imprimir_protege_permiso_envio_origen_y_factura(): void
    {
        $pedido = $this->pedido();
        $envio = $this->mandar($pedido);
        $otro = $this->pedido();
        $this->get(route('fabrica.pedidos.imprimir-web', [$otro, $envio]))->assertNotFound();
        $pedido->update(['origen' => OrigenPedidoSala::Sala]);
        $this->get(route('fabrica.pedidos.imprimir-web', [$pedido, $envio]))->assertNotFound();
        $pedido->update(['origen' => OrigenPedidoSala::Web]);
        $pedido->pedidoWeb->update(['estado' => EstadoPedidoWeb::Facturando]);
        $this->get(route('fabrica.pedidos.imprimir-web', [$pedido, $envio]))->assertStatus(409);
        $this->actingAs(User::factory()->create(['activo' => true])->assignRole('vendedor'));
        $this->get(route('fabrica.pedidos.imprimir-web', [$pedido, $envio]))->assertForbidden();
    }

    public function test_para_recoger_y_recogida_idempotente_sin_datos_personales(): void
    {
        $pedido = $this->pedido();
        $this->get(route('fabrica.pedidos.index', ['ver' => 'para-recoger']))->assertDontSee($pedido->pedidoWeb->codigo);
        $this->mandar($pedido);
        $this->get(route('fabrica.pedidos.index', ['ver' => 'para-recoger']))->assertOk()->assertSee('Para recoger (1)')->assertSee($pedido->pedidoWeb->codigo)->assertSee('lista desde 09:00')->assertSee('Ya lo recogieron');
        $this->travel(1)->minutes();
        $this->patch(route('fabrica.pedidos.recogido', $pedido))->assertRedirect();
        $this->patch(route('fabrica.pedidos.recogido', $pedido))->assertRedirect();
        $this->get(route('fabrica.pedidos.index', ['ver' => 'para-recoger']))->assertSee('Para recoger (0)')->assertDontSee($pedido->pedidoWeb->codigo);
        $this->assertSame('entregado', $pedido->pedidoWeb->refresh()->estado_por_informar);
        $auditoria = Activity::where('description', 'recogieron el pedido web')->sole();
        $this->assertSame(['estado' => 'entregado'], $auditoria->properties->sortKeys()->all());
    }

    public static function estados(): array
    {
        return [['ruta', 'en_ruta'], ['recoger', 'lista'], ['recoger', 'entregado']];
    }

    #[DataProvider('estados')]
    public function test_avisos_reintentan_y_salen_una_vez(string $tipo, string $estado): void
    {
        $pedido = $this->pedido(['entrega_tipo' => $tipo]);
        $this->mandar($pedido);
        if ($estado === 'entregado') {
            $this->travel(1)->minutes();
            $this->patch(route('fabrica.pedidos.recogido', $pedido))->assertRedirect();
        }
        $this->remoto(500);
        app(InformarEstadoPedidoWeb::class)->pasada(false);
        Http::assertNothingSent();
        app(InformarEstadoPedidoWeb::class)->pasada(true);
        $this->assertNull($pedido->pedidoWeb->refresh()->estado_informado_en);
        $this->remoto(200);
        app(InformarEstadoPedidoWeb::class)->pasada(true);
        app(InformarEstadoPedidoWeb::class)->pasada(true);
        $this->assertNotNull($pedido->pedidoWeb->refresh()->estado_informado_en);
        $this->assertSame($estado === 'entregado' ? ['lista', 'entregado'] : [$estado], Http::recorded()->map(fn ($par) => $par[0]['estado'])->all());
    }

    public function test_409_estado_cuenta_como_informado(): void
    {
        $pedido = $this->pedido();
        $this->mandar($pedido);
        $this->remoto(409, ['error' => 'estado', 'actual' => 'entregado']);
        app(InformarEstadoPedidoWeb::class)->pasada(true);
        app(InformarEstadoPedidoWeb::class)->pasada(true);
        Http::assertSentCount(1);
        $this->assertNotNull($pedido->pedidoWeb->refresh()->estado_informado_en);
    }

    public function test_recogido_despues_de_lista_informada_en_el_mismo_segundo_avisa_entregado(): void
    {
        $pedido = $this->pedido();
        $this->mandar($pedido);
        $this->remoto(200);
        app(InformarEstadoPedidoWeb::class)->pasada(true);
        $this->patch(route('fabrica.pedidos.recogido', $pedido))->assertRedirect();
        app(InformarEstadoPedidoWeb::class)->pasada(true);
        app(InformarEstadoPedidoWeb::class)->pasada(true);
        $this->assertSame(['lista', 'entregado'], Http::recorded()->map(fn ($par) => $par[0]['estado'])->all());
    }

    public function test_entregado_409_ajeno_no_cuenta_y_no_reintenta(): void
    {
        $pedido = $this->pedido();
        $this->mandar($pedido);
        $this->remoto(200);
        app(InformarEstadoPedidoWeb::class)->pasada(true);
        $this->travel(1)->minutes();
        $this->patch(route('fabrica.pedidos.recogido', $pedido))->assertRedirect();
        $this->remoto(409, ['error' => 'otro']);
        app(InformarEstadoPedidoWeb::class)->pasada(true);
        $this->assertSame('entregado', $pedido->pedidoWeb->refresh()->estado_por_informar);
        $this->remoto(200);
        app(InformarEstadoPedidoWeb::class)->pasada(true);
        app(InformarEstadoPedidoWeb::class)->pasada(true);
        Http::assertSentCount(0);
        $this->assertSame('entregado', $pedido->pedidoWeb->refresh()->estado_por_informar);
        $this->assertStringContainsString('La página rechazó el aviso:', $pedido->pedidoWeb->motivo_revision);
    }

    public function test_si_lista_sale_y_entregado_falla_solo_reintenta_entregado(): void
    {
        $pedido = $this->pedido();
        $this->mandar($pedido);
        $this->patch(route('fabrica.pedidos.recogido', $pedido))->assertRedirect();
        Http::fake(['*/estado' => Http::sequence()->push([], 200)->push([], 500)->push([], 200)]);
        app(InformarEstadoPedidoWeb::class)->pasada(true);
        app(InformarEstadoPedidoWeb::class)->pasada(true);
        app(InformarEstadoPedidoWeb::class)->pasada(true);
        $this->assertSame(['lista', 'entregado', 'entregado'], Http::recorded()->map(fn ($par) => $par[0]['estado'])->all());
        $this->assertNull($pedido->pedidoWeb->refresh()->estado_por_informar);
    }

    public function test_comando_sin_aplicar_no_manda_estados_y_con_aplicar_si(): void
    {
        $pedido = $this->pedido(['factura_correo_en' => now(), 'informado_factura_en' => now()]);
        $this->mandar($pedido);
        Http::fake([
            '*/pedidos-pagados*' => Http::response(['pedidos' => []]),
            '*/estado' => Http::response([], 200),
            '*/limpieza' => Http::response([], 200),
        ]);
        $this->artisan('tienda:traer-pedidos')->assertSuccessful();
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->assertSame('lista', $pedido->pedidoWeb->refresh()->estado_por_informar);
        $this->artisan('tienda:traer-pedidos', ['--aplicar' => true])->assertSuccessful();
        Http::assertSent(fn ($r) => $r->method() === 'POST' && ($r->data()['estado'] ?? null) === 'lista');
        $this->assertNull($pedido->pedidoWeb->refresh()->estado_por_informar);
    }

    public function test_resumen_diario_renderiza_nombre_visible_y_marca_web(): void
    {
        $pedido = $this->pedido();
        $this->mandar($pedido);
        $contenido = app(ResumenDiarioPedidos::class)->contenido(now()->startOfDay(), now()->endOfDay());
        $this->assertArrayHasKey('Pedido web · Compradora I. (web)', $contenido['salas']);
        $correo = new ResumenDiarioPedidosCorreo($contenido);
        $this->assertStringContainsString('Pedido web · Compradora I.: pedido N.º '.$pedido->id.', 2 cajitas', html_entity_decode(strip_tags($correo->render())));
        $pdfHtml = app(PdfFabricaDiario::class)->html($contenido);
        $this->assertStringContainsString('Pepiayote inventado', $pdfHtml);
        $this->assertStringContainsString('Cajita 1', $pdfHtml);
        foreach (['direccion-secreta', 'telefono-secreto', 'compradora@example.test', 'Compradora Inventada', '98765'] as $privado) {
            $this->assertStringNotContainsString($privado, $correo->render());
            $this->assertStringNotContainsString($privado, $pdfHtml);
        }
    }

    public function test_recogida_rechaza_otro_rol_y_pedido_sin_armar(): void
    {
        $pedido = $this->pedido();
        $this->patch(route('fabrica.pedidos.recogido', $pedido))->assertNotFound();
        $this->mandar($pedido);
        $this->actingAs(User::factory()->create(['activo' => true])->assignRole('vendedor'));
        $this->patch(route('fabrica.pedidos.recogido', $pedido))->assertForbidden();
        $this->assertNull($pedido->pedidoWeb->refresh()->entregado_en);
    }

    private function remoto(int $status, array $body = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/api/sistema/pedidos/*/estado' => Http::response($body, $status)]);
    }

    public function test_migracion_va_y_vuelve_y_no_pierde_seguimiento(): void
    {
        $migracion = require database_path('migrations/2026_11_16_300000_seguimiento_fabrica_pedidos_web.php');
        $migracion->down();
        $this->assertFalse(Schema::hasColumn('pedidos_web', 'lista_en'));
        $migracion->up();
        $this->assertTrue(Schema::hasColumns('pedidos_web', ['lista_en', 'entregado_en', 'estado_por_informar', 'estado_informado_en']));
        $this->mandar($this->pedido());
        $this->expectException(\RuntimeException::class);
        $migracion->down();
    }

    public function test_otros_actualiza_fila_ids_y_comparte_el_parcial_inicial(): void
    {
        $abierto = $this->pedido();
        $nuevo = $this->pedido();
        $otros = app(PedidosFabrica::class)->otrosPorMandar($abierto);
        $html = view('fabrica.pedidos._otros', compact('otros'))->render();
        $this->getJson(route('fabrica.pedidos.otros', $abierto))->assertOk()
            ->assertJsonPath('ids', [$nuevo->id])->assertJsonPath('html', $html);
        $this->get(route('fabrica.pedidos.show', $abierto))->assertOk()->assertSee($html, false)
            ->assertSee('setInterval(() => this.actualizar(), 30000)', false)
            ->assertSee('Llegó un pedido web')->assertSee('8000')->assertSee('[401, 419]', false);
        $abierto->update(['origen' => OrigenPedidoSala::Sala]);
        $this->get(route('fabrica.pedidos.show', $abierto))->assertOk()->assertSee($html, false)
            ->assertSee('setInterval(() => this.actualizar(), 30000)', false);
    }

    public function test_otros_y_lista_oficina_rechazan_sin_permiso(): void
    {
        $pedido = $this->pedido();
        $this->actingAs(User::factory()->create(['activo' => true]));
        $this->getJson(route('fabrica.pedidos.otros', $pedido))->assertForbidden();
        $this->get(route('facturacion.pedidos-web.index'))->assertForbidden();
        $this->post(route('facturacion.pedidos-web.reintentar', $pedido->pedidoWeb))->assertForbidden();
    }

    private function oficina(bool $gestionar = true): void
    {
        $user = User::factory()->create(['activo' => true]);
        $user->givePermissionTo($gestionar ? ['dte.ver', 'dte.gestionar'] : ['dte.ver']);
        $this->actingAs($user);
    }

    public function test_lista_oficina_estados_filtros_antiguedad_y_privacidad(): void
    {
        // Para revisar aunque se haya pagado hace más de 30 días: lo que requiere atención no vence.
        $revision = $this->pedido(['estado' => EstadoPedidoWeb::ParaRevisar, 'motivo_revision' => 'Motivo inventado', 'pagado_en' => now()->subDays(40)])->pedidoWeb;
        $viejo = $this->pedido(['estado' => EstadoPedidoWeb::Facturando])->pedidoWeb;
        $viejo->forceFill(['updated_at' => now()->subMinutes(31)])->save();
        $reciente = $this->pedido(['estado' => EstadoPedidoWeb::Facturando])->pedidoWeb;
        $reciente->forceFill(['updated_at' => now()->subMinutes(30)])->save();
        $importado = $this->pedido(['estado' => EstadoPedidoWeb::Importado, 'pagado_en' => now()->subDays(30)])->pedidoWeb;
        $facturado = $this->pedido()->pedidoWeb;
        $antiguo = $this->pedido(['pagado_en' => now()->subDays(31)])->pedidoWeb;
        $this->oficina();
        // Arriba lo que requiere atención; debajo los de los últimos 30 días, el más nuevo primero
        // y sin repetir los de arriba. Antes la lista quedaba vacía cuando todo iba bien.
        $this->get(route('facturacion.pedidos-web.index'))->assertOk()
            ->assertViewHas('atencion', function ($lista) use ($revision, $viejo) {
                $this->assertEquals([$revision->id, $viejo->id], $lista->pluck('id')->all());

                return true;
            })
            ->assertViewHas('pedidos', function ($lista) use ($facturado, $reciente, $importado) {
                $this->assertEquals([$facturado->id, $reciente->id, $importado->id], $lista->pluck('id')->all());

                return true;
            })
            ->assertSeeInOrder(['Requieren atención', $revision->codigo, $viejo->codigo, 'Últimos 30 días', $facturado->codigo, $reciente->codigo, $importado->codigo])
            ->assertDontSee($antiguo->codigo)
            ->assertSee('Motivo inventado')->assertSee('Compradora Inventada')->assertSee('16/11/2026 09:00')
            ->assertSee('$987.65')->assertDontSee('telefono-secreto')->assertDontSee('direccion-secreta');
        foreach (EstadoPedidoWeb::cases() as $estado) {
            $this->get(route('facturacion.pedidos-web.index', ['estado' => $estado->value]))
                ->assertOk()->assertViewHas('atencion', fn ($a) => $a === null)
                ->assertDontSee('Requieren atención')->assertDontSee('Últimos 30 días')
                ->assertViewHas('pedidos', fn ($lista) => $lista->count() > 0 && $lista->every(fn ($p) => $p->estado === $estado));
        }
        $this->get(route('facturacion.pedidos-web.index', ['estado' => 'todos']))->assertOk()
            ->assertViewHas('pedidos', fn ($lista) => $lista->total() === 6);
    }

    public function test_lista_oficina_vacia_dice_claro_que_no_hay_nada(): void
    {
        $this->oficina();
        $this->get(route('facturacion.pedidos-web.index'))->assertOk()
            ->assertSee('Nada requiere atención.')
            ->assertSee('No entró ningún pedido de la página en los últimos 30 días.')
            ->assertDontSee('Nada coincide con estos filtros.');
        $this->get(route('facturacion.pedidos-web.index', ['estado' => 'todos']))->assertOk()
            ->assertSee('Todavía no entró ningún pedido de la página.')->assertDontSee('Nada coincide con estos filtros.');

        $hoy = $this->pedido()->pedidoWeb; // facturado de hoy
        $this->get(route('facturacion.pedidos-web.index'))->assertOk()
            ->assertSee('Nada requiere atención.')->assertDontSee('No entró ningún pedido');
        $this->get(route('facturacion.pedidos-web.index', ['estado' => 'para_revisar']))->assertOk()
            ->assertSee('Nada coincide con estos filtros.')->assertSee('Limpiar filtros')
            ->assertDontSee('Nada requiere atención.');
        // Una página que no existe no dice que no entró ningún pedido.
        $this->get(route('facturacion.pedidos-web.index', ['page' => 3]))->assertOk()
            ->assertSee('Ningún otro pedido en los últimos 30 días.')->assertDontSee('No entró ningún pedido');

        // El único pedido de hoy quedó Para revisar: está arriba, y abajo no se contradice.
        $hoy->update(['estado' => EstadoPedidoWeb::ParaRevisar, 'motivo_revision' => 'Motivo inventado']);
        $this->get(route('facturacion.pedidos-web.index'))->assertOk()
            ->assertSeeInOrder(['Requieren atención', $hoy->codigo, 'Últimos 30 días', 'Ningún otro pedido en los últimos 30 días.'])
            ->assertDontSee('No entró ningún pedido')->assertDontSee('Nada requiere atención.');
    }

    public function test_reintentar_solo_revision_sin_factura_y_auditoria_sin_datos_personales(): void
    {
        $web = $this->pedido(['estado' => EstadoPedidoWeb::ParaRevisar, 'motivo_revision' => 'Motivo inventado'])->pedidoWeb;
        $web->update(['dte_id' => null]);
        $this->oficina();
        $this->post(route('facturacion.pedidos-web.reintentar', $web))->assertRedirect(route('facturacion.pedidos-web.index'));
        $this->assertSame(EstadoPedidoWeb::Importado, $web->fresh()->estado);
        $this->assertNull($web->fresh()->motivo_revision);
        $actividad = Activity::where('description', 'reintentar pedido web')->sole();
        $this->assertSame($web->id, $actividad->subject_id);
        $this->assertSame(auth()->id(), $actividad->causer_id);
        $this->assertEquals(collect(['estado_anterior' => 'para_revisar', 'estado' => 'importado'])->sortKeys(), $actividad->properties->sortKeys());
        $this->post(route('facturacion.pedidos-web.reintentar', $web))->assertSessionHasErrors('pedido');
        foreach ([EstadoPedidoWeb::Facturando, EstadoPedidoWeb::Facturado] as $estado) {
            $web->update(['estado' => $estado]);
            $this->post(route('facturacion.pedidos-web.reintentar', $web))->assertSessionHasErrors('pedido');
            $this->assertSame($estado, $web->fresh()->estado);
        }
        $this->assertSame(1, Activity::where('description', 'reintentar pedido web')->count());
    }

    public function test_reintentar_con_factura_o_sin_gestion_se_rechaza(): void
    {
        $web = $this->pedido(['estado' => EstadoPedidoWeb::ParaRevisar])->pedidoWeb;
        $this->oficina();
        $this->post(route('facturacion.pedidos-web.reintentar', $web))->assertSessionHasErrors('pedido');
        $this->assertSame(EstadoPedidoWeb::ParaRevisar, $web->fresh()->estado);
        $this->get(route('facturacion.pedidos-web.index'))->assertOk()->assertSee('Corregí la factura')->assertDontSee('¿Reintentar');
        $web->update(['dte_id' => null]);
        $this->oficina(false);
        $this->post(route('facturacion.pedidos-web.reintentar', $web))->assertForbidden();
        $this->get(route('facturacion.pedidos-web.index'))->assertOk()->assertDontSee('¿Reintentar');
    }

    public function test_oficina_pago_doble_permiso_nota_confirmacion_fallo_y_exito(): void
    {
        $pedido = $this->pedido(['alerta' => 'pago_doble']);
        $web = $pedido->pedidoWeb;
        $url = route('facturacion.pedidos-web.revisado', $web);
        $datos = ['nota' => 'Segundo cobro devuelto; revisión terminada.', 'confirmacion' => '1'];
        $this->oficina(false);
        $this->get(route('facturacion.pedidos-web.index'))->assertOk()->assertSee($web->codigo)
            ->assertSee('Pago doble: revisar si hay que devolver el segundo cobro')->assertDontSee('Ya lo revisé');
        $this->post($url, $datos)->assertForbidden();
        $this->oficina();
        $this->get(route('facturacion.pedidos-web.index'))->assertSee('Ya lo revisé');
        $this->post($url, ['confirmacion' => '1'])->assertSessionHasErrors('nota');
        $this->post($url, ['nota' => 'Revisado'])->assertSessionHasErrors('confirmacion');
        $this->post($url, ['nota' => 'Tarjeta 4111 1111 1111 1111', 'confirmacion' => '1'])->assertSessionHasErrors('nota');
        Http::swap(new Factory);
        Http::fake(['*/revisado' => Http::sequence()->push([], 503)->push(['alerta' => ''], 200)]);
        $this->post($url, $datos)->assertSessionHasErrors('pedido');
        $this->assertSame('pago_doble', $web->refresh()->alerta);
        $this->post($url, $datos)->assertRedirect(route('facturacion.pedidos-web.index'));
        $this->assertSame('', $web->refresh()->alerta);
        $this->assertSame(EstadoPedidoWeb::Facturado, $web->estado);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/revisado') && $r['nota'] === $datos['nota']);
        $actividad = Activity::where('description', 'revisó alerta de pago doble del pedido web')->sole();
        $this->assertSame(collect(['alerta_anterior' => 'pago_doble', 'alerta' => ''])->sortKeys()->all(), $actividad->properties->sortKeys()->all());
        $this->assertStringNotContainsString($datos['nota'], $actividad->properties->toJson());
        $this->assertNull(app(PedidosWebParaRevisar::class)->revisar());
    }

    public function test_alerta_y_motivo_sin_para_revisar_salen_en_oficina_y_detector_sin_habilitar_reintentar(): void
    {
        $alerta = $this->pedido(['alerta' => 'pago_doble']);
        $motivo = $this->pedido(['motivo_revision' => 'La página rechazó el aviso: factura (HTTP 409).']);
        $this->oficina();
        $this->get(route('facturacion.pedidos-web.index'))->assertOk()->assertSee($alerta->pedidoWeb->codigo)
            ->assertSee($motivo->pedidoWeb->codigo)->assertSee('La página rechazó el aviso: factura')->assertDontSee('>Reintentar<', false);
        $this->post(route('facturacion.pedidos-web.reintentar', $motivo->pedidoWeb))->assertSessionHasErrors('pedido');
        $hallazgo = app(PedidosWebParaRevisar::class)->revisar();
        $this->assertSame(2, $hallazgo->conteo);
    }

    public function test_fabrica_prefiere_nombre_del_surtido_y_conserva_codigo_anterior(): void
    {
        $pedido = $this->pedido(['cajitas_detalle' => [
            ['clase' => 'surtido', 'surtido' => 'codigo-nuevo', 'surtido_nombre' => 'La especial inventada', 'tam' => 12],
            ['clase' => 'surtido', 'surtido' => 'codigo-anterior', 'tam' => 12],
        ]]);
        $this->get(route('fabrica.pedidos.show', $pedido))->assertOk()->assertSee('La especial inventada')->assertSee('codigo-anterior')->assertDontSee('codigo-nuevo');
    }

    public function test_estado_rechazado_se_anota_una_vez_y_no_reintenta(): void
    {
        foreach ([409, 422, 404] as $status) {
            $pedido = $this->pedido();
            $this->mandar($pedido);
            $this->remoto($status, ['error' => 'estado']);
            app(InformarEstadoPedidoWeb::class)->pasada(true);
            app(InformarEstadoPedidoWeb::class)->pasada(true);
            Http::assertSentCount(1);
            $web = $pedido->pedidoWeb->refresh();
            $this->assertSame('lista', $web->estado_por_informar);
            $this->assertNull($web->estado_informado_en);
            $this->assertSame(EstadoPedidoWeb::Facturado, $web->estado);
            $this->assertStringContainsString('La página rechazó el aviso: lista (HTTP '.$status.')', $web->motivo_revision);
        }
    }

    public function test_contrato_de_409_solo_acepta_actual_igual_o_posterior(): void
    {
        foreach ([
            ['preparando', 'preparando', true], ['preparando', 'lista', true],
            ['lista', 'en_ruta', true], ['en_ruta', 'lista', true], ['lista', 'entregado', true],
            ['entregado', 'lista', false], ['lista', 'preparando', false],
            ['preparando', 'cancelado', false], ['lista', null, false], ['lista', [], false],
        ] as [$estado, $actual, $acepta]) {
            $respuesta = new \Illuminate\Http\Client\Response(new Response(409, ['Content-Type' => 'application/json'], json_encode(['error' => 'estado', 'actual' => $actual])));
            $this->assertSame($acepta, RespuestaAvisoPedidoWeb::estadoAceptado($respuesta, $estado));
        }
    }

    public function test_volver_a_avisar_limpia_motivo_audita_y_reintenta_en_la_pasada_siguiente(): void
    {
        $pedido = $this->pedido();
        $this->mandar($pedido);
        $this->remoto(422);
        app(InformarEstadoPedidoWeb::class)->pasada(true);
        $web = $pedido->pedidoWeb->refresh();
        $motivo = $web->motivo_revision;
        $estado = $web->estado;
        $this->oficina();
        $this->get(route('facturacion.pedidos-web.index'))->assertOk()->assertSee('Volver a avisar');
        $url = route('facturacion.pedidos-web.volver-a-avisar', $web);
        $this->post($url)->assertSessionHasErrors('confirmacion');
        $this->assertSame($motivo, $web->refresh()->motivo_revision);
        $this->post($url, ['confirmacion' => '1'])->assertRedirect(route('facturacion.pedidos-web.index'));
        $this->assertNull($web->refresh()->motivo_revision);
        $this->assertSame($estado, $web->estado);
        $this->assertSame('lista', $web->estado_por_informar);
        $this->assertNull(app(PedidosWebParaRevisar::class)->revisar());
        $actividad = Activity::where('description', 'volver a avisar pedido web')->sole();
        $this->assertSame($web->id, $actividad->subject_id);
        $this->assertSame(auth()->id(), $actividad->causer_id);
        $this->assertSame(collect(['motivo_anterior' => $motivo, 'motivo_revision' => null])->sortKeys()->all(), $actividad->properties->sortKeys()->all());
        // Otro rechazo se anota de nuevo; la oficina puede habilitar el aviso otra vez.
        $this->remoto(404);
        app(InformarEstadoPedidoWeb::class)->pasada(true);
        Http::assertSentCount(1);
        $this->assertStringContainsString('HTTP 404', $web->refresh()->motivo_revision);
        $this->post($url, ['confirmacion' => '1'])->assertRedirect();
        $this->remoto(200);
        app(InformarEstadoPedidoWeb::class)->pasada(true);
        Http::assertSentCount(1);
        $this->assertNull($web->refresh()->estado_por_informar);
        $this->assertNull($web->motivo_revision);
        $this->assertNotNull($web->estado_informado_en);
    }

    public function test_volver_a_avisar_exige_permiso_de_gestion(): void
    {
        $web = $this->pedido(['motivo_revision' => 'La página rechazó el aviso: lista (HTTP 422). Revisalo en la página.'])->pedidoWeb;
        $this->oficina(false);
        $this->get(route('facturacion.pedidos-web.index'))->assertOk()->assertDontSee('Volver a avisar');
        $this->post(route('facturacion.pedidos-web.volver-a-avisar', $web), ['confirmacion' => '1'])->assertForbidden();
        $this->assertNotNull($web->refresh()->motivo_revision);
        $this->assertSame(0, Activity::where('description', 'volver a avisar pedido web')->count());
    }

    public function test_volver_a_avisar_rechaza_para_revisar_y_sin_motivo(): void
    {
        $this->oficina();
        foreach ([
            ['estado' => EstadoPedidoWeb::ParaRevisar, 'motivo_revision' => 'Motivo inventado'],
            ['motivo_revision' => null],
            ['motivo_revision' => ''],
        ] as $extra) {
            $web = $this->pedido($extra)->pedidoWeb;
            $this->post(route('facturacion.pedidos-web.volver-a-avisar', $web), ['confirmacion' => '1'])->assertSessionHasErrors('pedido');
            $this->assertSame($extra['motivo_revision'], $web->refresh()->motivo_revision);
        }
        $this->get(route('facturacion.pedidos-web.index', ['estado' => 'todos']))->assertOk()->assertDontSee('Volver a avisar');
        $this->assertSame(0, Activity::where('description', 'volver a avisar pedido web')->count());
    }

    public function test_volver_a_avisar_no_copia_datos_personales_del_motivo_a_la_auditoria(): void
    {
        $web = $this->pedido(['motivo_revision' => 'Motivo inventado con correo privado@example.test'])->pedidoWeb;
        $this->oficina();
        $this->post(route('facturacion.pedidos-web.volver-a-avisar', $web), ['confirmacion' => '1'])->assertRedirect();
        $actividad = Activity::where('description', 'volver a avisar pedido web')->sole();
        $this->assertSame(collect(['motivo_anterior' => 'Motivo omitido por privacidad.', 'motivo_revision' => null])->sortKeys()->all(), $actividad->properties->sortKeys()->all());
        $this->assertNull($web->refresh()->motivo_revision);
    }
}
