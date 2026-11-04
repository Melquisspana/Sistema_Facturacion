<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\MotivoNoEntrega;
use App\Enums\ResultadoEntrega;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\User;
use App\Services\Rutas\LiquidacionSalida;
use App\Services\Rutas\ParticipantesSalida;
use App\Support\HoraNegocio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Salida de varios días (San Miguel, Santa Ana): cada día se termina aparte, sin entregar
 * el dinero, y al volver se termina la ruta con la liquidación de todos los días.
 */
class TerminarRutaTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private User $oficina;

    private PersonalRuta $persona;

    private SalidaRuta $salida;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 11, 4)->setTime(18, 0));
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28']);
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos', 'user_id' => $this->vendedor->id]);
        $this->salida = SalidaRuta::create([
            'ruta_id' => Ruta::create(['nombre' => 'San Miguel'])->id, 'fecha_inicio' => '2026-11-04',
            'fecha_fin_estimada' => '2026-11-06', 'estado' => EstadoSalidaRuta::EnCurso,
        ]);
        app(ParticipantesSalida::class)->sincronizar($this->salida, [$this->persona->id], null);
        $this->actingAs($this->vendedor);
    }

    private function entrega(?string $forma = 'efectivo'): SalidaRutaEntrega
    {
        $cliente = Cliente::factory()->create(['cobro_en_entrega' => $forma]);
        $sala = $cliente->sucursales()->create(['nombre' => 'Sala '.(Dte::count() + 1), 'ruta_id' => $this->salida->ruta_id]);
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Pruebas'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $dte = Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $cliente->id, 'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) (907 + Dte::count()), 15, '0', STR_PAD_LEFT),
            'fecha_emision' => '2026-11-01', 'hora_emision' => '10:00:00', 'total_pagar' => 100.25,
        ]);

        return $this->salida->entregas()->create(['dte_id' => $dte->id, 'cliente_sucursal_id' => $sala->id]);
    }

    private function entregar(SalidaRutaEntrega $entrega): void
    {
        $this->patch(route('rutas.mi-salida.update', [$this->salida, $entrega]), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
    }

    private function gasto(string $concepto, string $monto): void
    {
        $this->post(route('rutas.mi-salida.gastos.store', $this->salida), ['concepto' => $concepto, 'monto' => $monto])->assertSessionHasNoErrors();
    }

    private function terminarDia(array $entregas = []): void
    {
        $this->post(route('rutas.mi-salida.cerrar', $this->salida), ['entregas' => $entregas])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_salida_de_tres_dias_se_liquida_toda_junta_al_volver(): void
    {
        // Día 1: cobra en efectivo y echa gasolina. Terminar el día no entrega el dinero.
        $efectivo = $this->entrega();
        $this->entregar($efectivo);
        $this->gasto('gasolina', '10.00');
        $this->terminarDia([$efectivo->id => ['monto_cobrado' => '100.25', 'forma_cobro' => 'efectivo']]);
        $this->assertNull($this->salida->cierreDelDia('2026-11-04')->entrega_dinero_personal_id);
        $this->assertSame(EstadoSalidaRuta::EnCurso, $this->salida->refresh()->estado);
        $this->get(route('rutas.mi-salida.cierre', $this->salida))->assertOk()->assertSee('Día terminado')->assertSee('al terminar la ruta')->assertDontSee('¿Quién entrega el dinero?');

        // Día 2: sigue la misma salida. Cobra con cheque y paga parqueo; algo sobra.
        $this->travel(1)->days();
        $this->get(route('rutas.mi-salida.index'))->assertRedirect(route('rutas.mi-salida.show', $this->salida));
        $cheque = $this->entrega('cheque');
        $sobra = $this->entrega();
        $this->entregar($cheque);
        $this->gasto('parqueo', '5.00');

        $url = route('rutas.mi-salida.liquidar', $this->salida);
        $this->get(route('rutas.mi-salida.terminar', $this->salida))->assertOk()->assertSee('Falta terminar el día 05/11/2026')->assertSee('Sobraron 1');
        // Lo que sobró necesita su motivo, y con «Otro» su detalle.
        $this->post($url, ['entrega_dinero_personal_id' => $this->persona->id])->assertSessionHasErrors('motivos.'.$sobra->id.'.motivo');
        $this->post($url, ['entrega_dinero_personal_id' => $this->persona->id, 'motivos' => [$sobra->id => ['motivo' => 'otro']]])->assertSessionHasErrors('motivos.'.$sobra->id.'.nota');
        $motivos = ['motivos' => [$sobra->id => ['motivo' => MotivoNoEntrega::SalaCerrada->value]]];
        // Todo día con movimiento tiene que estar terminado; no se anota nada si falta uno.
        $this->post($url, $motivos + ['entrega_dinero_personal_id' => $this->persona->id])->assertSessionHasErrors('dias');
        $this->assertTrue($sobra->refresh()->estaPendiente());

        $this->terminarDia([$cheque->id => ['monto_cobrado' => '100.25', 'forma_cobro' => 'cheque']]);
        // Hubo cobros: quién entrega el dinero es obligatorio y tiene que ir en la salida.
        $this->post($url, $motivos)->assertSessionHasErrors('entrega_dinero_personal_id');
        $this->post($url, $motivos + ['entrega_dinero_personal_id' => PersonalRuta::create(['nombre' => 'Ajeno'])->id])->assertSessionHasErrors('entrega_dinero_personal_id');
        $this->assertTrue($sobra->refresh()->estaPendiente());
        $this->post($url, $motivos + ['entrega_dinero_personal_id' => $this->persona->id])->assertSessionHasNoErrors()->assertRedirect(route('rutas.mi-salida.terminar', $this->salida));

        $this->salida->refresh();
        $this->assertSame(EstadoSalidaRuta::Finalizada, $this->salida->estado);
        $this->assertTrue($this->salida->estaLiquidada());
        $this->assertSame($this->persona->id, $this->salida->entrega_dinero_personal_id);
        $this->assertSame($this->vendedor->id, $this->salida->liquidada_por);
        $this->assertSame(ResultadoEntrega::NoEntregado, $sobra->refresh()->resultado);
        $this->assertSame(MotivoNoEntrega::SalaCerrada, $sobra->motivo_no_entrega);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'salida_ruta', 'description' => 'terminó la ruta']);

        $totales = app(LiquidacionSalida::class)->totales($this->salida);
        $this->assertSame([10025, 10025, 0, 1500, 8525], [$totales['efectivo'], $totales['cheques'], $totales['transferencias'], $totales['gastos'], $totales['a_entregar']]);
        $this->get(route('rutas.mi-salida.terminar', $this->salida))->assertOk()->assertSee('Efectivo a entregar $85.25')->assertSee('Más los cheques por $100.25')
            ->assertSee('Sala cerrada')->assertDontSee('Sobraron');

        $pdf = $this->get(route('rutas.mi-salida.liquidacion-final', $this->salida))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('liquidacion-ruta-san-miguel-2026-11-04.pdf', $pdf->headers->get('content-disposition'));
        $html = view('rutas.liquidacion.pdf', [
            'salida' => $this->salida->load(['ruta', 'personal', 'entregaDinero']), 'empresa' => Empresa::first(),
            'totales' => $totales, 'entregadas' => app(LiquidacionSalida::class)->entregadas($this->salida),
            'noEntregadas' => app(LiquidacionSalida::class)->noEntregadas($this->salida), 'gastos' => $this->salida->gastos,
        ])->render();
        foreach (['Liquidación de la ruta', '04/11/2026', '05/11/2026', 'Gasolina', 'Parqueo', 'Sala cerrada', $sobra->dte->numero_control, 'Efectivo a entregar $85.25', 'Entrega: Carlos',
            // Los documentos de la salida: los entregados con su forma, lo cobrado y quién los entregó.
            'Entregados:</strong> 2 · $200.50', 'No entregados:</strong> 1 · $100.25', $efectivo->dte->numero_control, $cheque->dte->numero_control,
            '<td>Efectivo</td><td class="dinero">$100.25</td><td>Carlos</td>', '<td>Cheque</td><td class="dinero">$100.25</td><td>Carlos</td>'] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }
    }

    public function test_terminada_ya_no_se_cambia_y_no_se_termina_dos_veces(): void
    {
        $entrega = $this->entrega(null);
        $this->entregar($entrega);
        $this->terminarDia();
        $this->post(route('rutas.mi-salida.liquidar', $this->salida))->assertSessionHasNoErrors();
        $this->assertTrue($this->salida->refresh()->estaLiquidada());
        $this->assertNull($this->salida->entrega_dinero_personal_id);

        $this->actingAs($this->oficina);
        $fecha = HoraNegocio::fechaHoy();
        $this->post(route('rutas.salidas.liquidar', $this->salida))->assertSessionHasErrors('salida');
        $this->post(route('rutas.salidas.reabrir', [$this->salida, $fecha]), ['motivo' => 'Corregir cobro'])->assertSessionHasErrors('cierre');
        $this->post(route('rutas.salidas.gastos.store', $this->salida), ['concepto' => 'parqueo', 'monto' => '1.00', 'fecha' => $fecha])->assertForbidden();
        $this->patch(route('rutas.salidas.entregas.deshacer', [$this->salida, $entrega]))->assertSessionHasErrors('cierre');
        $this->assertSame(0, $this->salida->gastos()->count());
        $this->get(route('rutas.salidas.show', $this->salida))->assertOk()->assertSee('Liquidación de la ruta')->assertDontSee('Terminar ruta');
        $this->get(route('rutas.salidas.liquidacion-final', $this->salida))->assertOk();
    }

    public function test_quien_puede_terminar_la_ruta(): void
    {
        // «Terminar ruta» viene plegado y pregunta antes; el que entró sin querer vuelve a marcar.
        $this->get(route('rutas.mi-salida.show', $this->salida))->assertOk()->assertSee(route('rutas.mi-salida.terminar', $this->salida))
            ->assertSeeInOrder(['Terminar ruta', '¿Ya terminaron toda la ruta?', 'Para cerrar solo hoy, usá «Terminar día».', 'Sí, terminar ruta'], false);
        $this->get(route('rutas.mi-salida.terminar', $this->salida))->assertOk()
            ->assertSeeInOrder(['href="'.route('rutas.mi-salida.show', $this->salida).'"', 'Seguir marcando entregas'], false);
        $this->get(route('rutas.mi-salida.liquidacion-final', $this->salida))->assertNotFound();

        // Un vendedor que no va en la salida no la ve ni la termina.
        $ajeno = User::factory()->create(['activo' => true])->assignRole('vendedor');
        PersonalRuta::create(['nombre' => 'Ana', 'user_id' => $ajeno->id]);
        $this->actingAs($ajeno)->get(route('rutas.mi-salida.terminar', $this->salida))->assertForbidden();
        $this->post(route('rutas.mi-salida.liquidar', $this->salida))->assertForbidden();
        $this->get(route('rutas.salidas.terminar', $this->salida))->assertForbidden();

        // Planificada no se termina; la oficina sí termina una en curso.
        $this->salida->update(['estado' => EstadoSalidaRuta::Planificada]);
        $this->actingAs($this->oficina)->post(route('rutas.salidas.liquidar', $this->salida))->assertSessionHasErrors('salida');
        $this->actingAs($this->vendedor)->post(route('rutas.mi-salida.liquidar', $this->salida))->assertForbidden();
        $this->salida->update(['estado' => EstadoSalidaRuta::EnCurso]);
        $this->actingAs($this->oficina)->get(route('rutas.salidas.show', $this->salida))->assertOk()->assertSee(route('rutas.salidas.terminar', $this->salida));
        $this->get(route('rutas.salidas.terminar', $this->salida))->assertOk()->assertSee('Ningún día terminado todavía.')
            ->assertSee('Volver a la salida')->assertDontSee('Seguir marcando entregas');
        $this->post(route('rutas.salidas.liquidar', $this->salida))->assertSessionHasNoErrors()->assertRedirect(route('rutas.salidas.terminar', $this->salida));
        $this->assertSame($this->oficina->id, $this->salida->refresh()->liquidada_por);

        // Terminada, el vendedor ve su liquidación aunque ya no esté en curso.
        $this->actingAs($this->vendedor)->get(route('rutas.mi-salida.terminar', $this->salida))->assertOk()->assertSee('Descargar liquidación de la ruta')->assertDontSee('Seguir marcando entregas');
        $this->get(route('rutas.mi-salida.liquidacion-final', $this->salida))->assertOk();
    }

    public function test_migracion_reversible(): void
    {
        $migracion = require database_path('migrations/2026_11_03_120000_add_liquidacion_a_salidas_ruta.php');
        $migracion->down();
        $this->assertFalse(Schema::hasColumn('salidas_ruta', 'liquidada_en'));
        $this->assertFalse(Schema::hasColumn('salidas_ruta', 'entrega_dinero_personal_id'));
        $migracion->up();
        $this->assertTrue(Schema::hasColumn('salidas_ruta', 'liquidada_por'));
    }
}
