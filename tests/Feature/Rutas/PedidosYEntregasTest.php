<?php

namespace Tests\Feature\Rutas;

use App\Enums\AreaSistema;
use App\Enums\EstadoPedidoSala;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\PedidosFabrica;
use App\Services\Rutas\PedidosYEntregas;
use App\Services\Rutas\VentasRutas;
use App\Services\Rutas\Viajes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\QuienEntrega;
use Tests\TestCase;

/**
 * «Pedidos y entregas» (Melqui, 26/11/2026): la pantalla de solo mirar de la dueña (Jefatura),
 * pensada para el celular. Qué pidieron las salas, qué está en la fábrica, qué se entregó y
 * cuánto se vendió, de hoy, de ayer o de la semana. Los números salen de lo mismo que el
 * correo de las 18:00 y la pantalla de Ventas. Datos inventados.
 */
class PedidosYEntregasTest extends TestCase
{
    use RefreshDatabase;

    private ClienteSucursal $norte;

    private ClienteSucursal $sur;

    private User $vendedor;

    private PersonalRuta $persona;

    private User $fabrica;

    private Producto $galleta;

    private Establecimiento $establecimiento;

    /** @var array<string, PedidoSala> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Jueves: la semana va del lunes 23 a hoy.
        $this->travelTo('2026-11-26 15:00:00');
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28', 'rutas.ambiente_ccf' => null]);

        $cliente = Cliente::factory()->create(['nombre' => 'Cliente de prueba']);
        $this->norte = $cliente->sucursales()->create(['nombre' => 'Sala de prueba Norte', 'codigo' => '7101', 'ruta_id' => Ruta::create(['nombre' => 'Zona de prueba Norte'])->id]);
        $this->sur = $cliente->sucursales()->create(['nombre' => 'Sala de prueba Sur', 'codigo' => '7102', 'ruta_id' => Ruta::create(['nombre' => 'Zona de prueba Sur'])->id]);

        $this->vendedor = User::factory()->create(['name' => 'Vendedor de prueba', 'activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Vendedor de prueba', 'user_id' => $this->vendedor->id]);
        $this->fabrica = User::factory()->create(['activo' => true])->assignRole('fabrica');

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01']);
        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba', 'ambiente' => '01', 'activo' => true]);
        $this->establecimiento = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa', 'activo' => true]);
    }

    private function usuario(string $rol, string $nombre = 'Usuario de prueba'): User
    {
        return User::factory()->create(['name' => $nombre, 'activo' => true])->assignRole($rol);
    }

    private function pedido(ClienteSucursal $sala, string $cantidad = '12'): PedidoSala
    {
        $pedido = PedidoSala::create([
            'cliente_sucursal_id' => $sala->id, 'ruta_id' => $sala->ruta_id,
            'sacado_por_id' => $this->persona->id, 'registrado_por' => $this->vendedor->id,
            'fecha' => now()->toDateString(), 'estado' => EstadoPedidoSala::Registrado,
        ]);
        $pedido->lineas()->create(['producto_id' => $this->galleta->id, 'cantidad' => $cantidad]);

        return $pedido;
    }

    private function version(PedidoSala $pedido): string
    {
        return PedidoSala::query()->with(app(PedidosFabrica::class)->relaciones())->findOrFail($pedido->id)->versionProduccion();
    }

    private function mandar(PedidoSala $pedido, string $cantidad, bool $sigue = false): void
    {
        app(PedidosFabrica::class)->mandar($pedido, [
            'clave' => (string) Str::uuid(), 'version' => $this->version($pedido),
            'cantidades' => [$pedido->lineas()->value('id') => $cantidad], 'motivos' => [], 'sigue' => $sigue,
        ], $this->fabrica);
    }

    /** Un CCF real de hoy (aceptado por Hacienda, en producción), sin IVA, para la sala. */
    private function ccf(ClienteSucursal $sala, float $monto): Dte
    {
        return Dte::withoutEvents(function () use ($sala, $monto) {
            $dte = Dte::create([
                'establecimiento_id' => $this->establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
                'sello_recepcion' => (string) Str::uuid(), 'fecha_procesamiento_mh' => now(),
                'cliente_id' => $sala->cliente_id, 'cliente_sucursal_id' => $sala->id,
                'numero_control' => 'DTE-03-M001P001-'.str_pad((string) (Dte::count() + 1), 15, '0', STR_PAD_LEFT),
                'fecha_emision' => now()->toDateString(), 'hora_emision' => '08:00:00',
                'subtotal' => $monto, 'total_descuento' => 0, 'total_pagar' => $monto,
            ]);
            DteLinea::withoutEvents(fn () => DteLinea::create([
                'dte_id' => $dte->id, 'numero_linea' => 1, 'tipo_impuesto' => 'gravado', 'producto_id' => $this->galleta->id,
                'descripcion' => 'Galleta de prueba', 'cantidad' => 1, 'precio_unitario' => $monto, 'venta_gravada' => $monto, 'total_linea' => $monto,
            ]));

            return $dte;
        });
    }

