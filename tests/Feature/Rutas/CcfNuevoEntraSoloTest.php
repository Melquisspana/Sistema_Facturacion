<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\ResultadoEntrega;
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
use App\Services\Rutas\ParticipantesSalida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El CCF que se factura con la salida ya en camino entra solo (Melqui, 04/11/2026): la sala
 * hace la orden mientras los vendedores están ahí y se entrega y cobra en esa misma salida.
 * Entra al abrir «Mi salida» o la hoja de la oficina, y al «Salir ahora». Datos ficticios.
 */
class CcfNuevoEntraSoloTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private User $oficina;

    private PersonalRuta $persona;

    private Ruta $ruta;

    private ClienteSucursal $sala;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 11, 4)->setTime(11, 0));
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28', 'rutas.ambiente_ccf' => null]);
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos Ficticio', 'user_id' => $this->vendedor->id]);
        $this->ruta = Ruta::create(['nombre' => 'Ruta Ficticia Oriente']);
        $this->sala = Cliente::factory()->create(['cobro_en_entrega' => 'efectivo'])
            ->sucursales()->create(['nombre' => 'Súper Ficticio Centro', 'ruta_id' => $this->ruta->id]);
    }

    private function salida(EstadoSalidaRuta $estado = EstadoSalidaRuta::EnCurso): SalidaRuta
    {
        $salida = SalidaRuta::create(['ruta_id' => $this->ruta->id, 'fecha_inicio' => '2026-11-04', 'estado' => $estado]);
        app(ParticipantesSalida::class)->sincronizar($salida, [$this->persona->id], null);

        return $salida;
    }

    /** Un CCF aceptado de hoy para la sala de la ruta: lo que Melqui factura en el momento. */
    private function ccf(?ClienteSucursal $sala = null): Dte
    {
        $sala ??= $this->sala;
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Pruebas'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $n = 907 + Dte::count();

        return Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $sala->cliente_id, 'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'numero_orden_compra' => '26060232'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'fecha_emision' => now()->toDateString(), 'hora_emision' => '10:00:00', 'total_pagar' => 100.25,
        ]);
    }

    public function test_abrir_mi_salida_en_curso_agrega_el_ccf_nuevo_y_avisa(): void
    {
        $salida = $this->salida();
        $salida->entregas()->create(['dte_id' => $this->ccf()->id, 'cliente_sucursal_id' => $this->sala->id]);
        $nuevo = $this->ccf();

        $this->actingAs($this->vendedor)->get(route('rutas.mi-salida.show', $salida))->assertOk()
            ->assertSee('Se agregó 1 CCF nuevo de la ruta.')
            ->assertSee('0 de 2 entregados');
        $entrega = $salida->entregas()->where('dte_id', $nuevo->id)->sole();
        $this->assertNull($entrega->resultado);

        // Volver a abrir no lo duplica ni repite el aviso.
        $this->get(route('rutas.mi-salida.show', $salida))->assertOk()->assertDontSee('CCF nuevo de la ruta')->assertSee('0 de 2 entregados');
        $this->assertSame(2, $salida->entregas()->count());

        // Es una entrega normal, con dinero: se marca desde el teléfono.
        $this->patch(route('rutas.mi-salida.update', [$salida, $entrega]), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $this->assertSame(ResultadoEntrega::Entregado, $entrega->refresh()->resultado);
        $this->assertNotNull($entrega->monto_esperado);
    }

    public function test_varios_nuevos_y_el_aviso_se_suma_al_mensaje_que_ya_habia(): void
    {
        $salida = $this->salida();
        $this->ccf();
        $this->ccf();

        $this->actingAs($this->vendedor)->withSession(['status' => 'Entrega registrada: Entregado.'])
            ->get(route('rutas.mi-salida.show', $salida))->assertOk()
            ->assertSee('Entrega registrada: Entregado. Se agregaron 2 CCF nuevos de la ruta.');
        $this->assertSame(2, $salida->entregas()->count());
    }

    public function test_la_hoja_de_la_oficina_tambien_lo_agrega(): void
    {
        $salida = $this->salida();
        $nuevo = $this->ccf();

        $this->actingAs($this->oficina)->get(route('rutas.salidas.show', $salida))->assertOk()
            ->assertSee('Se agregó 1 CCF nuevo de la ruta.')
            ->assertSee($nuevo->numero_control);
        $this->get(route('rutas.salidas.show', $salida))->assertOk()->assertDontSee('CCF nuevo de la ruta');
        $this->assertSame(1, $salida->entregas()->count());
    }

    public function test_planificada_finalizada_o_cancelada_no_agrega_nada(): void
    {
        $this->ccf();
        $planificada = $this->salida(EstadoSalidaRuta::Planificada);
        $finalizada = $this->salida(EstadoSalidaRuta::Finalizada);
        $cancelada = $this->salida(EstadoSalidaRuta::Cancelada);

        $this->actingAs($this->vendedor)->get(route('rutas.mi-salida.show', $planificada))->assertOk()->assertDontSee('CCF nuevo de la ruta');
        foreach ([$planificada, $finalizada, $cancelada] as $salida) {
            $this->actingAs($this->oficina)->get(route('rutas.salidas.show', $salida))->assertOk()->assertDontSee('CCF nuevo de la ruta');
            $this->assertSame(0, $salida->entregas()->count());
        }
    }

    public function test_con_el_dia_terminado_no_agrega_y_entra_al_dia_siguiente(): void
    {
        $salida = $this->salida();
        $entrega = $salida->entregas()->create(['dte_id' => $this->ccf()->id, 'cliente_sucursal_id' => $this->sala->id]);
        $this->actingAs($this->vendedor);
        $this->patch(route('rutas.mi-salida.update', [$salida, $entrega]), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        $this->post(route('rutas.mi-salida.cerrar', $salida), ['entregas' => [$entrega->id => ['monto_cobrado' => '100.25', 'forma_cobro' => 'efectivo']]])
            ->assertSessionHasNoErrors();
        $this->assertTrue($salida->diaCerrado());
        $nuevo = $this->ccf();

        $this->get(route('rutas.mi-salida.show', $salida))->assertOk()->assertDontSee('CCF nuevo de la ruta');
        $this->actingAs($this->oficina)->get(route('rutas.salidas.show', $salida))->assertOk()->assertDontSee('CCF nuevo de la ruta');
        $this->assertSame(1, $salida->entregas()->count());

        $this->travel(1)->days();
        $this->actingAs($this->vendedor)->get(route('rutas.mi-salida.show', $salida))->assertOk()->assertSee('Se agregó 1 CCF nuevo de la ruta.');
        $this->assertTrue($salida->entregas()->where('dte_id', $nuevo->id)->exists());
    }

    public function test_un_ccf_que_ya_esta_en_otra_salida_no_se_mueve(): void
    {
        $otra = $this->salida();
        $enCamino = $this->ccf();
        $otra->entregas()->create(['dte_id' => $enCamino->id, 'cliente_sucursal_id' => $this->sala->id]);
        $entregado = $this->ccf();
        $vieja = $this->salida(EstadoSalidaRuta::Finalizada);
        $vieja->entregas()->create([
            'dte_id' => $entregado->id, 'cliente_sucursal_id' => $this->sala->id,
            'resultado' => ResultadoEntrega::Entregado, 'fecha_resultado' => '2026-11-04',
        ]);
        $salida = $this->salida();

        $this->actingAs($this->vendedor)->get(route('rutas.mi-salida.show', $salida))->assertOk()->assertDontSee('CCF nuevo de la ruta');

        $this->assertSame(0, $salida->entregas()->count());
        $this->assertSame([$otra->id], SalidaRutaEntrega::where('dte_id', $enCamino->id)->pluck('salida_ruta_id')->all());
        $this->assertSame([$vieja->id], SalidaRutaEntrega::where('dte_id', $entregado->id)->pluck('salida_ruta_id')->all());
    }

    public function test_un_ccf_de_una_sala_de_otra_ruta_no_entra(): void
    {
        $otraRuta = Ruta::create(['nombre' => 'Ruta Ficticia Occidente']);
        $ajena = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Tienda Ficticia Lejana', 'ruta_id' => $otraRuta->id]);
        $this->ccf($ajena);
        $salida = $this->salida();

        $this->actingAs($this->vendedor)->get(route('rutas.mi-salida.show', $salida))->assertOk()->assertDontSee('CCF nuevo de la ruta');
        $this->assertSame(0, $salida->entregas()->count());
    }

    public function test_salir_ahora_de_la_oficina_carga_los_nuevos(): void
    {
        $salida = $this->salida(EstadoSalidaRuta::Planificada);
        $nuevo = $this->ccf();

        $this->actingAs($this->oficina)->from(route('rutas.salidas.show', $salida))
            ->patch(route('rutas.salidas.iniciar', $salida))
            ->assertRedirect(route('rutas.salidas.show', $salida))
            ->assertSessionHas('status', 'Salida iniciada. Se agregó 1 CCF nuevo de la ruta.');

        $this->assertSame(EstadoSalidaRuta::EnCurso, $salida->refresh()->estado);
        $this->assertTrue($salida->entregas()->where('dte_id', $nuevo->id)->exists());

        // Un segundo toque no la inicia otra vez ni duplica.
        $this->patch(route('rutas.salidas.iniciar', $salida))->assertSessionHas('error');
        $this->assertSame(1, $salida->entregas()->count());
    }
}
