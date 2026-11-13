<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoSalidaRuta;
use App\Enums\FormaCobroEntrega;
use App\Enums\MotivoNoEntrega;
use App\Enums\OrigenRegistroEntrega;
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
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\QuienEntrega;
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
        // Fichas de vendedor: solo un vendedor activo figura como quien entregó (12/11/2026).
        $this->personaAna->funciones()->create(['funcion' => 'vendedor']);
        $this->personaBeto->funciones()->create(['funcion' => 'vendedor']);

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
        $this->actingAs($this->beto)->post(route('rutas.por-entregar.marcar', $dte), QuienEntrega::yo(['resultado' => 'entregado', 'forma_cobro' => 'efectivo']))
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

    // ---------------------------------------------------------------- el viaje es de quien lo abrió

    /** Beto entrega un CCF del pedido de Ana: queda como acompañante en el viaje de Ana. */
    private function betoEntregaUnCcfDeAna(): SalidaRuta
    {
        $pedido = $this->sacar('San Miguel', $this->personaAna, $this->ana);
        $this->actingAs($this->beto)->post(route('rutas.por-entregar.marcar', $this->facturado($pedido)), QuienEntrega::yo(['resultado' => 'entregado', 'forma_cobro' => 'efectivo']))
            ->assertSessionHasNoErrors();
        $this->assertSame(RolEnSalida::Acompanante, $pedido->salida->participantes()->where('rutas_personal_id', $this->personaBeto->id)->sole()->rol);

        return $pedido->salida;
    }

    public function test_entregar_el_ccf_de_otro_no_le_da_su_viaje_en_el_inicio(): void
    {
        $viaje = $this->betoEntregaUnCcfDeAna();

        $this->assertNull(app(Viajes::class)->abierto($this->personaBeto), 'El viaje es de quien lo abrió.');
        $this->assertTrue(app(Viajes::class)->abierto($this->personaAna)->is($viaje));
        $this->actingAs($this->beto)->get(route('rutas.mi-salida.index'))->assertOk()
            ->assertSee('Todavía no tenés un viaje abierto')->assertDontSee('Tu viaje');
        $this->actingAs($this->beto)->get(route('rutas.mi-salida.dia'))->assertOk()
            ->assertDontSee('¿Mañana seguís en la misma zona?');
    }

    public function test_el_no_vuelvo_del_que_solo_entrego_no_corta_el_viaje_de_otro(): void
    {
        $viaje = $this->betoEntregaUnCcfDeAna();

        $this->actingAs($this->beto)->post(route('rutas.mi-salida.terminar-dia', $viaje), ['sigue' => 0])->assertForbidden();
        $this->assertTrue($viaje->refresh()->suma_pedidos);
        // Quien lo abrió sí lo corta.
        $this->actingAs($this->ana)->post(route('rutas.mi-salida.terminar-dia', $viaje), ['sigue' => 0])->assertRedirect();
        $this->assertFalse($viaje->refresh()->suma_pedidos);
    }

    public function test_el_pedido_en_otra_zona_del_que_solo_entrego_no_corta_el_viaje_de_otro(): void
    {
        $viaje = $this->betoEntregaUnCcfDeAna();

        $deBeto = $this->sacar('Santa Ana', $this->personaBeto, $this->beto);

        $this->assertNotSame($viaje->id, $deBeto->salida_ruta_id, 'Beto abre su propio viaje.');
        $this->assertTrue($viaje->refresh()->suma_pedidos, 'El viaje de Ana sigue sumando.');
        $this->assertSame(0, Activity::where('log_name', 'salida_ruta')->where('subject_id', $viaje->id)->where('description', 'el viaje dejó de sumar pedidos')->count());
        // Y Ana sigue sacando pedidos en el suyo.
        $this->assertSame($viaje->id, $this->sacar('otra San Miguel', $this->personaAna, $this->ana)->salida_ruta_id);
    }

    public function test_el_ccf_sin_pedido_no_cae_en_el_viaje_de_otro_vendedor(): void
    {
        $viaje = $this->sacar('San Miguel', $this->personaAna, $this->ana)->salida;
        $suelto = $this->ccf($this->salas['otra San Miguel']);

        $this->actingAs($this->beto)->post(route('rutas.por-entregar.marcar', $suelto), QuienEntrega::yo(['resultado' => 'entregado', 'forma_cobro' => 'efectivo']))
            ->assertSessionHasNoErrors();

        $entrega = SalidaRutaEntrega::sole();
        $this->assertNotSame($viaje->id, $entrega->salida_ruta_id, 'No sale de un pedido de Ana: no va a su viaje.');
        $this->assertFalse($entrega->salida->esViaje());
        $this->assertSame(0, $viaje->participantes()->where('rutas_personal_id', $this->personaBeto->id)->count());
    }

    public function test_el_ccf_sin_pedido_tampoco_cae_en_el_viaje_donde_solo_acompana(): void
    {
        $viaje = $this->betoEntregaUnCcfDeAna();
        $suelto = $this->ccf($this->salas['otra San Miguel']);

        $this->actingAs($this->beto)->post(route('rutas.por-entregar.marcar', $suelto), QuienEntrega::yo(['resultado' => 'entregado', 'forma_cobro' => 'efectivo']))
            ->assertSessionHasNoErrors();

        $entrega = SalidaRutaEntrega::where('dte_id', $suelto->id)->sole();
        $this->assertNotSame($viaje->id, $entrega->salida_ruta_id);
        $this->assertFalse($entrega->salida->esViaje());
    }

    public function test_el_ccf_sin_pedido_y_sin_ruta_va_al_viaje_del_que_entrega(): void
    {
        $viaje = $this->sacar('San Miguel', $this->personaAna, $this->ana)->salida;
        $suelto = $this->ccf($this->salas['sin ruta']);

        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $suelto), QuienEntrega::yo(['resultado' => 'entregado', 'forma_cobro' => EntregasCcf::SIN_COBRO]))
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

        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), QuienEntrega::yo(['resultado' => 'entregado']))
            ->assertSessionHasErrors(['forma_cobro' => 'Elegí cómo te pagaron: efectivo, cheque, transferencia o quedan.']);
        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), QuienEntrega::yo(['resultado' => 'entregado', 'forma_cobro' => 'bitcoin']))
            ->assertSessionHasErrors('forma_cobro');
        $this->assertSame(0, SalidaRutaEntrega::count());
        $this->assertSame(0, SalidaRuta::count());

        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), QuienEntrega::yo(['resultado' => 'entregado', 'forma_cobro' => 'cheque']))
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
        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), QuienEntrega::yo(['resultado' => 'entregado', 'forma_cobro' => 'efectivo']))
            ->assertSessionHasNoErrors();
        $entrega = SalidaRutaEntrega::sole();
        $this->assertSame(ResultadoEntrega::Entregado, $entrega->resultado);
        $this->assertNull($entrega->forma_cobro);
        $this->assertNull($entrega->monto_cobrado);
    }

    // ---------------------------------------------------------------- la forma de pago es regla del servicio

    public function test_en_el_viaje_el_vendedor_no_entrega_sin_forma_de_pago_por_ningun_camino(): void
    {
        $viaje = $this->sacar('San Miguel', $this->personaAna, $this->ana)->salida;
        // La oficina le cargó dos CCF al viaje: quedan sin registrar en él.
        $entrega = app(EntregasCcf::class)->meterCcf($viaje, $this->ccf($this->salas['San Miguel'], '80.00')->id);
        $otra = app(EntregasCcf::class)->meterCcf($viaje, $this->ccf($this->salas['San Miguel'], '20.00')->id);
        $falta = ['forma_cobro' => 'Elegí cómo te pagaron: efectivo, cheque, transferencia o quedan.'];

        // La hoja de antes («Mi salida») ya no existe: no llega al viaje ni escribiendo la dirección.
        $this->actingAs($this->ana)->patch('/rutas/mi-salida/'.$viaje->id.'/entregas/'.$entrega->id, ['resultado' => 'entregado'])
            ->assertNotFound();
        $this->actingAs($this->ana)->post('/rutas/mi-salida/'.$viaje->id.'/sala/'.$this->salas['San Miguel']->id)
            ->assertNotFound();
        $this->assertSame(0, SalidaRutaEntrega::whereNotNull('resultado')->count());

        // La regla vive en el servicio: el vendedor, en el viaje, dice cómo le pagaron.
        try {
            app(EntregasCcf::class)->registrar($entrega, ['resultado' => 'entregado', 'entregado_por_id' => $this->personaAna->id], $this->ana, OrigenRegistroEntrega::Vendedor);
            $this->fail('Tenía que pedir la forma de pago.');
        } catch (ValidationException $e) {
            $this->assertSame($falta['forma_cobro'], $e->errors()['forma_cobro'][0]);
        }

        app(EntregasCcf::class)->registrar($entrega, ['resultado' => 'entregado', 'forma_cobro' => 'efectivo', 'entregado_por_id' => $this->personaAna->id], $this->ana, OrigenRegistroEntrega::Vendedor);
        $this->assertSame(FormaCobroEntrega::Efectivo, $entrega->refresh()->forma_cobro);
        $this->assertSame('80.00', $entrega->monto_cobrado);
        // «No se pudo» no mueve dinero: no pide forma de pago.
        app(EntregasCcf::class)->registrar($otra, ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'sala_cerrada', 'entregado_por_id' => $this->personaAna->id], $this->ana, OrigenRegistroEntrega::Vendedor);
        $this->assertSame(ResultadoEntrega::NoEntregado, $otra->refresh()->resultado);
    }

    public function test_la_oficina_registra_en_el_viaje_con_la_forma_habitual_del_cliente(): void
    {
        $viaje = $this->sacar('San Miguel', $this->personaAna, $this->ana)->salida;
        $this->cliente->update(['cobro_en_entrega' => 'cheque']);
        $entrega = app(EntregasCcf::class)->meterCcf($viaje, $this->ccf($this->salas['San Miguel'], '80.00')->id);
        $oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');

        app(EntregasCcf::class)->registrar($entrega, ['resultado' => 'entregado', 'entregado_por_id' => $this->personaAna->id], $oficina, OrigenRegistroEntrega::Oficina);

        $this->assertSame(FormaCobroEntrega::Cheque, $entrega->refresh()->forma_cobro);
    }

    public function test_el_portal_no_cobra_al_entregar_tampoco_desde_el_servicio(): void
    {
        $viaje = $this->sacar('San Miguel', $this->personaAna, $this->ana)->salida;
        $super = Cliente::factory()->create(['nombre' => 'Súper de prueba', 'cobro_en_entrega' => 'efectivo']);
        config(['ppq.cliente_default_id' => $super->id]);
        $salaSuper = $super->sucursales()->create(['nombre' => 'Súper de prueba Oriente', 'ruta_id' => $this->rutas['San Miguel']->id]);
        $entrega = app(EntregasCcf::class)->meterCcf($viaje, $this->ccf($salaSuper, '310.80', $super)->id);

        // Sin forma de pago, o con una: en el supermercado del portal nunca se cobra al entregar.
        app(EntregasCcf::class)->registrar($entrega, ['resultado' => 'entregado', 'forma_cobro' => 'efectivo', 'entregado_por_id' => $this->personaAna->id], $this->ana, OrigenRegistroEntrega::Vendedor);
        $this->assertSame(ResultadoEntrega::Entregado, $entrega->refresh()->resultado);
        $this->assertNull($entrega->forma_cobro);
        $this->assertNull($entrega->monto_cobrado);
    }

    // ---------------------------------------------------------------- el día

    public function test_terminar_el_dia_resume_y_saca_el_pdf(): void
    {
        $viaje = $this->sacar('San Miguel', $this->personaAna, $this->ana)->salida;
        $marcar = fn (Dte $dte, array $datos) => $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), QuienEntrega::yo($datos))->assertSessionHasNoErrors();
        $marcar($this->ccf($this->salas['San Miguel'], '142.00'), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo']);
        $marcar($this->ccf($this->salas['San Miguel'], '20.40'), ['resultado' => 'entregado', 'forma_cobro' => 'cheque']);
        $marcar($this->ccf($this->salas['San Miguel'], '24.00'), ['resultado' => 'entregado', 'forma_cobro' => 'transferencia']);
        $marcar($this->ccf($this->salas['otra San Miguel'], '200.00'), ['resultado' => 'entregado', 'forma_cobro' => EntregasCcf::SIN_COBRO]);
        $marcar($this->ccf($this->salas['otra San Miguel'], '110.80'), ['resultado' => 'entregado', 'forma_cobro' => EntregasCcf::SIN_COBRO]);
        $marcar($this->ccf($this->salas['San Miguel'], '15.00'), ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'sala_cerrada']);
        // Lo de otro vendedor no entra en su día.
        $this->actingAs($this->beto)->post(route('rutas.por-entregar.marcar', $this->ccf($this->salas['San Miguel'], '999.00')), QuienEntrega::yo(['resultado' => 'entregado', 'forma_cobro' => 'efectivo']))
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

    // ---------------------------------------------------------------- deshacer

    public function test_en_el_viaje_el_vendedor_solo_deshace_lo_de_hoy(): void
    {
        $viaje = $this->sacar('San Miguel', $this->personaAna, $this->ana)->salida;
        $dte = $this->ccf($this->salas['San Miguel'], '142.00');
        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), QuienEntrega::yo(['resultado' => 'entregado', 'forma_cobro' => 'efectivo']))
            ->assertSessionHasNoErrors();
        $entrega = SalidaRutaEntrega::sole();
        $this->assertSame($viaje->id, $entrega->salida_ruta_id);
        $this->assertTrue($this->ana->can('deshacer', [$viaje, $entrega]), 'El mismo día sí lo deshace.');

        // Al otro día el viaje sigue abierto, pero ese cobro ya salió en el PDF firmado de ayer.
        $this->travelTo('2026-11-11 09:00:00');
        $this->assertFalse($this->ana->can('deshacer', [$viaje, $entrega]));
        $this->actingAs($this->ana)->patch(route('rutas.mi-salida.deshacer', [$viaje, $entrega]))->assertForbidden();
        $this->assertSame(ResultadoEntrega::Entregado, $entrega->refresh()->resultado);
        $this->assertSame('142.00', $entrega->monto_cobrado);

        // La oficina sí lo deshace.
        $oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->actingAs($oficina)->patch(route('rutas.salidas.entregas.deshacer', [$viaje, $entrega]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(0, SalidaRutaEntrega::count());
    }

    public function test_lo_marcado_hoy_en_el_viaje_si_se_deshace(): void
    {
        $viaje = $this->sacar('San Miguel', $this->personaAna, $this->ana)->salida;
        $this->travelTo('2026-11-11 09:00:00');
        $dte = $this->ccf($this->salas['San Miguel']);
        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), QuienEntrega::yo(['resultado' => 'entregado', 'forma_cobro' => 'cheque']))
            ->assertSessionHasNoErrors();
        $entrega = SalidaRutaEntrega::sole();

        $this->actingAs($this->ana)->patch(route('rutas.mi-salida.deshacer', [$viaje, $entrega]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(0, SalidaRutaEntrega::count());
    }

    // ---------------------------------------------------------------- el PDF de cada día

    private function entregar(User $usuario, Dte $dte, string $forma = 'efectivo'): void
    {
        $this->actingAs($usuario)->post(route('rutas.por-entregar.marcar', $dte), QuienEntrega::yo(['resultado' => 'entregado', 'forma_cobro' => $forma]))
            ->assertSessionHasNoErrors();
    }

    public function test_terminar_el_dia_enlaza_el_pdf_de_cada_dia_del_viaje_con_movimiento(): void
    {
        $this->sacar('San Miguel', $this->personaAna, $this->ana);
        $this->entregar($this->ana, $this->ccf($this->salas['San Miguel'], '142.00'));
        // El miércoles no hubo nada; el jueves sí.
        $this->travelTo('2026-11-12 09:00:00');
        $this->entregar($this->ana, $this->ccf($this->salas['San Miguel'], '20.00'), 'cheque');

        $this->actingAs($this->ana)->get(route('rutas.mi-salida.dia'))->assertOk()
            ->assertSeeInOrder(['Días anteriores de este viaje', 'Martes 10/11/2026'])
            ->assertSee('href="'.e(route('rutas.mi-salida.dia.pdf', ['fecha' => '2026-11-10'])).'"', false)
            ->assertDontSee('Miércoles 11/11/2026')
            ->assertSee('href="'.route('rutas.mi-salida.dia.pdf').'"', false);

        $pdf = $this->actingAs($this->ana)->get(route('rutas.mi-salida.dia.pdf', ['fecha' => '2026-11-10']));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('dia-2026-11-10-ana-ficticia.pdf', (string) $pdf->headers->get('content-disposition'));
        // Ese día, en pantalla: su resumen, sin la pregunta de hoy.
        $this->actingAs($this->ana)->get(route('rutas.mi-salida.dia', ['fecha' => '2026-11-10']))->assertOk()
            ->assertSee('Martes 10/11/2026')->assertSeeInOrder(['Efectivo', '$142.00'])
            ->assertSee('href="'.e(route('rutas.mi-salida.dia.pdf', ['fecha' => '2026-11-10'])).'"', false)
            ->assertDontSee('¿Mañana seguís en la misma zona?');
        $this->assertSame(14200, app(DiaVendedor::class)->resumen($this->personaAna, $this->ana, '2026-11-10')['efectivo']);
    }

    public function test_un_dia_solo_con_gastos_tambien_cuenta_como_dia_con_movimiento(): void
    {
        $viaje = $this->sacar('San Miguel', $this->personaAna, $this->ana)->salida;
        $viaje->gastos()->create(['fecha' => '2026-11-10', 'personal_id' => $this->personaAna->id, 'concepto' => 'gasolina', 'monto' => '20.00', 'registrado_por' => $this->ana->id]);

        // Sin ninguna entrega: no falla y el día sale en la lista.
        $this->assertSame(['2026-11-10'], app(DiaVendedor::class)->diasConMovimiento($this->personaAna, $this->ana, '2026-11-09', '2026-11-12'));
        $this->actingAs($this->ana)->get(route('rutas.mi-salida.dia'))->assertOk()->assertSee('Gasolina');
        $this->travelTo('2026-11-11 09:00:00');
        $this->actingAs($this->ana)->get(route('rutas.mi-salida.dia'))->assertOk()
            ->assertSeeInOrder(['Días anteriores de este viaje', 'Martes 10/11/2026']);
    }

    public function test_el_pdf_del_dia_no_se_abre_para_un_dia_futuro_ni_viejo(): void
    {
        // Sin viaje: hasta 15 días atrás.
        $this->actingAs($this->ana)->get(route('rutas.mi-salida.dia.pdf', ['fecha' => '2026-10-26']))->assertOk();
        foreach (['2026-11-11', '2026-10-25', 'ayer', '2026-13-45'] as $fecha) {
            $this->actingAs($this->ana)->get(route('rutas.mi-salida.dia.pdf', ['fecha' => $fecha]))
                ->assertRedirect(route('rutas.mi-salida.dia'))->assertSessionHas('error');
            $this->actingAs($this->ana)->get(route('rutas.mi-salida.dia', ['fecha' => $fecha]))
                ->assertRedirect(route('rutas.mi-salida.dia'))->assertSessionHas('error');
        }

        // Con el viaje abierto, todos sus días, aunque pasen más de 15; antes del viaje, no.
        $this->sacar('San Miguel', $this->personaAna, $this->ana);
        $this->travelTo('2026-11-30 09:00:00');
        $this->actingAs($this->ana)->get(route('rutas.mi-salida.dia.pdf', ['fecha' => '2026-11-10']))->assertOk();
        $this->actingAs($this->ana)->get(route('rutas.mi-salida.dia.pdf', ['fecha' => '2026-11-09']))->assertRedirect(route('rutas.mi-salida.dia'));
    }

    public function test_la_oficina_y_el_jefe_abren_el_pdf_de_cualquier_dia_de_un_viaje(): void
    {
        $pedido = $this->sacar('San Miguel', $this->personaAna, $this->ana);
        $viaje = $pedido->salida;
        $this->entregar($this->ana, $this->ccf($this->salas['San Miguel'], '142.00'));
        $this->travelTo('2026-11-11 09:00:00');
        // Beto entrega un CCF del pedido de Ana: va de acompañante y también tiene su PDF.
        $this->entregar($this->beto, $this->facturado($pedido, '60.00'));
        $this->travelTo('2026-11-12 09:00:00');

        $deAna = route('rutas.salidas.dia-pdf', [$viaje, '2026-11-10', $this->personaAna]);
        $deBeto = route('rutas.salidas.dia-pdf', [$viaje, '2026-11-11', $this->personaBeto]);
        foreach (['jefe_vendedores', 'facturacion'] as $rol) {
            $usuario = User::factory()->create(['activo' => true])->assignRole($rol);
            $this->actingAs($usuario)->get(route('rutas.salidas.show', $viaje))->assertOk()
                ->assertSeeInOrder(['PDF de cada día', 'Martes 10/11/2026', 'Ana Ficticia', 'Miércoles 11/11/2026', 'Beto Ficticio'])
                ->assertSee('href="'.$deAna.'"', false)->assertSee('href="'.$deBeto.'"', false);
            $pdf = $this->actingAs($usuario)->get($deAna);
            $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->assertStringContainsString('dia-2026-11-10-ana-ficticia.pdf', (string) $pdf->headers->get('content-disposition'));
            $this->actingAs($usuario)->get($deBeto)->assertOk();
            // La pestaña «Viajes» lleva al viaje.
            $this->actingAs($usuario)->get(route('rutas.viajes'))->assertOk()->assertSee('href="'.route('rutas.salidas.show', $viaje).'"', false);
        }

        // Fuera del viaje, un día que no existe, quien no va en él o una salida que no es un viaje: 404.
        $otro = PersonalRuta::create(['nombre' => 'Carla Ficticia']);
        $aMano = SalidaRuta::create(['ruta_id' => $this->rutas['San Miguel']->id, 'fecha_inicio' => '2026-11-10', 'estado' => EstadoSalidaRuta::EnCurso]);
        $aMano->participantes()->create(['rutas_personal_id' => $this->personaAna->id, 'rol' => RolEnSalida::Responsable]);
        foreach ([[$viaje, '2026-11-09', $this->personaAna], [$viaje, '2026-11-13', $this->personaAna], [$viaje, '2026-02-31', $this->personaAna],
            [$viaje, '2026-11-10', $otro], [$aMano, '2026-11-10', $this->personaAna]] as $parametros) {
            $this->actingAs($usuario)->get(route('rutas.salidas.dia-pdf', $parametros))->assertNotFound();
        }
        // El vendedor no entra por la oficina: solo abre los suyos, desde «Terminar el día».
        $this->actingAs($this->beto)->get($deAna)->assertForbidden();
        $this->actingAs($this->ana)->get($deAna)->assertForbidden();
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

        $this->actingAs($this->beto)->post(route('rutas.por-entregar.marcar', $dte), QuienEntrega::yo(['resultado' => 'no_entregado', 'motivo_no_entrega' => 'sin_quien_reciba']))
            ->assertSessionHasNoErrors();
        $this->assertSame(MotivoNoEntrega::SinQuienReciba, SalidaRutaEntrega::sole()->motivo_no_entrega);
        // La factura sigue en «Por entregar»; una vez no avisa.
        $this->actingAs($this->beto)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee($dte->numero_control)->assertDontSee('avisale al jefe');

        $this->travelTo('2026-11-11 09:00:00');
        $this->actingAs($this->beto)->post(route('rutas.por-entregar.marcar', $dte), QuienEntrega::yo(['resultado' => 'no_entregado', 'motivo_no_entrega' => 'no_aceptado']))
            ->assertSessionHasNoErrors();
        $this->assertEquals([$dte->id => 2], app(EntregasCcf::class)->fallas([$dte->id]));
        // Al otro día sigue en la lista, con el aviso.
        $this->travelTo('2026-11-12 08:00:00');
        $this->actingAs($this->beto)->get(route('rutas.por-entregar.index'))->assertOk()->assertSee($dte->numero_control)
            ->assertSee('No se pudo entregar 2 veces. El jefe ya lo ve en su tablero.');
    }

    public function test_quedan_con_numero_y_fecha_de_pago_queda_por_cobrar(): void
    {
        $dte = $this->ccf($this->salas['San Miguel']);
        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), QuienEntrega::yo(['resultado' => 'entregado', 'forma_cobro' => EntregasCcf::SIN_COBRO,
            'quedan_numero' => 'Q-0451', 'quedan_fecha_pago' => '2026-11-03']))->assertSessionHasErrors('quedan_fecha_pago');
        $this->assertSame(0, SalidaRutaEntrega::whereNotNull('resultado')->count());

        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), QuienEntrega::yo(['resultado' => 'entregado', 'forma_cobro' => EntregasCcf::SIN_COBRO,
            'quedan_numero' => ' Q-0451 ', 'quedan_fecha_pago' => '2026-11-24']))->assertSessionHasNoErrors();
        $entrega = SalidaRutaEntrega::entregadas()->sole();
        $this->assertSame('Q-0451', $entrega->quedan_numero);
        $this->assertSame('2026-11-24', $entrega->quedan_fecha_pago->toDateString());
        $this->assertTrue(SalidaRutaEntrega::quedanPorCobrar()->sole()->is($entrega));

        // Con efectivo no se guarda un quedan.
        $otro = $this->ccf($this->salas['San Miguel']);
        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $otro), QuienEntrega::yo(['resultado' => 'entregado', 'forma_cobro' => 'efectivo',
            'quedan_numero' => 'Q-9', 'quedan_fecha_pago' => '2026-11-24']))->assertSessionHasNoErrors();
        $this->assertNull(SalidaRutaEntrega::where('dte_id', $otro->id)->sole()->quedan_numero);

        $this->actingAs($this->ana)->get(route('rutas.mi-salida.dia'))->assertOk()->assertSee('N.º Q-0451 · paga el 24/11/2026');
    }

    public function test_el_quedan_sin_fecha_tambien_queda_por_cobrar_y_va_primero(): void
    {
        $marcar = fn (Dte $dte, array $datos = []) => $this->actingAs($this->ana)
            ->post(route('rutas.por-entregar.marcar', $dte), QuienEntrega::yo(['resultado' => 'entregado'] + $datos))->assertSessionHasNoErrors();
        $conFecha = $this->ccf($this->salas['San Miguel'], '50.00');
        $marcar($conFecha, ['forma_cobro' => EntregasCcf::SIN_COBRO, 'quedan_numero' => 'Q-1', 'quedan_fecha_pago' => '2026-11-24']);
        // La tienda no dio fecha ni número: igual quedó por cobrar.
        $sinFecha = $this->ccf($this->salas['otra San Miguel'], '75.00');
        $marcar($sinFecha, ['forma_cobro' => EntregasCcf::SIN_COBRO]);
        // Lo cobrado en efectivo y lo del supermercado del portal no son quedan.
        $marcar($this->ccf($this->salas['San Miguel'], '10.00'), ['forma_cobro' => 'efectivo']);
        $super = Cliente::factory()->create(['nombre' => 'Súper de prueba']);
        config(['ppq.cliente_default_id' => $super->id]);
        $marcar($this->ccf($super->sucursales()->create(['nombre' => 'Súper de prueba Oriente', 'ruta_id' => $this->rutas['San Miguel']->id]), '310.80', $super));

        $this->assertSame([$sinFecha->id, $conFecha->id], SalidaRutaEntrega::quedanPorCobrar()->pluck('dte_id')->all());

        $jefe = User::factory()->create(['activo' => true])->assignRole('jefe_vendedores');
        $this->actingAs($jefe)->get(route('rutas.viajes'))->assertOk()
            ->assertSeeInOrder(['Quedan por cobrar', 'Tienda de prueba Oriente', 'Quedan sin número', 'Sin fecha de pago',
                'Sala de prueba Oriente', 'Quedan n.º Q-1', 'Paga el 24/11/2026'])
            ->assertDontSee('Súper de prueba Oriente');
    }

    public function test_al_entregar_los_datos_del_quedan_van_a_la_vista_y_no_dicen_opcional(): void
    {
        $this->ccf($this->salas['San Miguel']);
        $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSeeInOrder(['¿Cómo te pagaron?', 'Quedan', 'Datos del quedan', 'N.º de quedan', 'Fecha de pago'])
            ->assertDontSee('Si dejaron quedan')
            // Se ven al elegir «Quedan»; sin JavaScript quedan siempre a la vista, nunca en un desplegable.
            ->assertSee('x-show="forma === \'sin_cobro\'"', false);
    }

    public function test_la_observacion_de_la_entrega_queda_guardada(): void
    {
        $dte = $this->ccf($this->salas['San Miguel']);
        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $dte), QuienEntrega::yo(['resultado' => 'entregado', 'forma_cobro' => 'efectivo',
            'nota' => 'Devolvieron 2 cajas golpeadas']))->assertSessionHasNoErrors();
        $this->assertSame('Devolvieron 2 cajas golpeadas', SalidaRutaEntrega::sole()->nota);
        $this->actingAs($this->ana)->post(route('rutas.por-entregar.marcar', $this->ccf($this->salas['San Miguel'])), QuienEntrega::yo(['resultado' => 'entregado',
            'forma_cobro' => 'efectivo', 'nota' => str_repeat('a', 301)]))->assertSessionHasErrors('nota');
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
        [$pedido, $dte, $galleta] = $this->pedidoConFaltante();
        // Con la unidad de medida del producto, no «cajas» para todo (12/11/2026).
        $this->actingAs($this->beto)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('No vino: 4 u de Galleta de prueba')->assertDontSee('cajas de Galleta')
            ->assertSee('href="'.route('rutas.por-entregar.hoja', $dte).'"', false);
        // Sin abreviatura en su unidad: solo el número.
        $galleta->unidadMedida->update(['abreviatura' => null]);
        $this->actingAs($this->beto)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('No vino: 4 de Galleta de prueba');

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
