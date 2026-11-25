<?php

namespace Tests\Feature\Fabrica;

use App\Enums\EstadoPedidoSala;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\PedidoSala;
use App\Models\PedidoSalaEnvio;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\PdfFabricaDiario;
use App\Services\Rutas\PedidosFabrica;
use App\Services\Rutas\ResumenDiarioPedidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * El día de verdad en la PC de Pedidos (Melqui, 24/11/2026), con los mismos clics que en la
 * fábrica: los vendedores mandan pedidos todo el día; la persona que va a la fábrica se
 * regresa como a las 3 de la tarde con las cajas listas; lo que llega después, o lo que no
 * está terminado, espera el próximo viaje. Los pedidos los saca el vendedor desde su
 * pantalla e «Imprimir y mandar» manda lo que tiene puesto la hoja. Todos los datos son
 * inventados.
 */
class DiaRealEntregasTest extends TestCase
{
    use RefreshDatabase;

    private User $fabrica;

    private User $carlos;

    private User $ana;

    /** @var array<string, ClienteSucursal> */
    private array $salas = [];

    private Producto $galleta;

    private Producto $canilla;

    private Producto $quesadilla;

    protected function setUp(): void
    {
        parent::setUp();
        // Lunes.
        $this->travelTo('2026-11-23 07:30:00');
        config()->set('planta.enabled', false);

        $this->fabrica = User::factory()->create(['activo' => true, 'name' => 'Pedidos Inventada'])->assignRole('fabrica');
        $this->carlos = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->ana = User::factory()->create(['activo' => true])->assignRole('vendedor');
        PersonalRuta::create(['nombre' => 'Carlos Inventado', 'user_id' => $this->carlos->id]);
        PersonalRuta::create(['nombre' => 'Ana Ficticia', 'user_id' => $this->ana->id]);
        $cerca = Ruta::create(['nombre' => 'Zona Cerca Inventada']);
        $lejos = Ruta::create(['nombre' => 'Zona Lejos Inventada']);
        foreach (range('A', 'I') as $i => $letra) {
            $this->salas[$letra] = Cliente::factory()->create(['nombre' => 'Cliente Inventado '.$letra])
                ->sucursales()->create(['nombre' => 'Súper Ficticio '.$letra, 'codigo' => (string) (9101 + $i), 'ruta_id' => ($i % 2 === 0 ? $cerca : $lejos)->id]);
        }

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01', 'precio_unitario' => 7.77]);
        $this->canilla = Producto::factory()->create(['nombre' => 'Canilla de prueba', 'codigo' => 'C01', 'precio_unitario' => 3.33]);
        $this->quesadilla = Producto::factory()->create(['nombre' => 'Quesadilla de prueba', 'codigo' => 'Q01', 'precio_unitario' => 5.55]);
    }

    // ───────────────────────────── lo que hace cada quien, con sus pantallas

    /** El vendedor saca el pedido en el celular, a la hora dada. @param  array<int, string>  $cantidades  [producto_id => cantidad] */
    private function sacar(User $vendedor, string $letra, string $hora, array $cantidades): PedidoSala
    {
        $this->travelTo($hora);
        $this->actingAs($vendedor)->post(route('rutas.sacar-pedido.store', $this->salas[$letra]), ['cantidades' => $cantidades])
            ->assertSessionHasNoErrors();

        return PedidoSala::where('cliente_sucursal_id', $this->salas[$letra]->id)->latest('id')->firstOrFail();
    }

    /**
     * @param  array<int, string>  $porProducto  [producto_id => valor]
     * @return array<int, string> [linea_id => valor]
     */
    private function porLinea(PedidoSala $pedido, array $porProducto): array
    {
        return collect($porProducto)->mapWithKeys(fn ($valor, $producto) => [$pedido->lineas()->where('producto_id', $producto)->value('id') => $valor])->all();
    }

    /** Lo que ellas marcan en la hoja: se guarda solo. */
    private function marcar(PedidoSala $pedido, array $cantidades, array $motivos = [], bool $sigue = false): void
    {
        $this->actingAs($this->fabrica)->patchJson(route('fabrica.pedidos.borrador', $pedido), [
            'cantidades' => $this->porLinea($pedido, $cantidades), 'motivos' => $this->porLinea($pedido, $motivos), 'sigue' => $sigue,
        ])->assertOk();
    }

