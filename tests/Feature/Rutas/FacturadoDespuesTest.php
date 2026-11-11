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
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\User;
use App\Services\Rutas\CierreDia;
use App\Services\Rutas\LiquidacionSalida;
use App\Services\Rutas\ParticipantesSalida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CcfDeLaRuta;
use Tests\TestCase;

/**
 * Los CCF anotados como facturados DESPUÉS de entregar (PR #230). Desde el 04/11/2026 ya no
 * se anotan nuevos: el producto nunca se entrega sin CCF. Los registros que quedaron siguen
 * fuera del dinero, se ven en la hoja solo para leer y la oficina los puede deshacer.
 * Datos ficticios.
 */
class FacturadoDespuesTest extends TestCase
{
    use CcfDeLaRuta;
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

    /** Un CCF aceptado. */
    private function ccf(): Dte
    {
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Pruebas'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $n = 907 + Dte::count();

        return Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $this->sala->cliente_id, 'cliente_sucursal_id' => $this->sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'numero_orden_compra' => '26060232'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'fecha_emision' => now()->toDateString(), 'hora_emision' => '10:00:00', 'total_pagar' => 100.25,
        ]);
    }

    /** Un registro como los que dejó «Entregado antes de facturar»: entregado y sin dinero. */
    private function registroViejo(Dte $dte): SalidaRutaEntrega
    {
        return $this->salida->entregas()->create([
            'dte_id' => $dte->id, 'cliente_sucursal_id' => $dte->cliente_sucursal_id,
            'resultado' => ResultadoEntrega::Entregado, 'fecha_resultado' => '2026-11-04',
            'entregado_por_id' => $this->persona->id, 'nota' => 'La orden de compra llegó el jueves.',
            'trae_nota_averia' => false, 'origen_registro' => OrigenRegistroEntrega::FacturadoDespues,
            'registrado_por' => $this->oficina->id,
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

    // ══════════════════════════════ los registros que quedaron

    public function test_un_registro_viejo_no_entra_al_dia_ni_a_mi_salida(): void
    {
        $fila = $this->registroViejo($this->ccf());

        $this->assertSame([], app(CierreDia::class)->entregas($this->salida, '2026-11-04')->modelKeys());
        $this->assertSame([], app(LiquidacionSalida::class)->diasSinTerminar($this->salida));
        $this->actingAs($this->oficina)->postJson(route('rutas.salidas.cerrar', $this->salida))->assertUnprocessable();
        $this->assertSame(0, $this->salida->cierres()->count());

        $this->actingAs($this->vendedor)->get(route('rutas.mi-salida.show', $this->salida))->assertOk()
            ->assertDontSee(route('rutas.mi-salida.ccf', [$this->salida, $fila]))->assertSee('Esta salida no lleva CCF');
    }

    public function test_con_el_dia_terminado_no_cambia_el_dinero(): void
    {
        $this->diaConDinero();
        $this->travel(1)->days();
        $antes = $this->dinero();

        $this->registroViejo($this->ccf());

        $this->assertEquals($antes, $this->dinero());
        $this->assertTrue($this->salida->diaCerrado('2026-11-04'));
    }

    public function test_deshacer_en_una_salida_liquidada_no_toca_la_liquidacion(): void
    {
        $this->diaConDinero();
        $dte = $this->ccf();
        $fila = $this->registroViejo($dte);
        $this->post(route('rutas.mi-salida.liquidar', $this->salida), ['entrega_dinero_personal_id' => $this->persona->id])->assertSessionHasNoErrors();
        $this->assertTrue($this->salida->refresh()->estaLiquidada());
        $this->travel(2)->days();
        $antes = $this->dinero();
        $this->assertSame(10025 - 1000, $antes['totales']['a_entregar']);

        $this->actingAs($this->oficina)->delete(route('rutas.salidas.entregas.facturado-despues.destroy', [$this->salida, $fila]))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('salida_ruta_entregas', ['id' => $fila->id]);
        $this->assertEquals($antes, $this->dinero());
        $this->assertContains($dte->id, $this->pendientesDeLaRuta($this->salida->ruta)->pluck('id')->all());
        $this->assertDatabaseHas('activity_log', ['log_name' => 'salida_ruta_entrega', 'description' => 'deshizo el CCF facturado después de entregar', 'subject_id' => $fila->id]);
    }

    // ══════════════════════════════ la hoja

    public function test_la_hoja_lo_lista_solo_para_leer_con_deshacer_para_la_oficina(): void
    {
        $dte = $this->ccf();
        $fila = $this->registroViejo($dte);

        $this->actingAs($this->oficina)->get(route('rutas.salidas.show', $this->salida))->assertOk()
            ->assertSeeInOrder(['Facturados después de entregar (sin dinero)', 'Sala Ficticia Centro', $dte->numero_control, 'Entregó Carlos · 04/11/2026', 'La orden de compra llegó el jueves.', 'Anotó Oficina Prueba'])
            ->assertSee(route('rutas.salidas.entregas.facturado-despues.destroy', [$this->salida, $fila]))
            // Ya no hay formulario para anotar nuevos.
            ->assertDontSee('Entregado antes de facturar')
            ->assertDontSee('fd-numero')
            ->assertSee('Esta salida no lleva CCF');

        // Quien solo ve Rutas ve la lista, pero no deshace.
        $mirador = User::factory()->create(['activo' => true])->givePermissionTo('rutas.ver');
        $this->actingAs($mirador)->get(route('rutas.salidas.show', $this->salida))->assertOk()
            ->assertSee('Facturados después de entregar (sin dinero)')
            ->assertDontSee(route('rutas.salidas.entregas.facturado-despues.destroy', [$this->salida, $fila]));
    }

    public function test_sin_registros_la_seccion_no_aparece(): void
    {
        foreach ([EstadoSalidaRuta::EnCurso, EstadoSalidaRuta::Finalizada] as $estado) {
            $this->salida->update(['estado' => $estado]);
            $this->actingAs($this->oficina)->get(route('rutas.salidas.show', $this->salida))->assertOk()
                ->assertDontSee('Facturados después de entregar')
                ->assertDontSee('Entregado antes de facturar');
        }
    }

    public function test_ya_no_se_pueden_anotar_nuevos(): void
    {
        $this->actingAs($this->oficina)->post('/rutas/salidas/'.$this->salida->id.'/entregas/facturado-despues', [
            'numero_control' => $this->ccf()->numero_control, 'entregado_por_id' => $this->persona->id, 'fecha_entrega' => '2026-11-04',
        ])->assertMethodNotAllowed();

        $this->assertSame(0, SalidaRutaEntrega::count());
    }

    // ══════════════════════════════ permisos y caminos

    public function test_sin_gestionar_no_se_deshace(): void
    {
        $fila = $this->registroViejo($this->ccf());

        $jefe = User::factory()->create(['activo' => true])->assignRole(RolSistema::JefeVendedores->value);
        foreach ([$jefe, $this->vendedor] as $usuario) {
            $this->actingAs($usuario)->delete(route('rutas.salidas.entregas.facturado-despues.destroy', [$this->salida, $fila]))->assertForbidden();
        }
        $this->assertDatabaseHas('salida_ruta_entregas', ['id' => $fila->id]);
    }

    public function test_cada_registro_se_deshace_solo_por_su_camino(): void
    {
        $fila = $this->registroViejo($this->ccf());
        $this->actingAs($this->oficina);

        // El «Deshacer» normal no lo devuelve a pendiente.
        $this->patch(route('rutas.salidas.entregas.deshacer', [$this->salida, $fila]))->assertSessionHasErrors('entrega');
        $this->assertSame(ResultadoEntrega::Entregado, $fila->refresh()->resultado);

        // Y este camino no borra una entrega normal.
        $normal = $this->salida->entregas()->create(['dte_id' => $this->ccf()->id, 'cliente_sucursal_id' => $this->sala->id]);
        $this->delete(route('rutas.salidas.entregas.facturado-despues.destroy', [$this->salida, $normal]))->assertSessionHasErrors('entrega');
        $this->assertDatabaseHas('salida_ruta_entregas', ['id' => $normal->id]);
    }
}
