<?php

namespace Tests\Feature\Rutas;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoSalidaRuta;
use App\Enums\PropositoSalida;
use App\Enums\ResultadoEntrega;
use App\Enums\RolEnSalida;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\PpqAlbaran;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Services\Rutas\DiaVendedor;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\RitmoRutas;
use App\Services\Rutas\TableroViajes;
use App\Services\Rutas\Viajes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El cierre del flujo único de Rutas (Melqui, 10/11/2026; decisión 0010): el menú corto, las
 * pantallas retiradas (que ya no redirigen), las zonas en lugar de las rutas fijas, la pestaña Viajes
 * con sus avisos, la misma regla de «entregado» en todos lados, el viaje que no se liquida y
 * los gastos en el PDF del día. Datos ficticios.
 */
class FlujoUnicoRutasTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private PersonalRuta $persona;

    private User $jefe;

    private User $oficina;

    private Ruta $zona;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        // Martes.
        $this->travelTo('2026-11-10 09:00:00');
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-06-01', 'rutas.ambiente_ccf' => null]);
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos Ficticio', 'user_id' => $this->vendedor->id]);
        $this->jefe = User::factory()->create(['activo' => true])->assignRole('jefe_vendedores');
        $this->oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->zona = Ruta::create(['nombre' => 'San Miguel']);
        $this->cliente = Cliente::factory()->create(['nombre' => 'Cliente de prueba']);
    }

    private function sala(string $nombre, bool $conZona = true, array $extra = []): ClienteSucursal
    {
        return $this->cliente->sucursales()->create(['nombre' => $nombre, 'ruta_id' => $conZona ? $this->zona->id : null] + $extra);
    }

    /** Un viaje abierto por el vendedor. Sin `$zona`, de una sala que ninguna zona cubre. */
    private function viaje(string $desde = '2026-11-09', bool $conZona = true, bool $suma = true): SalidaRuta
    {
        $viaje = SalidaRuta::create([
            'ruta_id' => $conZona ? $this->zona->id : null, 'fecha_inicio' => $desde, 'estado' => EstadoSalidaRuta::EnCurso,
            'proposito' => PropositoSalida::Ambos, 'automatica' => true, 'suma_pedidos' => $suma,
        ]);
        $viaje->participantes()->create(['rutas_personal_id' => $this->persona->id, 'rol' => RolEnSalida::Responsable]);

        return $viaje;
    }

    private function pedido(SalidaRuta $viaje, ClienteSucursal $sala, string $fecha = '2026-11-09', EstadoPedidoSala $estado = EstadoPedidoSala::Registrado): PedidoSala
    {
        return $viaje->pedidos()->create([
            'cliente_sucursal_id' => $sala->id, 'ruta_id' => $sala->ruta_id, 'sacado_por_id' => $this->persona->id,
            'registrado_por' => $this->vendedor->id, 'fecha' => $fecha, 'estado' => $estado,
        ]);
    }

    private function ccf(ClienteSucursal $sala, string $fecha = '2026-11-09', string $total = '50.00'): Dte
    {
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Empresa de prueba'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $n = 700 + Dte::withTrashed()->count();

        return Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $this->cliente->id, 'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => $fecha, 'hora_emision' => '07:00:00', 'subtotal' => $total, 'total_pagar' => $total,
        ]);
    }

    /** La fábrica mandó el pedido y se facturó en un CCF. */
    private function facturado(PedidoSala $pedido, string $fecha = '2026-11-09'): Dte
    {
        $dte = $this->ccf($pedido->sala, $fecha);
        $pedido->envios()->create(['numero' => 1, 'clave' => (string) Str::uuid(), 'fecha' => $fecha, 'registrado_por' => $this->vendedor->id, 'dte_id' => $dte->id]);
        $pedido->update(['estado' => EstadoPedidoSala::Enviado]);

        return $dte;
    }

    private function entrega(SalidaRuta $salida, Dte $dte, array $extra = []): void
    {
        $salida->entregas()->create($extra + ['dte_id' => $dte->id, 'cliente_sucursal_id' => $dte->cliente_sucursal_id,
            'resultado' => ResultadoEntrega::Entregado, 'fecha_resultado' => '2026-11-10 08:00:00', 'entregado_por_id' => $this->persona->id,
            'registrado_por' => $this->vendedor->id]);
    }

    // ══════════════════════════════ el menú

    public function test_el_menu_del_vendedor_es_inicio_sacar_pedido_y_por_entregar(): void
    {
        $this->actingAs($this->vendedor)->get(route('rutas.mi-salida.index'))->assertOk()
            ->assertSee('<h1 class="text-3xl font-bold">Inicio</h1>', false)
            ->assertSeeInOrder(['>Inicio</a>', '>Sacar pedido</a>', '>Por entregar</a>'], false)
            ->assertDontSee('Mi salida')
            ->assertDontSee('Próximas salidas');
    }

    public function test_el_menu_de_la_oficina_es_corto_y_sin_armar_salidas(): void
    {
        $menu = fn (User $usuario) => $this->actingAs($usuario)->get(route('rutas.dashboard'))->assertOk();
        $entrada = fn (string $ruta) => '/href="'.preg_quote(route($ruta), '/').'"[^>]*>\s*Menú del vendedor/u';

        // El Tablero va primero. Lo del celular es UNA sola entrada, al final (11/11/2026).
        // El jefe también sale a vender: le abre el Inicio del celular.
        // «Todos los viajes» salió del menú (12/11/2026): se abre desde Tablero › Viajes.
        $jefe = $menu($this->jefe)->assertSeeInOrder(['Tablero', 'Pedidos', 'Zonas', 'Menú del vendedor'])
            ->assertDontSee('Todos los viajes')
            ->assertDontSee('href="'.route('rutas.salidas.index').'"', false)
            ->assertSee('href="'.route('rutas.rutas.index').'"', false)
            ->assertDontSee('href="'.route('rutas.sacar-pedido.index').'"', false)
            ->assertDontSee('href="'.route('rutas.por-entregar.index').'"', false);
        $this->assertMatchesRegularExpression($entrada('rutas.mi-salida.index'), $jefe->getContent());
        // La oficina no saca pedidos ni entrega: su entrada abre lo único que puede mirar, «Por entregar».
        $oficina = $menu($this->oficina)->assertSeeInOrder(['Tablero', 'Pedidos', 'Zonas', 'Menú del vendedor'])
            ->assertDontSee('href="'.route('rutas.mi-salida.index').'"', false)
            ->assertDontSee('href="'.route('rutas.sacar-pedido.index').'"', false)
            // Ventas es solo de Administrador y Jefatura (11/11/2026): a Facturación ni le sale.
            ->assertDontSee('href="'.route('rutas.ventas.index').'"', false);
        $this->assertMatchesRegularExpression($entrada('rutas.por-entregar.index'), $oficina->getContent());
        $this->actingAs($this->oficina)->get(route('rutas.ventas.index'))->assertForbidden();
        $menu(User::factory()->create(['activo' => true])->assignRole('jefatura'))
            ->assertSeeInOrder(['Tablero', 'Pedidos', 'Ventas', 'Zonas', 'Menú del vendedor'])
            ->assertSee('href="'.route('rutas.ventas.index').'"', false);

        foreach ([$this->jefe, $this->oficina] as $usuario) {
            $menu($usuario)->assertDontSee('Salidas anteriores')->assertDontSee('Configurar rutas')
                ->assertDontSee('Armar salida')->assertDontSee('Salir a esta ruta')->assertDontSee('por liquidar');
        }
    }

    // ══════════════════════════════ lo retirado ya no existe

    /** Desde el 11/11/2026 los enlaces guardados de las pantallas de antes ya no redirigen. */
    public function test_las_pantallas_retiradas_ya_no_existen_ni_redirigen(): void
    {
        $viaje = $this->viaje();
        $manual = SalidaRuta::create(['ruta_id' => $this->zona->id, 'fecha_inicio' => '2026-11-12', 'estado' => EstadoSalidaRuta::Planificada]);
        $manual->participantes()->create(['rutas_personal_id' => $this->persona->id, 'rol' => RolEnSalida::Responsable]);

        // El vendedor: ni el viaje ni la salida de antes tienen hoja, y «Tu próxima salida» ya no existe.
        $this->actingAs($this->vendedor);
        $this->get('/rutas/mi-salida/'.$viaje->id)->assertNotFound();
        $this->get('/rutas/mi-salida/'.$manual->id)->assertNotFound();
        $this->post('/rutas/mi-salida/'.$manual->id.'/empezar')->assertNotFound();
        $this->assertSame(EstadoSalidaRuta::Planificada, $manual->refresh()->estado);

        // La oficina: armar, editar, asignar salas y la ficha de una ruta. Ninguna abre ni redirige
        // (404, o 405 donde la misma dirección todavía recibe el guardado de la zona).
        $this->actingAs($this->oficina);
        foreach (['/rutas/salidas/crear', '/rutas/salidas/'.$manual->id.'/editar', '/rutas/rutas/'.$this->zona->id.'/editar', '/rutas-cobros', '/rutas-cobros/salidas/crear'] as $vieja) {
            $this->get($vieja)->assertNotFound();
        }
        foreach (['/rutas/rutas/crear', '/rutas/rutas/asignacion', '/rutas/rutas/'.$this->zona->id] as $vieja) {
            $this->get($vieja)->assertStatus(405);
        }
        $this->patch('/rutas/salidas/'.$manual->id.'/finalizar')->assertNotFound();

        // Y lo que escribía ya no existe.
        $this->post('/rutas/salidas', ['ruta_id' => $this->zona->id, 'personal' => [$this->persona->id]])->assertStatus(405);
        $this->put('/rutas/salidas/'.$manual->id, ['ruta_id' => $this->zona->id])->assertStatus(405);
        $this->post('/rutas/salidas/'.$manual->id.'/entregas/cargar')->assertStatus(405);
        $this->post('/rutas/rutas/asignacion', ['sucursales' => [1]])->assertStatus(405);
        $this->post('/rutas/rutas/'.$this->zona->id.'/salas', ['sucursales' => [1]])->assertNotFound();
        $this->assertSame(2, SalidaRuta::count());
    }

    // ══════════════════════════════ zonas

    public function test_zonas_solo_toca_nombre_y_cobertura(): void
    {
        $dep = Departamento::create(['codigo' => '12', 'nombre' => 'San Miguel', 'activo' => true]);
        $this->actingAs($this->oficina)->get(route('rutas.rutas.index'))->assertOk()
            ->assertSee('<h1', false)->assertSee('Zonas')
            ->assertSee('Lugares que cubre')
            ->assertSee('action="'.route('rutas.rutas.cobertura.store', $this->zona).'"', false)
            ->assertDontSee('días')->assertDontSee('Asignar')->assertDontSee('Agregar salas');

        $this->post(route('rutas.rutas.cobertura.store', $this->zona), ['departamento_id' => $dep->id])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->zona->coberturas()->count());

        // Una sala sin zona puesta toma la que cubre su lugar: así se nombra su viaje.
        $sala = $this->sala('Tienda de prueba cubierta', false, ['departamento_id' => $dep->id]);
        $this->assertSame($this->zona->id, app(Viajes::class)->zonaId($sala));
        $this->assertNull(app(Viajes::class)->zonaId($this->sala('Tienda de prueba lejana', false)));
    }

    // ══════════════════════════════ por entregar, sin asignar salas

    public function test_una_sala_sin_zona_se_entrega_igual(): void
    {
        $dte = $this->ccf($this->sala('Tienda de prueba sin zona', false));

        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('Sin zona')
            ->assertSee('action="'.route('rutas.por-entregar.marcar', $dte).'"', false)
            ->assertDontSee('pedile a la oficina que se la asigne');
        $this->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo'])->assertSessionHasNoErrors();

        $salida = SalidaRuta::sole();
        $this->assertNull($salida->ruta_id);
        $this->assertTrue($salida->esAutomatica());
        $this->assertFalse($salida->esViaje());
        $this->assertSame(ResultadoEntrega::Entregado, $salida->entregas()->sole()->resultado);

        // La segunda del mismo día va a la misma salida; otro vendedor arma la suya.
        $otro = $this->ccf($this->sala('Otra tienda de prueba sin zona', false));
        $this->post(route('rutas.por-entregar.marcar', $otro), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo'])->assertSessionHasNoErrors();
        $this->assertSame(1, SalidaRuta::count());
        $this->assertSame(2, $salida->entregas()->count());
    }

    public function test_por_entregar_agrupa_por_la_zona_que_cubre_el_lugar(): void
    {
        $dep = Departamento::create(['codigo' => '12', 'nombre' => 'San Miguel', 'activo' => true]);
        $this->zona->coberturas()->create(['departamento_id' => $dep->id]);
        $cubierta = $this->ccf($this->sala('Tienda de prueba cubierta', false, ['departamento_id' => $dep->id]));
        // Su viaje es de San Miguel: «Toda la zona» trae la tienda cubierta, sin zona puesta.
        $this->pedido($this->viaje(), $this->sala('Sala de prueba Oriente'));

        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index', ['ver' => 'zona']))->assertOk()
            ->assertSee($cubierta->numero_control)->assertSee('San Miguel')->assertDontSee('Sin zona');
    }

    /**
     * «CCF por entregar» de cada zona (tablero y aviso del Panel) cuenta todas las salas, con
     * el criterio del tablero: su ruta, la zona que cubre su lugar o «Sin zona» (clave 0).
     */
    public function test_los_ccf_por_entregar_de_cada_zona_cuentan_las_salas_sin_ruta_fija(): void
    {
        $dep = Departamento::create(['codigo' => '12', 'nombre' => 'San Miguel', 'activo' => true]);
        $this->zona->coberturas()->create(['departamento_id' => $dep->id]);
        $this->ccf($this->sala('Sala de prueba Oriente'), total: '100.00');
        $this->ccf($this->sala('Tienda de prueba cubierta', false, ['departamento_id' => $dep->id]), total: '40.00');
        $this->ccf($this->sala('Tienda de prueba lejana', false), total: '7.00');
        // La sala inactiva no cuenta, como antes.
        $this->ccf($this->sala('Sala de prueba cerrada', true, ['activo' => false]), total: '900.00');

        $pendientes = app(EntregasCcf::class)->pendientesPorRuta();
        $this->assertSame(['cantidad' => 2, 'monto' => 140.0], $pendientes[$this->zona->id]);
        $this->assertSame(['cantidad' => 1, 'monto' => 7.0], $pendientes[0]);

        $this->actingAs($this->jefe)->get(route('rutas.dashboard'))->assertOk()
            ->assertSee('2 CCF por entregar · $140.00')
            ->assertSeeInOrder(['1 sala activa sin zona.', '1 CCF por entregar · $7.00']);
    }

    // ══════════════════════════════ la pestaña Viajes

    public function test_viajes_avisa_lo_que_no_se_pudo_entregar_dos_veces(): void
    {
        $viaje = $this->viaje();
        $sala = $this->sala('Sala de prueba Oriente');
        $dosVeces = $this->ccf($sala, '2026-11-06');
        $unaVez = $this->ccf($this->sala('Sala de prueba Norte'), '2026-11-06');
        $otra = SalidaRuta::create(['ruta_id' => $this->zona->id, 'fecha_inicio' => '2026-11-07', 'estado' => EstadoSalidaRuta::Finalizada, 'fecha_fin_real' => '2026-11-07']);
        foreach ([[$otra, $dosVeces], [$viaje, $dosVeces], [$viaje, $unaVez]] as [$salida, $dte]) {
            $salida->entregas()->create(['dte_id' => $dte->id, 'cliente_sucursal_id' => $dte->cliente_sucursal_id,
                'resultado' => ResultadoEntrega::NoEntregado, 'motivo_no_entrega' => 'sala_cerrada', 'fecha_resultado' => '2026-11-08 10:00:00']);
        }

        $fallas = app(TableroViajes::class)->calcular()['fallas'];
        $this->assertEquals([[$dosVeces->id, 2]], $fallas->map(fn (array $f) => [$f['dte']->id, $f['veces']])->all());

        $this->actingAs($this->jefe)->get(route('rutas.viajes'))->assertOk()
            ->assertSee('No se pudieron entregar')->assertSee('No se pudo 2 veces')
            ->assertSee($dosVeces->numero_control)->assertSee('Sala de prueba Oriente')
            ->assertDontSee($unaVez->numero_control);

        // Entregada, deja de avisar.
        $viaje->entregas()->where('dte_id', $dosVeces->id)->update(['resultado' => ResultadoEntrega::Entregado->value, 'motivo_no_entrega' => null]);
        $this->actingAs($this->jefe)->get(route('rutas.viajes'))->assertOk()->assertDontSee('No se pudieron entregar');
    }

    public function test_viajes_lista_los_quedan_por_cobrar_por_fecha_de_pago(): void
    {
        $viaje = $this->viaje();
        $tarde = $this->ccf($this->sala('Tienda de prueba que paga tarde'), '2026-11-09', '310.80');
        $pronto = $this->ccf($this->sala('Tienda de prueba que paga pronto'), '2026-11-09', '96.40');
        $vencido = $this->ccf($this->sala('Tienda de prueba vencida'), '2026-11-01', '20.00');
        $pagado = $this->ccf($this->sala('Tienda de prueba ya pagada'), '2026-11-01', '77.00');
        $efectivo = $this->ccf($this->sala('Tienda de prueba al contado'), '2026-11-09', '15.00');
        $this->entrega($viaje, $tarde, ['quedan_numero' => 'Q-200', 'quedan_fecha_pago' => '2026-12-05']);
        $this->entrega($viaje, $pronto, ['quedan_numero' => 'Q-100', 'quedan_fecha_pago' => '2026-11-20']);
        $this->entrega($viaje, $vencido, ['quedan_numero' => null, 'quedan_fecha_pago' => '2026-11-08']);
        $this->entrega($viaje, $pagado, ['quedan_numero' => 'Q-050', 'quedan_fecha_pago' => '2026-11-05']);
        $this->entrega($viaje, $efectivo, ['forma_cobro' => 'efectivo', 'monto_esperado' => '15.00', 'monto_cobrado' => '15.00']);
        // «Facturas por cobrar» ya la dio por pagada: sale de la lista.
        DB::table('cobro_documentos')->insert(['dte_id' => $pagado->id, 'cliente_id' => $this->cliente->id, 'tipo_dte' => '03', 'ejercicio_control' => 2026,
            'numero_control' => $pagado->numero_control, 'numero_control_norm' => $pagado->numero_control, 'monto' => '77.00',
            'monto_pagado' => '77.00', 'pago_estado' => EstadoPagoCobro::Pagado->value, 'created_at' => now(), 'updated_at' => now()]);
        // Y otra que sigue pendiente en «Facturas por cobrar»: sigue en la lista.
        DB::table('cobro_documentos')->insert(['dte_id' => $pronto->id, 'cliente_id' => $this->cliente->id, 'tipo_dte' => '03', 'ejercicio_control' => 2026,
            'numero_control' => $pronto->numero_control, 'numero_control_norm' => $pronto->numero_control, 'monto' => '96.40',
            'pago_estado' => EstadoPagoCobro::Pendiente->value, 'created_at' => now(), 'updated_at' => now()]);

        $quedan = app(TableroViajes::class)->calcular()['quedan'];
        $this->assertEquals([$vencido->id, $pronto->id, $tarde->id], $quedan->pluck('dte_id')->all());

        $this->actingAs($this->oficina)->get(route('rutas.viajes'))->assertOk()
            ->assertSeeInOrder(['Quedan por cobrar', 'Tienda de prueba vencida', 'Venció el 08/11/2026',
                'Tienda de prueba que paga pronto', 'Quedan n.º Q-100', '$96.40', 'Paga el 20/11/2026',
                'Tienda de prueba que paga tarde', 'Quedan n.º Q-200', '$310.80', 'Paga el 05/12/2026'])
            ->assertSee($pronto->numero_control)
            ->assertDontSee('Tienda de prueba ya pagada')->assertDontSee('Tienda de prueba al contado');
    }

    public function test_sin_quedan_lo_dice(): void
    {
        $this->actingAs($this->oficina)->get(route('rutas.viajes'))->assertOk()->assertSee('No hay quedan de tiendas por cobrar.');
    }

    /** La pestaña Viajes y el cierre del viaje usan la misma regla de «entregado». */
    public function test_el_tablero_y_el_cierre_del_viaje_dicen_lo_mismo(): void
    {
        $coinciden = function (SalidaRuta $viaje, bool $esperado, string $caso): void {
            $fila = app(TableroViajes::class)->calcular()['abiertos']->firstWhere('salida.id', $viaje->id);
            $this->assertSame($esperado, app(Viajes::class)->todoEntregado($viaje->fresh()), $caso.': el cierre');
            $this->assertSame($esperado, $fila['todoEntregado'], $caso.': el tablero');
        };

        $viaje = $this->viaje(suma: false);
        $pedido = $this->pedido($viaje, $this->sala('Sala de prueba Oriente'));
        $coinciden($viaje, false, 'Recién sacado');

        $dte = $this->facturado($pedido);
        $coinciden($viaje, false, 'Facturado sin entregar');

        // El albarán de avería no entrega; el de entrega del supermercado, sí.
        PpqAlbaran::create(['numero_albaran' => 'AC02/0232/00/1111', 'dte_id' => $dte->id, 'monto_albaran' => 50, 'fecha_albaran' => '2026-11-10', 'origen' => 'gmail']);
        $coinciden($viaje, false, 'Con albarán de avería');
        PpqAlbaran::create(['numero_albaran' => 'AC01/0232/00/2222', 'dte_id' => $dte->id, 'monto_albaran' => 50, 'fecha_albaran' => '2026-11-10', 'origen' => 'gmail']);
        $coinciden($viaje, true, 'Con albarán de entrega');

        // Un CCF de la zona que se anotó en el viaje sin ser de sus pedidos también lo deja abierto.
        $suelto = $this->ccf($this->sala('Sala de prueba Norte'));
        $fila = $viaje->entregas()->create(['dte_id' => $suelto->id, 'cliente_sucursal_id' => $suelto->cliente_sucursal_id,
            'resultado' => ResultadoEntrega::NoEntregado, 'motivo_no_entrega' => 'sala_cerrada', 'fecha_resultado' => '2026-11-10 10:00:00']);
        $coinciden($viaje, false, 'Con un CCF suelto sin entregar');
        $this->assertSame(1, app(TableroViajes::class)->calcular()['abiertos']->first()['sueltos']);
        $this->actingAs($this->jefe)->get(route('rutas.viajes'))->assertOk()->assertSee('Además, 1 factura de la zona, que no salió de estos pedidos, sigue sin entregar.');

        // Entregado en otra salida, por quien sea: cuenta.
        $otra = SalidaRuta::create(['ruta_id' => $this->zona->id, 'fecha_inicio' => '2026-11-10', 'estado' => EstadoSalidaRuta::EnCurso]);
        $this->entrega($otra, $suelto);
        $coinciden($viaje, true, 'El suelto se entregó en otra salida');
        $this->actingAs($this->jefe)->get(route('rutas.viajes'))->assertOk()->assertSee('Todo entregado: se cierra solo mañana temprano.');

        // Lo que la fábrica no trae no espera nada; un pedido mandado sin ningún envío, sí.
        $this->pedido($viaje, $this->sala('Sala de prueba Sur'), estado: EstadoPedidoSala::NoSeTrae);
        $coinciden($viaje, true, 'Con un pedido que no se trae');
        $this->pedido($viaje, $this->sala('Sala de prueba Centro'), estado: EstadoPedidoSala::Enviado);
        $coinciden($viaje, false, 'Con un pedido mandado y sin facturar');
        $this->assertNotNull($fila);
    }

    // ══════════════════════════════ el viaje no se liquida

    public function test_el_viaje_no_se_liquida_ni_cierra_dias_y_no_aparece_por_liquidar(): void
    {
        $viaje = $this->viaje(suma: false);
        $dte = $this->ccf($this->sala('Sala de prueba Oriente'));
        $this->entrega($viaje, $dte, ['forma_cobro' => 'efectivo', 'monto_esperado' => '50.00', 'monto_cobrado' => '50.00']);
        $entrega = $viaje->entregas()->sole();

        $admin = User::factory()->create(['activo' => true])->assignRole('administrador');
        $this->actingAs($admin);
        // En curso y también después de cerrarse solo.
        foreach ([EstadoSalidaRuta::EnCurso, EstadoSalidaRuta::Finalizada] as $estado) {
            $viaje->update(['estado' => $estado, 'fecha_fin_real' => $estado === EstadoSalidaRuta::Finalizada ? '2026-11-10' : null]);

            $this->get(route('rutas.salidas.show', $viaje))->assertOk()
                ->assertSee('Volver al tablero')->assertSee('Lunes · San Miguel')->assertDontSee('Hoja del viaje')
                ->assertDontSee('Terminar ruta')->assertDontSee('Terminar día')->assertDontSee('Cambiar quiénes van')
                ->assertDontSee('Buscar CCF nuevos de la ruta');
            // Las pantallas de cierre y liquidación ya no existen para nadie (11/11/2026).
            $this->post('/rutas/salidas/'.$viaje->id.'/terminar', ['entrega_dinero_personal_id' => $this->persona->id])->assertNotFound();
            $this->post('/rutas/salidas/'.$viaje->id.'/cierre', ['fecha' => '2026-11-10',
                'entregas' => [$entrega->id => ['monto_cobrado' => '50.00', 'forma_cobro' => 'efectivo']]])->assertNotFound();
            $this->get(route('rutas.dashboard'))->assertOk()->assertDontSee('por liquidar');
            $this->get(route('rutas.salidas.index'))->assertOk()->assertDontSee('Por liquidar')->assertDontSee('Solo por liquidar');
        }
        $this->assertFalse($viaje->refresh()->estaLiquidada());
        $this->assertSame(0, $viaje->cierres()->count());
        // Abierto de nuevo: el día no quedó cerrado, se puede seguir entregando.
        $this->assertFalse($viaje->diaCerrado());
    }

    /**
     * La salida armada a mano de antes, ya terminada y liquidada con el flujo viejo (hay una así
     * en producción): se sigue abriendo en «Todos los viajes» y en su detalle, solo para leer,
     * aunque ya no existan las pantallas de cierre de día ni la liquidación.
     */
    public function test_una_salida_de_antes_ya_liquidada_se_abre_solo_para_leer(): void
    {
        $manual = SalidaRuta::create([
            'ruta_id' => $this->zona->id, 'fecha_inicio' => '2026-10-05', 'fecha_fin_real' => '2026-10-07', 'estado' => EstadoSalidaRuta::Finalizada,
            'liquidada_en' => '2026-10-07 18:00:00', 'liquidada_por' => $this->oficina->id, 'entrega_dinero_personal_id' => $this->persona->id,
        ]);
        $manual->participantes()->create(['rutas_personal_id' => $this->persona->id, 'rol' => RolEnSalida::Responsable]);
        $sala = $this->sala('Sala de prueba Oriente');
        $entregado = $this->ccf($sala, '2026-10-04');
        $this->entrega($manual, $entregado, ['fecha_resultado' => '2026-10-05 10:00:00', 'origen_registro' => 'oficina',
            'forma_cobro' => 'efectivo', 'monto_esperado' => '50.00', 'monto_cobrado' => '50.00']);
        // Una entrega que quedó sin origen ni resultado.
        $suelta = $this->ccf($sala, '2026-10-04');
        $manual->entregas()->create(['dte_id' => $suelta->id, 'cliente_sucursal_id' => $sala->id]);
        foreach (['2026-10-05', '2026-10-06'] as $dia) {
            $manual->cierres()->create(['fecha' => $dia, 'efectivo_esperado' => '50.00', 'efectivo_cobrado' => '50.00', 'a_entregar' => '50.00',
                'cerrado_por' => $this->oficina->id, 'cerrado_en' => $dia.' 18:00:00']);
        }

        foreach ([$this->oficina, $this->jefe, User::factory()->create(['activo' => true])->assignRole('administrador')] as $usuario) {
            $this->actingAs($usuario)->get(route('rutas.salidas.index'))->assertOk()
                ->assertSee('Todos los viajes')->assertSee('href="'.route('rutas.salidas.show', $manual).'"', false);
            $detalle = $this->get(route('rutas.salidas.show', $manual))->assertOk()
                ->assertSee('Hoja de la salida')->assertSee('San Miguel')->assertSee('Cerrada')
                ->assertSee($entregado->numero_control)->assertSee($suelta->numero_control)
                ->assertViewHas('resumen', ['total' => 2, 'entregados' => 1, 'no_entregados' => 0, 'sin_registrar' => 1]);
            // Nada del flujo viejo, y nada que la cambie.
            foreach (['Terminar ruta', 'Terminar día', 'Liquidación de la ruta', 'Salir ahora', 'Reabrir', 'Día 05/10/2026',
                'Quitar de la salida', '¿Falta un CCF?', 'Deshacer', '>Cancelar</button>', '+ Agregar gasto', '>Entregado</button>'] as $accion) {
                $detalle->assertDontSee($accion, false);
            }
        }

        // Las pantallas del flujo viejo ya no existen, tampoco escribiendo la dirección.
        foreach (['cierre', 'cierre/2026-10-05/pdf', 'terminar', 'terminar/pdf'] as $resto) {
            $this->get('/rutas/salidas/'.$manual->id.'/'.$resto)->assertNotFound();
        }
        $this->post('/rutas/salidas/'.$manual->id.'/cierre')->assertNotFound();
        $this->post('/rutas/salidas/'.$manual->id.'/terminar')->assertNotFound();
        $this->post('/rutas/salidas/'.$manual->id.'/cierre/2026-10-05/reabrir', ['motivo' => 'Corregir el cobro'])->assertNotFound();
        // Y lo que sí existe no la toca: está cerrada y liquidada.
        $this->patch(route('rutas.salidas.cancelar', $manual))->assertSessionHas('error');
        $this->patch(route('rutas.salidas.entregas.deshacer', [$manual, $manual->entregas()->entregadas()->sole()]))->assertSessionHasErrors('cierre');
        $this->assertSame([EstadoSalidaRuta::Finalizada, 2, 1], [$manual->refresh()->estado, $manual->cierres()->count(), $manual->entregas()->entregadas()->count()]);
    }

    /** La que hubiera quedado en camino ya no se termina ni se liquida: la oficina solo puede cancelarla. */
    public function test_una_salida_de_antes_en_camino_no_ofrece_terminar_y_la_oficina_la_cancela(): void
    {
        $manual = SalidaRuta::create(['ruta_id' => $this->zona->id, 'fecha_inicio' => '2026-11-09', 'estado' => EstadoSalidaRuta::EnCurso]);
        $manual->participantes()->create(['rutas_personal_id' => $this->persona->id, 'rol' => RolEnSalida::Responsable]);

        $this->actingAs($this->jefe)->get(route('rutas.salidas.show', $manual))->assertOk()
            ->assertSee('Hoja de la salida')->assertDontSee('Terminar ruta')->assertDontSee('Terminar día')->assertDontSee('>Cancelar</button>', false);
        $this->actingAs($this->oficina)->get(route('rutas.salidas.show', $manual))->assertOk()
            ->assertDontSee('Terminar ruta')->assertDontSee('Terminar día')->assertSee('>Cancelar</button>', false);
        $this->patch(route('rutas.salidas.cancelar', $manual))->assertSessionHas('status');
        $this->assertSame(EstadoSalidaRuta::Cancelada, $manual->refresh()->estado);
    }

    // ══════════════════════════════ el nombre del viaje

    public function test_la_oficina_ve_el_nombre_del_viaje_y_no_sin_zona(): void
    {
        // El viaje se abrió con una tienda nueva (sin zona) y después sumó una sala de San Miguel.
        $viaje = $this->viaje(conZona: false);
        $this->pedido($viaje, $this->sala('Sala de prueba Oriente'));
        $this->assertSame('Lunes · San Miguel', $viaje->titulo());

        $this->actingAs($this->oficina);
        foreach ([route('rutas.dashboard'), route('rutas.salidas.index'), route('rutas.salidas.show', $viaje), route('rutas.viajes')] as $url) {
            $this->get($url)->assertOk()->assertSee('Lunes · San Miguel')->assertDontSee('Sin zona ·');
        }
        $this->get(route('rutas.salidas.index'))->assertDontSee('>Sin zona</a>', false);
    }

    public function test_viajes_anteriores_nombra_muchos_viajes_sin_una_consulta_por_fila(): void
    {
        $poblar = function (int $n): void {
            for ($i = 0; $i < $n; $i++) {
                $viaje = $this->viaje(conZona: false);
                $this->pedido($viaje, $this->sala('Sala de prueba '.Str::random(5)));
            }
        };
        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->oficina)->get(route('rutas.salidas.index'))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $poblar(1);
        $contar();
        $pocas = $contar();
        $poblar(5);

        $this->assertSame($pocas, $contar());
    }

    // ══════════════════════════════ gastos y PDF del día

    public function test_los_gastos_del_dia_se_anotan_en_el_viaje_y_salen_en_el_pdf(): void
    {
        $viaje = $this->viaje();
        $dte = $this->ccf($this->sala('Sala de prueba Oriente'));
        $this->entrega($viaje, $dte, ['forma_cobro' => 'efectivo', 'monto_esperado' => '50.00', 'monto_cobrado' => '50.00']);

        $this->actingAs($this->vendedor)->get(route('rutas.mi-salida.dia'))->assertOk()
            ->assertSee('Agregar gasto')->assertSee('action="'.route('rutas.mi-salida.gastos.store', $viaje).'"', false);
        $this->post(route('rutas.mi-salida.gastos.store', $viaje), ['concepto' => 'parqueo', 'monto' => '3.50'])->assertSessionHasNoErrors();
        $this->post(route('rutas.mi-salida.gastos.store', $viaje), ['concepto' => 'otro', 'monto' => '10.25', 'detalle' => 'Peaje de prueba'])->assertSessionHasNoErrors();
        // El gasto de un compañero no es suyo.
        $otra = PersonalRuta::create(['nombre' => 'Rosa Ficticia']);
        $viaje->gastos()->create(['fecha' => '2026-11-10', 'personal_id' => $otra->id, 'concepto' => 'parqueo', 'monto' => '99.00', 'registrado_por' => $this->oficina->id]);

        $resumen = app(DiaVendedor::class)->resumen($this->persona, $this->vendedor, '2026-11-10');
        $this->assertSame([5000, 1375, 3625], [$resumen['efectivo'], $resumen['gastos_total'], $resumen['a_entregar']]);
        $this->assertSame(['3.50', '10.25'], $resumen['gastos']->pluck('monto')->all());

        $this->get(route('rutas.mi-salida.dia'))->assertOk()->assertSee('Peaje de prueba')
            ->assertSeeInOrder(['Efectivo a entregar:', '$36.25', '$50.00 cobrado menos $13.75 de gastos']);

        $html = view('rutas.mi-salida.dia-pdf', ['resumen' => $resumen, 'persona' => $this->persona, 'empresa' => 'Empresa de prueba', 'generado' => now()])->render();
        $this->assertStringContainsString('Gastos del día', $html);
        $this->assertStringContainsString('Peaje de prueba', $html);
        foreach (['$13.75', '$36.25', 'Efectivo a entregar', '>Entregó<', '>Recibió<', 'RESUMEN DEL DÍA', 'No es factura · Sin valor tributario'] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }
        $this->assertStringNotContainsString('$99.00', $html);
        $this->assertSame('application/pdf', $this->get(route('rutas.mi-salida.dia.pdf'))->assertOk()->headers->get('Content-Type'));
    }

    // ══════════════════════════════ «le toca», una sola fuente

    public function test_le_toca_es_el_mismo_en_el_buscador_el_sugerido_y_el_tablero(): void
    {
        // Compra cada 10 días; el último CCF fue hace 12, pero se le entregó hace 2.
        $alDia = $this->sala('Sala de prueba al dia');
        $atrasada = $this->sala('Sala de prueba atrasada');
        foreach ([$alDia, $atrasada] as $sala) {
            foreach (['2026-09-29', '2026-10-09', '2026-10-19', '2026-10-29'] as $fecha) {
                $ultimo = $this->ccf($sala, $fecha);
            }
        }
        $vieja = SalidaRuta::create(['ruta_id' => $this->zona->id, 'fecha_inicio' => '2026-11-08', 'estado' => EstadoSalidaRuta::Finalizada, 'fecha_fin_real' => '2026-11-08']);
        $this->entrega($vieja, Dte::where('cliente_sucursal_id', $alDia->id)->latest('fecha_emision')->first(), ['fecha_resultado' => '2026-11-08 10:00:00']);
        $this->assertNotNull($ultimo);

        $hoy = CarbonImmutable::parse('2026-11-10');
        $tablero = app(RitmoRutas::class)->tablero($hoy)['salas']->keyBy(fn (array $f) => $f['sala']->id);
        $filas = app(RitmoRutas::class)->deSalas(ClienteSucursal::whereKey([$alDia->id, $atrasada->id])->get(), $hoy);
        foreach ([$alDia, $atrasada] as $sala) {
            $this->assertSame([$tablero[$sala->id]['estado'], $tablero[$sala->id]['dias']], [$filas[$sala->id]['estado'], $filas[$sala->id]['dias']]);
        }
        $this->assertSame([2, false], [$filas[$alDia->id]['dias'], RitmoRutas::leToca($filas[$alDia->id])]);
        $this->assertSame([12, true], [$filas[$atrasada->id]['dias'], RitmoRutas::leToca($filas[$atrasada->id])]);

        // El buscador de «Sacar pedido».
        $buscador = $this->actingAs($this->vendedor)->get(route('rutas.sacar-pedido.index', ['q' => 'Sala de prueba']))->assertOk()->getContent();
        $this->assertSame(1, substr_count($buscador, '>Le toca</span>'));
        $this->assertStringContainsString('Última visita hace 2 días', $buscador);
        $this->assertStringContainsString('Última visita hace 12 días', $buscador);

        // La historia de la sala, arriba del sugerido.
        $this->get(route('rutas.sacar-pedido.create', $atrasada))->assertOk()
            ->assertSeeInOrder(['Historia de la sala', 'Última entrega', '29/10/2026', 'Compra cada', 'unos 10 días', 'Próxima visita', '08/11/2026', 'Le toca']);
        $this->get(route('rutas.sacar-pedido.create', $alDia))->assertOk()
            ->assertSeeInOrder(['Última entrega', '08/11/2026', 'Próxima visita', '18/11/2026'])
            ->assertDontSee('Le toca</span>', false);
    }

    // ══════════════════════════════ permisos

    public function test_facturacion_da_de_alta_clientes_y_nadie_arma_salidas(): void
    {
        // Solo el alta de la tienda nueva de un pedido (`clientes.alta`); gestionar clientes sigue siendo del administrador.
        $this->assertContains('clientes.alta', RolSistema::Facturacion->permisos());
        $this->assertNotContains('clientes.gestionar', RolSistema::Facturacion->permisos());
        $this->assertNotContains('clientes.gestionar', RolSistema::JefeVendedores->permisos());
        $this->assertNotContains('clientes.gestionar', RolSistema::Jefatura->permisos());
        foreach (RolSistema::cases() as $rol) {
            $this->assertNotContains('rutas.salidas.armar', $rol->permisos(), $rol->value);
            // Ni las termina: ya no se cierran días ni se liquidan salidas (11/11/2026).
            $this->assertNotContains('rutas.salidas.terminar', $rol->permisos(), $rol->value);
        }
    }
}
