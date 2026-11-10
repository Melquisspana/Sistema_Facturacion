<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\PropositoSalida;
use App\Enums\ResultadoEntrega;
use App\Enums\RolEnSalida;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PersonalRuta;
use App\Models\PpqAlbaran;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\ParticipantesSalida;
use App\Services\Rutas\PorEntregar;
use App\Services\Rutas\RitmoRutas;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * «Por entregar» (Melqui, 06/11/2026): el vendedor ve todos los CCF que faltan entregar, por
 * ruta, y los marca sin que nadie arme la salida. Datos ficticios.
 */
class PorEntregarTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private PersonalRuta $persona;

    /** @var array<string, ClienteSucursal> */
    private array $salas = [];

    /** @var array<string, Ruta> */
    private array $rutas = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-11-06 10:00:00');
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28', 'rutas.ambiente_ccf' => null]);
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos Ficticio', 'user_id' => $this->vendedor->id]);
        $cliente = Cliente::factory()->create(['nombre' => 'Súper Ficticio S.A. de C.V.', 'cobro_en_entrega' => 'efectivo']);
        foreach (['Santa Tecla' => 'Sala Tecla', 'Antiguo' => 'Sala Antiguo', 'San Marcos' => 'Sala Marcos'] as $ruta => $sala) {
            $this->rutas[$ruta] = Ruta::create(['nombre' => $ruta]);
            $this->salas[$ruta] = $cliente->sucursales()->create(['nombre' => $sala, 'ruta_id' => $this->rutas[$ruta]->id]);
        }
        $this->salas['sin'] = $cliente->sucursales()->create(['nombre' => 'Sala Huérfana']);
    }

    private function ccf(string $sala, string $fecha = '2026-11-06', array $extra = []): Dte
    {
        $sala = $this->salas[$sala];
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Dulces Inventados'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $n = 900 + Dte::count();

        return Dte::create($extra + [
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $sala->cliente_id, 'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'numero_orden_compra' => '26060232'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'fecha_emision' => $fecha, 'hora_emision' => '07:00:00', 'total_pagar' => 100.25,
        ]);
    }

    /** @param  array<int, PersonalRuta>  $acompanantes */
    private function salidaFormal(string $ruta, PersonalRuta $persona, EstadoSalidaRuta $estado = EstadoSalidaRuta::EnCurso, string $fecha = '2026-11-06', array $acompanantes = []): SalidaRuta
    {
        $salida = SalidaRuta::create(['ruta_id' => $this->rutas[$ruta]->id, 'fecha_inicio' => $fecha, 'estado' => $estado]);
        app(ParticipantesSalida::class)->sincronizar($salida, [$persona->id, ...array_map(fn (PersonalRuta $p) => $p->id, $acompanantes)], $persona->id);

        return $salida;
    }

    public function test_la_lista_agrupa_por_ruta_ordena_y_filtra_sin_guardar_nada(): void
    {
        $tecla = $this->ccf('Santa Tecla');
        $antiguoNuevo = $this->ccf('Antiguo', '2026-11-05');
        $antiguoViejo = $this->ccf('Antiguo', '2026-10-20');
        $marcos = $this->ccf('San Marcos');
        $huerfano = $this->ccf('sin');

        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('5 CCF de los últimos 45 días')
            ->assertSeeInOrder(['Todas 5', 'Antiguo 2', 'San Marcos 1', 'Santa Tecla 1', 'Sin ruta 1'])
            // Por ruta (en orden, «Sin ruta» al final) y, dentro, los más viejos primero.
            ->assertSeeInOrder([$antiguoViejo->numero_control, $antiguoNuevo->numero_control, $marcos->numero_control, $tecla->numero_control, $huerfano->numero_control])
            ->assertSee('…'.substr($tecla->numero_control, -4))
            ->assertSee('Súper Ficticio S.A. de C.V.')
            ->assertSee('06/11')
            ->assertSee('$100.25');

        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index', ['ruta' => $this->rutas['Antiguo']->id]))->assertOk()
            ->assertSee('aria-current="page"', false)
            ->assertSee($antiguoViejo->numero_control)->assertSee($antiguoNuevo->numero_control)
            ->assertDontSee($tecla->numero_control)->assertDontSee($marcos->numero_control)->assertDontSee($huerfano->numero_control);
        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index', ['ruta' => 'sin']))->assertOk()
            ->assertSee($huerfano->numero_control)->assertDontSee($tecla->numero_control);
        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index', ['ruta' => '999']))->assertOk()
            ->assertSee('Esa ruta no tiene CCF por entregar.')->assertSee('Ver todas');

        $this->assertSame(0, SalidaRuta::count());
    }

    public function test_excluye_entregados_invalidados_con_albaran_viejos_y_sin_sala(): void
    {
        config(['rutas.entregas_desde' => '2026-01-01']);
        $pendiente = $this->ccf('Antiguo');
        $invalidado = $this->ccf('Antiguo', extra: ['estado' => 'invalidado']);
        $viejo = $this->ccf('Antiguo', '2026-09-21');
        $conAlbaran = $this->ccf('Antiguo');
        PpqAlbaran::create(['numero_albaran' => 'AC01/0232/00/6715', 'numero_orden_compra' => $conAlbaran->numero_orden_compra,
            'monto_albaran' => 100.25, 'fecha_albaran' => '2026-11-06', 'origen' => 'gmail']);
        $entregado = $this->ccf('Antiguo');
        $vieja = $this->salidaFormal('Antiguo', PersonalRuta::create(['nombre' => 'Ana Inventada']), fecha: '2026-11-05');
        $vieja->entregas()->create(['dte_id' => $entregado->id, 'cliente_sucursal_id' => $entregado->cliente_sucursal_id,
            'resultado' => ResultadoEntrega::Entregado, 'fecha_resultado' => '2026-11-05 11:00:00']);
        $sinSala = $this->ccf('Antiguo', extra: ['cliente_sucursal_id' => null]);
        // Hace 45 días justos todavía entra.
        $limite = $this->ccf('Antiguo', '2026-09-22');

        $this->assertSame([$limite->id, $pendiente->id], app(EntregasCcf::class)->porEntregar()->pluck('id')->all());
        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee($pendiente->numero_control)->assertSee($limite->numero_control)
            ->assertDontSee($invalidado->numero_control)->assertDontSee($viejo->numero_control)
            ->assertDontSee($conAlbaran->numero_control)->assertDontSee($entregado->numero_control)
            ->assertDontSee($sinSala->numero_control);
    }

    public function test_marcar_arma_la_salida_de_hoy_solo_con_lo_marcado_y_la_segunda_la_reutiliza(): void
    {
        $primero = $this->ccf('Antiguo', '2026-11-04');
        $segundo = $this->ccf('Antiguo');
        $otroMas = $this->ccf('Antiguo');
        $deOtraRuta = $this->ccf('San Marcos');

        $this->actingAs($this->vendedor)->from(route('rutas.por-entregar.index'))
            ->post(route('rutas.por-entregar.marcar', $primero), ['resultado' => 'entregado', 'trae_nota_averia' => '1'])
            ->assertRedirect(route('rutas.por-entregar.index'))->assertSessionHasNoErrors()->assertSessionHas('status');

        $salida = SalidaRuta::sole();
        $this->assertSame(EstadoSalidaRuta::EnCurso, $salida->estado);
        $this->assertSame(PropositoSalida::Entregar, $salida->proposito);
        $this->assertSame('2026-11-06', $salida->fecha_inicio->toDateString());
        $this->assertSame($this->rutas['Antiguo']->id, $salida->ruta_id);
        $this->assertSame($this->vendedor->id, $salida->created_by);
        $this->assertTrue($salida->esAutomatica());
        $this->assertSame([$this->persona->id], $salida->participantes()->pluck('rutas_personal_id')->all());
        // Solo lo marcado: no carga los demás pendientes de la ruta.
        $this->assertSame([$primero->id], $salida->entregas()->pluck('dte_id')->all());
        $entrega = $salida->entregas()->sole();
        $this->assertSame(ResultadoEntrega::Entregado, $entrega->resultado);
        $this->assertSame($this->persona->id, $entrega->entregado_por_id);
        $this->assertSame($this->vendedor->id, $entrega->registrado_por);
        $this->assertTrue($entrega->trae_nota_averia);
        $this->assertSame('100.25', (string) $entrega->monto_cobrado);
        $this->assertTrue(Activity::where('subject_id', $salida->id)->where('description', 'se armó sola la salida al marcar una entrega')->exists());

        // La segunda marca de la misma ruta usa la misma salida; «no se pudo» pide el motivo.
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $segundo), ['resultado' => 'no_entregado'])
            ->assertSessionHasErrors('motivo_no_entrega');
        $this->assertSame(1, $salida->entregas()->count(), 'Sin motivo no queda nada anotado.');
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $segundo), ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'sala_cerrada'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, SalidaRuta::count());
        $this->assertSame([$primero->id, $segundo->id], $salida->entregas()->orderBy('id')->pluck('dte_id')->all());

        // Abrir «Mi salida» no le carga el resto de la ruta.
        $this->actingAs($this->vendedor)->get(route('rutas.mi-salida.show', $salida))->assertOk();
        $this->assertSame(2, $salida->entregas()->count());

        // Lo marcado hoy queda abajo con «Deshacer»; el entregado ya no está por entregar.
        $deshacer = route('rutas.mi-salida.deshacer', [$salida, $salida->entregas()->where('dte_id', $primero->id)->first()]);
        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSeeInOrder([$otroMas->numero_control, $deOtraRuta->numero_control, 'Marcados hoy', '✓ Entregado', '✗ No se pudo entregar'])
            ->assertSee('action="'.$deshacer.'"', false)
            ->assertSee('2 CCF de los últimos');
        $this->actingAs($this->vendedor)->from(route('rutas.por-entregar.index'))->patch($deshacer)->assertRedirect(route('rutas.por-entregar.index'));
        // En la automática, deshacer quita la fila: el CCF queda libre para cualquier salida.
        $this->assertFalse($salida->entregas()->where('dte_id', $primero->id)->exists());
    }

    public function test_con_salida_formal_en_curso_se_marca_dentro_de_ella_solo_si_va_en_ella(): void
    {
        $ana = PersonalRuta::create(['nombre' => 'Ana Inventada']);
        // San Miguel: el CCF ya va en la salida formal donde va el vendedor, que empezó ayer.
        $cargado = $this->ccf('San Marcos', '2026-11-05');
        $formal = $this->salidaFormal('San Marcos', $ana, fecha: '2026-11-05', acompanantes: [$this->persona]);
        app(EntregasCcf::class)->cargarPendientes($formal);
        // Antiguo: la oficina armó la salida de hoy solo con Ana; un CCF va en ella y otro se
        // facturó después.
        $ajeno = $this->ccf('Antiguo');
        $deAna = $this->salidaFormal('Antiguo', $ana);
        app(EntregasCcf::class)->cargarPendientes($deAna);
        $facturadoDespues = $this->ccf('Antiguo');

        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('Va en la salida San Marcos')
            ->assertSee(PorEntregar::noVa($deAna))
            ->assertDontSee('action="'.route('rutas.por-entregar.marcar', $ajeno).'"', false)
            ->assertSee('action="'.route('rutas.por-entregar.marcar', $cargado).'"', false);
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $cargado), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $this->assertSame(ResultadoEntrega::Entregado, $formal->entregas()->sole()->resultado);

        // En la formal donde no va no se marca ni se lo suma: ir en ella le daría el cierre y la liquidación.
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $ajeno), ['resultado' => 'entregado'])
            ->assertSessionHasErrors(['entrega' => PorEntregar::noVa($deAna)]);
        $this->assertNull($deAna->entregas()->where('dte_id', $ajeno->id)->sole()->resultado);

        // Lo que no va en ella lo lleva su automática de hoy, no la formal de Ana.
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $facturadoDespues), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $auto = SalidaRuta::whereKeyNot([$formal->id, $deAna->id])->sole();
        $this->assertTrue($auto->esAutomatica());
        $this->assertSame([$facturadoDespues->id], $auto->entregas()->pluck('dte_id')->all());

        $this->assertSame([$ana->id], $deAna->participantes()->pluck('rutas_personal_id')->all());
        $this->assertSame($ana->id, $formal->responsable->rutas_personal_id);
        $this->assertFalse(Activity::whereIn('subject_id', [$formal->id, $deAna->id])->where('subject_type', SalidaRuta::class)
            ->where('description', 'cambió los participantes de la salida')->where('properties->desde', 'por_entregar')->exists());
    }

    public function test_viaje_de_varios_dias_automatica_de_ayer_y_dia_cerrado(): void
    {
        // San Marcos: un viaje formal que empezó ayer sigue en curso; lo facturado hoy va en él.
        $ana = PersonalRuta::create(['nombre' => 'Ana Inventada']);
        $viaje = $this->salidaFormal('San Marcos', $ana, fecha: '2026-11-05', acompanantes: [$this->persona]);
        $nuevo = $this->ccf('San Marcos');
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $nuevo), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $this->assertSame(1, SalidaRuta::count());
        $this->assertSame(ResultadoEntrega::Entregado, $viaje->entregas()->sole()->resultado);

        // Antiguo: la automática de ayer no se reutiliza; se arma la de hoy.
        $ayer = SalidaRuta::create(['ruta_id' => $this->rutas['Antiguo']->id, 'fecha_inicio' => '2026-11-05', 'estado' => EstadoSalidaRuta::EnCurso,
            'observaciones' => SalidaRuta::OBSERVACION_AUTOMATICA, 'created_by' => $this->vendedor->id]);
        $primero = $this->ccf('Antiguo');
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $primero), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $hoy = SalidaRuta::whereKeyNot([$viaje->id, $ayer->id])->sole();
        $this->assertTrue($hoy->esAutomatica());
        $this->assertSame('2026-11-06', $hoy->fecha_inicio->toDateString());
        $this->assertSame(0, $ayer->entregas()->count());
        $this->assertSame(EstadoSalidaRuta::Cancelada, $ayer->refresh()->estado, 'Vacía: se cancela.');

        // Con el día de hoy cerrado, la de hoy ya no recibe: se arma otra.
        $hoy->cierres()->create(['fecha' => '2026-11-06', 'cerrado_por' => User::factory()->create()->id, 'cerrado_en' => now()]);
        $segundo = $this->ccf('Antiguo');
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $segundo), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $this->assertSame(4, SalidaRuta::count());
        $this->assertSame(1, $hoy->entregas()->count());

        // Y si el CCF ya va en una salida con el día cerrado, se dice por qué no se marca.
        $tercero = $this->ccf('Antiguo');
        $hoy->entregas()->create(['dte_id' => $tercero->id, 'cliente_sucursal_id' => $tercero->cliente_sucursal_id]);
        // En la lista, el aviso y sin botones que siempre fallarían.
        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee(PorEntregar::diaCerrado($hoy))
            ->assertDontSee('action="'.route('rutas.por-entregar.marcar', $tercero).'"', false);
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $tercero), ['resultado' => 'entregado'])
            ->assertSessionHasErrors(['entrega' => PorEntregar::diaCerrado($hoy)]);
        $this->assertNull($hoy->entregas()->where('dte_id', $tercero->id)->sole()->resultado);
    }

    private function automatica(string $ruta, string $fecha, PersonalRuta $persona): SalidaRuta
    {
        $salida = SalidaRuta::create(['ruta_id' => $this->rutas[$ruta]->id, 'fecha_inicio' => $fecha, 'estado' => EstadoSalidaRuta::EnCurso,
            'observaciones' => SalidaRuta::OBSERVACION_AUTOMATICA, 'created_by' => $this->vendedor->id]);
        $salida->participantes()->create(['rutas_personal_id' => $persona->id, 'rol' => RolEnSalida::Acompanante]);

        return $salida;
    }

    private function vendedorMas(string $nombre): array
    {
        $usuario = User::factory()->create(['activo' => true])->assignRole('vendedor');

        return [$usuario, PersonalRuta::create(['nombre' => $nombre, 'user_id' => $usuario->id])];
    }

    public function test_las_automaticas_de_dias_anteriores_se_finalizan_solas_sin_liquidarse(): void
    {
        $ayer = $this->automatica('Antiguo', '2026-11-05', $this->persona);
        $entregado = $this->ccf('Antiguo', '2026-11-05');
        $ayer->entregas()->create(['dte_id' => $entregado->id, 'cliente_sucursal_id' => $entregado->cliente_sucursal_id,
            'resultado' => ResultadoEntrega::Entregado, 'fecha_resultado' => '2026-11-05 11:00:00', 'entregado_por_id' => $this->persona->id]);
        $deHoy = $this->automatica('San Marcos', '2026-11-06', $this->persona);
        $viaje = $this->salidaFormal('Santa Tecla', $this->persona, fecha: '2026-11-04');
        $proxima = $this->salidaFormal('Antiguo', $this->persona, EstadoSalidaRuta::Planificada, '2026-11-07');

        $estadoAntiguo = fn () => app(RitmoRutas::class)->porRuta(CarbonImmutable::parse('2026-11-06'))
            ->firstWhere('ruta.id', $this->rutas['Antiguo']->id)['estado'];
        $this->assertSame(RitmoRutas::EN_RUTA, $estadoAntiguo());

        $this->artisan('rutas:finalizar-automaticas')->assertSuccessful();

        $ayer->refresh();
        $this->assertSame(EstadoSalidaRuta::Finalizada, $ayer->estado);
        $this->assertSame('2026-11-05', $ayer->fecha_fin_real->toDateString());
        $this->assertFalse($ayer->estaLiquidada(), 'La liquida la oficina, como cualquier salida finalizada.');
        $this->assertTrue(Activity::where('subject_type', SalidaRuta::class)->where('subject_id', $ayer->id)->where('description', 'se finalizó sola')->exists());
        // La de hoy y las formales, como estaban.
        $this->assertSame(EstadoSalidaRuta::EnCurso, $deHoy->refresh()->estado);
        $this->assertSame(EstadoSalidaRuta::EnCurso, $viaje->refresh()->estado);
        // La ruta ya no figura «en ruta», y «Mi salida» abre su próxima salida, no la vieja.
        $this->assertNotSame(RitmoRutas::EN_RUTA, $estadoAntiguo());
        $deHoy->finalizar('2026-11-06');
        $viaje->finalizar('2026-11-06');
        $this->actingAs($this->vendedor)->get(route('rutas.mi-salida.index'))->assertOk()->assertSee(route('rutas.mi-salida.show', $proxima));

        // Temprano cada día y sin pisarse.
        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'rutas:finalizar-automaticas'));
        $this->assertNotNull($evento);
        $this->assertSame('0 4 * * *', $evento->expression);
        $this->assertTrue($evento->withoutOverlapping);
    }

    public function test_la_oficina_termina_una_automatica_que_se_finalizo_sola(): void
    {
        // Ayer: Carlos entregó y cobró en efectivo; nadie terminó el día ni la ruta.
        $this->travelTo('2026-11-05 10:00:00');
        $dte = $this->ccf('Antiguo', '2026-11-05');
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $auto = SalidaRuta::sole();
        $entrega = $auto->entregas()->sole();

        $this->travelTo('2026-11-06 05:00:00');
        $this->artisan('rutas:finalizar-automaticas')->assertSuccessful();
        $this->assertSame(EstadoSalidaRuta::Finalizada, $auto->refresh()->estado);

        $oficina = User::factory()->create(['activo' => true])->assignRole('administrador');
        $this->actingAs($oficina)->get(route('rutas.salidas.show', $auto))->assertOk()
            ->assertSee('href="'.route('rutas.salidas.terminar', $auto).'"', false);
        // Antes de liquidar se termina el día, como en cualquier salida.
        $this->actingAs($oficina)->get(route('rutas.salidas.terminar', $auto))->assertOk()
            ->assertSee('Falta terminar el día 05/11/2026')->assertSee('action="'.route('rutas.salidas.liquidar', $auto).'"', false);
        $this->actingAs($oficina)->post(route('rutas.salidas.liquidar', $auto), ['entrega_dinero_personal_id' => $this->persona->id])->assertSessionHasErrors('dias');
        $this->actingAs($oficina)->post(route('rutas.salidas.cerrar', $auto), ['fecha' => '2026-11-05',
            'entregas' => [$entrega->id => ['monto_cobrado' => '100.25', 'forma_cobro' => 'efectivo']]])->assertSessionHasNoErrors();

        $this->actingAs($oficina)->post(route('rutas.salidas.liquidar', $auto), ['entrega_dinero_personal_id' => $this->persona->id])->assertSessionHasNoErrors();
        $auto->refresh();
        $this->assertTrue($auto->estaLiquidada());
        $this->assertSame(EstadoSalidaRuta::Finalizada, $auto->estado);
        $this->assertSame('2026-11-05', $auto->fecha_fin_real->toDateString(), 'Conserva su regreso: no se finaliza de nuevo.');
        $this->assertSame($this->persona->id, $auto->entrega_dinero_personal_id);
        $pdf = $this->actingAs($oficina)->get(route('rutas.salidas.liquidacion-final', $auto))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->actingAs($oficina)->post(route('rutas.salidas.liquidar', $auto))->assertSessionHasErrors('salida');

        // Una formal finalizada sin liquidar sigue sin poder terminarse por acá.
        $formal = $this->salidaFormal('San Marcos', $this->persona, fecha: '2026-11-04');
        $formal->finalizar('2026-11-05');
        $this->actingAs($oficina)->post(route('rutas.salidas.liquidar', $formal))->assertSessionHasErrors(['salida' => 'Solo se termina una ruta en curso.']);
    }

    public function test_la_automatica_vacia_se_cancela(): void
    {
        // Deshacer lo único que llevaba: se cancela en el acto.
        $dte = $this->ccf('Antiguo');
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $auto = SalidaRuta::sole();
        $this->actingAs($this->vendedor)->patch(route('rutas.mi-salida.deshacer', [$auto, $auto->entregas()->sole()]))->assertSessionHasNoErrors();
        $this->assertSame(EstadoSalidaRuta::Cancelada, $auto->refresh()->estado);
        $this->assertTrue(Activity::where('subject_type', SalidaRuta::class)->where('subject_id', $auto->id)->where('description', 'se canceló sola: quedó vacía')->exists());

        // Con dos, deshacer una no la cancela.
        $uno = $this->ccf('Antiguo');
        $dos = $this->ccf('Antiguo');
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $uno), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dos), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $otra = SalidaRuta::whereKeyNot($auto->id)->sole();
        $this->actingAs($this->vendedor)->patch(route('rutas.mi-salida.deshacer', [$otra, $otra->entregas()->where('dte_id', $uno->id)->sole()]))->assertSessionHasNoErrors();
        $this->assertSame(EstadoSalidaRuta::EnCurso, $otra->refresh()->estado);

        // El comando cancela la vacía de ayer (lo que quedó sin marcar se suelta) y no cuenta como visita.
        $vacia = $this->automatica('San Marcos', '2026-11-05', $this->persona);
        $suelto = $this->ccf('San Marcos', '2026-11-05');
        $vacia->entregas()->create(['dte_id' => $suelto->id, 'cliente_sucursal_id' => $suelto->cliente_sucursal_id]);
        $this->artisan('rutas:finalizar-automaticas')->assertSuccessful();
        $this->assertSame(EstadoSalidaRuta::Cancelada, $vacia->refresh()->estado);
        $this->assertSame(0, $vacia->entregas()->count());
        $this->assertSame([$suelto->id], app(EntregasCcf::class)->pendientesDeRuta($this->rutas['San Marcos'])->pluck('id')->all());
        $fila = app(RitmoRutas::class)->porRuta(CarbonImmutable::parse('2026-11-06'))->firstWhere('ruta.id', $this->rutas['San Marcos']->id);
        $this->assertNotSame(RitmoRutas::EN_RUTA, $fila['estado']);
        $this->assertNull($fila['ultima'], 'La cancelada no es la última visita.');
    }

    public function test_el_no_se_pudo_de_un_dia_cerrado_del_viaje_se_reintenta_en_el_viaje(): void
    {
        $viaje = $this->salidaFormal('San Marcos', $this->persona, fecha: '2026-11-05');
        $dte = $this->ccf('San Marcos', '2026-11-05');
        $anterior = $viaje->entregas()->create(['dte_id' => $dte->id, 'cliente_sucursal_id' => $dte->cliente_sucursal_id,
            'resultado' => ResultadoEntrega::NoEntregado, 'motivo_no_entrega' => 'sala_cerrada', 'fecha_resultado' => '2026-11-05 16:00:00', 'registrado_por' => $this->vendedor->id]);
        $cierre = $viaje->cierres()->create(['fecha' => '2026-11-05', 'cerrado_por' => User::factory()->create()->id, 'cerrado_en' => '2026-11-05 18:00:00']);
        $antesDelCierre = $cierre->refresh()->only(['efectivo_cobrado', 'cheques_cobrado', 'transferencias_cobrado', 'gastos_total', 'a_entregar']);

        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado'])->assertSessionHasNoErrors();

        // En el viaje, la misma fila con fecha de hoy; sin automática y con el intento anterior en la bitácora.
        $this->assertSame(1, SalidaRuta::count(), 'No se arma una automática: el cobro queda en el viaje.');
        $anterior->refresh();
        $this->assertSame(ResultadoEntrega::Entregado, $anterior->resultado);
        $this->assertSame('2026-11-06', $anterior->fecha_resultado->toDateString());
        $intento = Activity::where('description', 'reintento desde Por entregar')->sole();
        $this->assertSame('2026-11-05 16:00:00', $intento->properties['antes']['fecha_resultado']);
        $this->assertSame('sala_cerrada', $intento->properties['antes']['motivo_no_entrega']);
        // El cierre del día anterior no cambia.
        $this->assertSame($antesDelCierre, $cierre->refresh()->only(array_keys($antesDelCierre)));
        $this->assertTrue($viaje->diaCerrado('2026-11-05'));
    }

    public function test_la_oficina_ve_las_salidas_por_liquidar(): void
    {
        $oficina = User::factory()->create(['activo' => true])->assignRole('administrador');
        $porLiquidar = $this->automatica('Antiguo', '2026-11-04', $this->persona);
        $porLiquidar->finalizar('2026-11-04');
        $liquidada = $this->automatica('San Marcos', '2026-11-04', $this->persona);
        $liquidada->finalizar('2026-11-04');
        $liquidada->update(['liquidada_por' => $oficina->id, 'liquidada_en' => now()]);
        $formal = $this->salidaFormal('Santa Tecla', $this->persona, fecha: '2026-11-03');
        $formal->finalizar('2026-11-04');

        $this->assertSame([$porLiquidar->id], SalidaRuta::porLiquidar()->pluck('id')->all());
        $this->assertTrue($porLiquidar->refresh()->esPorLiquidar());
        $this->assertFalse($liquidada->refresh()->esPorLiquidar(), 'La liquidada no cuenta.');
        $this->assertFalse($formal->refresh()->esPorLiquidar());

        // Insignia en el listado y filtro.
        $todas = $this->actingAs($oficina)->get(route('rutas.salidas.index'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($todas, '>Por liquidar</span>'));
        $this->actingAs($oficina)->get(route('rutas.salidas.index', ['por_liquidar' => 1]))->assertOk()
            ->assertSee('href="'.route('rutas.salidas.show', $porLiquidar).'"', false)
            ->assertDontSee('href="'.route('rutas.salidas.show', $liquidada).'"', false)
            ->assertDontSee('href="'.route('rutas.salidas.show', $formal).'"', false);

        // Aviso en el inicio de Rutas, con enlace al filtro.
        $this->actingAs($oficina)->get(route('rutas.dashboard'))->assertOk()
            ->assertSee('1 salida por liquidar')
            ->assertSee('href="'.route('rutas.salidas.index', ['por_liquidar' => 1]).'"', false);
        $porLiquidar->update(['liquidada_por' => $oficina->id, 'liquidada_en' => now()]);
        $this->actingAs($oficina)->get(route('rutas.dashboard'))->assertOk()->assertDontSee('por liquidar');
    }

    public function test_por_entregar_avisa_que_la_oficina_recibe_el_efectivo_de_hoy(): void
    {
        $aviso = 'Al terminar, la oficina recibe el efectivo de hoy.';
        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index'))->assertOk()->assertDontSee($aviso);
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $this->ccf('Antiguo')), ['resultado' => 'entregado', 'forma_cobro' => EntregasCcf::SIN_COBRO])->assertSessionHasNoErrors();
        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index'))->assertOk()->assertDontSee($aviso);
        // Con transferencia no hay efectivo que rendir.
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $this->ccf('Antiguo')), ['resultado' => 'entregado', 'forma_cobro' => 'transferencia'])->assertSessionHasNoErrors();
        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index'))->assertOk()->assertDontSee($aviso);

        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $this->ccf('Antiguo')), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index'))->assertOk()->assertSee($aviso);
    }

    public function test_deshacer_la_unica_entrega_desde_mi_salida_vuelve_a_por_entregar(): void
    {
        $dte = $this->ccf('Antiguo');
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $auto = SalidaRuta::sole();

        $this->actingAs($this->vendedor)->from(route('rutas.mi-salida.show', $auto))->followingRedirects()
            ->patch(route('rutas.mi-salida.deshacer', [$auto, $auto->entregas()->sole()]))
            ->assertOk()->assertSee('Se quitó el CCF de la salida; vuelve a Por entregar.')->assertSee($dte->numero_control);
        $this->assertSame(EstadoSalidaRuta::Cancelada, $auto->refresh()->estado);
    }

    public function test_la_automatica_finalizada_que_queda_vacia_se_cancela(): void
    {
        $this->travelTo('2026-11-05 10:00:00');
        $dte = $this->ccf('Antiguo', '2026-11-05');
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $auto = SalidaRuta::sole();
        $this->travelTo('2026-11-06 05:00:00');
        $this->artisan('rutas:finalizar-automaticas')->assertSuccessful();
        $this->assertTrue($auto->refresh()->esPorLiquidar());

        // La oficina deshace su única entrega: no queda nada que liquidar.
        $oficina = User::factory()->create(['activo' => true])->assignRole('administrador');
        $this->actingAs($oficina)->from(route('rutas.salidas.show', $auto))
            ->patch(route('rutas.salidas.entregas.deshacer', [$auto, $auto->entregas()->sole()]))->assertSessionHasNoErrors();

        $auto->refresh();
        $this->assertSame(EstadoSalidaRuta::Cancelada, $auto->estado);
        $this->assertNull($auto->fecha_fin_real);
        $this->assertSame(0, SalidaRuta::porLiquidar()->count());
        $bitacora = Activity::where('subject_type', SalidaRuta::class)->where('subject_id', $auto->id)->where('description', 'se canceló sola: quedó vacía')->sole();
        $this->assertSame(EstadoSalidaRuta::Finalizada->value, $bitacora->properties['estado_anterior']);
        $fila = app(RitmoRutas::class)->porRuta(CarbonImmutable::parse('2026-11-06'))->firstWhere('ruta.id', $this->rutas['Antiguo']->id);
        $this->assertNull($fila['ultima'], 'La cancelada no cuenta como visita.');
    }

    public function test_el_reintento_no_lo_mete_en_la_automatica_de_otra_ruta(): void
    {
        // Beto no lo pudo entregar en su automática de San Marcos; después la sala pasó a Antiguo.
        [$beto, $personaBeto] = $this->vendedorMas('Beto Inventado');
        $dte = $this->ccf('San Marcos');
        $this->actingAs($beto)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'sala_cerrada'])->assertSessionHasNoErrors();
        $deBeto = SalidaRuta::sole();
        $this->salas['San Marcos']->update(['ruta_id' => $this->rutas['Antiguo']->id]);

        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte->fresh()), ['resultado' => 'entregado'])->assertSessionHasNoErrors();

        $this->assertSame([$personaBeto->id], $deBeto->participantes()->pluck('rutas_personal_id')->all(), 'Carlos no entra a la de Beto.');
        $this->assertSame(ResultadoEntrega::NoEntregado, $deBeto->entregas()->sole()->resultado);
        $propia = SalidaRuta::whereKeyNot($deBeto->id)->sole();
        $this->assertTrue($propia->esAutomatica());
        $this->assertSame($this->rutas['Antiguo']->id, $propia->ruta_id);
        $this->assertSame(ResultadoEntrega::Entregado, $propia->entregas()->sole()->resultado);
    }

    public function test_deshacer_en_la_automatica_no_lo_frena_otra_salida_abierta(): void
    {
        // Carlos no lo pudo entregar en su automática; la oficina lo cargó en la salida de Ana.
        $dte = $this->ccf('Antiguo');
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'sala_cerrada'])->assertSessionHasNoErrors();
        $auto = SalidaRuta::sole();
        $deAna = $this->salidaFormal('Antiguo', PersonalRuta::create(['nombre' => 'Ana Inventada']));
        $this->assertSame(1, app(EntregasCcf::class)->cargarPendientes($deAna));

        $this->actingAs($this->vendedor)->patch(route('rutas.mi-salida.deshacer', [$auto, $auto->entregas()->sole()]))->assertSessionHasNoErrors();

        $this->assertSame(0, $auto->entregas()->count());
        $this->assertNull($deAna->entregas()->sole()->resultado, 'En la de Ana sigue pendiente.');
    }

    public function test_al_marcar_se_finalizan_las_automaticas_viejas_de_esa_ruta(): void
    {
        $ayer = $this->automatica('Antiguo', '2026-11-05', $this->persona);
        $entregado = $this->ccf('Antiguo', '2026-11-05');
        $ayer->entregas()->create(['dte_id' => $entregado->id, 'cliente_sucursal_id' => $entregado->cliente_sucursal_id,
            'resultado' => ResultadoEntrega::Entregado, 'fecha_resultado' => '2026-11-05 11:00:00', 'entregado_por_id' => $this->persona->id]);
        $otraRuta = $this->automatica('San Marcos', '2026-11-05', $this->persona);

        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $this->ccf('Antiguo')), ['resultado' => 'entregado'])->assertSessionHasNoErrors();

        $this->assertSame(EstadoSalidaRuta::Finalizada, $ayer->refresh()->estado);
        $this->assertSame(EstadoSalidaRuta::EnCurso, $otraRuta->refresh()->estado, 'Solo la ruta que se marca; el resto, el planificador.');
    }

    public function test_deshacer_en_la_automatica_quita_la_fila_y_la_formal_lo_puede_llevar(): void
    {
        $dte = $this->ccf('Antiguo');
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $auto = SalidaRuta::sole();
        $entrega = $auto->entregas()->sole();

        $this->actingAs($this->vendedor)->from(route('rutas.por-entregar.index'))
            ->patch(route('rutas.mi-salida.deshacer', [$auto, $entrega]))->assertSessionHasNoErrors();

        $this->assertSame(0, $auto->entregas()->count());
        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee($dte->numero_control)->assertDontSee('Va en la salida');
        $formal = $this->salidaFormal('Antiguo', PersonalRuta::create(['nombre' => 'Ana Inventada']));
        $this->assertSame(1, app(EntregasCcf::class)->cargarPendientes($formal));
        $this->assertSame([$dte->id], $formal->entregas()->pluck('dte_id')->all());
    }

    public function test_un_no_se_pudo_entregar_ya_anotado_se_reintenta(): void
    {
        // Hoy: Beto no lo pudo entregar; después Carlos sí.
        [$beto, $personaBeto] = $this->vendedorMas('Beto Inventado');
        $dte = $this->ccf('Antiguo');
        $this->actingAs($beto)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'sala_cerrada'])->assertSessionHasNoErrors();
        $fila = SalidaRuta::sole()->entregas()->sole();

        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('action="'.route('rutas.por-entregar.marcar', $dte).'"', false);
        // Sin motivo el reintento no pasa y lo anotado queda como estaba.
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'no_entregado'])->assertSessionHasErrors('motivo_no_entrega');
        $this->assertSame(ResultadoEntrega::NoEntregado, $fila->refresh()->resultado);
        $this->assertSame($beto->id, $fila->registrado_por);

        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $fila->refresh();
        $this->assertSame(1, SalidaRuta::sole()->entregas()->count(), 'La misma fila, no otra.');
        $this->assertSame(ResultadoEntrega::Entregado, $fila->resultado);
        $this->assertSame($this->persona->id, $fila->entregado_por_id);
        $this->assertSame($this->vendedor->id, $fila->registrado_por);
        $this->assertTrue(Activity::where('description', 'reintento desde Por entregar')->where('properties->entrega', $fila->id)->exists());
        $this->assertEqualsCanonicalizing([$personaBeto->id, $this->persona->id], SalidaRuta::sole()->participantes()->pluck('rutas_personal_id')->all());

        // Un día anterior del viaje: el mismo vendedor lo vuelve a intentar hoy.
        $viaje = $this->salidaFormal('San Marcos', $this->persona, fecha: '2026-11-05');
        $otro = $this->ccf('San Marcos', '2026-11-05');
        $anterior = $viaje->entregas()->create(['dte_id' => $otro->id, 'cliente_sucursal_id' => $otro->cliente_sucursal_id,
            'resultado' => ResultadoEntrega::NoEntregado, 'motivo_no_entrega' => 'sala_cerrada', 'fecha_resultado' => '2026-11-05 16:00:00', 'registrado_por' => $this->vendedor->id]);
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $otro), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $this->assertSame(ResultadoEntrega::Entregado, $anterior->refresh()->resultado);
        $this->assertSame(1, $viaje->entregas()->count());
    }

    public function test_sumarse_con_un_participante_inactivo_no_rompe_el_registro(): void
    {
        [$beto, $personaBeto] = $this->vendedorMas('Beto Inventado');
        $this->actingAs($beto)->post(route('rutas.por-entregar.marcar', $this->ccf('Antiguo')), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $personaBeto->update(['activo' => false]);

        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $this->ccf('Antiguo')), ['resultado' => 'entregado'])->assertSessionHasNoErrors();

        $auto = SalidaRuta::sole();
        $this->assertSame(2, $auto->entregas()->entregadas()->count());
        $this->assertEqualsCanonicalizing([$personaBeto->id, $this->persona->id], $auto->participantes()->pluck('rutas_personal_id')->all());
        $this->assertSame(RolEnSalida::Acompanante, $auto->participantes()->where('rutas_personal_id', $this->persona->id)->sole()->rol);
    }

    public function test_la_nota_tiene_un_limite_de_300_en_el_servidor(): void
    {
        $dte = $this->ccf('Antiguo');
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'otro', 'nota' => str_repeat('a', 301)])
            ->assertSessionHasErrors('nota');
        $this->assertSame(0, SalidaRuta::count());

        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'otro', 'nota' => str_repeat('a', 300)])
            ->assertSessionHasNoErrors();
    }

    public function test_lo_que_va_en_una_salida_planificada_espera_a_que_empiece(): void
    {
        $dte = $this->ccf('Antiguo');
        $planificada = $this->salidaFormal('Antiguo', PersonalRuta::create(['nombre' => 'Ana Inventada']), EstadoSalidaRuta::Planificada, '2026-11-07');
        app(EntregasCcf::class)->cargarPendientes($planificada);

        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('que todavía no empezó')->assertDontSee('action="'.route('rutas.por-entregar.marcar', $dte).'"', false);
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado'])->assertSessionHasErrors('entrega');
        $this->assertSame(1, SalidaRuta::count());
    }

    public function test_sin_ruta_muestra_el_aviso_y_no_deja_marcar(): void
    {
        $dte = $this->ccf('sin');

        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee(PorEntregar::SIN_RUTA)
            ->assertSee(route('rutas.por-entregar.pdf', $dte))
            ->assertDontSee('action="'.route('rutas.por-entregar.marcar', $dte).'"', false);
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado'])
            ->assertSessionHasErrors(['entrega' => PorEntregar::SIN_RUTA]);
        $this->assertSame(0, SalidaRuta::count());
    }

    public function test_permisos(): void
    {
        $dte = $this->ccf('Antiguo');
        $marcar = route('rutas.por-entregar.marcar', $dte);

        // Sin `rutas.entregas.registrar` ni `rutas.ver`: nada.
        $contabilidad = User::factory()->create(['activo' => true])->assignRole('contabilidad');
        $this->actingAs($contabilidad)->get(route('rutas.por-entregar.index'))->assertForbidden();
        $this->actingAs($contabilidad)->get(route('rutas.por-entregar.pdf', $dte))->assertForbidden();
        $this->actingAs($contabilidad)->post($marcar, ['resultado' => 'entregado'])->assertForbidden();

        // La oficina mira, sin botones, y no marca.
        $oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->actingAs($oficina)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee($dte->numero_control)->assertDontSee('action="'.$marcar.'"', false);
        $this->actingAs($oficina)->post($marcar, ['resultado' => 'entregado'])->assertForbidden();

        // Vendedor sin persona de Rutas: ve la lista pero no marca.
        $this->persona->update(['user_id' => null]);
        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('Tu usuario no está enlazado')->assertDontSee('action="'.$marcar.'"', false);
        $this->actingAs($this->vendedor)->post($marcar, ['resultado' => 'entregado'])->assertSessionHas('error');
        $this->assertSame(0, SalidaRuta::count());

        $this->post('/logout');
        $this->get(route('rutas.por-entregar.index'))->assertRedirect(route('login'));
    }

    public function test_el_pdf_solo_de_un_ccf_de_la_lista(): void
    {
        $dte = $this->ccf('Antiguo');
        $respuesta = $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.pdf', $dte))->assertOk();
        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', (string) $respuesta->headers->get('Content-Disposition'));

        $factura = $this->ccf('Antiguo', extra: ['tipo_dte' => '01']);
        $invalidado = $this->ccf('Antiguo', extra: ['estado' => 'invalidado']);
        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.pdf', $factura))->assertNotFound();
        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.pdf', $invalidado))->assertNotFound();
        $this->actingAs($this->vendedor)->get('/rutas/por-entregar/999999/pdf')->assertNotFound();
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $factura), ['resultado' => 'entregado'])->assertNotFound();

        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $dte), ['resultado' => 'entregado']);
        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.pdf', $dte))->assertNotFound();
    }

    public function test_el_vendedor_entra_por_su_inicio_y_por_entregar_sigue_a_un_toque(): void
    {
        // Desde el 10/11/2026 entra por su inicio («Mi salida»), que lleva a «Por entregar».
        $this->actingAs($this->vendedor)->get('/dashboard')->assertRedirect(route('rutas.mi-salida.index'));
        $this->actingAs($this->vendedor)->get(route('rutas.mi-salida.index'))->assertOk()
            ->assertSee('href="'.route('rutas.por-entregar.index').'"', false);
        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSeeInOrder(['Por entregar', 'Mi salida'])
            ->assertSee('href="'.route('rutas.mi-salida.index').'"', false)
            ->assertSee('No hay CCF por entregar');
    }
}
