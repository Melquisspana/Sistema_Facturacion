<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoSalidaRuta;
use App\Enums\RolEnSalida;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Las mismas palabras en todo Rutas (revisión del 11/11/2026): «zona» es lo que antes era la
 * ruta y «viaje» lo que antes era la salida; el viaje está «Abierto» o «Cerrado»; las fechas
 * van en dd/mm/aaaa; y «sugerido» es solo la hoja que firma el gerente. Datos ficticios.
 */
class PalabrasRutasTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private PersonalRuta $personaAna;

    private User $jefe;

    private User $oficina;

    private Ruta $zona;

    private ClienteSucursal $sala;

    private ClienteSucursal $otraSala;

    private ClienteSucursal $sinZona;

    private Producto $galleta;

    protected function setUp(): void
    {
        parent::setUp();
        // Martes.
        $this->travelTo('2026-11-10 09:00:00');
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28', 'rutas.ambiente_ccf' => null]);
        $this->ana = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->personaAna = PersonalRuta::create(['nombre' => 'Ana Ficticia', 'user_id' => $this->ana->id]);
        $this->jefe = User::factory()->create(['activo' => true])->assignRole('jefe_vendedores');
        $this->oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');

        $dep = Departamento::firstOrCreate(['codigo' => '12'], ['nombre' => 'San Miguel', 'activo' => true]);
        $this->zona = Ruta::create(['nombre' => 'San Miguel']);
        $cliente = Cliente::factory()->create(['nombre' => 'Cliente de prueba']);
        $this->sala = $cliente->sucursales()->create(['nombre' => 'Sala de prueba Oriente', 'ruta_id' => $this->zona->id, 'departamento_id' => $dep->id]);
        $this->otraSala = $cliente->sucursales()->create(['nombre' => 'Tienda de prueba Oriente', 'ruta_id' => $this->zona->id, 'departamento_id' => $dep->id]);
        $this->sinZona = $cliente->sucursales()->create(['nombre' => 'Tienda de prueba sin zona']);
        UnidadMedida::firstOrCreate(['codigo' => '59'], ['nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01']);
    }

    private function sacar(ClienteSucursal $sala): PedidoSala
    {
        $this->actingAs($this->ana)->post(route('rutas.sacar-pedido.store', $sala), ['cantidades' => [$this->galleta->id => '12']])
            ->assertSessionHasNoErrors();

        return PedidoSala::where('cliente_sucursal_id', $sala->id)->latest('id')->firstOrFail();
    }

    private function ccf(ClienteSucursal $sala, string $total = '100.25'): Dte
    {
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Empresa de prueba'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $n = 700 + Dte::count();

        return Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $sala->cliente_id, 'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => now()->toDateString(), 'hora_emision' => '07:00:00', 'total_pagar' => $total,
        ]);
    }

    private function facturado(PedidoSala $pedido): Dte
    {
        $dte = $this->ccf($pedido->sala);
        $pedido->envios()->create(['numero' => 1, 'clave' => (string) Str::uuid(), 'fecha' => now()->toDateString(),
            'registrado_por' => $this->ana->id, 'dte_id' => $dte->id]);
        $pedido->update(['estado' => EstadoPedidoSala::Enviado]);

        return $dte;
    }

    // ---------------------------------------------------------------- B1: «sugerido» es la hoja

    public function test_la_pantalla_del_celular_se_llama_pedido_de_la_sala_y_no_sugerido(): void
    {
        $this->actingAs($this->ana)->get(route('rutas.sacar-pedido.create', $this->sala))->assertOk()
            ->assertSee('Pedido de Sala de prueba Oriente')->assertDontSee('Sugerido');
        $this->actingAs($this->ana)->get(route('rutas.sacar-pedido.tienda-nueva.create', ['nombre' => 'Tienda La Chapina']))->assertOk()
            ->assertSee('Pedido de una tienda nueva')->assertDontSee('Sugerido');
    }

    // ---------------------------------------------------------------- B2: los avisos al vendedor

    public function test_los_avisos_al_vendedor_dicen_viaje_y_por_entregar(): void
    {
        $pedido = $this->sacar($this->sala);
        $dte = $this->facturado($pedido);
        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo'])
            ->assertSessionHas('status', 'Entrega registrada: Entregado (viaje Martes · San Miguel).');
        $entrega = SalidaRutaEntrega::sole();
        $this->actingAs($this->ana)->patch(route('rutas.mi-salida.deshacer', [$entrega->salida, $entrega]))
            ->assertSessionHas('status', 'Se deshizo: el CCF vuelve a Por entregar.');
    }

    public function test_sin_viaje_el_aviso_de_la_entrega_no_nombra_una_salida(): void
    {
        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $this->ccf($this->sala)), ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'sala_cerrada'])
            ->assertSessionHas('status', 'Entrega registrada: No entregado.');
    }

    // ---------------------------------------------------------------- B3: la hoja que firma el gerente

    public function test_la_hoja_dice_zona_y_sin_zona_no_lleva_la_cajita(): void
    {
        $conZona = $this->sacar($this->sala);
        $this->actingAs($this->oficina)->get(route('rutas.pedidos.hoja', $conZona))->assertOk()
            ->assertSee('<dt>Zona</dt><dd>San Miguel</dd>', false)->assertDontSee('<dt>Ruta</dt>', false);

        $sinZona = $this->sacar($this->sinZona);
        $this->actingAs($this->oficina)->get(route('rutas.pedidos.hoja', $sinZona))->assertOk()
            ->assertDontSee('Sin ruta')->assertDontSee('<dt>Zona</dt>', false)->assertDontSee('<dt>Ruta</dt>', false);
    }

    // ---------------------------------------------------------------- B4: zona, viaje, abierto y cerrado

    public function test_el_tablero_dice_zona_y_viaje(): void
    {
        $viaje = $this->sacar($this->sala)->salida;

        $this->actingAs($this->jefe)->get(route('rutas.dashboard'))->assertOk()
            ->assertSee('zonas atrasadas')->assertSee('viaje abierto')->assertSee('Viajes abiertos')
            ->assertSee('Abierto desde hoy')->assertSee('todavía sin facturas')
            ->assertSee('Ver viaje')
            ->assertDontSee('Abrir hoja')->assertDontSee('0 de 0 entregados')->assertDontSee('rutas atrasadas')
            ->assertDontSee('viaje en camino')->assertDontSee('viajes en camino')->assertDontSee('En camino')
            ->assertDontSee('Cada ruta dice el suyo')->assertDontSee('Sin salidas');
        $this->actingAs($this->jefe)->get(route('rutas.dashboard', ['rutas' => 'atrasadas']))->assertOk()
            ->assertSee('Ninguna zona está atrasada.')->assertSee('Ver todas las zonas');

        // En el viaje nadie «encabeza».
        $viaje->participantes()->create(['rutas_personal_id' => PersonalRuta::create(['nombre' => 'Beto Ficticio'])->id, 'rol' => RolEnSalida::Acompanante]);
        $this->assertSame('Ana Ficticia y Beto Ficticio', $viaje->load('personal')->quienesVan());
    }

    public function test_los_pedidos_de_la_oficina_dicen_zona_y_viaje(): void
    {
        $this->actingAs($this->oficina)->get(route('rutas.pedidos.index'))->assertOk()
            ->assertSee('Los sacan los vendedores desde «Sacar pedido».')->assertDontSee('Mi salida');
        $conZona = $this->sacar($this->sala);
        $sinZona = $this->sacar($this->sinZona);

        $this->actingAs($this->oficina)->get(route('rutas.pedidos.index'))->assertOk()
            ->assertSeeInOrder(['Hasta', 'Zona', 'Todas'])
            ->assertSee('Sin zona')->assertDontSee('Sin ruta')->assertDontSee('>Ruta<', false);
        $this->actingAs($this->oficina)->get(route('rutas.pedidos.show', $conZona))->assertOk()
            ->assertSeeInOrder(['Zona', 'San Miguel', 'Viaje', 'Martes · San Miguel'])
            ->assertDontSee('>Ruta<', false)->assertDontSee('>Salida<', false);
        $this->actingAs($this->oficina)->get(route('rutas.pedidos.show', $sinZona))->assertOk()->assertSee('Sin zona')->assertDontSee('Sin ruta');
    }

    public function test_el_viaje_esta_abierto_o_cerrado_y_la_lista_son_todos_los_viajes(): void
    {
        $viaje = $this->sacar($this->sala)->salida;
        $this->assertSame('Abierto', $viaje->estadoLegible());

        $this->actingAs($this->oficina)->get(route('rutas.salidas.show', $viaje))->assertOk()
            ->assertSee('<h1', false)->assertSeeInOrder(['Viaje', 'Volver al tablero', 'Martes · San Miguel', 'Abierto'])
            ->assertSee('¿Cancelar este viaje?')
            ->assertDontSee('Hoja del viaje')->assertDontSee('Volver a rutas')->assertDontSee('En curso')->assertDontSee('¿Cancelar esta salida?');
        $this->actingAs($this->oficina)->get(route('rutas.salidas.index'))->assertOk()
            ->assertSee('Todos los viajes')->assertSee('Abierto')
            ->assertDontSee('Viajes anteriores')->assertDontSee('En curso')->assertDontSee('Finalizada');

        $viaje->update(['suma_pedidos' => false]);
        $viaje->finalizar('2026-11-10');
        $this->assertSame('Cerrado', $viaje->refresh()->estadoLegible());
        $this->actingAs($this->oficina)->get(route('rutas.salidas.index'))->assertOk()->assertSee('Cerrado')->assertDontSee('Finalizada');

        // La salida armada a mano de antes conserva su hoja y su estado de siempre.
        $aMano = SalidaRuta::create(['ruta_id' => $this->zona->id, 'fecha_inicio' => '2026-11-03', 'estado' => EstadoSalidaRuta::EnCurso]);
        $this->assertSame('Abierta', $aMano->estadoLegible());
        $this->actingAs($this->oficina)->get(route('rutas.salidas.show', $aMano))->assertOk()
            ->assertSee('Hoja de la salida')->assertSee('¿Cancelar esta salida?');
    }

    public function test_el_mapa_y_las_ventas_dicen_zona(): void
    {
        $this->actingAs($this->jefe)->get(route('rutas.mapa'))->assertOk()
            ->assertSeeInOrder(['Mes', 'Zona', 'Todas las zonas', 'Ver en el mapa', 'Zonas', 'Ver por', 'Departamentos', 'Distritos'])
            ->assertSee('Tocá un lugar del mapa o de la tabla para ver su ficha.')
            ->assertDontSee('Todas las rutas')->assertDontSee('>Ruta<', false)->assertDontSee('>Rutas</button>', false);
        $js = (string) file_get_contents(public_path('js/rutas-mapa.js'));
        foreach (["'Ninguna ruta pasa", "'<br>Ruta: '", "'Otras rutas'", 'Tocá una zona', 'de otras rutas'] as $viejo) {
            $this->assertStringNotContainsString($viejo, $js);
        }
        $this->assertStringContainsString("'<br>Zona: '", $js);

        $this->actingAs($this->oficina)->get(route('rutas.ventas.index'))->assertOk()
            ->assertSeeInOrder(['Productos', 'Salas', 'Zonas', 'Devoluciones']);
    }

    // ---------------------------------------------------------------- B5: fechas

    public function test_las_fechas_de_la_salida_van_en_dd_mm_aaaa(): void
    {
        $salida = SalidaRuta::create(['ruta_id' => $this->zona->id, 'fecha_inicio' => '2026-08-14', 'estado' => EstadoSalidaRuta::Planificada]);
        $this->assertSame('14/08/2026', $salida->periodoLegible());
        $salida->update(['fecha_fin_estimada' => '2026-08-16']);
        $this->assertSame('14/08/2026 al 16/08/2026', $salida->periodoLegible());
        $salida->update(['estado' => EstadoSalidaRuta::EnCurso]);
        $salida->finalizar('2026-09-02');
        $this->assertSame('14/08/2026 al 02/09/2026', $salida->refresh()->periodoLegible());
        $this->assertSame('San Miguel · 14/08/2026 al 02/09/2026', $salida->descripcionCorta());

        $this->actingAs($this->oficina)->get(route('rutas.salidas.index'))->assertOk()
            ->assertSee('14/08/2026')->assertSee('02/09/2026')->assertDontSee('ago 2026')->assertDontSee('Aug 2026')->assertDontSee('sep 2026');
    }

    public function test_las_entregas_del_viaje_llevan_la_fecha_completa_en_la_oficina(): void
    {
        $dte = $this->facturado($this->sacar($this->sala));
        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->oficina)->get(route('rutas.salidas.show', SalidaRutaEntrega::sole()->salida))->assertOk()
            ->assertSee('· 10/11/2026')->assertDontSee('10 nov');
    }

    // ---------------------------------------------------------------- B7: por qué no se corrige

    public function test_tus_pedidos_de_hoy_dice_por_que_no_se_puede_corregir(): void
    {
        $primero = $this->sacar($this->sala);
        $this->actingAs($this->ana)->get(route('rutas.sacar-pedido.index'))->assertOk()
            ->assertSee('Corregir')->assertDontSee('este lo corrige la oficina')->assertDontSee('Ya está en la fábrica');

        $segundo = $this->sacar($this->otraSala);
        $this->actingAs($this->ana)->get(route('rutas.sacar-pedido.index'))->assertOk()
            ->assertSeeInOrder(['Sala de prueba Oriente', 'Ya sacaste otro pedido: este lo corrige la oficina.'])
            ->assertSee('href="'.route('rutas.sacar-pedido.edit', $segundo).'"', false)
            ->assertDontSee('href="'.route('rutas.sacar-pedido.edit', $primero).'"', false);

        $segundo->update(['armando_desde' => now()]);
        $this->actingAs($this->ana)->get(route('rutas.sacar-pedido.index'))->assertOk()
            ->assertSeeInOrder(['Tienda de prueba Oriente', 'Ya está en la fábrica: si cambió algo, avisá a la oficina.']);
    }

    // ---------------------------------------------------------------- B8: textos sueltos

    public function test_la_pestana_viajes_y_las_zonas_con_sus_textos(): void
    {
        $viaje = $this->sacar($this->sala)->salida;
        $this->actingAs($this->jefe)->get(route('rutas.viajes'))->assertOk()
            ->assertSee('Ver su pedido')->assertDontSee('Ver u ocultar');
        $this->sacar($this->otraSala);
        // Una factura de la zona anotada en el viaje, que no salió de sus pedidos.
        $viaje->entregas()->create(['dte_id' => ($suelta = $this->ccf($this->otraSala))->id, 'cliente_sucursal_id' => $suelta->cliente_sucursal_id]);
        $this->actingAs($this->jefe)->get(route('rutas.viajes'))->assertOk()
            ->assertSee('Ver sus 2 pedidos')
            ->assertSee('Además, 1 factura de la zona, que no salió de estos pedidos, sigue sin entregar.')
            ->assertDontSee('anotada en el viaje');

        $this->actingAs($this->oficina)->get(route('rutas.rutas.index'))->assertOk()
            ->assertSee('placeholder="Ej.: Oriente"', false)->assertDontSee('placeholder="Puerto"', false);
    }

    // ---------------------------------------------------------------- B6: la tarjeta de «Por entregar»

    public function test_la_tarjeta_de_por_entregar_lleva_la_sala_de_titulo_y_el_pdf_del_ccf(): void
    {
        $dte = $this->facturado($this->sacar($this->sala));
        $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSeeInOrder(['<h3', 'Sala de prueba Oriente', '</h3>', 'Cliente de prueba', 'Hoja para firmar', 'PDF del CCF'], false)
            ->assertSee(now()->format('d/m/Y'))
            ->assertDontSee('Descargar PDF');

        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo'])
            ->assertSessionHasNoErrors();
        // «Deshacer» con color de enlace, también en modo oscuro (antes era gris claro sobre oscuro).
        $html = $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<button class="[^"]*min-h-11[^"]*text-indigo-600[^"]*underline[^"]*dark:text-indigo-300[^"]*">Deshacer<\/button>/', $html);
    }

    public function test_a_la_segunda_vez_que_no_se_pudo_el_jefe_lo_ve_en_su_tablero(): void
    {
        $this->actingAs($this->jefe)->get(route('rutas.dashboard'))->assertOk()->assertDontSee('no se pudo entregar');
        $dte = $this->ccf($this->sala);
        foreach (['2026-11-10 10:00:00', '2026-11-11 10:00:00'] as $momento) {
            $this->travelTo($momento);
            $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'sala_cerrada'])
                ->assertSessionHasNoErrors();
        }
        $this->travelTo('2026-11-12 08:00:00');

        $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('No se pudo entregar 2 veces. El jefe ya lo ve en su tablero.')->assertDontSee('avisale al jefe');
        $this->actingAs($this->jefe)->get(route('rutas.dashboard'))->assertOk()
            ->assertSee('1 factura no se pudo entregar 2 veces o más')
            ->assertSee('href="'.route('rutas.viajes').'#fallas-titulo"', false);
        $this->actingAs($this->jefe)->get(route('rutas.viajes'))->assertOk()->assertSee('id="fallas-titulo"', false);
    }

    // ---------------------------------------------------------------- B9: la historia de la sala

    public function test_la_historia_de_la_sala_dice_cuanto_pidio_como_pago_y_cuantas_debe(): void
    {
        $this->actingAs($this->ana)->get(route('rutas.sacar-pedido.create', $this->otraSala))->assertOk()
            ->assertSeeInOrder(['Último pedido', 'Ninguno todavía', 'Última entrega', 'Sin entregas', 'Facturas por cobrar', 'Ninguna']);

        $dte = $this->facturado($this->sacar($this->sala));
        $conQuedan = $this->ccf($this->sala, '75.00');
        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $conQuedan), ['resultado' => 'entregado', 'forma_cobro' => 'sin_cobro'])
            ->assertSessionHasNoErrors();
        $this->travelTo('2026-11-10 11:00:00');
        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo'])
            ->assertSessionHasNoErrors();

        // Diez días después (el viaje ya no suma): la historia, sin precios.
        $this->travelTo('2026-11-20 09:00:00');
        $this->actingAs($this->ana)->get(route('rutas.sacar-pedido.create', $this->sala))->assertOk()
            ->assertSeeInOrder(['Último pedido', '10/11/2026 · 12 cajas', 'Última entrega', '10/11/2026 · efectivo', 'Compra cada', 'Facturas por cobrar', '1 factura'])
            ->assertDontSee('100.25')->assertDontSee('75.00')->assertDontSee('Precio');
    }

    // ---------------------------------------------------------------- B10: guía de pantallas

    public function test_blancos_de_toque_de_44_y_textos_que_no_se_parten(): void
    {
        $viaje = $this->sacar($this->sala)->salida;
        $this->ccf($this->sala);
        $html = $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<label class="[^"]*min-h-11[^"]*">\s*<input type="checkbox" name="trae_nota_averia"/', $html);
        $this->assertMatchesRegularExpression('/placeholder="Otro motivo…"[^>]*class="[^"]*min-h-11/', $html);

        $viaje->gastos()->create(['fecha' => '2026-11-10', 'personal_id' => $this->personaAna->id, 'concepto' => 'gasolina', 'monto' => '20.00', 'registrado_por' => $this->ana->id]);
        $this->actingAs($this->ana)->get(route('rutas.mi-salida.dia'))->assertOk()
            ->assertSee('<p class="whitespace-nowrap font-semibold">Gasolina · $20.00</p>', false);

        $lista = $this->actingAs($this->oficina)->get(route('rutas.salidas.index'))->assertOk()
            ->assertSee('<th class="whitespace-nowrap py-3 px-4 text-center">CCF entregados</th>', false)->getContent();
        $this->assertSame(2, preg_match_all('/<select id="f-(zona|estado)"[^>]*class="[^"]*min-h-11/', $lista));
        $this->assertMatchesRegularExpression('/<button class="[^"]*min-h-11[^"]*border-gray-300[^"]*bg-white[^"]*">Filtrar<\/button>/', $lista);
    }

    public function test_los_titulos_de_rutas_van_en_h1(): void
    {
        $admin = User::factory()->create(['activo' => true])->assignRole('administrador');
        $this->actingAs($admin)->get(route('rutas.personal.show', $this->personaAna))->assertOk()->assertSee('Ana Ficticia</h1>', false);
        $this->actingAs($admin)->get(route('rutas.personal.edit', $this->personaAna))->assertOk()->assertSee('Ana Ficticia</h1>', false);
        $this->actingAs($this->oficina)->get(route('rutas.salidas.index'))->assertOk()->assertSee('Todos los viajes</h1>', false);
    }
}
