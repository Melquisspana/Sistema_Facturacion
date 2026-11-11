<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoSalidaRuta;
use App\Enums\FormaCobroEntrega;
use App\Enums\MotivoNoEntrega;
use App\Enums\ResultadoEntrega;
use App\Enums\RolEnSalida;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\PpqAlbaran;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\DiaVendedor;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\Viajes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Por entregar con el viaje, la forma de pago y «Terminar el día» (Melqui, 10/11/2026):
 * cualquier vendedor entrega cualquier CCF; la entrega queda en el viaje del pedido (o en
 * el del que entrega); al entregar se marca cómo pagaron, salvo el supermercado que cobra por
 * el portal; el día cierra con su resumen y su PDF. Datos ficticios.
 */
class PorEntregarViajeTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private PersonalRuta $personaAna;

    private User $beto;

    private PersonalRuta $personaBeto;

    private Cliente $cliente;

    /** @var array<string, ClienteSucursal> */
    private array $salas = [];

    /** @var array<string, Ruta> */
    private array $rutas = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Martes.
        $this->travelTo('2026-11-10 09:00:00');
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28', 'rutas.ambiente_ccf' => null]);
        $this->ana = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->personaAna = PersonalRuta::create(['nombre' => 'Ana Ficticia', 'user_id' => $this->ana->id]);
        $this->beto = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->personaBeto = PersonalRuta::create(['nombre' => 'Beto Ficticio', 'user_id' => $this->beto->id]);

        $this->cliente = Cliente::factory()->create(['nombre' => 'Cliente de prueba']);
        foreach (['San Miguel' => ['12', 'Sala de prueba Oriente'], 'Santa Ana' => ['02', 'Sala de prueba Occidente']] as $zona => [$codigo, $sala]) {
            $dep = Departamento::firstOrCreate(['codigo' => $codigo], ['nombre' => $zona, 'activo' => true]);
            $this->rutas[$zona] = Ruta::create(['nombre' => $zona]);
            $this->salas[$zona] = $this->cliente->sucursales()->create(['nombre' => $sala, 'ruta_id' => $this->rutas[$zona]->id, 'departamento_id' => $dep->id]);
        }
        $this->salas['otra San Miguel'] = $this->cliente->sucursales()->create(['nombre' => 'Tienda de prueba Oriente', 'ruta_id' => $this->rutas['San Miguel']->id]);
        $this->salas['sin ruta'] = $this->cliente->sucursales()->create(['nombre' => 'Tienda de prueba sin zona']);
    }

    /** Lo que hace «Sacar pedido»: el viaje y el pedido en él. */
    private function sacar(string $sala, PersonalRuta $persona, User $usuario): PedidoSala
    {
        return DB::transaction(function () use ($sala, $persona, $usuario) {
            $sala = $this->salas[$sala];
            $viaje = app(Viajes::class)->paraPedido($persona, $sala, $usuario);

            return $viaje->pedidos()->create([
                'cliente_sucursal_id' => $sala->id, 'ruta_id' => $sala->ruta_id, 'sacado_por_id' => $persona->id,
                'registrado_por' => $usuario->id, 'fecha' => now()->toDateString(), 'estado' => EstadoPedidoSala::Registrado,
            ]);
        });
    }

    private function ccf(ClienteSucursal $sala, string $total = '100.25', ?Cliente $cliente = null): Dte
    {
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Empresa de prueba'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $n = 700 + Dte::count();

        return Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => ($cliente ?? $this->cliente)->id, 'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'numero_orden_compra' => '26110232'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'fecha_emision' => now()->toDateString(), 'hora_emision' => '07:00:00', 'total_pagar' => $total,
        ]);
    }

    /** La fábrica lo mandó y se facturó en un CCF. */
    private function facturado(PedidoSala $pedido, string $total = '100.25'): Dte
    {
        $dte = $this->ccf($pedido->sala, $total);
        $pedido->envios()->create(['numero' => 1, 'clave' => (string) Str::uuid(), 'fecha' => now()->toDateString(),
            'registrado_por' => $this->ana->id, 'dte_id' => $dte->id]);
        $pedido->update(['estado' => EstadoPedidoSala::Enviado]);

        return $dte;
    }

    // ---------------------------------------------------------------- por entregar

    public function test_otro_vendedor_entrega_el_ccf_y_queda_en_el_viaje_del_pedido(): void
    {
        $pedido = $this->sacar('San Miguel', $this->personaAna, $this->ana);
        $viaje = $pedido->salida;
        $dte = $this->facturado($pedido);

        // Beto no sacó el pedido y no tiene viaje: lo ve en «Todas» y lo entrega.
        $this->actingAs($this->beto)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee($dte->numero_control)->assertSee('Lo sacó Ana Ficticia');
        $this->actingAs($this->beto)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo'])
            ->assertSessionHasNoErrors();

        $entrega = SalidaRutaEntrega::sole();
        $this->assertSame($viaje->id, $entrega->salida_ruta_id, 'La entrega queda en el viaje del pedido.');
        $this->assertSame(ResultadoEntrega::Entregado, $entrega->resultado);
        $this->assertSame($this->personaBeto->id, $entrega->entregado_por_id);
        $this->assertSame(FormaCobroEntrega::Efectivo, $entrega->forma_cobro);
        $this->assertSame('100.25', $entrega->monto_cobrado);
        // Beto queda en el viaje como acompañante; no se arma otra salida.
        $this->assertSame(1, SalidaRuta::count());
        $this->assertSame(RolEnSalida::Acompanante, $viaje->participantes()->where('rutas_personal_id', $this->personaBeto->id)->sole()->rol);
    }

    public function test_el_ccf_sin_pedido_y_sin_ruta_va_al_viaje_del_que_entrega(): void
    {
        $viaje = $this->sacar('San Miguel', $this->personaAna, $this->ana)->salida;
        $suelto = $this->ccf($this->salas['sin ruta']);

        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $suelto), ['resultado' => 'entregado', 'forma_cobro' => EntregasCcf::SIN_COBRO])
            ->assertSessionHasNoErrors();

        $entrega = SalidaRutaEntrega::sole();
        $this->assertSame($viaje->id, $entrega->salida_ruta_id);
        $this->assertNull($entrega->forma_cobro, 'Quedan: no se cobra hoy.');
        $this->assertNull($entrega->monto_cobrado);
    }

    public function test_este_viaje_y_toda_la_zona(): void
    {
        $pedido = $this->sacar('San Miguel', $this->personaAna, $this->ana);
        $delViaje = $this->facturado($pedido);
        // Sin pedido, de una sala de la misma zona: sale en «Toda la zona».
        $deLaZona = $this->ccf($this->salas['otra San Miguel']);
        $deOtraZona = $this->ccf($this->salas['Santa Ana']);

        $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('Este viaje 1')->assertSee('Toda la zona 2')
            ->assertSee($delViaje->numero_control)->assertDontSee($deLaZona->numero_control)->assertDontSee($deOtraZona->numero_control);
        $this->actingAs($this->ana)->get(route('rutas.por-entregar.index', ['ver' => 'zona']))->assertOk()
            ->assertSee($delViaje->numero_control)->assertSee($deLaZona->numero_control)->assertDontSee($deOtraZona->numero_control);
        $this->actingAs($this->ana)->get(route('rutas.por-entregar.index', ['ver' => 'todas']))->assertOk()
            ->assertSee($delViaje->numero_control)->assertSee($deLaZona->numero_control)->assertSee($deOtraZona->numero_control);
    }

    public function test_entregado_pide_como_pagaron(): void
    {
        $dte = $this->ccf($this->salas['San Miguel']);
        $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('¿Cómo te pagaron?')->assertSee('Efectivo')->assertSee('Cheque')->assertSee('Transferencia')->assertSee('Quedan');

        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado'])
            ->assertSessionHasErrors(['forma_cobro' => 'Elegí cómo te pagaron: efectivo, cheque, transferencia o quedan.']);
        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado', 'forma_cobro' => 'bitcoin'])
            ->assertSessionHasErrors('forma_cobro');
        $this->assertSame(0, SalidaRutaEntrega::count());
        $this->assertSame(0, SalidaRuta::count());

        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado', 'forma_cobro' => 'cheque'])
            ->assertSessionHasNoErrors();
        $entrega = SalidaRutaEntrega::sole();
        $this->assertSame(FormaCobroEntrega::Cheque, $entrega->forma_cobro);
        $this->assertSame('100.25', $entrega->monto_esperado);
        $this->assertSame('100.25', $entrega->monto_cobrado);
        // El dinero queda en la auditoría: cómo pagaron y cuánto.
        $log = Activity::where('log_name', 'salida_ruta_entrega')->where('subject_id', $entrega->id)
            ->where('description', 'registró la entrega del CCF')->sole();
        $this->assertSame('cheque', $log->properties['attributes']['forma_cobro']);
        $this->assertSame('100.25', $log->properties['attributes']['monto_cobrado']);
    }

    public function test_el_supermercado_del_portal_no_pide_forma_de_pago(): void
    {
        $super = Cliente::factory()->create(['nombre' => 'Súper de prueba', 'cobro_en_entrega' => 'efectivo']);
        config(['ppq.cliente_default_id' => $super->id]);
        $salaSuper = $super->sucursales()->create(['nombre' => 'Súper de prueba Oriente', 'ruta_id' => $this->rutas['San Miguel']->id]);
        $dte = $this->ccf($salaSuper, '310.80', $super);

        $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('El cobro va por el portal; la entrega se confirma sola con el albarán.')
            ->assertDontSee('¿Cómo te pagaron?');
        // Aunque llegue una forma de pago, no se cobra nada al entregar.
        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo'])
            ->assertSessionHasNoErrors();
        $entrega = SalidaRutaEntrega::sole();
        $this->assertSame(ResultadoEntrega::Entregado, $entrega->resultado);
        $this->assertNull($entrega->forma_cobro);
        $this->assertNull($entrega->monto_cobrado);
    }

    // ---------------------------------------------------------------- el día

    public function test_terminar_el_dia_resume_y_saca_el_pdf(): void
    {
        $viaje = $this->sacar('San Miguel', $this->personaAna, $this->ana)->salida;
        $marcar = fn (Dte $dte, array $datos) => $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), $datos)->assertSessionHasNoErrors();
        $marcar($this->ccf($this->salas['San Miguel'], '142.00'), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo']);
        $marcar($this->ccf($this->salas['San Miguel'], '20.40'), ['resultado' => 'entregado', 'forma_cobro' => 'cheque']);
        $marcar($this->ccf($this->salas['San Miguel'], '24.00'), ['resultado' => 'entregado', 'forma_cobro' => 'transferencia']);
        $marcar($this->ccf($this->salas['otra San Miguel'], '200.00'), ['resultado' => 'entregado', 'forma_cobro' => EntregasCcf::SIN_COBRO]);
        $marcar($this->ccf($this->salas['otra San Miguel'], '110.80'), ['resultado' => 'entregado', 'forma_cobro' => EntregasCcf::SIN_COBRO]);
        $marcar($this->ccf($this->salas['San Miguel'], '15.00'), ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'sala_cerrada']);
        // Lo de otro vendedor no entra en su día.
        $this->actingAs($this->beto)->post(route('rutas.por-entregar.marcar', $this->ccf($this->salas['San Miguel'], '999.00')), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo'])
            ->assertSessionHasNoErrors();

        $resumen = app(DiaVendedor::class)->resumen($this->personaAna, $this->ana, '2026-11-10');
        $this->assertSame(5, $resumen['entregadas']->count());
        $this->assertSame(1, $resumen['no_entregadas']->count());
        $this->assertSame(14200, $resumen['efectivo']);
        $this->assertSame(2040, $resumen['cheque']);
        $this->assertSame(2400, $resumen['transferencia']);
        $this->assertSame(18640, $resumen['cobrado']);
        $this->assertEquals(['cantidad' => 2, 'monto' => 31080], $resumen['quedan']);

        $this->actingAs($this->ana)->get(route('rutas.mi-salida.index'))->assertOk()
            ->assertSeeInOrder(['Entregas marcadas', '5', 'Cobrado', '$186.40'])
            ->assertSee('href="'.route('rutas.mi-salida.dia').'"', false);
        $this->actingAs($this->ana)->get(route('rutas.mi-salida.dia'))->assertOk()
            ->assertSeeInOrder(['Entregas', '5', 'No se pudo', '1', 'Efectivo', '$142.00', 'Cheque / transf.', '$44.40'])
            ->assertSee('2 facturas, $310.80')
            ->assertSee('¿Mañana seguís en la misma zona?')
            ->assertSee('action="'.route('rutas.mi-salida.terminar-dia', $viaje).'"', false)
            ->assertSee('href="'.route('rutas.mi-salida.dia.pdf').'"', false);

        $pdf = $this->actingAs($this->ana)->get(route('rutas.mi-salida.dia.pdf'));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_el_dia_es_solo_del_vendedor(): void
    {
        $oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->actingAs($oficina)->get(route('rutas.mi-salida.dia'))->assertForbidden();
        $this->actingAs($oficina)->get(route('rutas.mi-salida.dia.pdf'))->assertForbidden();
    }

    public function test_el_inicio_cuenta_lo_que_falta_entregar(): void
    {
        $this->facturado($this->sacar('San Miguel', $this->personaAna, $this->ana));
        $this->facturado($this->sacar('otra San Miguel', $this->personaAna, $this->ana));
        $this->ccf($this->salas['Santa Ana']);

        $this->actingAs($this->ana)->get(route('rutas.mi-salida.index'))->assertOk()
            ->assertSeeInOrder(['Por entregar', '2']);
    }

    // ---------------------------------------------------------------- sacar pedido

    public function test_el_buscador_marca_la_sala_que_le_toca(): void
    {
        // Compra cada 7 días; la última fue hace 9: le toca.
        foreach (['2026-10-11', '2026-10-18', '2026-10-25', '2026-11-01'] as $fecha) {
            $this->travelTo($fecha.' 08:00:00');
            $this->ccf($this->salas['San Miguel']);
        }
        $this->travelTo('2026-11-10 09:00:00');
        $this->ccf($this->salas['otra San Miguel']);

        $this->actingAs($this->ana)->get(route('rutas.sacar-pedido.index', ['q' => 'prueba Oriente']))->assertOk()
            ->assertSeeInOrder(['Sala de prueba Oriente', 'Le toca'])
            ->assertSee('Última visita hace 9 días');
    }

    // ---------------------------------------------------------------- el viaje

    public function test_el_viaje_se_cierra_con_la_entrega_confirmada_por_el_albaran(): void
    {
        $pedido = $this->sacar('San Miguel', $this->personaAna, $this->ana);
        $viaje = $pedido->salida;
        $dte = $this->facturado($pedido);
        app(Viajes::class)->terminarDia($viaje, false, $this->ana);

        $this->travelTo('2026-11-11 04:00:00');
        $this->artisan('rutas:finalizar-automaticas')->assertSuccessful();
        $this->assertSame(EstadoSalidaRuta::EnCurso, $viaje->refresh()->estado, 'Sin entregar ni albarán: abierto.');

        // Nadie la marcó, pero llegó el albarán de entrega del supermercado.
        PpqAlbaran::create(['numero_albaran' => 'AC01/0232/00/6715', 'numero_orden_compra' => $dte->numero_orden_compra,
            'monto_albaran' => 100.25, 'fecha_albaran' => '2026-11-11', 'origen' => 'gmail']);
        $this->travelTo('2026-11-12 04:00:00');
        $this->artisan('rutas:finalizar-automaticas')->assertSuccessful();
        $this->assertSame(EstadoSalidaRuta::Finalizada, $viaje->refresh()->estado);
    }

    public function test_el_domingo_sin_pedidos_no_corta_el_viaje(): void
    {
        $this->travelTo('2026-11-14 09:00:00'); // sábado
        $viaje = $this->sacar('San Miguel', $this->personaAna, $this->ana)->salida;

        foreach (['2026-11-15 04:00:00', '2026-11-16 04:00:00'] as $momento) { // domingo y lunes
            $this->travelTo($momento);
            $this->artisan('rutas:finalizar-automaticas')->assertSuccessful();
            $this->assertTrue($viaje->refresh()->suma_pedidos, 'El domingo no cuenta como día sin pedidos.');
        }
        $this->travelTo('2026-11-16 09:00:00');
        $this->assertSame($viaje->id, $this->sacar('San Miguel', $this->personaAna, $this->ana)->salida_ruta_id);

        // Un sábado entero sin pedidos sí corta: del viernes al lunes ya no son días seguidos.
        $this->travelTo('2026-11-20 09:00:00'); // viernes
        $otro = $this->sacar('Santa Ana', $this->personaAna, $this->ana)->salida;
        $this->travelTo('2026-11-22 04:00:00'); // domingo
        $this->artisan('rutas:finalizar-automaticas')->assertSuccessful();
        $this->assertFalse($otro->refresh()->suma_pedidos);
    }

    // ---------------------------------------------------------------- decisiones del 10/11/2026

    /** Pedido mandado en parte y cerrado: pidió 12 galletas, vinieron 8 («Sin existencia»). */
    private function pedidoConFaltante(): array
    {
        UnidadMedida::firstOrCreate(['codigo' => '59'], ['nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01', 'precio_unitario' => 1.75]);
        $pedido = $this->sacar('San Miguel', $this->personaAna, $this->ana);
        $linea = $pedido->lineas()->create(['producto_id' => $galleta->id, 'cantidad' => '12', 'motivo_no_enviado' => 'Sin existencia']);
        $dte = $this->facturado($pedido);
        $pedido->envios()->sole()->lineas()->create(['pedido_sala_linea_id' => $linea->id, 'cantidad' => '8']);

        return [$pedido, $dte, $galleta];
    }

    public function test_sacar_pedido_no_muestra_precios(): void
    {
        UnidadMedida::firstOrCreate(['codigo' => '59'], ['nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01', 'precio_unitario' => 1.75]);
        $this->actingAs($this->ana)->get(route('rutas.sacar-pedido.create', $this->salas['San Miguel']))->assertOk()
            ->assertSee('Galleta de prueba')->assertDontSee('1.75')->assertDontSee('Precio');
    }

    public function test_no_se_pudo_con_los_motivos_nuevos_y_aviso_a_las_dos_veces(): void
    {
        $dte = $this->ccf($this->salas['San Miguel']);
        $this->actingAs($this->beto)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSeeInOrder(['No alcanzó el tiempo', 'Estaba cerrada', 'No había quien recibiera', 'No quiso'])
            ->assertDontSee('La sala ya no recibía a esa hora');

        $this->actingAs($this->beto)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'sin_quien_reciba'])
            ->assertSessionHasNoErrors();
        $this->assertSame(MotivoNoEntrega::SinQuienReciba, SalidaRutaEntrega::sole()->motivo_no_entrega);
        // La factura sigue en «Por entregar»; una vez no avisa.
        $this->actingAs($this->beto)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee($dte->numero_control)->assertDontSee('avisale al jefe');

        $this->travelTo('2026-11-11 09:00:00');
        $this->actingAs($this->beto)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'no_aceptado'])
            ->assertSessionHasNoErrors();
        $this->assertEquals([$dte->id => 2], app(EntregasCcf::class)->fallas([$dte->id]));
        // Al otro día sigue en la lista, con el aviso.
        $this->travelTo('2026-11-12 08:00:00');
        $this->actingAs($this->beto)->get(route('rutas.por-entregar.index'))->assertOk()->assertSee($dte->numero_control)
            ->assertSee('No se pudo entregar 2 veces: avisale al jefe.');
    }

    public function test_quedan_con_numero_y_fecha_de_pago_queda_por_cobrar(): void
    {
        $dte = $this->ccf($this->salas['San Miguel']);
        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado', 'forma_cobro' => EntregasCcf::SIN_COBRO,
            'quedan_numero' => 'Q-0451', 'quedan_fecha_pago' => '2026-11-03'])->assertSessionHasErrors('quedan_fecha_pago');
        $this->assertSame(0, SalidaRutaEntrega::whereNotNull('resultado')->count());

        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado', 'forma_cobro' => EntregasCcf::SIN_COBRO,
            'quedan_numero' => ' Q-0451 ', 'quedan_fecha_pago' => '2026-11-24'])->assertSessionHasNoErrors();
        $entrega = SalidaRutaEntrega::entregadas()->sole();
        $this->assertSame('Q-0451', $entrega->quedan_numero);
        $this->assertSame('2026-11-24', $entrega->quedan_fecha_pago->toDateString());
        $this->assertTrue(SalidaRutaEntrega::quedanPorCobrar()->sole()->is($entrega));

        // Con efectivo no se guarda un quedan.
        $otro = $this->ccf($this->salas['San Miguel']);
        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $otro), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo',
            'quedan_numero' => 'Q-9', 'quedan_fecha_pago' => '2026-11-24'])->assertSessionHasNoErrors();
        $this->assertNull(SalidaRutaEntrega::where('dte_id', $otro->id)->sole()->quedan_numero);

        $this->actingAs($this->ana)->get(route('rutas.mi-salida.dia'))->assertOk()->assertSee('N.º Q-0451 · paga el 24/11/2026');
    }

    public function test_la_observacion_de_la_entrega_queda_guardada(): void
    {
        $dte = $this->ccf($this->salas['San Miguel']);
        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo',
            'nota' => 'Devolvieron 2 cajas golpeadas'])->assertSessionHasNoErrors();
        $this->assertSame('Devolvieron 2 cajas golpeadas', SalidaRutaEntrega::sole()->nota);
        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $this->ccf($this->salas['San Miguel'])), ['resultado' => 'entregado',
            'forma_cobro' => 'efectivo', 'nota' => str_repeat('a', 301)])->assertSessionHasErrors('nota');
    }

    public function test_el_pdf_del_dia_lleva_las_firmas(): void
    {
        $html = view('rutas.mi-salida.dia-pdf', [
            'resumen' => app(DiaVendedor::class)->resumen($this->personaAna, $this->ana, '2026-11-10'),
            'persona' => $this->personaAna, 'empresa' => 'Empresa de prueba', 'generado' => now(),
        ])->render();
        $this->assertStringContainsString('Entregó: ______________________', $html);
        $this->assertStringContainsString('Recibió: ______________________', $html);
        $this->assertStringContainsString('el vendedor entrega a la oficina el efectivo, los cheques y los quedan', $html);
    }

    public function test_no_vino_en_por_entregar_y_la_hoja_con_observaciones_para_firmar(): void
    {
        [$pedido, $dte] = $this->pedidoConFaltante();
        $this->actingAs($this->beto)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('No vino: 4 cajas de Galleta de prueba')
            ->assertSee('href="'.route('rutas.por-entregar.hoja', $dte).'"', false);

        $this->actingAs($this->beto)->get(route('rutas.por-entregar.hoja', $dte))->assertOk()
            ->assertSee('Observaciones')
            ->assertSee('Galleta de prueba: se pidieron 12, vinieron 8 (faltaron 4). Motivo: Sin existencia.')
            ->assertSee('Recibe (gerente de la sala)')->assertSee('Volver a «Por entregar»')
            ->assertDontSee('1.75');
        // Mandado en parte (todavía va otro envío): no es faltante.
        $pedido->update(['estado' => EstadoPedidoSala::Parcial]);
        $this->actingAs($this->beto)->get(route('rutas.por-entregar.index'))->assertOk()->assertDontSee('No vino:');
        $this->actingAs($this->beto)->get(route('rutas.por-entregar.hoja', $dte))->assertOk()
            ->assertSee('Galleta de prueba: se pidieron 12, vinieron 8 (faltan 4). Va en otro envío.');

        // Un CCF sin pedido no tiene hoja.
        $this->actingAs($this->beto)->get(route('rutas.por-entregar.hoja', $this->ccf($this->salas['Santa Ana'])))->assertNotFound();
    }
}
