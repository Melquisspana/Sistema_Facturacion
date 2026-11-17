<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\FormaCobroEntrega;
use App\Enums\FuncionPersonalRuta;
use App\Enums\OrigenRegistroEntrega;
use App\Enums\PropositoSalida;
use App\Enums\ResultadoEntrega;
use App\Enums\RolEnSalida;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\User;
use App\Services\Rutas\DiaVendedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Errores de vendedores (revisión del 13/11/2026, aprobada el 16/11/2026). «Corregir quién»:
 * la oficina cambia solo a quién se atribuye una entrega, con motivo, también en un día cerrado.
 * Y deshacer en un día cerrado o en una salida liquidada sigue bloqueado, aun para el
 * administrador: sus totales guardados dejarían de cuadrar. Datos ficticios.
 */
class CorregirQuienEntregoTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private PersonalRuta $personaAna;

    private User $beto;

    private PersonalRuta $personaBeto;

    private ClienteSucursal $sala;

    private SalidaRuta $viaje;

    private SalidaRutaEntrega $entrega;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 09:00:00');
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28', 'rutas.ambiente_ccf' => null]);
        [$this->ana, $this->personaAna] = $this->vendedor('Ana Ficticia');
        [$this->beto, $this->personaBeto] = $this->vendedor('Beto Ficticio');
        $ruta = Ruta::create(['nombre' => 'Zona de prueba']);
        $this->sala = Cliente::factory()->create(['nombre' => 'Tienda de prueba S.A.', 'cobro_en_entrega' => 'efectivo'])
            ->sucursales()->create(['nombre' => 'Tienda de prueba Norte', 'ruta_id' => $ruta->id]);

        // El viaje de Ana, con Beto de acompañante. Ayer Ana anotó que lo entregó ella, cobrado en efectivo.
        $this->viaje = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => '2026-11-09', 'estado' => EstadoSalidaRuta::EnCurso,
            'proposito' => PropositoSalida::Ambos, 'automatica' => true, 'suma_pedidos' => true]);
        $this->viaje->participantes()->create(['rutas_personal_id' => $this->personaAna->id, 'rol' => RolEnSalida::Responsable]);
        $this->viaje->participantes()->create(['rutas_personal_id' => $this->personaBeto->id, 'rol' => RolEnSalida::Acompanante]);
        $this->entrega = $this->entregado($this->viaje, '80.00');
    }

    /** @return array{0: User, 1: PersonalRuta} */
    private function vendedor(string $nombre, FuncionPersonalRuta $funcion = FuncionPersonalRuta::Vendedor): array
    {
        $usuario = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $persona = PersonalRuta::create(['nombre' => $nombre, 'user_id' => $usuario->id, 'telefono' => '7000-0000']);
        $persona->funciones()->create(['funcion' => $funcion->value]);

        return [$usuario, $persona];
    }

    private function oficina(string $rol = 'facturacion'): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    private function ccf(string $total): Dte
    {
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Empresa de prueba'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $n = 900 + Dte::count();

        return Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $this->sala->cliente_id, 'cliente_sucursal_id' => $this->sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => '2026-11-08', 'hora_emision' => '07:00:00', 'total_pagar' => $total,
        ]);
    }

    private function entregado(SalidaRuta $salida, string $total, string $fecha = '2026-11-09 10:00:00'): SalidaRutaEntrega
    {
        return $salida->entregas()->create([
            'dte_id' => $this->ccf($total)->id, 'cliente_sucursal_id' => $this->sala->id, 'resultado' => ResultadoEntrega::Entregado,
            'fecha_resultado' => $fecha, 'origen_registro' => OrigenRegistroEntrega::Vendedor, 'registrado_por' => $this->ana->id,
            'entregado_por_id' => $this->personaAna->id, 'forma_cobro' => FormaCobroEntrega::Efectivo,
            'monto_esperado' => $total, 'monto_cobrado' => $total, 'quedan_numero' => null,
        ]);
    }

    private function corregir(User $quien, SalidaRutaEntrega $entrega, array $datos)
    {
        return $this->actingAs($quien)->from(route('rutas.salidas.show', $entrega->salida_ruta_id))
            ->patch(route('rutas.salidas.entregas.quien', [$entrega->salida_ruta_id, $entrega]), $datos + ['corrige_entrega' => $entrega->id]);
    }

    /** Lo que no tiene que cambiar: todo menos la persona. */
    private function sinLaPersona(SalidaRutaEntrega $entrega): array
    {
        return collect($entrega->fresh()->getAttributes())->except(['entregado_por_id', 'updated_at'])->all();
    }

    // ══════════════════════════════ corregir quién

    public function test_cambia_solo_la_persona_con_motivo_y_queda_en_la_bitacora(): void
    {
        $antes = $this->sinLaPersona($this->entrega);
        $oficina = $this->oficina();
        $this->actingAs($oficina)->get(route('rutas.salidas.show', $this->viaje))->assertOk()
            ->assertSee('Corregir quién')->assertSee('Corregir quién lo entregó')
            ->assertSee('action="'.route('rutas.salidas.entregas.quien', [$this->viaje, $this->entrega]).'"', false);

        $this->corregir($oficina, $this->entrega, ['entregado_por_id' => $this->personaBeto->id, 'motivo' => '  Se eligió a Ana por error  '])
            ->assertRedirect(route('rutas.salidas.show', $this->viaje))->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Listo: ahora figura Beto Ficticio. La fecha y el cobro no cambiaron.');

        $entrega = $this->entrega->fresh();
        $this->assertSame($this->personaBeto->id, (int) $entrega->entregado_por_id);
        // Fecha, cobro, forma, quien lo anotó y el resto, igual.
        $this->assertSame($antes, $this->sinLaPersona($entrega));
        $this->assertSame(['2026-11-09 10:00:00', FormaCobroEntrega::Efectivo, '80.00', $this->ana->id],
            [$entrega->fecha_resultado->toDateTimeString(), $entrega->forma_cobro, $entrega->monto_cobrado, (int) $entrega->registrado_por]);

        // Una sola entrada en la bitácora: quién, antes, después y el motivo; ids y nombres, nada más de la ficha.
        $log = Activity::where('log_name', 'salida_ruta_entrega')->where('subject_id', $entrega->id)->where('description', 'corrigió quién lo entregó')->sole();
        $this->assertFalse(Activity::where('log_name', 'salida_ruta_entrega')->where('subject_id', $entrega->id)->where('description', 'registró la entrega del CCF')->exists());
        $this->assertSame($oficina->id, (int) $log->causer_id);
        $this->assertSame(['id' => $this->personaAna->id, 'nombre' => 'Ana Ficticia'], $log->properties['antes']);
        $this->assertSame(['id' => $this->personaBeto->id, 'nombre' => 'Beto Ficticio'], $log->properties['despues']);
        $this->assertSame('Se eligió a Ana por error', $log->properties['motivo']);
        // MySQL guarda el JSON con las claves en otro orden: se comparan ordenadas.
        $claves = array_keys($log->properties->all());
        sort($claves);
        $this->assertSame(['antes', 'despues', 'dte_id', 'fecha_resultado', 'motivo', 'resultado'], $claves);
        $this->assertStringNotContainsString('7000-0000', json_encode($log->properties));

        // El PDF del día de cada uno: el efectivo pasa de Ana a Beto, sin cambiar el total.
        $dia = app(DiaVendedor::class);
        $this->assertSame(0, $dia->resumen($this->personaAna, $this->ana, '2026-11-09')['efectivo']);
        $this->assertSame(8000, $dia->resumen($this->personaBeto, $this->beto, '2026-11-09')['efectivo']);
        $this->assertSame(['Beto Ficticio'], $dia->personasConMovimiento('2026-11-09')->pluck('nombre')->all());
        $hoja = fn (PersonalRuta $p, User $u) => view('rutas.mi-salida.dia-pdf', ['resumen' => $dia->resumen($p, $u, '2026-11-09'),
            'persona' => $p, 'empresa' => 'Empresa de prueba', 'generado' => now()])->render();
        $numero = $entrega->dte->numero_control;
        $this->assertStringContainsString($numero, $hoja($this->personaBeto, $this->beto));
        $this->assertStringNotContainsString($numero, $hoja($this->personaAna, $this->ana));
        $this->actingAs($oficina)->get(route('rutas.salidas.dia-pdf', [$this->viaje, '2026-11-09', $this->personaBeto]))->assertOk();
        $this->actingAs($oficina)->get(route('rutas.salidas.show', $this->viaje))->assertOk()
            ->assertViewHas('diasPdf', fn (array $dias) => array_map(fn ($d) => [$d['fecha'], $d['persona']->nombre], $dias) === [['2026-11-09', 'Beto Ficticio']]);
    }

    public function test_en_un_no_entregado_corrige_quien_fue(): void
    {
        $this->entrega->update(['resultado' => ResultadoEntrega::NoEntregado, 'motivo_no_entrega' => 'sala_cerrada',
            'forma_cobro' => null, 'monto_esperado' => null, 'monto_cobrado' => null]);

        $this->actingAs($this->oficina('jefatura'))->get(route('rutas.salidas.show', $this->viaje))->assertOk()->assertSee('Corregir quién fue');
        $this->corregir($this->oficina('jefatura'), $this->entrega, ['entregado_por_id' => $this->personaBeto->id, 'motivo' => 'Fue Beto'])
            ->assertSessionHasNoErrors();

        $dia = app(DiaVendedor::class);
        $this->assertSame(1, $dia->resumen($this->personaBeto, $this->beto, '2026-11-09')['no_entregadas']->count());
        $this->assertSame(0, $dia->resumen($this->personaAna, $this->ana, '2026-11-09')['no_entregadas']->count());
        $this->assertSame(ResultadoEntrega::NoEntregado, $this->entrega->fresh()->resultado);
    }

    public function test_una_persona_que_no_vale_o_sin_motivo_no_cambia_nada(): void
    {
        $antes = $this->entrega->fresh()->getAttributes();
        [, $ajena] = $this->vendedor('Carla Ajena');
        [, $inactiva] = $this->vendedor('Dora Inactiva');
        [, $repartidor] = $this->vendedor('Eli Repartidor', FuncionPersonalRuta::Repartidor);
        foreach ([$inactiva, $repartidor] as $persona) {
            $this->viaje->participantes()->create(['rutas_personal_id' => $persona->id, 'rol' => RolEnSalida::Acompanante]);
        }
        $inactiva->update(['activo' => false]);
        $oficina = $this->oficina('administrador');

        $html = $this->actingAs($oficina)->get(route('rutas.salidas.show', $this->viaje))->assertOk()->getContent();
        $modal = Str::between($html, 'id="quien-titulo-'.$this->entrega->id.'"', '</form>');
        $this->assertStringContainsString('value="'.$this->personaBeto->id.'"', $modal);
        foreach ([$this->personaAna, $ajena, $inactiva, $repartidor] as $persona) {
            $this->assertStringNotContainsString('name="entregado_por_id" value="'.$persona->id.'"', $modal);
        }

        $casos = [
            [['entregado_por_id' => $ajena->id, 'motivo' => 'Error'], 'entregado_por_id'],         // no va en la salida
            [['entregado_por_id' => $inactiva->id, 'motivo' => 'Error'], 'entregado_por_id'],      // inactiva
            [['entregado_por_id' => $repartidor->id, 'motivo' => 'Error'], 'entregado_por_id'],    // no es vendedor
            [['entregado_por_id' => $this->personaAna->id, 'motivo' => 'Error'], 'entregado_por_id'], // ya figura
            [['entregado_por_id' => 999999, 'motivo' => 'Error'], 'entregado_por_id'],
            [['entregado_por_id' => 'x', 'motivo' => 'Error'], 'entregado_por_id'],
            [['motivo' => 'Error'], 'entregado_por_id'],
            [['entregado_por_id' => $this->personaBeto->id], 'motivo'],
            [['entregado_por_id' => $this->personaBeto->id, 'motivo' => '   '], 'motivo'],
            [['entregado_por_id' => $this->personaBeto->id, 'motivo' => str_repeat('a', 201)], 'motivo'],
        ];
        foreach ($casos as [$datos, $campo]) {
            $this->corregir($oficina, $this->entrega, $datos)->assertSessionHasErrors($campo);
        }

        // Lo que no tiene entrega anotada no se corrige.
        $pendiente = $this->viaje->entregas()->create(['dte_id' => $this->ccf('10.00')->id, 'cliente_sucursal_id' => $this->sala->id]);
        $this->corregir($oficina, $pendiente, ['entregado_por_id' => $this->personaBeto->id, 'motivo' => 'Error'])->assertSessionHasErrors('entrega');

        $this->assertSame($antes, $this->entrega->fresh()->getAttributes());
        $this->assertFalse(Activity::where('description', 'corrigió quién lo entregó')->exists());

        // Si el servidor lo rechaza, el diálogo vuelve abierto con lo que se escribió.
        $this->corregir($oficina, $this->entrega, ['entregado_por_id' => $ajena->id, 'motivo' => 'Lo entregó otra']);
        $this->get(route('rutas.salidas.show', $this->viaje))->assertOk()->assertSee('value="Lo entregó otra"', false);
    }

    public function test_sin_rutas_gestionar_da_403(): void
    {
        $datos = ['entregado_por_id' => $this->personaBeto->id, 'motivo' => 'Error'];
        foreach ([$this->oficina('jefe_vendedores'), $this->ana, $this->oficina('contabilidad')] as $usuario) {
            $this->corregir($usuario, $this->entrega, $datos)->assertForbidden();
        }
        // El jefe ve el viaje, pero sin el botón.
        $this->actingAs($this->oficina('jefe_vendedores'))->get(route('rutas.salidas.show', $this->viaje))->assertOk()->assertDontSee('Corregir quién');
        $this->assertSame($this->personaAna->id, (int) $this->entrega->fresh()->entregado_por_id);

        // La entrega de otra salida no se toca por la URL.
        $otra = SalidaRuta::create(['ruta_id' => $this->viaje->ruta_id, 'fecha_inicio' => '2026-11-10', 'estado' => EstadoSalidaRuta::EnCurso]);
        $this->actingAs($this->oficina())->patch(route('rutas.salidas.entregas.quien', [$otra, $this->entrega]), $datos)->assertNotFound();
    }

    // ══════════════════════════════ días cerrados y salidas liquidadas

    /** Una salida armada a mano de antes, con el día cerrado (y, si se pide, liquidada). */
    private function salidaCerrada(bool $liquidada): SalidaRutaEntrega
    {
        $oficina = $this->oficina();
        $salida = SalidaRuta::create(['ruta_id' => $this->viaje->ruta_id, 'fecha_inicio' => '2026-10-05', 'fecha_fin_real' => '2026-10-05',
            'estado' => EstadoSalidaRuta::Finalizada] + ($liquidada ? ['liquidada_en' => '2026-10-05 18:00:00', 'liquidada_por' => $oficina->id,
                'entrega_dinero_personal_id' => $this->personaAna->id] : []));
        $salida->participantes()->create(['rutas_personal_id' => $this->personaAna->id, 'rol' => RolEnSalida::Responsable]);
        $salida->participantes()->create(['rutas_personal_id' => $this->personaBeto->id, 'rol' => RolEnSalida::Acompanante]);
        $salida->cierres()->create(['fecha' => '2026-10-05', 'efectivo_esperado' => '50.00', 'efectivo_cobrado' => '50.00', 'a_entregar' => '50.00',
            'entrega_dinero_personal_id' => $this->personaAna->id, 'cerrado_por' => $oficina->id, 'cerrado_en' => '2026-10-05 18:00:00']);

        return $this->entregado($salida, '50.00', '2026-10-05 10:00:00');
    }

    public function test_corrige_quien_en_un_dia_cerrado_y_en_una_salida_liquidada_sin_tocar_lo_guardado(): void
    {
        foreach ([false, true] as $liquidada) {
            $entrega = $this->salidaCerrada($liquidada);
            $salida = $entrega->salida;
            $cierre = $salida->cierres()->sole()->getAttributes();
            $antes = $this->sinLaPersona($entrega);
            $admin = $this->oficina('administrador');

            $this->actingAs($admin)->get(route('rutas.salidas.show', $salida))->assertOk()
                ->assertSee('Corregir quién')->assertSee('Este día ya se cerró. El total no cambia: solo a quién se atribuye.')
                ->assertDontSee('>Deshacer</button>', false);

            $this->corregir($admin, $entrega, ['entregado_por_id' => $this->personaBeto->id, 'motivo' => 'Lo entregó Beto'])
                ->assertSessionHasNoErrors()->assertSessionHas('status');

            $this->assertSame($this->personaBeto->id, (int) $entrega->fresh()->entregado_por_id);
            $this->assertSame($antes, $this->sinLaPersona($entrega));
            // El cierre del día y la liquidación quedan como estaban: guardan totales de la salida, no de cada persona.
            $this->assertSame($cierre, $salida->cierres()->sole()->getAttributes());
            $this->assertSame([EstadoSalidaRuta::Finalizada, $liquidada], [$salida->fresh()->estado, $salida->fresh()->estaLiquidada()]);
            $this->assertTrue($salida->fresh()->diaCerrado('2026-10-05'));
        }
    }

    /**
     * Deshacer en un día cerrado o en una salida liquidada NO se habilitó (punto 2 de la
     * revisión): el cierre guarda lo esperado, lo cobrado y lo entregado de ese día, y la
     * liquidación sale de esos cierres. Deshacer una entrega dejaría esos totales sin cuadrar.
     * Ni el administrador, ni con motivo.
     */
    public function test_deshacer_en_un_dia_cerrado_o_liquidado_sigue_bloqueado_aun_para_el_administrador(): void
    {
        $admin = $this->oficina('administrador');

        $cerrado = $this->salidaCerrada(liquidada: false);
        $antes = $cerrado->fresh()->getAttributes();
        $this->actingAs($admin)->patch(route('rutas.salidas.entregas.deshacer', [$cerrado->salida_ruta_id, $cerrado]), ['motivo' => 'Se marcó otro CCF'])
            ->assertForbidden();
        $this->assertSame($antes, $cerrado->fresh()->getAttributes());

        $liquidado = $this->salidaCerrada(liquidada: true);
        $antes = $liquidado->fresh()->getAttributes();
        $this->actingAs($admin)->from(route('rutas.salidas.show', $liquidado->salida_ruta_id))
            ->patch(route('rutas.salidas.entregas.deshacer', [$liquidado->salida_ruta_id, $liquidado]), ['motivo' => 'Se marcó otro CCF'])
            ->assertSessionHasErrors(['cierre' => 'La ruta ya está terminada: sus días no se cambian.']);
        $this->assertSame($antes, $liquidado->fresh()->getAttributes());
        $this->assertSame(2, SalidaRutaEntrega::whereIn('id', [$cerrado->id, $liquidado->id])->entregadas()->count());
    }
}
