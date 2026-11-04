<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\OrigenRegistroEntrega;
use App\Enums\ResultadoEntrega;
use App\Enums\RolSistema;
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
use App\Services\Rutas\CierreDia;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\LiquidacionSalida;
use App\Services\Rutas\ParticipantesSalida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El CCF que se factura DESPUÉS de entregar el producto (docs/PLAN_TABLERO_RUTAS.md, PR 5):
 * queda entregado en la salida, el día y por quien dice la oficina, sin mover dinero.
 * Datos ficticios.
 */
class FacturadoDespuesTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private User $oficina;

    private PersonalRuta $persona;

    private SalidaRuta $salida;

    private ClienteSucursal $sala;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 11, 4)->setTime(18, 0));
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28', 'rutas.ambiente_ccf' => null]);
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->oficina = User::factory()->create(['activo' => true, 'name' => 'Oficina Prueba'])->assignRole('facturacion');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos', 'user_id' => $this->vendedor->id]);
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $this->salida = SalidaRuta::create([
            'ruta_id' => $ruta->id, 'fecha_inicio' => '2026-11-04',
            'fecha_fin_estimada' => '2026-11-06', 'estado' => EstadoSalidaRuta::EnCurso,
        ]);
        app(ParticipantesSalida::class)->sincronizar($this->salida, [$this->persona->id], null);
        $this->sala = Cliente::factory()->create(['cobro_en_entrega' => 'efectivo'])
            ->sucursales()->create(['nombre' => 'Sala Ficticia Centro', 'ruta_id' => $ruta->id]);
    }

    /** Un CCF aceptado. `$extra` lo vuelve borrador, de otro tipo, etc. */
    private function ccf(array $extra = []): Dte
    {
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Pruebas'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $n = 907 + Dte::count();

        return Dte::create($extra + [
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $this->sala->cliente_id, 'cliente_sucursal_id' => $this->sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'numero_orden_compra' => '26060232'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'fecha_emision' => now()->toDateString(), 'hora_emision' => '10:00:00', 'total_pagar' => 100.25,
        ]);
    }

    /** Día normal del vendedor: entrega un CCF en efectivo, gasta y termina el día. */
    private function diaConDinero(): void
    {
        $this->actingAs($this->vendedor);
        $dte = $this->ccf();
        $entrega = $this->salida->entregas()->create(['dte_id' => $dte->id, 'cliente_sucursal_id' => $this->sala->id]);
        $this->patch(route('rutas.mi-salida.update', [$this->salida, $entrega]), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $this->post(route('rutas.mi-salida.gastos.store', $this->salida), ['concepto' => 'gasolina', 'monto' => '10.00'])->assertSessionHasNoErrors();
        $this->post(route('rutas.mi-salida.cerrar', $this->salida), ['entregas' => [$entrega->id => ['monto_cobrado' => '100.25', 'forma_cobro' => 'efectivo']]])
            ->assertSessionHasNoErrors();
    }

    private function anotar(Dte $dte, array $datos = [])
    {
        return $this->actingAs($this->oficina)->from(route('rutas.salidas.show', $this->salida))
            ->post(route('rutas.salidas.entregas.facturado-despues', $this->salida), $datos + [
                'numero_control' => $dte->numero_control,
                'entregado_por_id' => $this->persona->id,
                'fecha_entrega' => '2026-11-04',
                'nota' => 'La orden de compra llegó el jueves.',
            ]);
    }

    /** Todo lo que dice cuánto dinero dejó la salida: cierres, liquidación y lo que lista el día. */
    private function dinero(): array
    {
        $salida = $this->salida->fresh();
        $totales = app(LiquidacionSalida::class)->totales($salida);
        unset($totales['dias']);

        return [
            'cierres' => $salida->cierres()->orderBy('id')->get()->map->only([
                'fecha', 'efectivo_esperado', 'efectivo_cobrado', 'cheques_esperado', 'cheques_cobrado',
                'transferencias_esperado', 'transferencias_cobrado', 'gastos_total', 'a_entregar', 'cerrado_en', 'reabierto_en',
            ])->all(),
            'totales' => $totales,
            'dias_sin_terminar' => app(LiquidacionSalida::class)->diasSinTerminar($salida),
            'entregas_del_dia' => app(CierreDia::class)->entregas($salida, '2026-11-04')->modelKeys(),
            'salida' => $salida->only(['estado', 'liquidada_en', 'liquidada_por', 'entrega_dinero_personal_id', 'fecha_fin_real']),
        ];
    }

    private function asertarAnotado(Dte $dte, string $fecha): SalidaRutaEntrega
    {
        $fila = SalidaRutaEntrega::where('dte_id', $dte->id)->sole();
        $this->assertSame($this->salida->id, $fila->salida_ruta_id);
        $this->assertSame(ResultadoEntrega::Entregado, $fila->resultado);
        $this->assertSame(OrigenRegistroEntrega::FacturadoDespues, $fila->origen_registro);
        $this->assertSame($this->persona->id, $fila->entregado_por_id);
        $this->assertSame($fecha, $fila->fecha_resultado->toDateString());
        $this->assertSame($this->oficina->id, $fila->registrado_por);
        $this->assertNull($fila->forma_cobro);
        $this->assertNull($fila->monto_esperado);
        $this->assertNull($fila->monto_cobrado);
        // Ya no está pendiente de entregar.
        $this->assertNotContains($dte->id, app(EntregasCcf::class)->pendientesDeRuta($this->salida->ruta)->pluck('id')->all());

        return $fila;
    }

    // ══════════════════════════════ los tres casos

    public function test_en_curso_con_el_dia_abierto_queda_entregado_sin_dinero(): void
    {
        $dte = $this->ccf();

        $this->anotar($dte)->assertSessionHasNoErrors()->assertRedirect(route('rutas.salidas.show', $this->salida))
            ->assertSessionHas('status', 'CCF '.$dte->numero_control.' anotado como entregado el 04/11/2026, sin dinero.');

        $fila = $this->asertarAnotado($dte, '2026-11-04');
        $this->assertSame('La orden de compra llegó el jueves.', $fila->nota);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'salida_ruta_entrega', 'description' => 'anotó el CCF facturado después de entregar', 'subject_id' => $fila->id]);

        // No entra al día: ni en lo que lista el cierre ni en «Terminar día».
        $this->assertSame([], app(CierreDia::class)->entregas($this->salida, '2026-11-04')->modelKeys());
        $this->assertSame([], app(LiquidacionSalida::class)->diasSinTerminar($this->salida));
        $this->actingAs($this->oficina)->postJson(route('rutas.salidas.cerrar', $this->salida))->assertUnprocessable();
        $this->assertSame(0, $this->salida->cierres()->count());

        // Tampoco aparece en «Mi salida» del vendedor.
        $this->actingAs($this->vendedor)->get(route('rutas.mi-salida.show', $this->salida))->assertOk()
            ->assertDontSee(route('rutas.mi-salida.ccf', [$this->salida, $fila]))->assertSee('Esta salida no lleva CCF');
    }

    public function test_en_curso_con_el_dia_terminado_no_reabre_el_dia_ni_cambia_el_dinero(): void
    {
        $this->diaConDinero();
        $this->travel(1)->days();
        $antes = $this->dinero();
        $dte = $this->ccf();

        $this->anotar($dte)->assertSessionHasNoErrors();

        $this->asertarAnotado($dte, '2026-11-04');
        $this->assertEquals($antes, $this->dinero());
        $this->assertTrue($this->salida->diaCerrado('2026-11-04'));

        // En el tablero, la última entrega de la sala es el día real de la entrega.
        $this->assertSame('2026-11-04', app(EntregasCcf::class)->ultimaVisitaPorSala([$this->sala->id])[$this->sala->id]->toDateString());
    }

    public function test_terminada_y_liquidada_no_toca_la_liquidacion_y_deshacer_tampoco(): void
    {
        $this->diaConDinero();
        $this->post(route('rutas.mi-salida.liquidar', $this->salida), ['entrega_dinero_personal_id' => $this->persona->id])->assertSessionHasNoErrors();
        $this->assertTrue($this->salida->refresh()->estaLiquidada());
        $this->travel(2)->days();
        $antes = $this->dinero();
        $this->assertSame(10025 - 1000, $antes['totales']['a_entregar']);
        $dte = $this->ccf();

        $this->anotar($dte)->assertSessionHasNoErrors();

        $fila = $this->asertarAnotado($dte, '2026-11-04');
        $this->assertEquals($antes, $this->dinero());
        $this->actingAs($this->oficina)->get(route('rutas.salidas.terminar', $this->salida))->assertOk()->assertSee('Efectivo a entregar $90.25');

        // Deshacer: el CCF vuelve a quedar sin entregar y el dinero no se mueve.
        $this->delete(route('rutas.salidas.entregas.facturado-despues.destroy', [$this->salida, $fila]))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('salida_ruta_entregas', ['id' => $fila->id]);
        $this->assertEquals($antes, $this->dinero());
        $this->assertContains($dte->id, app(EntregasCcf::class)->pendientesDeRuta($this->salida->ruta)->pluck('id')->all());
        $this->assertDatabaseHas('activity_log', ['log_name' => 'salida_ruta_entrega', 'description' => 'deshizo el CCF facturado después de entregar', 'subject_id' => $fila->id]);
    }

    public function test_la_hoja_lo_muestra_aparte_con_quien_y_que_dia(): void
    {
        $dte = $this->ccf();
        $this->anotar($dte)->assertSessionHasNoErrors();

        $hoja = $this->actingAs($this->oficina)->get(route('rutas.salidas.show', $this->salida))->assertOk();
        $hoja->assertSeeInOrder(['Facturados después de entregar (sin dinero)', 'Sala Ficticia Centro', $dte->numero_control, 'Entregó Carlos · 04/11/2026', 'La orden de compra llegó el jueves.', 'Anotó Oficina Prueba'])
            ->assertSee('Entregado antes de facturar')
            ->assertSee(route('rutas.salidas.entregas.facturado-despues.destroy', [$this->salida, SalidaRutaEntrega::where('dte_id', $dte->id)->sole()]))
            // No cuenta en la lista de la ruta: sigue «Esta salida no lleva CCF».
            ->assertSee('Esta salida no lleva CCF');

        // Quien solo ve Rutas ve el bloque, pero no anota ni deshace.
        $mirador = User::factory()->create(['activo' => true])->givePermissionTo('rutas.ver');
        $this->actingAs($mirador)->get(route('rutas.salidas.show', $this->salida))->assertOk()
            ->assertSee('Facturados después de entregar (sin dinero)')
            ->assertDontSee('Entregado antes de facturar')
            ->assertDontSee(route('rutas.salidas.entregas.facturado-despues', $this->salida));
    }

    // ══════════════════════════════ rechazos

    public function test_rechaza_a_quien_no_fue_y_un_dia_fuera_de_la_salida(): void
    {
        $dte = $this->ccf();
        $ajena = PersonalRuta::create(['nombre' => 'Ana']);

        $this->anotar($dte, ['entregado_por_id' => $ajena->id])->assertSessionHasErrorsIn('facturadoDespues', 'entregado_por_id');
        $this->anotar($dte, ['fecha_entrega' => '2026-11-03'])->assertSessionHasErrorsIn('facturadoDespues', 'fecha_entrega');
        $this->anotar($dte, ['fecha_entrega' => '2026-11-05'])->assertSessionHasErrorsIn('facturadoDespues', 'fecha_entrega');
        $this->anotar($dte, ['fecha_entrega' => '04/11/2026'])->assertSessionHasErrorsIn('facturadoDespues', 'fecha_entrega');

        // Terminada, el último día es el del regreso.
        $this->salida->update(['estado' => EstadoSalidaRuta::Finalizada, 'fecha_fin_real' => '2026-11-04']);
        $this->travel(3)->days();
        $this->anotar($dte, ['fecha_entrega' => '2026-11-06'])->assertSessionHasErrorsIn('facturadoDespues', 'fecha_entrega');

        $this->assertSame(0, SalidaRutaEntrega::count());
    }

    public function test_rechaza_lo_mismo_que_agregar_un_ccf(): void
    {
        // Ya entregado en otra salida.
        $entregado = $this->ccf();
        $otra = SalidaRuta::create(['ruta_id' => $this->salida->ruta_id, 'fecha_inicio' => '2026-11-01', 'estado' => EstadoSalidaRuta::Finalizada]);
        $otra->entregas()->create(['dte_id' => $entregado->id, 'cliente_sucursal_id' => $this->sala->id, 'resultado' => ResultadoEntrega::Entregado, 'fecha_resultado' => '2026-11-01']);
        $this->anotar($entregado)->assertSessionHasErrorsIn('facturadoDespues', ['numero_control' => 'Ese CCF ya consta como entregado.']);

        // Sin registrar en otra salida abierta.
        $enCamino = $this->ccf();
        $abierta = SalidaRuta::create(['ruta_id' => $this->salida->ruta_id, 'fecha_inicio' => '2026-11-04', 'estado' => EstadoSalidaRuta::EnCurso]);
        $abierta->entregas()->create(['dte_id' => $enCamino->id, 'cliente_sucursal_id' => $this->sala->id]);
        $this->anotar($enCamino)->assertSessionHasErrorsIn('facturadoDespues', ['numero_control' => 'Ese CCF ya va sin registrar en otra salida abierta.']);

        // Con albarán de entrega.
        $conAlbaran = $this->ccf();
        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0232/00/9001', 'numero_orden_compra' => $conAlbaran->numero_orden_compra,
            'monto_albaran' => 100.25, 'fecha_albaran' => '2026-11-04', 'origen' => 'gmail',
        ]);
        $this->anotar($conAlbaran)->assertSessionHasErrorsIn('facturadoDespues', ['numero_control' => 'Ese CCF ya tiene albarán de entrega: consta como entregado.']);

        // No aceptado por Hacienda, o no es un CCF, o no existe.
        $this->anotar($this->ccf(['estado' => 'borrador', 'sello_recepcion' => null]))->assertSessionHasErrorsIn('facturadoDespues', 'numero_control');
        $this->anotar($this->ccf(['tipo_dte' => '01']))->assertSessionHasErrorsIn('facturadoDespues', ['numero_control' => 'Ese documento no es un CCF.']);
        $this->anotar($entregado, ['numero_control' => 'DTE-03-M001P002-999999999999999'])->assertSessionHasErrorsIn('facturadoDespues', 'numero_control');

        $this->assertSame(0, SalidaRutaEntrega::where('origen_registro', OrigenRegistroEntrega::FacturadoDespues->value)->count());
    }

    public function test_no_se_anota_en_una_salida_planificada_ni_cancelada(): void
    {
        $dte = $this->ccf();
        foreach ([EstadoSalidaRuta::Planificada, EstadoSalidaRuta::Cancelada] as $estado) {
            $this->salida->update(['estado' => $estado]);
            $this->anotar($dte)->assertSessionHasErrorsIn('facturadoDespues', 'salida');
        }
        $this->assertSame(0, SalidaRutaEntrega::count());
    }

    public function test_sin_gestionar_da_403(): void
    {
        $dte = $this->ccf();
        $this->anotar($dte)->assertSessionHasNoErrors();
        $fila = SalidaRutaEntrega::where('dte_id', $dte->id)->sole();

        $jefe = User::factory()->create(['activo' => true])->assignRole(RolSistema::JefeVendedores->value);
        foreach ([$jefe, $this->vendedor] as $usuario) {
            $this->actingAs($usuario)->post(route('rutas.salidas.entregas.facturado-despues', $this->salida), [
                'numero_control' => $this->ccf()->numero_control, 'entregado_por_id' => $this->persona->id, 'fecha_entrega' => '2026-11-04',
            ])->assertForbidden();
            $this->delete(route('rutas.salidas.entregas.facturado-despues.destroy', [$this->salida, $fila]))->assertForbidden();
        }
        $this->assertDatabaseHas('salida_ruta_entregas', ['id' => $fila->id]);
        $this->assertSame(1, SalidaRutaEntrega::count());
    }

    public function test_cada_registro_se_deshace_solo_por_su_camino(): void
    {
        $dte = $this->ccf();
        $this->anotar($dte)->assertSessionHasNoErrors();
        $fila = SalidaRutaEntrega::where('dte_id', $dte->id)->sole();

        // El «Deshacer» normal no lo devuelve a pendiente.
        $this->patch(route('rutas.salidas.entregas.deshacer', [$this->salida, $fila]))->assertSessionHasErrors('entrega');
        $this->assertSame(ResultadoEntrega::Entregado, $fila->refresh()->resultado);

        // Y este camino no borra una entrega normal.
        $normal = $this->salida->entregas()->create(['dte_id' => $this->ccf()->id, 'cliente_sucursal_id' => $this->sala->id]);
        $this->delete(route('rutas.salidas.entregas.facturado-despues.destroy', [$this->salida, $normal]))->assertSessionHasErrors('entrega');
        $this->assertDatabaseHas('salida_ruta_entregas', ['id' => $normal->id]);
    }
}
