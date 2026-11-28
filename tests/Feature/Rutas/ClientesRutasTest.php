<?php

namespace Tests\Feature\Rutas;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoSalidaRuta;
use App\Enums\ResultadoEntrega;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\PpqAlbaran;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\HistoriaSala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * «Clientes» de Rutas (Melqui, 28/11/2026): todas las salas con lo último que se les sacó y su
 * último CCF, la historia de cada una y el PDF del CCF para cobrar. Solo cantidades: ningún
 * monto en la lista ni en la historia. «Todos los pedidos» es la lista de siempre, como pestaña.
 * Ventas pasa a ser pestaña del Tablero y Zonas se abre desde ahí. Datos ficticios.
 */
class ClientesRutasTest extends TestCase
{
    use RefreshDatabase;

    private User $jefe;

    private User $vendedor;

    private PersonalRuta $persona;

    private Ruta $norte;

    private Ruta $sur;

    private Cliente $cliente;

    private ClienteSucursal $salaNorte;

    private ClienteSucursal $salaSur;

    private Producto $galleta;

    private Producto $canilla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-28 09:00:00');
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-06-01', 'rutas.ambiente_ccf' => null]);

        $this->jefe = User::factory()->create(['activo' => true])->assignRole('jefe_vendedores');
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Vendedor de prueba', 'user_id' => $this->vendedor->id]);
        $this->norte = Ruta::create(['nombre' => 'Zona de prueba Norte']);
        $this->sur = Ruta::create(['nombre' => 'Zona de prueba Sur']);
        $this->cliente = Cliente::factory()->create(['nombre' => 'Cliente de prueba']);
        $this->salaNorte = $this->sala('Sala de prueba Norte', '9001', $this->norte);
        $this->salaSur = $this->sala('Sala de prueba Sur', '9002', $this->sur);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01', 'precio_unitario' => 7.77]);
        $this->canilla = Producto::factory()->create(['nombre' => 'Canilla de prueba', 'codigo' => 'C01', 'precio_unitario' => 3.33]);
    }

    private function sala(string $nombre, string $codigo, ?Ruta $zona): ClienteSucursal
    {
        return $this->cliente->sucursales()->create(['nombre' => $nombre, 'codigo' => $codigo, 'ruta_id' => $zona?->id]);
    }

    /** @param  array<int, array{0: Producto, 1: string}>  $lineas */
    private function pedido(ClienteSucursal $sala, string $fecha, array $lineas = [], EstadoPedidoSala $estado = EstadoPedidoSala::Registrado): PedidoSala
    {
        $pedido = PedidoSala::create([
            'cliente_sucursal_id' => $sala->id, 'ruta_id' => $sala->ruta_id, 'sacado_por_id' => $this->persona->id,
            'registrado_por' => $this->vendedor->id, 'fecha' => $fecha, 'estado' => $estado,
        ]);
        foreach ($lineas as [$producto, $cantidad]) {
            $pedido->lineas()->create(['producto_id' => $producto->id, 'cantidad' => $cantidad]);
        }

        return $pedido;
    }

    private function ccf(ClienteSucursal $sala, string $fecha, string $total = '123.45', array $extra = []): Dte
    {
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Empresa de prueba'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $n = 700 + Dte::withTrashed()->count();

        return Dte::create($extra + [
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $this->cliente->id, 'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => $fecha, 'hora_emision' => '07:00:00', 'subtotal' => $total, 'total_pagar' => $total,
        ]);
    }

    private function entregado(Dte $dte, string $cuando): void
    {
        $salida = SalidaRuta::create(['ruta_id' => $this->norte->id, 'fecha_inicio' => substr($cuando, 0, 10), 'estado' => EstadoSalidaRuta::Finalizada]);
        $salida->entregas()->create(['dte_id' => $dte->id, 'cliente_sucursal_id' => $dte->cliente_sucursal_id,
            'resultado' => ResultadoEntrega::Entregado, 'fecha_resultado' => $cuando, 'entregado_por_id' => $this->persona->id,
            'registrado_por' => $this->vendedor->id]);
    }

    private function porCobrar(Dte $dte): void
    {
        CobroDocumento::create(['cliente_id' => $dte->cliente_id, 'dte_id' => $dte->id, 'tipo_dte' => '03',
            'numero_control' => $dte->numero_control, 'fecha_emision' => $dte->fecha_emision->toDateString(), 'monto' => $dte->total_pagar,
            'pago_estado' => EstadoPagoCobro::Pendiente]);
    }

    /** Lo que va dentro de `<main>`: la lista o la historia, sin el menú ni la barra de arriba. */
    private function contenido(string $html): string
    {
        $inicio = strpos($html, '<main');
        $this->assertNotFalse($inicio, 'La página no tiene <main>.');

        return substr($html, $inicio, (int) strpos($html, '</main>') - $inicio);
    }

    private function sidebar(string $html): string
    {
        $inicio = strpos($html, '<aside');
        $this->assertNotFalse($inicio, 'La página no tiene barra lateral.');

        return substr($html, $inicio, (int) strpos($html, '</aside>') - $inicio);
    }

    // ══════════════════════════════ la lista

    public function test_la_lista_muestra_lo_ultimo_de_cada_sala_sin_montos(): void
    {
        $this->pedido($this->salaNorte, '2026-11-10', [[$this->canilla, '9']]);
        $this->pedido($this->salaNorte, '2026-11-25', [[$this->galleta, '6'], [$this->canilla, '1']]);
        // Lo anulado no cuenta como lo último que se le sacó.
        $this->pedido($this->salaNorte, '2026-11-27', [[$this->canilla, '40']], EstadoPedidoSala::Anulado);
        $viejo = $this->ccf($this->salaNorte, '2026-11-12', '999.99');
        $ultimo = $this->ccf($this->salaNorte, '2026-11-26', '123.45');
        $this->entregado($ultimo, '2026-11-27 10:00:00');
        $this->porCobrar($viejo);
        $this->porCobrar($ultimo);

        $html = $this->contenido($this->actingAs($this->jefe)->get(route('rutas.clientes.index'))->assertOk()->getContent());

        // Todas las salas, por nombre, cada una con su zona.
        $this->assertMatchesRegularExpression('/Sala de prueba Norte.*Zona de prueba Norte.*Sala de prueba Sur.*Zona de prueba Sur/su', $html);
        // Lo último que se le sacó: fecha, quién y cuántas cajas de cada producto.
        $this->assertMatchesRegularExpression('/25\/11\/2026.*Vendedor de prueba.*Galleta de prueba.*6 cajas.*Canilla de prueba.*1 caja\b/su', $html);
        $this->assertStringNotContainsString('40 cajas', $html);
        $this->assertStringNotContainsString('10/11/2026', $html);
        // Su último CCF: número, fecha y si se entregó; y cuántos tiene por cobrar.
        $this->assertStringContainsString('CCF …'.substr($ultimo->numero_control, -4), $html);
        $this->assertMatchesRegularExpression('/26\/11\/2026.*Entregado el 27\/11\/2026/su', $html);
        $this->assertStringContainsString('2 CCF por cobrar', $html);
        // La sala sin nada lo dice.
        $this->assertStringContainsString('Todavía no tiene pedidos', $html);
        // Sin montos: ni el total del CCF ni ningún precio.
        foreach (['123.45', '999.99', '7.77', '3.33', '$'] as $monto) {
            $this->assertStringNotContainsString($monto, $html, $monto);
        }
        // Cada sala abre su historia.
        $this->assertStringContainsString('href="'.route('rutas.clientes.show', $this->salaNorte).'"', $html);
    }

    public function test_se_busca_por_nombre_o_codigo_y_se_filtra_por_zona(): void
    {
        $this->actingAs($this->jefe);

        $this->get(route('rutas.clientes.index', ['q' => 'norte']))->assertOk()
            ->assertSee('Sala de prueba Norte')->assertDontSee('Sala de prueba Sur');
        $this->get(route('rutas.clientes.index', ['q' => '9002']))->assertOk()
            ->assertSee('Sala de prueba Sur')->assertDontSee('Sala de prueba Norte');
        $this->get(route('rutas.clientes.index', ['zona' => $this->sur->id]))->assertOk()
            ->assertSee('Sala de prueba Sur')->assertDontSee('Sala de prueba Norte');
        $this->get(route('rutas.clientes.index', ['q' => 'nada parecido']))->assertOk()
            ->assertSee('Nada coincide con estos filtros');

        // Un filtro armado a mano (en lista, o una zona que no existe) se ignora: no da 500.
        $this->get('/rutas/clientes?q[]=x&zona[]=1')->assertOk()->assertSee('Sala de prueba Norte')->assertSee('Sala de prueba Sur');
        $this->get(route('rutas.clientes.index', ['zona' => 999999]))->assertOk()->assertSee('Sala de prueba Sur');
        // Una sala inactiva no sale.
        $this->salaSur->update(['activo' => false]);
        $this->get(route('rutas.clientes.index'))->assertOk()->assertDontSee('Sala de prueba Sur');
    }

    /** Pocas salas por ahora, pero se probó con carga: las mismas consultas para 2 salas que para 12. */
    public function test_la_lista_no_consulta_sala_por_sala(): void
    {
        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->jefe)->get(route('rutas.clientes.index'))->assertOk();
            $cuantas = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $cuantas;
        };
        $llenar = function (ClienteSucursal $sala, int $i): void {
            $this->pedido($sala, '2026-11-2'.($i % 8), [[$this->galleta, '2'], [$this->canilla, '3']]);
            $dte = $this->ccf($sala, '2026-11-2'.($i % 8));
            $this->entregado($dte, '2026-11-27 08:00:00');
            $this->porCobrar($dte);
        };
        $llenar($this->salaNorte, 1);
        $llenar($this->salaSur, 2);
        // La primera vuelta carga permisos y ajustes que después quedan en memoria: no se mide.
        $contar();
        $pocas = $contar();

        foreach (range(3, 12) as $i) {
            $llenar($this->sala('Sala de prueba '.$i, (string) (9000 + $i), $i % 2 ? $this->norte : null), $i);
        }

        $this->assertSame($pocas, $contar());
    }

    // ══════════════════════════════ la historia de una sala

    public function test_la_historia_trae_pedidos_y_ccf_del_mas_nuevo_al_mas_viejo_sin_montos(): void
    {
        foreach (range(1, 12) as $dia) {
            $this->pedido($this->salaNorte, sprintf('2026-11-%02d', $dia), [[$this->galleta, (string) $dia]]);
            $dte = $this->ccf($this->salaNorte, sprintf('2026-10-%02d', $dia + 14), '321.09');
        }
        $this->entregado($dte, '2026-10-27 09:00:00');
        $this->porCobrar($dte);
        // Otra sala, otro documento y lo que no cuenta como CCF no aparecen.
        $this->ccf($this->salaSur, '2026-09-30');
        $factura = $this->ccf($this->salaNorte, '2026-10-21', extra: ['tipo_dte' => '01']);
        $invalidado = $this->ccf($this->salaNorte, '2026-10-22', extra: ['estado' => 'invalidado']);

        $html = $this->contenido($this->actingAs($this->jefe)->get(route('rutas.clientes.show', $this->salaNorte))->assertOk()->getContent());

        // Los 10 más nuevos de cada uno, del más nuevo al más viejo, y «Ver más».
        $this->assertMatchesRegularExpression('/12\/11\/2026.*11\/11\/2026.*03\/11\/2026/su', $html);
        $this->assertStringNotContainsString('02/11/2026', $html);
        $this->assertMatchesRegularExpression('/26\/10\/2026.*Entregado el 27\/10\/2026.*Por cobrar.*25\/10\/2026.*Por entregar.*17\/10\/2026/su', $html);
        $this->assertStringNotContainsString('16/10/2026', $html);
        $this->assertStringContainsString('12 cajas', $html);
        $this->assertSame(2, substr_count($html, '>Ver más'));
        // «Ver más» lleva al primero de los nuevos.
        $this->assertStringContainsString('pedidos=2&amp;ccf=1#pedido-11"', $html);
        $this->assertStringContainsString('pedidos=1&amp;ccf=2#ccf-11"', $html);
        foreach ([$factura, $invalidado] as $otro) {
            $this->assertStringNotContainsString(substr($otro->numero_control, -4), $html);
        }
        $this->assertStringNotContainsString('30/09/2026', $html);
        // Cada CCF se abre en PDF y se comparte desde el teléfono.
        $this->assertStringContainsString('href="'.route('rutas.clientes.ccf', [$this->salaNorte, $dte]).'"', $html);
        $this->assertStringContainsString('Compartir', $html);
        // Sin montos.
        foreach (['321.09', '7.77', '$'] as $monto) {
            $this->assertStringNotContainsString($monto, $html, $monto);
        }

        // «Ver más» trae los que faltan.
        $mas = $this->contenido($this->get(route('rutas.clientes.show', [$this->salaNorte, 'pedidos' => 2, 'ccf' => 2]))->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/02\/11\/2026.*01\/11\/2026/su', $mas);
        $this->assertMatchesRegularExpression('/16\/10\/2026.*15\/10\/2026/su', $mas);
        $this->assertStringContainsString('id="ccf-12"', $mas);
        $this->assertStringNotContainsString('>Ver más', $mas);

        // Un «Ver más» armado a mano no rompe nada.
        $this->get('/rutas/clientes/'.$this->salaNorte->id.'?pedidos[]=9&ccf=abc')->assertOk();
        $this->get(route('rutas.clientes.show', 999999))->assertNotFound();
    }

    /**
     * «Por entregar» solo para lo que el vendedor ve en su «Por entregar» (los últimos 45 días
     * desde el control de entregas). Lo más viejo sin entrega anotada lo dice así, y el albarán
     * de entrega cuenta como entregado, con su fecha.
     */
    public function test_la_entrega_del_ccf_tiene_la_misma_regla_que_por_entregar(): void
    {
        config(['rutas.entregas_desde' => '2026-10-01']);
        $antesDelControl = $this->ccf($this->salaNorte, '2026-09-20');
        $viejo = $this->ccf($this->salaSur, '2026-10-10');
        $reciente = $this->ccf($this->salaSur, '2026-11-20', extra: ['numero_orden_compra' => '43010000123456']);
        PpqAlbaran::create(['numero_albaran' => 'AC01/0232/00/6716', 'monto_albaran' => 123.45, 'fecha_albaran' => '2026-11-22',
            'origen' => 'gmail', 'numero_orden_compra' => '43010000123456']);
        $porEntregar = $this->ccf($this->salaNorte, '2026-11-25', extra: ['numero_orden_compra' => '43010000999999']);
        $this->assertSame([$porEntregar->id], app(EntregasCcf::class)->porEntregar()->pluck('id')->all());

        $this->actingAs($this->jefe);
        $this->get(route('rutas.clientes.show', $this->salaNorte))->assertOk()
            ->assertSeeInOrder(['25/11/2026', 'Por entregar', '20/09/2026', 'Sin entrega anotada']);
        $sur = $this->get(route('rutas.clientes.show', $this->salaSur))->assertOk()
            ->assertSeeInOrder(['20/11/2026', 'Entregado el 22/11/2026', '10/10/2026', 'Sin entrega anotada'])->getContent();
        $this->assertStringNotContainsString('Por entregar', $this->contenido($sur));
        $this->assertNotNull($antesDelControl);
        $this->assertNotNull($viejo);
        $this->assertNotNull($reciente);
    }

    /**
     * «Por cobrar» con los filtros de «Facturas por cobrar»: un CCF que su NC deja en saldo 0 ya
     * no se cobra, y la NC no es un CCF. Vale igual para la historia del sugerido del vendedor.
     */
    public function test_por_cobrar_no_cuenta_lo_que_una_nc_dejo_saldado(): void
    {
        $saldado = $this->ccf($this->salaNorte, '2026-11-20', '123.45');
        $pendiente = $this->ccf($this->salaNorte, '2026-11-21', '80.00');
        $nota = $this->ccf($this->salaNorte, '2026-11-22', '123.45', ['tipo_dte' => '05', 'dte_relacionado_id' => $saldado->id]);
        foreach ([$saldado, $pendiente] as $dte) {
            $this->porCobrar($dte);
        }
        CobroDocumento::create(['cliente_id' => $nota->cliente_id, 'dte_id' => $nota->id, 'tipo_dte' => '05',
            'numero_control' => $nota->numero_control, 'fecha_emision' => '2026-11-22', 'monto' => '123.45', 'pago_estado' => EstadoPagoCobro::Pendiente]);

        $this->assertSame([$this->salaNorte->id => [$pendiente->id]], app(HistoriaSala::class)->porCobrar([$this->salaNorte->id, $this->salaSur->id]));
        $this->assertSame(1, app(HistoriaSala::class)->de($this->salaNorte)['porCobrar']);

        $this->actingAs($this->jefe)->get(route('rutas.clientes.index'))->assertOk()->assertSee('1 CCF por cobrar');
        $historia = $this->contenido($this->get(route('rutas.clientes.show', $this->salaNorte))->assertOk()->getContent());
        $this->assertSame(1, substr_count($historia, '>Por cobrar</span>'));
    }

    // ══════════════════════════════ permisos y PDF

    public function test_sin_rutas_pedidos_ver_no_se_abre_nada(): void
    {
        $dte = $this->ccf($this->salaNorte, '2026-11-20');
        $urls = [
            route('rutas.clientes.index'),
            route('rutas.clientes.show', $this->salaNorte),
            route('rutas.clientes.ccf', [$this->salaNorte, $dte]),
        ];

        // Con la entrada a Rutas pero sin `rutas.pedidos.ver`, y el vendedor (que no tiene ninguno).
        $sinPedidos = User::factory()->create(['activo' => true])->assignRole('contabilidad')->givePermissionTo('rutas.ver');
        foreach ([$sinPedidos, $this->vendedor] as $usuario) {
            foreach ($urls as $url) {
                $this->actingAs($usuario)->get($url)->assertForbidden();
            }
        }
        // Sin `rutas.pedidos.ver` tampoco se ve la entrada en el menú.
        $this->actingAs($sinPedidos)->get(route('rutas.dashboard'))->assertOk()
            ->assertDontSee('href="'.route('rutas.clientes.index').'"', false);

        $this->post('/logout');
        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }

        // Jefe de vendedores, Facturación, Jefatura y el administrador lo tienen.
        foreach (['jefe_vendedores', 'facturacion', 'jefatura', 'administrador'] as $rol) {
            $this->actingAs(User::factory()->create(['activo' => true])->assignRole($rol))->get(route('rutas.clientes.index'))->assertOk();
        }
    }

    public function test_el_pdf_del_ccf_se_abre_con_permiso_y_solo_de_esa_sala(): void
    {
        $dte = $this->ccf($this->salaNorte, '2026-11-20');
        $this->entregado($dte, '2026-11-21 08:00:00');

        // Cualquiera con el permiso, para cualquier sala, también ya entregado: se ve en el navegador.
        $respuesta = $this->actingAs($this->jefe)->get(route('rutas.clientes.ccf', [$this->salaNorte, $dte]))->assertOk();
        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', (string) $respuesta->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));

        // De otra sala, que no es CCF, invalidado, archivado, borrado o que no existe: no se encuentra.
        $this->get(route('rutas.clientes.ccf', [$this->salaSur, $dte]))->assertNotFound();
        foreach ([['tipo_dte' => '01'], ['estado' => 'invalidado'], ['archivado' => true], ['ambiente' => '00']] as $cambio) {
            $otro = $this->ccf($this->salaNorte, '2026-11-20', extra: $cambio);
            $this->get(route('rutas.clientes.ccf', [$this->salaNorte, $otro]))->assertNotFound();
        }
        // Borrado (un CCF aceptado no se borra desde la app: se simula en la base).
        $borrado = $this->ccf($this->salaNorte, '2026-11-20');
        DB::table('dtes')->where('id', $borrado->id)->update(['deleted_at' => now()]);
        $this->get(route('rutas.clientes.ccf', [$this->salaNorte, $borrado->id]))->assertNotFound();
        $this->get('/rutas/clientes/'.$this->salaNorte->id.'/ccf/999999')->assertNotFound();
    }

    // ══════════════════════════════ menú, Ventas y Zonas

    public function test_el_menu_trae_clientes_y_ya_no_pedidos_ventas_ni_zonas(): void
    {
        $jefatura = User::factory()->create(['activo' => true])->assignRole('jefatura');
        $menu = $this->sidebar($this->actingAs($jefatura)->get(route('rutas.clientes.index'))->assertOk()->getContent());

        $this->assertMatchesRegularExpression('/Tablero.*Clientes.*Menú del vendedor/su', $menu);
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('rutas.clientes.index'), '/').'"[^>]*aria-current="page"/u', $menu);
        foreach (['rutas.pedidos.index', 'rutas.ventas.index', 'rutas.rutas.index'] as $fuera) {
            $this->assertStringNotContainsString('href="'.route($fuera).'"', $menu, $fuera);
        }
        foreach (['>Zonas<', '>Ventas<', '>Pedidos<'] as $titulo) {
            $this->assertDoesNotMatchRegularExpression('/'.preg_quote($titulo, '/').'/', preg_replace('/\s+/', '', $menu), $titulo);
        }

        // Zonas sigue andando y se abre desde el Tablero.
        $this->get(route('rutas.dashboard'))->assertOk()->assertSee('href="'.route('rutas.rutas.index').'"', false)->assertSee('Ver zonas');
        $this->get(route('rutas.rutas.index'))->assertOk();
    }

    public function test_ventas_es_una_pestana_del_tablero_solo_con_su_permiso(): void
    {
        // Jefatura tiene `rutas.ventas.ver`: la pestaña sale y, en Ventas, el Tablero queda resaltado.
        $jefatura = User::factory()->create(['activo' => true])->assignRole('jefatura');
        $this->actingAs($jefatura)->get(route('rutas.dashboard'))->assertOk()
            ->assertSeeInOrder(['>Resumen</a>', '>Viajes</a>', '>Mapa</a>', '>Ventas</a>'], false);
        $ventas = $this->actingAs($jefatura)->get(route('rutas.ventas.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('rutas.ventas.index'), '/').'"\s+aria-current="page"[^>]*>Ventas</u', $ventas);
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('rutas.dashboard'), '/').'"[^>]*aria-current="page"[^>]*>\s*Tablero/u', $this->sidebar($ventas));

        // Facturación y el jefe de vendedores no lo tienen: ni la pestaña ni la pantalla.
        foreach ([User::factory()->create(['activo' => true])->assignRole('facturacion'), $this->jefe] as $usuario) {
            foreach (['rutas.dashboard', 'rutas.viajes'] as $pantalla) {
                $this->actingAs($usuario)->get(route($pantalla))->assertOk()
                    ->assertDontSee('href="'.route('rutas.ventas.index').'"', false);
            }
            $this->actingAs($usuario)->get(route('rutas.ventas.index'))->assertForbidden();
        }
    }

    // ══════════════════════════════ «Todos los pedidos»

    public function test_los_enlaces_viejos_a_la_lista_de_pedidos_siguen_andando(): void
    {
        $viaje = SalidaRuta::create(['ruta_id' => $this->norte->id, 'fecha_inicio' => '2026-11-20', 'estado' => EstadoSalidaRuta::EnCurso]);
        $delViaje = $this->pedido($this->salaNorte, '2026-11-20', [[$this->galleta, '2']]);
        $delViaje->update(['salida_ruta_id' => $viaje->id]);
        $this->pedido($this->salaSur, '2026-11-21', [[$this->canilla, '3']]);

        $this->actingAs($this->jefe);
        $lista = $this->get(route('rutas.pedidos.index'))->assertOk()
            // Es la pestaña «Todos los pedidos» del panel, y en el menú queda resaltado «Clientes».
            ->assertSeeInOrder(['>Clientes</a>', '>Todos los pedidos</a>'], false)
            ->assertSee('Sala de prueba Norte')->assertSee('Sala de prueba Sur')->getContent();
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('rutas.pedidos.index'), '/').'"\s+aria-current="page"[^>]*>Todos los pedidos</u', $lista);
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('rutas.clientes.index'), '/').'"[^>]*aria-current="page"[^>]*>\s*Clientes/u', $this->sidebar($lista));

        // Desde un viaje (`?salida=`), por sala y lo que mandó la fábrica un día (`mandado_el`).
        $this->get(route('rutas.pedidos.index', ['salida' => $viaje->id]))->assertOk()
            ->assertSee('Solo los de una salida')->assertSee('Sala de prueba Norte')->assertDontSee('Sala de prueba Sur');
        $this->get(route('rutas.pedidos.index', ['sala' => '9002']))->assertOk()
            ->assertSee('Sala de prueba Sur')->assertDontSee('Sala de prueba Norte');
        $this->get(route('rutas.pedidos.index', ['mandado_el' => '2026-11-22']))->assertOk()
            ->assertSee('Nada coincide con estos filtros');
        // El detalle del pedido sigue abriendo, y desde la historia de la sala se llega a él.
        $this->get(route('rutas.pedidos.show', $delViaje))->assertOk();
        $this->get(route('rutas.clientes.show', $this->salaNorte))->assertOk()
            ->assertSee('href="'.route('rutas.pedidos.show', $delViaje).'"', false);
    }
}
