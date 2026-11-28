<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Enums\ResultadoEntrega;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\Ruta;
use App\Models\SalidaRutaEntrega;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\PedidosFabrica;
use App\Services\Rutas\PedidosSala;
use App\Services\Rutas\ResumenDiarioPedidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El probador del día del vendedor (27/11/2026, antes del primer sábado de uso real): lo que
 * un formulario o un enlace puede mandar raro (listas donde va un texto, cantidades con coma,
 * negativas o enormes) no da 500: vuelve con un aviso en castellano y no guarda nada.
 * Datos ficticios.
 */
class ProbadorDiaVendedorTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private PersonalRuta $persona;

    private ClienteSucursal $sala;

    private Producto $galleta;

    protected function setUp(): void
    {
        parent::setUp();
        // Sábado, a media mañana en El Salvador.
        $this->travelTo('2026-11-28 09:00:00');
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28', 'rutas.ambiente_ccf' => null]);

        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Vendedor de prueba', 'user_id' => $this->vendedor->id]);
        $this->persona->funciones()->create(['funcion' => 'vendedor']);

        $departamento = Departamento::firstOrCreate(['codigo' => '02'], ['nombre' => 'Santa Ana', 'activo' => true]);
        $ruta = Ruta::create(['nombre' => 'Zona de prueba']);
        $this->sala = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba', 'requiere_orden_compra' => false])
            ->sucursales()->create(['nombre' => 'Sala de prueba', 'codigo' => '9001', 'ruta_id' => $ruta->id, 'departamento_id' => $departamento->id]);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01', 'precio_unitario' => 1.25]);
    }

    /** Un CCF aceptado de la sala, por entregar. */
    private function ccf(): Dte
    {
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Empresa de prueba'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $n = 700 + Dte::count();

        return Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $this->sala->cliente_id, 'cliente_sucursal_id' => $this->sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'numero_orden_compra' => '26112832'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'fecha_emision' => now()->toDateString(), 'hora_emision' => '07:00:00', 'total_pagar' => '100.25',
        ]);
    }

    /** Lo que necesita la oficina para crear el borrador del CCF. */
    private function prepararFacturacion(): void
    {
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Empresa de prueba'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        PuntoVenta::create(['establecimiento_id' => $establecimiento->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    // ---------------------------------------------------------------- el día completo

    public function test_el_dia_del_vendedor_de_punta_a_punta_y_cada_quien_en_lo_suyo(): void
    {
        $this->prepararFacturacion();
        $otro = $this->usuario('vendedor');
        PersonalRuta::create(['nombre' => 'Otro vendedor de prueba', 'user_id' => $otro->id])->funciones()->create(['funcion' => 'vendedor']);
        $fabrica = $this->usuario('fabrica');
        $oficina = $this->usuario('facturacion');
        $jefatura = $this->usuario('jefatura');

        // 1. Entra y cae en su inicio: sin viaje todavía, con «Sacar pedido» y «Por entregar».
        $this->actingAs($this->vendedor)->get(route('rutas.mi-salida.index'))->assertOk()
            ->assertSee(route('rutas.sacar-pedido.index'))->assertSee(route('rutas.por-entregar.index'));

        // 2. Busca la sala, abre el sugerido y saca el pedido con la OC.
        $this->get(route('rutas.sacar-pedido.index', ['q' => 'Sala de prueba']))->assertOk()->assertSee('Sala de prueba');
        $this->get(route('rutas.sacar-pedido.create', $this->sala))->assertOk()->assertSee('Galleta de prueba');
        $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => '12'], 'numero_orden_compra' => '2611283200001'])
            ->assertSessionHasNoErrors()->assertRedirect(route('rutas.sacar-pedido.index'));
        $pedido = PedidoSala::sole();
        $this->assertSame([$this->persona->id, EstadoPedidoSala::Registrado, '2026-11-28'], [$pedido->sacado_por_id, $pedido->estado, $pedido->fecha->toDateString()]);
        $viaje = $pedido->salida;
        $this->assertTrue($viaje->esViaje(), 'El primer pedido abre su viaje.');

        // El doble toque no saca otro, y otro vendedor en la misma sala tampoco.
        $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => '12']])->assertSessionHasErrors('lineas');
        $this->actingAs($otro)->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => '3']])
            ->assertSessionHasErrors(['lineas' => 'El pedido de «Sala de prueba» ya lo sacó hoy Vendedor de prueba.']);
        $this->actingAs($otro)->get(route('rutas.sacar-pedido.edit', $pedido))->assertForbidden();
        $this->assertSame(1, PedidoSala::count());

        // Lo corrige mientras la fábrica no empezó.
        $this->actingAs($this->vendedor)->put(route('rutas.sacar-pedido.update', $pedido), ['cantidades' => [$this->galleta->id => '10'], 'numero_orden_compra' => '2611283200001'])
            ->assertSessionHasNoErrors();
        $this->assertSame('10.0000', $pedido->lineas()->sole()->cantidad);

        // 3. La fábrica lo manda; desde ahí el vendedor ya no lo corrige. La fábrica no factura.
        $pedido = PedidoSala::query()->with(app(PedidosFabrica::class)->relaciones())->findOrFail($pedido->id);
        $this->actingAs($fabrica)->get(route('fabrica.pedidos.index'))->assertOk()->assertSee('Sala de prueba');
        $this->post(route('fabrica.pedidos.mandar', $pedido), [
            'clave' => (string) Str::uuid(), 'version' => $pedido->versionProduccion(),
            'cantidades' => [$pedido->lineas->sole()->id => '10'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::Enviado, $pedido->refresh()->estado);
        $this->get(route('facturacion.llego-de-fabrica'))->assertForbidden();
        $this->post(route('facturacion.pedidos.facturar', $pedido), ['numero_orden_compra' => '2611283200001'])->assertForbidden();
        $this->actingAs($this->vendedor)->put(route('rutas.sacar-pedido.update', $pedido), ['cantidades' => [$this->galleta->id => '4']])
            ->assertSessionHasErrors(['lineas' => PedidosSala::EN_FABRICA]);
        $this->get(route('facturacion.llego-de-fabrica'))->assertForbidden();

        // 4. La oficina lo ve en «Llegó de pedidos» y crea el borrador; se emite.
        $this->actingAs($oficina)->get(route('facturacion.llego-de-fabrica'))->assertOk()->assertSee('Sala de prueba');
        $this->post(route('facturacion.pedidos.facturar', $pedido), ['numero_orden_compra' => '2611283200001'])->assertSessionHasNoErrors();
        $dte = Dte::sole();
        // Lo que hace Hacienda al aceptarlo (acá no se transmite nada).
        $dte->update(['estado' => 'aceptado', 'ambiente' => '01', 'sello_recepcion' => '2026'.strtoupper(Str::random(36)),
            'fecha_procesamiento_mh' => now(), 'numero_control' => 'DTE-03-M001P001-000000000000901', 'total_pagar' => '12.50']);

        // 5. El vendedor lo entrega desde «Por entregar»: Jefatura mira pero no marca.
        $this->actingAs($jefatura)->get(route('rutas.por-entregar.index'))->assertOk()->assertSee($dte->numero_control);
        $this->actingAs($jefatura)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo'])->assertForbidden();
        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index'))->assertOk()->assertSee($dte->numero_control);
        $this->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo', 'entregado_por_id' => $this->persona->id])
            ->assertSessionHasNoErrors();
        $entrega = SalidaRutaEntrega::sole();
        $this->assertSame([$viaje->id, ResultadoEntrega::Entregado, '12.50'], [$entrega->salida_ruta_id, $entrega->resultado, $entrega->monto_cobrado]);
        // El doble toque no anota otra.
        $this->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo', 'entregado_por_id' => $this->persona->id])
            ->assertSessionHas('status');
        $this->assertSame(1, SalidaRutaEntrega::count());

        // Se equivocó: deshace y lo anota con quedan.
        $this->patch(route('rutas.mi-salida.deshacer', [$viaje, $entrega]))->assertSessionHasNoErrors();
        $this->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado', 'forma_cobro' => 'sin_cobro',
            'quedan_numero' => 'Q-100', 'quedan_fecha_pago' => '2026-12-05', 'entregado_por_id' => $this->persona->id])->assertSessionHasNoErrors();
        $entrega = SalidaRutaEntrega::where('dte_id', $dte->id)->entregadas()->sole();
        $this->assertSame([null, 'Q-100', '2026-12-05'], [$entrega->forma_cobro, $entrega->quedan_numero, $entrega->quedan_fecha_pago?->toDateString()]);

        // 6. Termina el día: mañana no sigue en la zona y el viaje deja de sumar.
        $this->get(route('rutas.mi-salida.dia'))->assertOk()->assertSee('Sala de prueba');
        $this->post(route('rutas.mi-salida.terminar-dia', $viaje), ['sigue' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($viaje->refresh()->suma_pedidos);

        // 7. Jefatura mira «Pedidos y entregas» y el correo de las 18:00 cuenta lo del día.
        $this->actingAs($jefatura)->get(route('rutas.pedidos-entregas'))->assertOk()->assertSee('Sala de prueba');
        $resumen = app(ResumenDiarioPedidos::class)->contenido(Carbon::parse('2026-11-27 18:00:00'), Carbon::parse('2026-11-28 18:00:00'));
        $this->assertCount(1, $resumen['salas']['Sala de prueba']['llego']);
        $this->assertCount(1, $resumen['salas']['Sala de prueba']['salio']);
    }

    /**
     * «Volvió a la fábrica» dice que, si la sala lo quiere, se saca otro pedido. Hoy, el que
     * volvió sigue contando como sacado y frena el nuevo en la misma sala.
     */
    public function test_despues_de_que_volvio_a_la_fabrica_la_sala_puede_pedir_otro(): void
    {
        $this->prepararFacturacion();
        $oficina = $this->usuario('facturacion');
        $this->actingAs($this->vendedor)->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => '12']])
            ->assertSessionHasNoErrors();
        $pedido = PedidoSala::sole();
        $envio = $pedido->envios()->create(['numero' => 1, 'clave' => (string) Str::uuid(), 'fecha' => '2026-11-28', 'registrado_por' => $oficina->id]);
        $envio->lineas()->create(['pedido_sala_linea_id' => $pedido->lineas()->sole()->id, 'cantidad' => '12']);
        $pedido->update(['estado' => EstadoPedidoSala::Enviado]);
        $this->actingAs($oficina)->patch(route('facturacion.pedidos.volvio', $pedido), ['motivo' => 'La sala estaba cerrada'])->assertSessionHasNoErrors();
        $this->assertSame(EstadoPedidoSala::Volvio, $pedido->refresh()->estado);

        // En sus pedidos de hoy dice que volvió y qué hacer, no que está en despacho.
        $this->actingAs($this->vendedor)->get(route('rutas.sacar-pedido.index'))->assertOk()
            ->assertSee('Volvió a la fábrica: si la sala lo quiere, sacá otro pedido.')->assertDontSee('Ya está en despacho');
        $this->get(route('rutas.sacar-pedido.index', ['q' => 'Sala de prueba']))->assertOk()->assertDontSee('Pedido sacado');
        $this->get(route('rutas.sacar-pedido.create', $this->sala))->assertOk();
        $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => '12']])->assertSessionHasNoErrors();
        $nuevo = PedidoSala::whereKeyNot($pedido->id)->sole();
        $this->assertSame([EstadoPedidoSala::Registrado, $pedido->salida_ruta_id], [$nuevo->estado, $nuevo->salida_ruta_id]);
        // El nuevo sí cuenta: otro toque no saca un tercero.
        $this->get(route('rutas.sacar-pedido.index', ['q' => 'Sala de prueba']))->assertSee('Pedido sacado');
        $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => '12']])->assertSessionHasErrors('lineas');
        $this->assertSame(2, PedidoSala::count());
    }

    /**
     * Medianoche en El Salvador: lo de las 23:59 es del sábado (su pedido, su entrega y su PDF)
     * y a las 00:00 del domingo «hoy» ya es otro día, aunque el viaje siga abierto.
     */
    public function test_a_medianoche_lo_del_sabado_queda_en_el_sabado(): void
    {
        $dte = $this->ccf();
        $this->travelTo(Carbon::parse('2026-11-28 23:59:30', 'America/El_Salvador'));
        $this->actingAs($this->vendedor)->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => '6']])
            ->assertSessionHasNoErrors();
        $this->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo', 'entregado_por_id' => $this->persona->id])
            ->assertSessionHasNoErrors();
        $pedido = PedidoSala::sole();
        $entrega = SalidaRutaEntrega::sole();
        $this->assertSame('2026-11-28', $pedido->fecha->toDateString());
        $this->assertSame('2026-11-28', $entrega->fecha_resultado->toDateString());

        $this->travelTo(Carbon::parse('2026-11-29 00:00:30', 'America/El_Salvador'));
        // «Tus pedidos de hoy» y «Marcados hoy» ya no lo traen; el día del sábado sí, con su PDF.
        $this->get(route('rutas.sacar-pedido.index'))->assertOk()->assertViewHas('pedidosHoy', fn ($lista) => $lista->isEmpty());
        $this->get(route('rutas.por-entregar.index'))->assertOk()->assertViewHas('marcados', fn ($lista) => $lista->isEmpty());
        $this->get(route('rutas.mi-salida.dia', ['fecha' => '2026-11-28']))->assertOk()
            ->assertSee('Sábado 28/11/2026')->assertSeeText('Efectivo a entregar: $100.25');
        $this->get(route('rutas.mi-salida.dia'))->assertOk()->assertSeeText('Efectivo a entregar: $0.00');
        $this->get(route('rutas.mi-salida.dia.pdf', ['fecha' => '2026-11-28']))->assertOk();
        // El viaje sigue sumando (el pedido es de ayer): la misma sala no se saca dos veces en él.
        $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => '6']])->assertSessionHasErrors('lineas');
        $this->assertSame(1, PedidoSala::count());
    }

    // ---------------------------------------------------------------- sacar pedido

    public function test_la_tienda_nueva_con_el_nombre_en_lista_vuelve_con_el_aviso(): void
    {
        $this->actingAs($this->vendedor)
            ->from(route('rutas.sacar-pedido.tienda-nueva.create'))
            ->post(route('rutas.sacar-pedido.tienda-nueva.store'), [
                'sala_nueva_nombre' => ['Tienda de prueba'],
                'cantidades' => [$this->galleta->id => '5'],
            ])
            ->assertRedirect(route('rutas.sacar-pedido.tienda-nueva.create'))
            ->assertSessionHasErrors(['sala_nueva_nombre' => 'Escribí el nombre de la tienda.']);

        $this->assertSame(0, PedidoSala::count());
    }

    public function test_cantidades_raras_vuelven_con_el_aviso_y_no_guardan_nada(): void
    {
        $this->actingAs($this->vendedor);
        $raras = [
            'con coma' => '1,5',
            'negativa' => '-3',
            'enorme' => '100000',
            'cero' => '0',
            'con exponente' => '1e3',
            'con letras' => 'doce',
            'con cinco decimales' => '1.23456',
            'en lista' => ['5'],
        ];
        foreach ($raras as $caso => $cantidad) {
            $this->from(route('rutas.sacar-pedido.create', $this->sala))
                ->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => $cantidad]])
                ->assertRedirect(route('rutas.sacar-pedido.create', $this->sala))
                ->assertSessionHasErrors('lineas.0.cantidad', "Cantidad {$caso}");
        }
        // Las cantidades como texto suelto o la OC y las notas en lista, tampoco.
        $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => 'doce'])->assertSessionHasErrors();
        $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => '5'], 'numero_orden_compra' => ['OC-1']])
            ->assertSessionHasErrors('numero_orden_compra');
        $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => '5'], 'notas' => ['nota']])
            ->assertSessionHasErrors('notas');
        $this->post(route('rutas.sacar-pedido.store', $this->sala), ['cantidades' => [$this->galleta->id => '5'], 'confirmar_cantidades' => ['1']])
            ->assertSessionHasErrors('confirmar_cantidades');
        // El buscador y la tienda nueva con la búsqueda en lista: aviso, no 500.
        $this->get(route('rutas.sacar-pedido.index', ['q' => ['sala']]))->assertRedirect();
        $this->getJson(route('rutas.sacar-pedido.salas', ['q' => ['sala']]))->assertUnprocessable();
        $this->get(route('rutas.sacar-pedido.tienda-nueva.create', ['nombre' => ['Tienda']]))->assertRedirect();

        $this->assertSame(0, PedidoSala::count());
    }

    // ---------------------------------------------------------------- por entregar

    public function test_por_entregar_con_los_filtros_en_lista_abre_igual(): void
    {
        $dte = $this->ccf();

        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index', ['ver' => ['todas']]))
            ->assertOk()->assertSee($dte->numero_control);
        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index', ['ruta' => ['sin']]))
            ->assertOk()->assertSee($dte->numero_control);
    }

    public function test_el_tablero_y_todos_los_viajes_con_los_filtros_en_lista_abren_igual(): void
    {
        $jefatura = User::factory()->create(['activo' => true])->assignRole('jefatura');

        $this->actingAs($jefatura)->get(route('rutas.dashboard', ['q' => ['sala'], 'ver' => ['todas'], 'ruta' => ['1']]))->assertOk();
        $this->actingAs($jefatura)->get(route('rutas.salidas.index', ['estado' => ['en_curso'], 'ruta_id' => ['1']]))->assertOk();
    }

    public function test_marcar_con_un_dato_en_lista_vuelve_con_el_aviso_y_no_anota_nada(): void
    {
        $dte = $this->ccf();
        $this->actingAs($this->vendedor);
        $base = ['resultado' => 'entregado', 'forma_cobro' => 'efectivo', 'entregado_por_id' => $this->persona->id];
        $casos = [
            'resultado' => ['resultado' => ['entregado']],
            'forma_cobro' => ['forma_cobro' => ['efectivo']],
            'nota' => ['nota' => ['Devolvió una caja']],
            'quedan_numero' => ['forma_cobro' => 'sin_cobro', 'quedan_numero' => ['Q-1']],
            'quedan_fecha_pago' => ['forma_cobro' => 'sin_cobro', 'quedan_fecha_pago' => ['2026-12-05']],
            'motivo_no_entrega' => ['resultado' => 'no_entregado', 'motivo_no_entrega' => ['cerrado']],
        ];
        foreach ($casos as $campo => $datos) {
            $this->from(route('rutas.por-entregar.index'))
                ->post(route('rutas.por-entregar.marcar', $dte), $datos + $base)
                ->assertRedirect(route('rutas.por-entregar.index'))
                ->assertSessionHasErrors($campo);
        }

        $this->assertSame(0, SalidaRutaEntrega::whereNotNull('resultado')->count());
        // Con los datos bien, se anota como siempre.
        $this->post(route('rutas.por-entregar.marcar', $dte), $base)->assertSessionHasNoErrors();
        $this->assertSame(ResultadoEntrega::Entregado, SalidaRutaEntrega::sole()->resultado);
    }
}
