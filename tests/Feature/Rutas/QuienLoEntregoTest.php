<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\FormaCobroEntrega;
use App\Enums\FuncionPersonalRuta;
use App\Enums\ResultadoEntrega;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PersonalRuta;
use App\Models\PpqAlbaran;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\User;
use App\Services\Rutas\DiaVendedor;
use App\Services\Rutas\EntregasCcf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\SalidaDePrueba;
use Tests\TestCase;

/**
 * «¿Quién lo entregó?» en todas las entregas (Melqui, 12/11/2026). La sala con albarán (el
 * supermercado que cobra por el portal) solo anota quién la entregó y espera el albarán; la
 * tienda, además, cómo pagó. Cualquier vendedor anota a otro; la entrega va en el PDF del que
 * la entregó. Datos ficticios.
 */
class QuienLoEntregoTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private PersonalRuta $personaAna;

    private User $beto;

    private PersonalRuta $personaBeto;

    private Cliente $super;

    private Cliente $tienda;

    private ClienteSucursal $salaSuper;

    private ClienteSucursal $salaTienda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 09:00:00');
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28', 'rutas.ambiente_ccf' => null]);
        [$this->ana, $this->personaAna] = $this->vendedor('Ana Ficticia');
        [$this->beto, $this->personaBeto] = $this->vendedor('Beto Ficticio');

        $ruta = Ruta::create(['nombre' => 'Zona de prueba']);
        $this->super = Cliente::factory()->create(['nombre' => 'Súper de prueba', 'cobro_en_entrega' => 'efectivo']);
        config(['ppq.cliente_default_id' => $this->super->id]);
        $this->salaSuper = $this->super->sucursales()->create(['nombre' => 'Súper de prueba Centro', 'ruta_id' => $ruta->id]);
        $this->tienda = Cliente::factory()->create(['nombre' => 'Tienda de prueba S.A.', 'cobro_en_entrega' => 'efectivo']);
        $this->salaTienda = $this->tienda->sucursales()->create(['nombre' => 'Tienda de prueba Norte', 'ruta_id' => $ruta->id]);
    }

    /** @return array{0: User, 1: PersonalRuta} */
    private function vendedor(string $nombre, bool $activo = true, FuncionPersonalRuta $funcion = FuncionPersonalRuta::Vendedor): array
    {
        $usuario = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $persona = PersonalRuta::create(['nombre' => $nombre, 'user_id' => $usuario->id, 'activo' => $activo]);
        $persona->funciones()->create(['funcion' => $funcion->value]);

        return [$usuario, $persona];
    }

    private function ccf(ClienteSucursal $sala, string $total = '100.25'): Dte
    {
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Empresa de prueba'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $n = 500 + Dte::count();

        return Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $sala->cliente_id, 'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'numero_orden_compra' => '26110232'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'fecha_emision' => now()->toDateString(), 'hora_emision' => '07:00:00', 'total_pagar' => $total,
        ]);
    }

    /** Cae el albarán de entrega (AC01) de ese CCF, por su orden de compra. */
    private function caeAlbaran(Dte $dte): void
    {
        PpqAlbaran::create(['numero_albaran' => 'AC01/0232/00/'.$dte->id, 'numero_orden_compra' => $dte->numero_orden_compra,
            'monto_albaran' => $dte->total_pagar, 'fecha_albaran' => now()->toDateString(), 'origen' => 'gmail']);
    }

    private function marcar(User $quien, Dte $dte, array $datos)
    {
        return $this->actingAs($quien)->from(route('rutas.por-entregar.index'))->post(route('rutas.por-entregar.marcar', $dte), $datos);
    }

    // ---------------------------------------------------------------- sala con albarán

    public function test_la_sala_con_albaran_solo_pide_quien_y_queda_esperando_el_albaran(): void
    {
        $dte = $this->ccf($this->salaSuper, '310.80');

        $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('¿Quién lo entregó?')
            ->assertSee('Anotar quién lo entregó')
            ->assertSee('Beto Ficticio')
            ->assertDontSee('¿Cómo te pagaron?')
            ->assertDontSee('Trae nota de avería');

        // Ana anota que lo entregó Beto: no pide forma de pago.
        $this->marcar($this->ana, $dte, ['resultado' => 'entregado', 'entregado_por_id' => $this->personaBeto->id])
            ->assertRedirect(route('rutas.por-entregar.index'))->assertSessionHasNoErrors();

        $entrega = SalidaRutaEntrega::sole();
        $this->assertSame(ResultadoEntrega::Entregado, $entrega->resultado);
        $this->assertSame($this->personaBeto->id, $entrega->entregado_por_id);
        $this->assertSame($this->ana->id, $entrega->registrado_por);
        $this->assertNull($entrega->forma_cobro);
        $this->assertNull($entrega->monto_cobrado);
        // Cobros lee la misma fila: entregado en Rutas y falta el albarán.
        $this->assertTrue(SalidaRutaEntrega::where('dte_id', $dte->id)->where('resultado', ResultadoEntrega::Entregado->value)->exists());
        // Bitácora: quién anotó (causante) y a quién eligió.
        $log = Activity::where('log_name', 'salida_ruta_entrega')->where('subject_id', $entrega->id)->where('description', 'registró la entrega del CCF')->sole();
        $this->assertSame($this->ana->id, $log->causer_id);
        $this->assertSame($this->personaBeto->id, $log->properties['attributes']['entregado_por_id']);

        // Sale de la lista y queda en «Marcados hoy» esperando el albarán.
        $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('No hay CCF por entregar')
            ->assertSeeInOrder(['Marcados hoy', 'Súper de prueba Centro', 'Entregó: Beto Ficticio · esperando albarán']);

        // Cae el albarán: queda entregada con ese nombre.
        $this->caeAlbaran($dte);
        $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('Entregó: Beto Ficticio · llegó el albarán')
            ->assertDontSee('esperando albarán');
        $this->assertSame($this->personaBeto->id, $entrega->refresh()->entregado_por_id);
        $this->assertSame(1, SalidaRutaEntrega::count());
    }

    public function test_no_se_pudo_en_la_sala_con_albaran_dice_quien_fue_sin_esperar_albaran(): void
    {
        // No se entregó nada: no espera albarán (12/11/2026). Solo quién fue y el motivo.
        $dte = $this->ccf($this->salaSuper, '310.80');
        $this->marcar($this->ana, $dte, ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'sala_cerrada', 'entregado_por_id' => $this->personaBeto->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSeeInOrder(['Marcados hoy', 'Súper de prueba Centro', 'No se pudo entregar', 'Fue: Beto Ficticio'])
            ->assertDontSee('esperando albarán')->assertDontSee('llegó el albarán');
    }

    public function test_si_cae_el_albaran_sin_anotar_queda_entregada_y_se_anota_quien_despues(): void
    {
        $dte = $this->ccf($this->salaSuper, '310.80');
        $this->caeAlbaran($dte);

        // Ya no está por entregar, pero se puede anotar quién lo entregó.
        $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('No hay CCF por entregar')
            ->assertSeeInOrder(['Llegó el albarán: falta quién lo entregó', 'Súper de prueba Centro', 'Anotar quién lo entregó']);

        // Solo se anota quién: aunque llegue otro resultado, queda entregada.
        $this->marcar($this->ana, $dte, ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'sala_cerrada', 'entregado_por_id' => $this->personaBeto->id])
            ->assertSessionHasNoErrors();
        $entrega = SalidaRutaEntrega::sole();
        $this->assertSame(ResultadoEntrega::Entregado, $entrega->resultado);
        $this->assertSame($this->personaBeto->id, $entrega->entregado_por_id);

        $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertDontSee('falta quién lo entregó')
            ->assertSee('Entregó: Beto Ficticio · llegó el albarán');

        // El doble toque no crea otra.
        $this->marcar($this->ana, $dte, ['resultado' => 'entregado', 'entregado_por_id' => $this->personaBeto->id])
            ->assertRedirect(route('rutas.por-entregar.index'))->assertSessionHas('status', 'Esa entrega ya estaba anotada.');
        $this->assertSame(1, SalidaRutaEntrega::count());
    }

    public function test_el_doble_toque_no_duplica_la_entrega(): void
    {
        $dte = $this->ccf($this->salaSuper, '310.80');

        $this->marcar($this->ana, $dte, ['resultado' => 'entregado', 'entregado_por_id' => $this->personaBeto->id])->assertSessionHasNoErrors();
        $this->marcar($this->ana, $dte, ['resultado' => 'entregado', 'entregado_por_id' => $this->personaBeto->id])
            ->assertRedirect(route('rutas.por-entregar.index'))->assertSessionHas('status', 'Esa entrega ya estaba anotada.');

        $this->assertSame(1, SalidaRutaEntrega::count());
        $this->assertSame(1, SalidaRuta::count());
    }

    public function test_no_se_pudo_anota_quien_fue_y_va_en_su_pdf(): void
    {
        $dte = $this->ccf($this->salaSuper, '310.80');

        $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()->assertSee('No se pudo entregar');
        $this->marcar($this->ana, $dte, ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'sala_cerrada', 'entregado_por_id' => $this->personaBeto->id])
            ->assertSessionHasNoErrors();

        $entrega = SalidaRutaEntrega::sole();
        $this->assertSame(ResultadoEntrega::NoEntregado, $entrega->resultado);
        $this->assertSame($this->personaBeto->id, $entrega->entregado_por_id);
        $this->assertSame($this->ana->id, $entrega->registrado_por);

        // Sigue por entregar; en «Marcados hoy», quién fue.
        $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee($dte->numero_control)
            ->assertSeeInOrder(['Marcados hoy', '✗ No se pudo entregar', 'Fue: Beto Ficticio']);

        // Va en el PDF del día de Beto, que fue; no en el de Ana, que lo anotó. Y en el correo, Beto.
        $dia = app(DiaVendedor::class);
        $this->assertSame(1, $dia->resumen($this->personaBeto, $this->beto, '2026-11-10')['no_entregadas']->count());
        $this->assertSame(0, $dia->resumen($this->personaAna, $this->ana, '2026-11-10')['no_entregadas']->count());
        $this->assertSame(['Beto Ficticio'], $dia->personasConMovimiento('2026-11-10')->pluck('nombre')->all());
        $this->assertSame(['2026-11-10'], $dia->diasConMovimiento($this->personaBeto, $this->beto, '2026-11-01', '2026-11-10'));
        $this->assertSame([], $dia->diasConMovimiento($this->personaAna, $this->ana, '2026-11-01', '2026-11-10'));
    }

    // ---------------------------------------------------------------- tienda sin albarán

    public function test_la_tienda_pide_como_pago_y_quien_y_el_dinero_va_en_el_pdf_del_que_entrego(): void
    {
        $dte = $this->ccf($this->salaTienda, '80.00');

        $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('¿Quién lo entregó?')
            ->assertSee('¿Cómo te pagaron?')
            ->assertDontSee('Anotar quién lo entregó');

        $this->marcar($this->ana, $dte, ['resultado' => 'entregado', 'forma_cobro' => 'efectivo', 'entregado_por_id' => $this->personaBeto->id])
            ->assertSessionHasNoErrors();

        $entrega = SalidaRutaEntrega::sole();
        $this->assertSame($this->personaBeto->id, $entrega->entregado_por_id);
        $this->assertSame(FormaCobroEntrega::Efectivo, $entrega->forma_cobro);
        $this->assertSame('80.00', $entrega->monto_cobrado);
        // Beto queda en la salida donde se anotó (va de acompañante).
        $this->assertTrue($entrega->salida->participantes()->where('rutas_personal_id', $this->personaBeto->id)->exists());

        $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSeeInOrder(['Marcados hoy', '✓ Entregado', 'Entregó: Beto Ficticio']);

        // El efectivo lo rinde Beto: va en su PDF, no en el de Ana.
        $dia = app(DiaVendedor::class);
        $this->assertSame(8000, $dia->resumen($this->personaBeto, $this->beto, '2026-11-10')['efectivo']);
        $this->assertSame(0, $dia->resumen($this->personaAna, $this->ana, '2026-11-10')['efectivo']);
        $this->assertSame(['Beto Ficticio'], $dia->personasConMovimiento('2026-11-10')->pluck('nombre')->all());
        // El PDF de Beto dice quién lo anotó.
        $this->actingAs($this->beto)->get(route('rutas.mi-salida.dia.pdf'))->assertOk();
        $html = view('rutas.mi-salida.dia-pdf', ['resumen' => $dia->resumen($this->personaBeto, $this->beto, '2026-11-10'),
            'persona' => $this->personaBeto, 'empresa' => 'Empresa de prueba', 'generado' => now()])->render();
        $this->assertStringContainsString('Anotó: '.e($this->ana->name), $html);
    }

    public function test_sin_elegir_quien_no_se_guarda_nada(): void
    {
        // Antes quedaba el que tiene el celular y el que lo prestaba figuraba en todo (12/11/2026).
        $tienda = $this->ccf($this->salaTienda, '80.00');
        $super = $this->ccf($this->salaSuper, '310.80');

        foreach ([[$tienda, ['resultado' => 'entregado', 'forma_cobro' => 'efectivo']], [$tienda, ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'sala_cerrada']],
            [$super, ['resultado' => 'entregado']], [$tienda, ['resultado' => 'entregado', 'forma_cobro' => 'efectivo', 'entregado_por_id' => '']]] as [$dte, $datos]) {
            $this->marcar($this->ana, $dte, $datos)->assertSessionHasErrors(['entregado_por_id' => 'Tocá quién lo entregó.']);
        }
        $this->assertSame(0, SalidaRutaEntrega::count());
        $this->assertSame(0, SalidaRuta::count());

        // Por JSON, 422 con el mismo mensaje.
        $this->actingAs($this->ana)->postJson(route('rutas.por-entregar.marcar', $tienda), ['resultado' => 'entregado', 'forma_cobro' => 'efectivo'])
            ->assertUnprocessable()->assertJsonValidationErrors(['entregado_por_id' => 'Tocá quién lo entregó.']);

        // Con el nombre tocado, queda ese.
        $this->marcar($this->ana, $tienda, ['resultado' => 'entregado', 'forma_cobro' => 'efectivo', 'entregado_por_id' => $this->personaBeto->id])->assertSessionHasNoErrors();
        $this->assertSame($this->personaBeto->id, SalidaRutaEntrega::sole()->entregado_por_id);
    }

    public function test_los_nombres_son_botones_cortos_y_ninguno_viene_elegido(): void
    {
        $this->vendedor('Ana María López Díaz');
        $this->vendedor('Ana Lucía López Pérez');
        $this->vendedor('Carlos Ernesto Ramos Ruiz');
        $this->ccf($this->salaTienda, '80.00');
        $this->caeAlbaran($this->ccf($this->salaSuper, '310.80'));

        $html = $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()
            // Primer nombre y primer apellido; si dos quedan iguales, el nombre completo.
            ->assertSee('>Carlos Ramos</span>', false)
            ->assertSee('>Ana María López Díaz</span>', false)->assertSee('>Ana Lucía López Pérez</span>', false)
            ->assertSee('>Beto Ficticio</span>', false)
            ->assertSee('Tocá quién lo entregó')
            ->getContent();

        // Nadie viene elegido: ni el que tiene el celular. Los botones de confirmar, apagados.
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*\schecked[\s>]/', $html);
        $this->assertStringContainsString("x-data=\"{ quien: '' }\"", $html);
        $this->assertStringNotContainsString('name="entregado_por_id" value="'.$this->personaAna->id.'"', $html);
        $this->assertMatchesRegularExpression('/<button\s+disabled x-bind:disabled="! quien"[^>]*>Entregado</', $html);
        $this->assertMatchesRegularExpression('/<button\s+disabled x-bind:disabled="! quien"[^>]*>Anotar quién lo entregó</', $html);
        $this->assertMatchesRegularExpression('/<button name="motivo_no_entrega" value="sala_cerrada"\s+disabled x-bind:disabled="! quien"/', $html);
    }

    public function test_solo_se_elige_un_vendedor_activo(): void
    {
        $dte = $this->ccf($this->salaTienda, '80.00');
        [, $inactivo] = $this->vendedor('Carla Inactiva', activo: false);
        [, $repartidor] = $this->vendedor('Dario Repartidor', funcion: FuncionPersonalRuta::Repartidor);

        // La lista solo trae vendedores activos.
        $this->actingAs($this->ana)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('Beto Ficticio')->assertDontSee('Carla Inactiva')->assertDontSee('Dario Repartidor');

        foreach ([$inactivo->id, $repartidor->id, 999999, 'x'] as $id) {
            $this->marcar($this->ana, $dte, ['resultado' => 'entregado', 'forma_cobro' => 'efectivo', 'entregado_por_id' => $id])
                ->assertSessionHasErrors('entregado_por_id');
        }
        $this->assertSame(0, SalidaRutaEntrega::count());
        $this->assertSame(0, SalidaRuta::count());
    }

    // ---------------------------------------------------------------- la oficina, en la hoja de la salida

    /** Una salida de antes (armada a mano) en curso, con esta gente, y el CCF cargado. */
    private function salidaDeLaOficina(Dte $dte, array $personas): SalidaRutaEntrega
    {
        $salida = SalidaRuta::create(['ruta_id' => $this->salaTienda->ruta_id, 'fecha_inicio' => now()->toDateString(), 'estado' => EstadoSalidaRuta::EnCurso]);
        SalidaDePrueba::participantes($salida, array_map(fn (PersonalRuta $p) => $p->id, $personas), $personas[0]->id);

        return app(EntregasCcf::class)->meterCcf($salida, $dte->id);
    }

    public function test_la_sala_con_albaran_no_queda_cobrada_ni_desde_la_oficina(): void
    {
        $entrega = $this->salidaDeLaOficina($this->ccf($this->salaSuper, '310.80'), [$this->personaBeto]);
        $oficina = User::factory()->create(['activo' => true])->assignRole('administrador');

        $this->actingAs($oficina)->patch(route('rutas.salidas.entregas.update', [$entrega->salida_ruta_id, $entrega]), [
            'resultado' => 'entregado', 'entregado_por_id' => $this->personaBeto->id, 'forma_cobro' => 'efectivo',
        ])->assertSessionHasNoErrors();

        $entrega->refresh();
        $this->assertSame(ResultadoEntrega::Entregado, $entrega->resultado);
        $this->assertNull($entrega->forma_cobro);
        $this->assertNull($entrega->monto_cobrado);
        $dia = app(DiaVendedor::class)->resumen($this->personaBeto, $this->beto, '2026-11-10');
        $this->assertSame(0, $dia['efectivo']);
        $this->assertSame(1, $dia['portal']['cantidad']);
    }

    public function test_la_oficina_tampoco_anota_a_una_ficha_inactiva_o_que_no_es_vendedor(): void
    {
        [, $inactivo] = $this->vendedor('Carla Inactiva');
        [, $repartidor] = $this->vendedor('Dario Repartidor', funcion: FuncionPersonalRuta::Repartidor);
        $entrega = $this->salidaDeLaOficina($this->ccf($this->salaTienda, '80.00'), [$this->personaBeto, $inactivo, $repartidor]);
        // Se desactiva después de salir: sigue en la salida.
        $inactivo->update(['activo' => false]);
        $oficina = User::factory()->create(['activo' => true])->assignRole('administrador');
        $url = route('rutas.salidas.entregas.update', [$entrega->salida_ruta_id, $entrega]);

        foreach ([$inactivo->id, $repartidor->id] as $id) {
            $this->actingAs($oficina)->patch($url, ['resultado' => 'entregado', 'entregado_por_id' => $id, 'forma_cobro' => 'efectivo'])
                ->assertSessionHasErrors('entregado_por_id');
            $this->actingAs($oficina)->patch($url, ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'sala_cerrada', 'entregado_por_id' => $id])
                ->assertSessionHasErrors('entregado_por_id');
        }
        $this->assertTrue($entrega->refresh()->estaPendiente());

        $this->actingAs($oficina)->patch($url, ['resultado' => 'entregado', 'entregado_por_id' => $this->personaBeto->id, 'forma_cobro' => 'efectivo'])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->personaBeto->id, $entrega->refresh()->entregado_por_id);
    }

    // ---------------------------------------------------------------- tablero

    public function test_el_tablero_avisa_la_sala_entregada_que_al_dia_siguiente_sigue_sin_albaran(): void
    {
        $sinAlbaran = $this->ccf($this->salaSuper, '310.80');
        $conAlbaran = $this->ccf($this->salaSuper, '120.00');
        $this->marcar($this->ana, $sinAlbaran, ['resultado' => 'entregado', 'entregado_por_id' => $this->personaBeto->id])->assertSessionHasNoErrors();
        $this->marcar($this->ana, $conAlbaran, ['resultado' => 'entregado', 'entregado_por_id' => $this->personaBeto->id])->assertSessionHasNoErrors();
        $jefe = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $aviso = 'Entregada por Beto Ficticio el 10/11 y todavía sin albarán';

        // El mismo día todavía no: el albarán puede caer más tarde.
        $this->actingAs($jefe)->get(route('rutas.dashboard'))->assertOk()->assertDontSee($aviso);

        $this->caeAlbaran($conAlbaran);
        $this->travelTo('2026-11-11 08:00:00');
        $this->actingAs($jefe)->get(route('rutas.dashboard'))->assertOk()
            ->assertSee($aviso)->assertSee($sinAlbaran->numero_control)->assertDontSee($conAlbaran->numero_control);
        $this->actingAs($jefe)->get(route('rutas.viajes'))->assertOk()
            ->assertSee($aviso)->assertSee($sinAlbaran->numero_control);

        // Cae el albarán: el aviso se va.
        $this->caeAlbaran($sinAlbaran);
        $this->actingAs($jefe)->get(route('rutas.dashboard'))->assertOk()->assertDontSee($aviso);
        $this->actingAs($jefe)->get(route('rutas.viajes'))->assertOk()->assertDontSee($aviso);
    }
}