    /** El vendedor marca el CCF en «Por entregar»: entregado (en efectivo) o «No se pudo». */
    private function marcar(Dte $dte, bool $entregado = true): void
    {
        $datos = $entregado ? ['resultado' => 'entregado', 'forma_cobro' => 'efectivo'] : ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'sala_cerrada'];
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte), QuienEntrega::yo($datos))
            ->assertSessionHasNoErrors()->assertSessionMissing('error');
    }

    /**
     * Una semana con movimiento, de domingo 22 (semana pasada) a jueves 26 (hoy). Termina
     * hoy a las 15:00.
     */
    private function semana(): void
    {
        $fabrica = app(PedidosFabrica::class);

        // Domingo 22, la semana pasada: queda en la fábrica, sin empezar.
        $this->travelTo('2026-11-22 10:00:00');
        $this->p['viejo'] = $this->pedido($this->norte);
        $this->marcar($this->ccf($this->norte, 100));

        // Martes 24: sale completo ese día.
        $this->travelTo('2026-11-24 10:00:00');
        $this->p['martes'] = $this->pedido($this->sur);
        $this->marcar($this->ccf($this->norte, 200));
        $this->travelTo('2026-11-24 16:00:00');
        $this->mandar($this->p['martes'], '12');

        // Ayer, miércoles 25: uno que se arma hoy, uno que sale en parte hoy y uno anulado.
        $this->travelTo('2026-11-25 10:00:00');
        $this->p['armando'] = $this->pedido($this->norte);
        $this->p['parte'] = $this->pedido($this->norte, '10');
        $anulado = $this->pedido($this->sur);
        $anulado->update(['estado' => EstadoPedidoSala::Anulado]);
        $this->marcar($this->ccf($this->sur, 300), entregado: false);

        // Hoy, jueves 26.
        $this->travelTo('2026-11-26 09:00:00');
        $this->p['pendiente'] = $this->pedido($this->sur);
        $this->p['listo'] = $this->pedido($this->norte);
        $this->p['no_se_trae'] = $this->pedido($this->sur);
        $this->travelTo('2026-11-26 10:00:00');
        $this->marcar($this->ccf($this->norte, 40));
        $this->marcar($this->ccf($this->sur, 50.50));
        $this->marcar($this->ccf($this->norte, 10), entregado: false);
        $this->travelTo('2026-11-26 11:00:00');
        $this->mandar($this->p['parte'], '4', sigue: true);
        $fabrica->guardarBorrador($this->p['armando'], ['cantidades' => [$this->p['armando']->lineas()->value('id') => '1']], $this->fabrica);
        $fabrica->marcarListo($this->p['listo'], true, $this->fabrica, $this->version($this->p['listo']));
        $fabrica->cerrar($this->p['no_se_trae'], $this->version($this->p['no_se_trae']), $this->fabrica, 'El cliente canceló');

        $this->travelTo('2026-11-26 15:00:00');
    }

    /** @return list<int> los N.º de una lista, en su orden */
    private function numeros(array $lista): array
    {
        return array_column($lista, 'numero');
    }

    // ══════════════════════════════ quién la ve

    public function test_jefatura_y_el_administrador_la_ven(): void
    {
        foreach (['jefatura', 'administrador'] as $rol) {
            $this->actingAs($this->usuario($rol))->get(route('rutas.pedidos-entregas'))->assertOk()
                ->assertSee('<h1', false)->assertSee('Pedidos y entregas')
                ->assertSee('26/11/2026')->assertSee('Actualizado a las 15:00');
        }
        $this->assertSame('/rutas/pedidos-y-entregas', route('rutas.pedidos-entregas', [], false));
    }

    public function test_los_demas_roles_reciben_403(): void
    {
        foreach (['vendedor', 'jefe_vendedores', 'fabrica', 'facturacion', 'contabilidad', 'produccion'] as $rol) {
            $this->actingAs($this->usuario($rol))->get(route('rutas.pedidos-entregas'))->assertForbidden();
        }
    }

    public function test_sin_sesion_va_al_login(): void
    {
        $this->get(route('rutas.pedidos-entregas'))->assertRedirect(route('login'));
    }

    public function test_el_permiso_es_de_jefatura_y_del_administrador(): void
    {
        $this->assertTrue($this->usuario('jefatura')->can('rutas.pedidos-entregas.ver'));
        $this->assertTrue($this->usuario('administrador')->can('rutas.pedidos-entregas.ver'));
        foreach (['vendedor', 'jefe_vendedores', 'fabrica', 'facturacion', 'contabilidad', 'produccion'] as $rol) {
            $this->assertFalse($this->usuario($rol)->can('rutas.pedidos-entregas.ver'), $rol);
        }
    }

    // ══════════════════════════════ por dónde entra

    public function test_jefatura_entra_a_rutas_por_esta_pantalla_y_el_administrador_por_el_tablero(): void
    {
        $jefatura = $this->usuario('jefatura');
        $admin = $this->usuario('administrador');

        $this->assertSame('rutas.pedidos-entregas', AreaSistema::Rutas->rutaInicioPara($jefatura));
        $this->assertSame('rutas.dashboard', AreaSistema::Rutas->rutaInicioPara($admin));

        // El selector de áreas la lleva ahí.
        $this->actingAs($jefatura)->get(route('rutas.dashboard'))->assertOk()
            ->assertSee('href="'.route('rutas.pedidos-entregas').'"', false);

        // Al iniciar sesión siguen llegando adonde llegaban: al Inicio de Facturación.
        foreach ([$jefatura, $admin] as $usuario) {
            $this->assertSame(AreaSistema::Facturacion, AreaSistema::principalPara($usuario));
            $this->actingAs($usuario)->get(route('dashboard'))->assertOk();
        }
    }

    public function test_en_el_menu_va_primero_solo_con_el_permiso(): void
    {
        // Las opciones de Rutas en la barra lateral, sin el selector de áreas que va arriba.
        $menu = function (User $usuario): string {
            $html = $this->actingAs($usuario)->get(route('rutas.dashboard'))->assertOk()->getContent();
            $aside = substr($html, strpos($html, '<aside'), strpos($html, '</aside>') - strpos($html, '<aside'));
            $areas = strpos($aside, '<nav aria-label="Áreas de trabajo"');

            return $areas === false ? $aside : substr_replace($aside, '', $areas, strpos($aside, '</nav>', $areas) + 6 - $areas);
        };
        $enlace = fn (string $ruta) => 'href="'.route($ruta).'"';

        foreach (['jefatura', 'administrador'] as $rol) {
            $aside = $menu($this->usuario($rol));
            $this->assertStringContainsString('Pedidos y entregas', $aside);
            $this->assertNotFalse(strpos($aside, $enlace('rutas.pedidos-entregas')), $rol);
            $this->assertLessThan(strpos($aside, $enlace('rutas.dashboard')), strpos($aside, $enlace('rutas.pedidos-entregas')), $rol);
        }

        // Facturación ve Rutas pero no esta pantalla.
        $this->assertStringNotContainsString($enlace('rutas.pedidos-entregas'), $menu($this->usuario('facturacion')));
    }

    // ══════════════════════════════ el período

    /** La pastilla elegida: la única con `aria-current`. */
    private function elegida(string $html): string
    {
        $this->assertSame(1, preg_match_all('/href="[^"]*\?periodo=(\w+)"\s+aria-current="page"/', $html, $m));

        return $m[1][0];
    }

    public function test_abre_en_hoy_y_un_periodo_raro_vuelve_a_hoy(): void
    {
        $jefatura = $this->usuario('jefatura');

        foreach ([[], ['periodo' => 'mes'], ['periodo' => ['semana']], ['periodo' => 'SEMANA']] as $consulta) {
            $respuesta = $this->actingAs($jefatura)->get(route('rutas.pedidos-entregas', $consulta))->assertOk();
            $this->assertSame('hoy', $respuesta->viewData('periodo'), json_encode($consulta));
            $this->assertSame('hoy', $this->elegida($respuesta->getContent()));
            $respuesta->assertSee('26/11/2026');
        }

        $ayer = $this->get(route('rutas.pedidos-entregas', ['periodo' => 'ayer']))->assertOk()
            ->assertSee('25/11/2026')->assertDontSee('26/11/2026');
        $this->assertSame('ayer', $this->elegida($ayer->getContent()));
        $semana = $this->get(route('rutas.pedidos-entregas', ['periodo' => 'semana']))->assertOk()
            ->assertSee('23/11/2026 al 26/11/2026');
        $this->assertSame('semana', $this->elegida($semana->getContent()));
    }

    public function test_el_lunes_la_semana_es_solo_ese_dia(): void
    {
        $this->travelTo('2026-11-23 08:00:00');

        $datos = app(PedidosYEntregas::class)->calcular('semana');

        $this->assertSame(['2026-11-23', '2026-11-23'], [$datos['desde']->toDateString(), $datos['hasta']->toDateString()]);
    }

    // ══════════════════════════════ lo que cuenta cada bloque

    public function test_hoy_cuenta_solo_lo_de_hoy(): void
    {
        $this->semana();

        $datos = app(PedidosYEntregas::class)->calcular('hoy');

        // Los pedidos que entraron hoy, con su estado de ahora.
        $this->assertSame(3, $datos['pedidos']['total']);
        $this->assertSame(['registrado' => 1, 'parcial' => 0, 'listo' => 1, 'enviado' => 0, 'no_se_trae' => 1], $datos['pedidos']['por_estado']);
        $this->assertEqualsCanonicalizing([$this->p['pendiente']->id, $this->p['listo']->id, $this->p['no_se_trae']->id], $this->numeros($datos['pedidos']['lista']));
        $fila = collect($datos['pedidos']['lista'])->firstWhere('numero', $this->p['listo']->id);
        $this->assertSame(['Sala de prueba Norte', 'Vendedor de prueba', EstadoPedidoSala::Listo], [$fila['sala'], $fila['vendedor'], $fila['estado']]);

        // La fábrica, de cualquier día; lo que salió, solo hoy.
        $this->assertFabricaDeAhora($datos);
        $this->assertSame([$this->p['parte']->id], $this->numeros($datos['fabrica']['salieron']));

        // Lo entregado hoy, por zona.
        $this->assertSame([2, 1], [$datos['entregas']['entregadas'], $datos['entregas']['no_se_pudo']]);
        $this->assertSame(
            [['Zona de prueba Norte', 1, 1], ['Zona de prueba Sur', 1, 0]],
            array_map(fn ($z) => [$z['nombre'], $z['entregadas'], $z['no_se_pudo']], $datos['entregas']['zonas']),
        );
        $entrega = $datos['entregas']['zonas'][1]['lista'][0];
        $this->assertSame(['Sala de prueba Sur', 'Vendedor de prueba', '10:00', true], [$entrega['sala'], $entrega['quien'], $entrega['hora'], $entrega['entregado']]);
        $this->assertStringStartsWith('DTE-03-', $entrega['documento']);

        // Lo vendido hoy, sin IVA: lo mismo que el correo de las 18:00.
        $this->assertSame(['monto' => 100.5, 'facturas' => 3], $datos['vendido']['nacional']);
    }

    public function test_ayer_cuenta_solo_lo_de_ayer(): void
    {
        $this->semana();

        $datos = app(PedidosYEntregas::class)->calcular('ayer');

        // El anulado no se cuenta.
        $this->assertSame(2, $datos['pedidos']['total']);
        $this->assertSame(['registrado' => 1, 'parcial' => 1, 'listo' => 0, 'enviado' => 0, 'no_se_trae' => 0], $datos['pedidos']['por_estado']);
        $this->assertEqualsCanonicalizing([$this->p['armando']->id, $this->p['parte']->id], $this->numeros($datos['pedidos']['lista']));

        // Lo que está en la fábrica es lo de ahora, con el atraso contado hasta hoy.
        $this->assertFabricaDeAhora($datos);
        $this->assertSame([], $datos['fabrica']['salieron']);

        $this->assertSame([0, 1], [$datos['entregas']['entregadas'], $datos['entregas']['no_se_pudo']]);
        $this->assertSame([['Zona de prueba Sur', 0, 1]], array_map(fn ($z) => [$z['nombre'], $z['entregadas'], $z['no_se_pudo']], $datos['entregas']['zonas']));

        $this->assertSame(['monto' => 300.0, 'facturas' => 1], $datos['vendido']['nacional']);
    }

    public function test_la_semana_va_de_lunes_a_hoy(): void
    {
        $this->semana();

        $datos = app(PedidosYEntregas::class)->calcular('semana');

        $this->assertSame(['2026-11-23', '2026-11-26'], [$datos['desde']->toDateString(), $datos['hasta']->toDateString()]);
        // Sin el del domingo 22 ni el anulado.
        $this->assertSame(6, $datos['pedidos']['total']);
        $this->assertSame(['registrado' => 2, 'parcial' => 1, 'listo' => 1, 'enviado' => 1, 'no_se_trae' => 1], $datos['pedidos']['por_estado']);
        $this->assertNotContains($this->p['viejo']->id, $this->numeros($datos['pedidos']['lista']));

        $this->assertFabricaDeAhora($datos);
        $this->assertEqualsCanonicalizing([$this->p['martes']->id, $this->p['parte']->id], $this->numeros($datos['fabrica']['salieron']));

        $this->assertSame([3, 2], [$datos['entregas']['entregadas'], $datos['entregas']['no_se_pudo']]);
        $this->assertSame(
            [['Zona de prueba Norte', 2, 1], ['Zona de prueba Sur', 1, 1]],
            array_map(fn ($z) => [$z['nombre'], $z['entregadas'], $z['no_se_pudo']], $datos['entregas']['zonas']),
        );
        // Con varios días, la hora lleva el día.
        $this->assertSame('24/11 10:00', $datos['entregas']['zonas'][0]['lista'][0]['hora']);

        // La suma de cada día de la semana, como «Ventas del día» del correo.
        $this->assertSame(['monto' => 600.5, 'facturas' => 5], $datos['vendido']['nacional']);
    }

    private function assertFabricaDeAhora(array $datos): void
    {
        $fabrica = $datos['fabrica'];
        // Del más atrasado al más nuevo.
        $this->assertSame([$this->p['viejo']->id, $this->p['parte']->id, $this->p['pendiente']->id], $this->numeros($fabrica['sin_empezar']));
        $this->assertSame([4, 1, 0], array_column($fabrica['sin_empezar'], 'atraso'));
        $this->assertSame(['22/11/2026', true], [$fabrica['sin_empezar'][0]['fecha'], $fabrica['sin_empezar'][1]['resto']]);
        $this->assertSame([$this->p['armando']->id], $this->numeros($fabrica['armando']));
        $this->assertSame(1, $fabrica['armando'][0]['atraso']);
        $this->assertSame([$this->p['listo']->id], $this->numeros($fabrica['listos']));
        $this->assertSame('Sala de prueba Norte', $fabrica['listos'][0]['sala']);
    }

    /**
     * Concisa (Melqui, 26/11/2026: «más concisa, cuál pedido es»): el total chico en el título
     * y una línea corta por pedido o entrega, a la vista sin tocar nada.
     */
    public function test_la_pantalla_dice_cual_pedido_es_en_una_linea(): void
    {
        $this->semana();
        $jefatura = $this->usuario('jefatura');

        $respuesta = $this->actingAs($jefatura)->get(route('rutas.pedidos-entregas'))->assertOk()
            ->assertSeeInOrder(['Pedidos de las salas', 'En la fábrica', 'Entregado', 'Vendido'])
            ->assertSee('Vendedor de prueba')->assertSee('Listo para salir')->assertSee('No se trae')
            ->assertDontSee('Cliente de prueba')
            // La lista ya está a la vista: nada que abrir con pocas líneas.
            ->assertDontSee('<details', false)->assertDontSee('restantes');
        $texto = $this->texto($respuesta->getContent());
        foreach ([
            'Pedidos de las salas · 3', 'Sala de prueba Norte · N.º '.$this->p['listo']->id, 'Sala de prueba Sur · N.º '.$this->p['pendiente']->id,
            'En la fábrica · 5', 'Listos para salir · 1', 'Armando · 1', 'Sin empezar · 3',
            'Sala de prueba Norte · N.º '.$this->p['viejo']->id.' hace 4 días', 'Salió hoy · 1', 'Sala de prueba Norte · N.º '.$this->p['parte']->id.' 11:00',
            'Entregado · 2 (1 no se pudo)', 'Zona de prueba Norte', 'Sala de prueba Norte · CCF …0004', 'No se pudo',
            '$100.50 sin IVA · 3 facturas',
        ] as $frase) {
            $this->assertStringContainsString($frase, $texto);
        }
        // Sin frases largas ni números gigantes.
        foreach (['entraron', 'están en la fábrica', 'entregadas hoy', 'El más viejo', 'Ver los 3 pedidos', 'Ver la lista'] as $frase) {
            $this->assertStringNotContainsString($frase, $texto);
        }
        // El atraso solo si pasa de un día.
        $this->assertStringNotContainsString('hace 1 día', $texto);

        // Ayer no hubo entregas, pero sí un «No se pudo».
        $ayer = $this->texto($this->get(route('rutas.pedidos-entregas', ['periodo' => 'ayer']))->assertOk()->getContent());
        $this->assertStringContainsString('Entregado · 0 (1 no se pudo)', $ayer);
        $this->assertStringContainsString('$300.00 sin IVA · 1 factura', $ayer);
    }

    public function test_mas_de_ocho_lineas_por_grupo_se_abren_aparte(): void
    {
        foreach (range(1, 11) as $i) {
            $this->pedido($i % 2 ? $this->norte : $this->sur);
        }
        // En el orden de la lista (por sala, como el correo).
        $numeros = $this->numeros(app(PedidosYEntregas::class)->calcular('hoy')['pedidos']['lista']);
        $this->assertCount(11, $numeros);

        $html = $this->actingAs($this->usuario('jefatura'))->get(route('rutas.pedidos-entregas'))->assertOk()->getContent();

        // Ocho a la vista y los tres que siguen detrás de «Ver los 3 restantes»: en los pedidos
        // de las salas y en «Sin empezar».
        $this->assertSame(2, substr_count($this->texto($html), 'Ver los 3 restantes'));
        $this->assertSame(2, substr_count($html, '<details'));
        $pedidos = substr($html, strpos($html, 'Pedidos de las salas'), strpos($html, 'En la fábrica') - strpos($html, 'Pedidos de las salas'));
        [$vista, $resto] = explode('<details', $pedidos);
        foreach ($numeros as $i => $numero) {
            $this->assertStringContainsString('N.º '.$numero.'<', $i < 8 ? $vista : $resto);
        }
    }

    /** La página como la lee la persona: sin etiquetas y con los espacios juntos. */
    private function texto(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html))));
    }

    public function test_un_periodo_sin_nada_lo_dice_en_una_linea(): void
    {
        $html = $this->actingAs($this->usuario('jefatura'))->get(route('rutas.pedidos-entregas', ['periodo' => 'ayer']))->assertOk()->getContent();
        $texto = $this->texto($html);

        // Pedidos, entregas y ventas; la fábrica es la de ahora.
        $this->assertSame(3, substr_count($texto, 'Nada ayer.'));
        $this->assertStringContainsString('En la fábrica Nada ahora.', $texto);
        $this->assertStringNotContainsString('· 0', $texto);
        $this->assertStringNotContainsString('<details', $html);
    }

    public function test_si_falla_la_cuenta_de_ventas_lo_dice_y_lo_demas_sale_igual(): void
    {
        Exceptions::fake();
        $this->mock(VentasRutas::class)->shouldReceive('delDia')->andThrow(new RuntimeException('Falla simulada de ventas'));
        $this->pedido($this->norte);

        $respuesta = $this->actingAs($this->usuario('jefatura'))->get(route('rutas.pedidos-entregas'))->assertOk()
            ->assertSee('No se pudo calcular lo vendido.');
        $this->assertStringContainsString('Pedidos de las salas · 1', $this->texto($respuesta->getContent()));
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Falla simulada de ventas');
    }

    // ══════════════════════════════ solo mira

    public function test_no_tiene_formularios_y_sacar_pedido_es_un_enlace(): void
    {
        $this->semana();
        $pagina = function (User $usuario): string {
            $html = $this->actingAs($usuario)->get(route('rutas.pedidos-entregas', ['periodo' => 'semana']))->assertOk()->getContent();
            $inicio = strpos($html, '<h1');

            return substr($html, $inicio, strpos($html, '</main>') - $inicio);
        };
        $sacar = '<a href="'.route('rutas.sacar-pedido.index').'"';

        $jefatura = $pagina($this->usuario('jefatura'));
        $this->assertDoesNotMatchRegularExpression('/<form\b/i', $jefatura);
        $this->assertStringContainsString($sacar, $jefatura);
        $this->assertStringContainsString('Sacar un pedido', $jefatura);

        // Quien la mira sin poder sacar pedidos no ve el botón.
        $mira = $this->usuario('facturacion')->givePermissionTo('rutas.pedidos-entregas.ver');
        $this->assertStringNotContainsString($sacar, $pagina($mira));
    }

    // ══════════════════════════════ Jefatura saca pedidos

    public function test_jefatura_saca_un_pedido_de_punta_a_punta(): void
    {
        $jefatura = $this->usuario('jefatura', 'Jefa de prueba');
        $this->assertTrue($jefatura->can('rutas.pedidos.registrar'));
        $this->actingAs($jefatura);

        // Al entrar se le arma su ficha de Rutas, una vez.
        $this->get(route('rutas.sacar-pedido.index', ['q' => 'Sala de prueba']))->assertOk()
            ->assertDontSee('Tu usuario no es vendedor')->assertSee('id="buscar-sala"', false);
        $ficha = PersonalRuta::where('user_id', $jefatura->id)->sole();
        $this->assertSame([true, 'Jefa de prueba'], [$ficha->activo, $ficha->nombre]);

        $this->get(route('rutas.sacar-pedido.create', $this->norte))->assertOk();
        $this->post(route('rutas.sacar-pedido.store', $this->norte), ['cantidades' => [$this->galleta->id => '12']])
            ->assertSessionHasNoErrors()->assertRedirect(route('rutas.sacar-pedido.index'));

        $pedido = PedidoSala::sole();
        $this->assertSame([$ficha->id, $jefatura->id, EstadoPedidoSala::Registrado], [$pedido->sacado_por_id, $pedido->registrado_por, $pedido->estado]);

        // Y lo ve en su pantalla, con su nombre.
        $fila = app(PedidosYEntregas::class)->calcular('hoy')['pedidos']['lista'][0];
        $this->assertSame([$pedido->id, 'Jefa de prueba'], [$fila['numero'], $fila['vendedor']]);
        $this->get(route('rutas.pedidos-entregas'))->assertOk()->assertSee('Jefa de prueba');

        // Sigue sin marcar entregas ni el Inicio del vendedor.
        $this->get(route('rutas.mi-salida.index'))->assertForbidden();
        $this->assertSame(1, PersonalRuta::where('user_id', $jefatura->id)->count());

        // Al día siguiente no se le pregunta «¿Seguís en ese viaje?»: contestar es del Inicio
        // del vendedor, que ella no tiene. Saca el pedido igual.
        $this->travelTo('2026-11-27 09:00:00');
        $this->assertTrue(app(Viajes::class)->preguntarSiSigue($pedido->salida), 'a un vendedor se le preguntaría');
        $this->get(route('rutas.sacar-pedido.index'))->assertOk()
            ->assertDontSee(route('rutas.mi-salida.sigue-viaje', $pedido->salida_ruta_id))
            ->assertDontSee('sigue abierto');
        $this->post(route('rutas.sacar-pedido.store', $this->sur), ['cantidades' => [$this->galleta->id => '6']])
            ->assertSessionHasNoErrors()->assertRedirect(route('rutas.sacar-pedido.index'));
        $this->assertSame(2, PedidoSala::where('sacado_por_id', $ficha->id)->count());
    }
}
