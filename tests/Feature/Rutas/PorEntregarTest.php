<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\PropositoSalida;
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
use App\Models\User;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\ParticipantesSalida;
use App\Services\Rutas\PorEntregar;
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

    private function salidaFormal(string $ruta, PersonalRuta $persona, EstadoSalidaRuta $estado = EstadoSalidaRuta::EnCurso, string $fecha = '2026-11-06'): SalidaRuta
    {
        $salida = SalidaRuta::create(['ruta_id' => $this->rutas[$ruta]->id, 'fecha_inicio' => $fecha, 'estado' => $estado]);
        app(ParticipantesSalida::class)->sincronizar($salida, [$persona->id], $persona->id);

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
        $this->assertNull($salida->entregas()->where('dte_id', $primero->id)->first()->resultado);
    }

    public function test_con_salida_formal_en_curso_se_marca_dentro_de_ella(): void
    {
        $ana = PersonalRuta::create(['nombre' => 'Ana Inventada']);
        // San Miguel: el CCF ya va en la salida formal, aunque esa salida empezó ayer.
        $cargado = $this->ccf('San Marcos', '2026-11-05');
        $formal = $this->salidaFormal('San Marcos', $ana, fecha: '2026-11-05');
        app(EntregasCcf::class)->cargarPendientes($formal);
        // Antiguo: la oficina armó la salida de hoy y el CCF se facturó después.
        $hoy = $this->salidaFormal('Antiguo', $ana);
        $facturadoDespues = $this->ccf('Antiguo');

        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('Va en la salida San Marcos');
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $cargado), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $this->actingAs($this->vendedor)->post(route('rutas.por-entregar.marcar', $facturadoDespues), ['resultado' => 'entregado'])->assertSessionHasNoErrors();

        $this->assertSame(2, SalidaRuta::count(), 'No se arma ninguna salida nueva.');
        $this->assertSame(ResultadoEntrega::Entregado, $formal->entregas()->sole()->resultado);
        $this->assertSame(ResultadoEntrega::Entregado, $hoy->entregas()->where('dte_id', $facturadoDespues->id)->sole()->resultado);
        foreach ([$formal, $hoy] as $salida) {
            // El vendedor se suma; Ana sigue y sigue encabezando.
            $this->assertEqualsCanonicalizing([$ana->id, $this->persona->id], $salida->participantes()->pluck('rutas_personal_id')->all());
            $this->assertSame($ana->id, $salida->responsable->rutas_personal_id);
            $this->assertTrue(Activity::where('subject_id', $salida->id)->where('description', 'cambió los participantes de la salida')->exists());
        }
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

    public function test_el_vendedor_entra_por_por_entregar_y_mi_salida_sigue(): void
    {
        $this->actingAs($this->vendedor)->get('/dashboard')->assertRedirect(route('rutas.por-entregar.index'));
        $this->actingAs($this->vendedor)->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSeeInOrder(['Por entregar', 'Mi salida'])
            ->assertSee('href="'.route('rutas.mi-salida.index').'"', false)
            ->assertSee('No hay CCF por entregar');
    }
}