    private function listo(PedidoSala $pedido, bool $listo = true): TestResponse
    {
        return $this->actingAs($this->fabrica)->patch(route('fabrica.pedidos.listo', $pedido), $listo
            ? ['listo' => '1', 'version' => PedidoSala::with(app(PedidosFabrica::class)->relaciones())->findOrFail($pedido->id)->versionProduccion()]
            : ['listo' => '0']);
    }

    /** «Imprimir para la caja»: el texto de la orden, sin espacios de más. */
    private function caja(PedidoSala $pedido): string
    {
        return $this->texto($this->actingAs($this->fabrica)->get(route('fabrica.pedidos.caja', $pedido))->assertOk()->getContent());
    }

    private function texto(string $html): string
    {
        $html = str_replace('<', ' <', preg_replace('/<(style|script)\b.*?<\/\1>/s', '', $html));

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html))));
    }

    /**
     * «Imprimir y mandar» desde la hoja abierta: manda exactamente lo que la hoja tiene puesto
     * (lo marcado o, sin marcar, todo lo que falta), los motivos y la casilla «va en otro envío».
     */
    private function imprimirYMandar(PedidoSala $pedido): TestResponse
    {
        $html = $this->actingAs($this->fabrica)->get(route('fabrica.pedidos.show', $pedido))->assertOk()->getContent();
        preg_match('/name="clave" value="([^"]+)"/', $html, $clave);
        preg_match('/name="version" value="([^"]+)"/', $html, $version);
        preg_match_all('/name="cantidades\[(\d+)\]" x-model="va" value="([^"]*)"/', $html, $cantidades, PREG_SET_ORDER);
        preg_match_all('/name="motivos\[(\d+)\]" maxlength="200" autocomplete="off"\s*value="([^"]*)"/', $html, $motivos, PREG_SET_ORDER);
        $datos = [
            'clave' => $clave[1], 'version' => $version[1],
            'cantidades' => collect($cantidades)->mapWithKeys(fn ($m) => [$m[1] => $m[2]])->all(),
            'motivos' => collect($motivos)->mapWithKeys(fn ($m) => [$m[1] => html_entity_decode($m[2])])->all(),
        ];
        if (preg_match('/name="sigue" value="1"\s+checked/', $html) === 1) {
            $datos['sigue'] = '1';
        }

        return $this->post(route('fabrica.pedidos.mandar', $pedido), $datos);
    }

    private function lista(): TestResponse
    {
        return $this->actingAs($this->fabrica)->get(route('fabrica.pedidos.index'))->assertOk();
    }

    /** @return list<int> los pedidos de un grupo de la lista de la fábrica */
    private function grupo(TestResponse $lista, string $grupo): array
    {
        return $lista->viewData($grupo)->pluck('id')->all();
    }

    /** @return list<float> lo que llevó cada línea del único envío del pedido */
    private function llevo(PedidoSala $pedido): array
    {
        return PedidoSalaEnvio::where('pedido_sala_id', $pedido->id)->sole()->lineas()->orderBy('id')->pluck('cantidad')->map(fn ($n) => (float) $n)->all();
    }

    // ───────────────────────────── el día

    public function test_el_lunes_se_llevan_los_listos_a_las_tres_y_el_martes_sale_lo_que_quedo_con_el_papel_de_ayer(): void
    {
        $g = $this->galleta->id;
        $c = $this->canilla->id;
        $q = $this->quesadilla->id;

        // ═══ LUNES EN LA MAÑANA: seis pedidos.
        $a = $this->sacar($this->carlos, 'A', '2026-11-23 07:45', [$g => '10', $c => '6']);
        $b = $this->sacar($this->carlos, 'B', '2026-11-23 08:30', [$g => '4']);
        $cc = $this->sacar($this->carlos, 'C', '2026-11-23 09:10', [$g => '12', $q => '3']);
        $d = $this->sacar($this->ana, 'D', '2026-11-23 09:40', [$g => '8', $c => '4', $q => '2']);
        $e = $this->sacar($this->ana, 'E', '2026-11-23 10:20', [$c => '5']);
        $f = $this->sacar($this->ana, 'F', '2026-11-23 11:15', [$q => '6']);

        // Ellas arman cuatro. A y B quedan como van a salir (a A le faltan canillas que no hay).
        $this->travelTo('2026-11-23 11:30');
        $this->marcar($a, [$g => '10', $c => '4'], [$c => 'No hay canilla']);
        $this->marcar($b, [$g => '4']);
        // C y D no se terminan: les dejan la orden encima de la caja.
        $this->marcar($cc, [$g => '6', $q => '0']);
        $this->marcar($d, [$g => '8', $c => '0', $q => '0']);
        $ordenC = $this->caja($cc);
        $ordenD = $this->caja($d);
        $this->assertStringContainsString('ORDEN PARA LA CAJA', $ordenD);
        $this->assertStringContainsString('Pedido N.º '.$d->id.' Fecha del pedido 23/11/2026', $ordenD);
        $this->assertStringContainsString('G01 Galleta de prueba 8', $ordenD);
        $this->assertStringContainsString('C01 Canilla de prueba 0 4', $ordenD);
        $this->assertStringContainsString('Q01 Quesadilla de prueba 0 2', $ordenD);
        $this->assertStringContainsString('G01 Galleta de prueba 6 6', $ordenC);
        // «Imprimir para la caja» no manda nada ni llega a la oficina.
        $this->assertSame(0, PedidoSalaEnvio::count());
        $this->assertSame(0, PedidoSalaEnvio::query()->porFacturar()->count());
        $this->assertSame(EstadoPedidoSala::Registrado, $cc->refresh()->estado);
        $this->assertSame(EstadoPedidoSala::Registrado, $d->refresh()->estado);

        // Marcan listos tres: A y B, y E sin tocar nada (va todo).
        foreach ([$a, $b, $e] as $pedido) {
            $this->listo($pedido)->assertRedirect(route('fabrica.pedidos.index'))
                ->assertSessionHas('deshacer', ['tipo' => 'listo', 'pedido' => $pedido->id]);
        }
        $this->assertSame(3, Activity::where('description', PedidosFabrica::AUDITORIA_LISTO)->where('causer_id', $this->fabrica->id)->count());

        $lista = $this->lista();
        $this->assertSame([$a->id, $b->id, $e->id], $this->grupo($lista, 'listos'));
        $this->assertSame([], $this->grupo($lista, 'atrasados'));
        $this->assertSame([$cc->id, $d->id, $f->id], $this->grupo($lista, 'deHoy'));
        $lista->assertSeeInOrder(['aria-label="Listos para salir"', 'Súper Ficticio A', 'Súper Ficticio B', 'Súper Ficticio E',
            'aria-label="De hoy"', 'Súper Ficticio C', 'Armando', 'Súper Ficticio D', 'Armando', 'Súper Ficticio F'], false);

        // ═══ 3 DE LA TARDE: se lleva los listos, uno por uno.
        $this->travelTo('2026-11-23 15:00');
        foreach ([$a, $b, $e] as $pedido) {
            $respuesta = $this->imprimirYMandar($pedido)->assertSessionHasNoErrors();
            $envio = PedidoSalaEnvio::where('pedido_sala_id', $pedido->id)->sole();
            $respuesta->assertRedirect(route('fabrica.pedidos.imprimir', [$pedido, $envio]));
            $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);
            $this->assertSame('2026-11-23', $envio->fecha->toDateString());
        }
        $this->assertSame([10.0, 4.0], $this->llevo($a));
        $this->assertSame('No hay canilla', $a->lineas()->where('producto_id', $c)->value('motivo_no_enviado'));
        $this->assertSame([4.0], $this->llevo($b));
        $this->assertSame([5.0], $this->llevo($e));
        $this->assertSame([], $this->grupo($this->lista(), 'listos'));

        // ═══ LUNES EN LA TARDE: tres pedidos más, ya sin quien los traiga.
        $gg = $this->sacar($this->carlos, 'G', '2026-11-23 15:30', [$g => '3']);
        $h = $this->sacar($this->ana, 'H', '2026-11-23 16:00', [$c => '2']);
        $i = $this->sacar($this->carlos, 'I', '2026-11-23 16:30', [$q => '4']);
        // Terminan C, que espera el próximo viaje.
        $this->travelTo('2026-11-23 16:45');
        $this->marcar($cc, [$g => '12', $q => '3']);
        $this->listo($cc)->assertSessionHasNoErrors();

        // ═══ 6 DE LA TARDE: el correo.
        $this->travelTo('2026-11-23 18:00');
        $contenido = app(ResumenDiarioPedidos::class)->contenido(Carbon::parse('2026-11-23'), now());
        $salas = $contenido['salas'];
        foreach (['A', 'B', 'E'] as $letra) {
            $this->assertCount(1, $salas['Súper Ficticio '.$letra]['salio'], $letra);
        }
        $this->assertSame([['numero' => $cc->id, 'fecha' => '23/11/2026', 'productos' => [
            ['nombre' => 'Galleta de prueba', 'cantidad' => '12'], ['nombre' => 'Quesadilla de prueba', 'cantidad' => '3'],
        ]]], $salas['Súper Ficticio C']['armados']);
        $this->assertSame([], $salas['Súper Ficticio C']['por_mandar']);
        foreach (['D', 'F', 'G', 'H', 'I'] as $letra) {
            $this->assertCount(1, $salas['Súper Ficticio '.$letra]['por_mandar'], $letra);
            $this->assertSame([], $salas['Súper Ficticio '.$letra]['armados'], $letra);
        }
        $pdf = $this->texto(app(PdfFabricaDiario::class)->html($contenido));
        $this->assertMatchesRegularExpression('/Súper Ficticio C.*Armados, esperando salir.*Pedido N\.º '.$cc->id.' del 23\/11\/2026: Galleta de prueba 12/s', $pdf);

        // ═══ MARTES. Carlos vuelve a pedir para D: la sala queda con dos pedidos abiertos.
        $d2 = $this->sacar($this->carlos, 'D', '2026-11-24 08:00', [$c => '3']);
        $this->travelTo('2026-11-24 08:30');
        // El papel de ayer sigue sirviendo: la orden de D sale igual que ayer.
        $this->assertSame($ordenD, $this->caja($d));
        $lista = $this->lista();
        $this->assertSame([$cc->id], $this->grupo($lista, 'listos'));
        $this->assertSame([$d->id, $f->id, $gg->id, $h->id, $i->id], $this->grupo($lista, 'atrasados'));
        $this->assertSame([$d2->id], $this->grupo($lista, 'deHoy'));

        // A C le encontraron algo: la sacan de listos (sin confirmar), vuelve abajo y la marcan de nuevo.
        $this->from(route('fabrica.pedidos.show', $cc))->listo($cc, false)->assertRedirect(route('fabrica.pedidos.show', $cc));
        $this->assertSame(EstadoPedidoSala::Registrado, $cc->refresh()->estado);
        $this->assertContains($cc->id, $this->grupo($this->lista(), 'atrasados'));
        $this->assertSame(1, Activity::where('description', PedidosFabrica::AUDITORIA_NO_LISTO)->count());
        $this->listo($cc)->assertSessionHasNoErrors();

        // Terminan D (sin quesadilla: va el jueves), G y H.
        $this->travelTo('2026-11-24 10:00');
        $this->marcar($d, [$g => '8', $c => '4', $q => '0'], [$q => 'Sin quesadilla, va el jueves'], sigue: true);
        $this->listo($d)->assertSessionHasNoErrors();
        $this->marcar($gg, [$g => '3']);
        $this->listo($gg)->assertSessionHasNoErrors();
        $this->listo($h)->assertSessionHasNoErrors();

        $lista = $this->lista();
        $this->assertSame([$cc->id, $d->id, $gg->id, $h->id], $this->grupo($lista, 'listos'));
        $this->assertSame([$f->id, $i->id], $this->grupo($lista, 'atrasados'));
        $this->assertSame([$d2->id], $this->grupo($lista, 'deHoy'));
        $lista->assertSeeInOrder(['aria-label="Listos para salir"', 'Súper Ficticio C', 'Del 23/11/2026 · hace 1 día', 'Súper Ficticio D', 'Del 23/11/2026'], false);

        // ═══ MARTES 3 DE LA TARDE: se los lleva. D sale en parte.
        $this->travelTo('2026-11-24 15:00');
        foreach ([$cc, $d, $gg, $h] as $pedido) {
            $this->imprimirYMandar($pedido)->assertSessionHasNoErrors();
        }
        $this->assertSame(EstadoPedidoSala::Enviado, $cc->refresh()->estado);
        $this->assertSame(EstadoPedidoSala::Parcial, $d->refresh()->estado);
        $this->assertSame(EstadoPedidoSala::Enviado, $gg->refresh()->estado);
        $this->assertSame(EstadoPedidoSala::Enviado, $h->refresh()->estado);
        $this->assertSame([8.0, 4.0, 0.0], array_pad($this->llevo($d), 3, 0.0));
        $envioD = PedidoSalaEnvio::where('pedido_sala_id', $d->id)->sole();
        $this->actingAs($this->fabrica)->get(route('fabrica.pedidos.imprimir', [$d, $envioD]))->assertOk()
            ->assertSeeInOrder(['Lo que va', 'Galleta de prueba', '8', 'Canilla de prueba', '4', 'Queda pendiente (va en otro envío)', 'Quesadilla de prueba', '2', 'Sin quesadilla, va el jueves']);
        $lista = $this->lista();
        $this->assertSame([], $this->grupo($lista, 'listos'));
        $this->assertSame([$d->id, $f->id, $i->id], $this->grupo($lista, 'atrasados'));

        // ═══ JUEVES: llega la quesadilla, la dejan lista y en la tarde sale el resto.
        $this->travelTo('2026-11-26 13:00');
        $this->marcar($d, [$q => '2']);
        $this->listo($d)->assertSessionHasNoErrors();
        $this->assertSame([$d->id], $this->grupo($this->lista(), 'listos'));
        $this->assertStringContainsString('Ya salió una parte de este pedido: esta caja lleva lo que faltaba.', $this->caja($d));
        $this->travelTo('2026-11-26 15:00');
        $this->imprimirYMandar($d)->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::Enviado, $d->refresh()->estado);
        $this->assertFalse($d->faltaAlgo());
        $this->assertSame([1, 2], PedidoSalaEnvio::where('pedido_sala_id', $d->id)->orderBy('numero')->pluck('numero')->all());
        $this->assertSame('2026-11-26', PedidoSalaEnvio::where('pedido_sala_id', $d->id)->where('numero', 2)->sole()->fecha->toDateString());
        // D2, el de Carlos, sigue esperando su turno, después de los del lunes.
        $this->assertSame([$f->id, $i->id, $d2->id], $this->grupo($this->lista(), 'atrasados'));
    }

    /**
     * El martes D sale en parte a las 3; a las 4:30 llega la quesadilla que faltaba y dejan
     * el resto listo para el jueves. La hoja de ese envío (la que se reimprime en «Hechos hoy»
     * y la que va en el PDF del correo de las 6) tiene que seguir diciendo que lo que falta va
     * en otro envío, no que «No va».
     */
    public function test_la_hoja_del_envio_en_parte_sigue_diciendo_que_el_resto_va_aunque_ya_este_listo(): void
    {
        $g = $this->galleta->id;
        $q = $this->quesadilla->id;
        $d = $this->sacar($this->ana, 'D', '2026-11-24 08:00', [$g => '8', $q => '2']);
        $this->travelTo('2026-11-24 10:00');
        $this->marcar($d, [$g => '8', $q => '0'], [$q => 'Sin quesadilla, va el jueves'], sigue: true);
        $this->travelTo('2026-11-24 15:00');
        $this->imprimirYMandar($d)->assertSessionHasNoErrors();
        $envio = PedidoSalaEnvio::where('pedido_sala_id', $d->id)->sole();

        $this->travelTo('2026-11-24 16:30');
        $this->marcar($d, [$q => '2']);
        $this->listo($d)->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::Listo, $d->refresh()->estado);

        // «Imprimir de nuevo» desde «Hechos hoy».
        $hoja = $this->texto($this->actingAs($this->fabrica)->get(route('fabrica.pedidos.imprimir', [$d, $envio]))->assertOk()->getContent());
        $this->assertStringContainsString('Queda pendiente (va en otro envío)', $hoja);
        $this->assertStringNotContainsString(' No va ', $hoja);

        // El PDF del correo de las 6.
        $this->travelTo('2026-11-24 18:00');
        $pdf = $this->texto(app(PdfFabricaDiario::class)->html(app(ResumenDiarioPedidos::class)->contenido(Carbon::parse('2026-11-24'), now())));
        $this->assertStringContainsString('Queda pendiente (va en otro envío)', $pdf);
        $this->assertStringNotContainsString(' No va ', $pdf);
    }

    /**
     * El papel que quedó encima de la caja dice el N.º de pedido en grande; en la pantalla
     * tiene que estar ese mismo N.º para saber de qué pedido es esa caja, sobre todo cuando
     * la sala tiene dos pedidos abiertos.
     */
    public function test_el_numero_del_papel_de_la_caja_se_ve_en_la_pantalla(): void
    {
        $g = $this->galleta->id;
        $ayer = $this->sacar($this->ana, 'D', '2026-11-23 09:40', [$g => '8']);
        $this->travelTo('2026-11-23 11:30');
        $this->marcar($ayer, [$g => '5']);
        $this->assertStringContainsString('Pedido N.º '.$ayer->id, $this->caja($ayer));
        $hoy = $this->sacar($this->carlos, 'D', '2026-11-24 08:00', [$g => '2']);

        $this->travelTo('2026-11-24 09:00');
        $lista = $this->texto($this->lista()->getContent());
        $this->assertStringContainsString('N.º '.$ayer->id, $lista);
        $this->assertStringContainsString('N.º '.$hoy->id, $lista);
        $hoja = $this->texto($this->actingAs($this->fabrica)->get(route('fabrica.pedidos.show', $ayer))->assertOk()->getContent());
        $this->assertStringContainsString('Pedido N.º: '.$ayer->id, $hoja);
    }

    // ───────────────────────────── lo que no debe pasar

    public function test_no_se_marca_listo_un_pedido_ya_mandado_que_no_se_trae_o_anulado(): void
    {
        $g = $this->galleta->id;
        $mandado = $this->sacar($this->carlos, 'A', '2026-11-23 08:00', [$g => '4']);
        $noSeTrae = $this->sacar($this->carlos, 'B', '2026-11-23 08:10', [$g => '4']);
        $anulado = $this->sacar($this->carlos, 'C', '2026-11-23 08:20', [$g => '4']);
        $this->actingAs($this->carlos)->patch(route('rutas.sacar-pedido.anular', $anulado))->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::Anulado, $anulado->refresh()->estado);
        $this->travelTo('2026-11-23 10:00');
        $this->imprimirYMandar($mandado)->assertSessionHasNoErrors();
        $fabrica = app(PedidosFabrica::class);
        $fabrica->cerrar($noSeTrae, $noSeTrae->load($fabrica->relaciones())->versionProduccion(), $this->fabrica, 'No hay producto');
        $antes = Activity::count();

        foreach ([[$mandado, EstadoPedidoSala::Enviado], [$noSeTrae, EstadoPedidoSala::NoSeTrae], [$anulado, EstadoPedidoSala::Anulado]] as [$pedido, $estado]) {
            $this->from(route('fabrica.pedidos.index'))->listo($pedido)->assertSessionHasErrors('pedido');
            $this->from(route('fabrica.pedidos.index'))->listo($pedido, false)->assertSessionHasErrors('pedido');
            $this->assertSame($estado, $pedido->refresh()->estado);
            // Tampoco sale el papel para la caja.
            $this->actingAs($this->fabrica)->get(route('fabrica.pedidos.caja', $pedido))->assertRedirect(route('fabrica.pedidos.index'));
        }
        $this->assertSame($antes, Activity::count());
        $this->assertSame(1, PedidoSalaEnvio::count());
    }

    public function test_el_doble_clic_no_repite_nada(): void
    {
        $g = $this->galleta->id;
        $pedido = $this->sacar($this->carlos, 'A', '2026-11-23 08:00', [$g => '4']);
        $this->travelTo('2026-11-23 10:00');
        $this->marcar($pedido, [$g => '3']);

        // «Listo para salir» dos veces: queda una vez, sin un segundo «Deshacer».
        $this->listo($pedido)->assertSessionHas('deshacer');
        $this->listo($pedido)->assertSessionHasNoErrors()->assertSessionMissing('deshacer')
            ->assertSessionHas('status', 'El pedido de Súper Ficticio A ya estaba en «Listos para salir».');
        $this->assertSame(1, Activity::where('description', PedidosFabrica::AUDITORIA_LISTO)->count());
        // «Quitar de listos» dos veces: igual.
        $this->listo($pedido, false)->assertSessionHasNoErrors();
        $this->listo($pedido, false)->assertSessionHasNoErrors();
        $this->assertSame(1, Activity::where('description', PedidosFabrica::AUDITORIA_NO_LISTO)->count());
        $this->listo($pedido)->assertSessionHasNoErrors();

        // «Imprimir para la caja» dos veces: dos papeles iguales y nada guardado.
        $this->assertSame($this->caja($pedido), $this->caja($pedido));
        $this->assertSame(0, PedidoSalaEnvio::count());

        // «Imprimir y mandar» dos veces con la misma hoja: un solo envío.
        $html = $this->actingAs($this->fabrica)->get(route('fabrica.pedidos.show', $pedido))->getContent();
        preg_match('/name="clave" value="([^"]+)"/', $html, $clave);
        preg_match('/name="version" value="([^"]+)"/', $html, $version);
        $datos = ['clave' => $clave[1], 'version' => $version[1], 'cantidades' => $this->porLinea($pedido, [$g => '3'])];
        $this->post(route('fabrica.pedidos.mandar', $pedido), $datos)->assertSessionHasNoErrors();
        $this->post(route('fabrica.pedidos.mandar', $pedido), $datos)->assertSessionHasNoErrors();
        $this->assertSame(1, PedidoSalaEnvio::count());
        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);
        // Y el «Listo para salir» de una pantalla vieja ya no lo toca.
        $this->from(route('fabrica.pedidos.index'))->listo($pedido)->assertSessionHasErrors('pedido');
        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);
    }

    public function test_la_cuenta_de_pedidos_marca_listos_y_el_vendedor_no(): void
    {
        $g = $this->galleta->id;
        $pedido = $this->sacar($this->carlos, 'A', '2026-11-23 08:00', [$g => '4']);
        $this->travelTo('2026-11-23 10:00');

        // Ningún vendedor, ni el que lo sacó, marca listo ni saca la orden para la caja.
        foreach ([$this->carlos, $this->ana] as $vendedor) {
            $this->actingAs($vendedor)->patch(route('fabrica.pedidos.listo', $pedido), ['listo' => '1'])->assertForbidden();
            $this->actingAs($vendedor)->get(route('fabrica.pedidos.caja', $pedido))->assertForbidden();
            $this->actingAs($vendedor)->get(route('fabrica.pedidos.index'))->assertForbidden();
        }
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->refresh()->estado);

        // La cuenta de Pedidos sí; con el pedido listo, el vendedor recibe el aviso de siempre.
        $this->listo($pedido)->assertSessionHasNoErrors();
        $this->actingAs($this->carlos)->put(route('rutas.sacar-pedido.update', $pedido), ['cantidades' => [$g => '9']])
            ->assertSessionHasErrors('lineas');
        $this->actingAs($this->carlos)->patch(route('rutas.sacar-pedido.anular', $pedido))->assertSessionHasErrors('lineas');
        $this->assertSame(EstadoPedidoSala::Listo, $pedido->refresh()->estado);
        $this->assertSame(4.0, (float) $pedido->lineas()->value('cantidad'));

        // La cuenta de Pedidos no ve Facturación ni factura.
        $this->actingAs($this->fabrica)->get(route('facturacion.llego-de-fabrica'))->assertForbidden();
        $this->actingAs($this->fabrica)->post(route('facturacion.pedidos.facturar', $pedido), ['directo' => '1'])->assertForbidden();
    }
}
