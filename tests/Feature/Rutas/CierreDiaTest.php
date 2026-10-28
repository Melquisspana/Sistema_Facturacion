<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Pais;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaCierre;
use App\Models\SalidaRutaEntrega;
use App\Models\User;
use App\Services\Rutas\CierreDia;
use App\Services\Rutas\ParticipantesSalida;
use App\Support\HoraNegocio;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CierreDiaTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private User $oficina;

    private PersonalRuta $persona;

    private SalidaRuta $salida;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(18, 0));
        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28']);
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos', 'user_id' => $this->vendedor->id]);
        $this->salida = SalidaRuta::create(['ruta_id' => Ruta::create(['nombre' => 'Centro'])->id, 'fecha_inicio' => '2026-10-28', 'estado' => EstadoSalidaRuta::EnCurso]);
        app(ParticipantesSalida::class)->sincronizar($this->salida, [$this->persona->id], null);
        $this->actingAs($this->vendedor);
    }

    private function entrega(?string $forma = 'efectivo', bool $registrar = true): SalidaRutaEntrega
    {
        $cliente = Cliente::factory()->create(['cobro_en_entrega' => $forma]);
        $sala = $cliente->sucursales()->create(['nombre' => 'Sala centro', 'ruta_id' => $this->salida->ruta_id]);
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Pruebas'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $dte = Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $cliente->id, 'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) (907 + Dte::count()), 15, '0', STR_PAD_LEFT),
            'fecha_emision' => '2026-10-28', 'hora_emision' => '10:00:00', 'total_pagar' => 100.25,
        ]);
        $entrega = $this->salida->entregas()->create(['dte_id' => $dte->id, 'cliente_sucursal_id' => $sala->id]);
        if ($registrar) {
            $this->patch(route('rutas.mi-salida.update', [$this->salida, $entrega]), ['resultado' => 'entregado'])->assertSessionHasNoErrors();
        }

        return $entrega->refresh();
    }

    private function datos(array $entregas): array
    {
        return ['entrega_dinero_personal_id' => $this->persona->id, 'entregas' => $entregas];
    }

    private function cerrar(array $datos = [])
    {
        return $this->postJson(route('rutas.mi-salida.cerrar', $this->salida), $datos);
    }

    public function test_foto_del_cobro_deshacer_y_todo_entregado(): void
    {
        $e = $this->entrega();
        $this->assertSame('efectivo', $e->forma_cobro->value);
        $this->assertSame('100.25', $e->monto_esperado);
        $this->assertSame('100.25', $e->monto_cobrado);
        $e->dte->cliente->update(['cobro_en_entrega' => 'cheque']);
        $this->assertSame('efectivo', $e->refresh()->forma_cobro->value);
        $this->patch(route('rutas.mi-salida.deshacer', [$this->salida, $e]))->assertSessionHasNoErrors();
        $this->assertNull($e->refresh()->forma_cobro);
        $this->assertNull($e->monto_esperado);
        $this->assertNull($e->monto_cobrado);
        $this->post(route('rutas.mi-salida.sala', [$this->salida, $e->sala]))->assertSessionHasNoErrors();
        $this->assertSame('cheque', $e->refresh()->forma_cobro->value);
        $sin = $this->entrega(null);
        $this->assertNull($sin->forma_cobro);
        $this->assertNull($sin->monto_esperado);
        $this->assertNull($sin->monto_cobrado);
    }

    public function test_la_oficina_liquida_un_dia_de_una_salida_ya_terminada_pero_no_una_cancelada(): void
    {
        $this->entrega();
        $this->salida->update(['estado' => EstadoSalidaRuta::Finalizada]);
        $this->actingAs($this->oficina);
        $entrega = $this->salida->entregas()->first();
        $datos = $this->datos([$entrega->id => ['monto_cobrado' => '100.25', 'forma_cobro' => 'efectivo']]);

        $this->postJson(route('rutas.salidas.cerrar', $this->salida), $datos)->assertRedirect();
        $this->assertSame('100.25', $this->salida->cierreDelDia()->efectivo_cobrado);

        $otra = SalidaRuta::create(['ruta_id' => $this->salida->ruta_id, 'fecha_inicio' => '2026-10-28', 'estado' => EstadoSalidaRuta::Cancelada]);
        $this->postJson(route('rutas.salidas.cerrar', $otra), [])->assertStatus(422);
    }

    public function test_cierre_totales_parcial_fecha_y_doble_cierre(): void
    {
        $efectivo = $this->entrega();
        $cheque = $this->entrega('cheque');
        $transferencia = $this->entrega('transferencia');
        $ayer = $this->entrega();
        $ayer->update(['fecha_resultado' => now()->subDay()]);
        $this->entrega(null);
        $this->cerrar($this->datos([
            $efectivo->id => ['monto_cobrado' => '40.20', 'forma_cobro' => 'efectivo'],
            $cheque->id => ['monto_cobrado' => '100.25', 'forma_cobro' => 'cheque'],
            $transferencia->id => ['monto_cobrado' => '100.25', 'forma_cobro' => 'transferencia'],
        ]))->assertRedirect();
        $c = SalidaRutaCierre::sole();
        $this->assertSame('100.25', $c->efectivo_esperado);
        $this->assertSame('100.25', $c->cheques_esperado);
        $this->assertSame('100.25', $c->transferencias_esperado);
        $this->assertSame('100.25', $c->cheques_cobrado);
        $this->assertSame('100.25', $c->transferencias_cobrado);
        $this->assertSame('40.20', $c->a_entregar);
        $this->assertSame('0.00', $c->gastos_total);
        $this->assertSame('40.20', $efectivo->refresh()->monto_cobrado);
        $this->get(route('rutas.mi-salida.show', $this->salida))->assertOk()->assertSee('Día cerrado')->assertSee('Saldo pendiente $60.05')->assertDontSee('Todo entregado');
        $this->cerrar()->assertForbidden();
        $this->actingAs($this->oficina)->postJson(route('rutas.salidas.cerrar', $this->salida))->assertUnprocessable()->assertSee('cerrado');
        $this->assertSame(1, SalidaRutaCierre::count());
    }

    public function test_valida_montos_forma_persona_y_campos_faltantes(): void
    {
        $e = $this->entrega();
        foreach (['-0.01', '100.26', '1.001', 'abc'] as $monto) {
            $this->cerrar($this->datos([$e->id => ['monto_cobrado' => $monto, 'forma_cobro' => 'efectivo']]))->assertUnprocessable();
        }
        $this->cerrar(['entregas' => [$e->id => ['monto_cobrado' => '1.00', 'forma_cobro' => 'efectivo']]])->assertUnprocessable();
        $this->cerrar($this->datos([$e->id => ['monto_cobrado' => '1.00', 'forma_cobro' => 'otro']]))->assertUnprocessable();
        $this->cerrar($this->datos([]))->assertUnprocessable();
        $this->assertSame(0, SalidaRutaCierre::count());
    }

    public function test_cliente_guarda_valida_y_respeta_autorizacion(): void
    {
        $this->seed(CatalogosMhSeeder::class);
        $admin = User::factory()->create(['activo' => true])->assignRole('administrador');
        $datos = ['tipo_cliente' => 'consumidor_final', 'nombre' => 'Cliente con cobro', 'pais_id' => Pais::where('codigo', 'SV')->value('id'), 'activo' => '1', 'cobro_en_entrega' => 'efectivo'];
        $this->postJson(route('clientes.store'), $datos)->assertForbidden();
        $this->actingAs($admin)->post(route('clientes.store'), $datos)->assertSessionHasNoErrors()->assertRedirect();
        $cliente = Cliente::where('nombre', 'Cliente con cobro')->sole();
        $this->assertSame('efectivo', $cliente->cobro_en_entrega->value);
        $this->get(route('clientes.edit', $cliente))->assertOk()->assertSee('Cobra en la entrega');
        $this->putJson(route('clientes.update', $cliente), array_replace($datos, ['cobro_en_entrega' => 'tarjeta']))->assertUnprocessable()->assertJsonValidationErrors('cobro_en_entrega');
        $this->put(route('clientes.update', $cliente), array_replace($datos, ['cobro_en_entrega' => null]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNull($cliente->refresh()->cobro_en_entrega);
    }

    public function test_pantalla_montos_sin_cobro_y_cambio_de_forma(): void
    {
        $e = $this->entrega();
        $this->entrega(null);
        $this->entrega(null, false);
        $this->get(route('rutas.mi-salida.cierre', $this->salida))->assertOk()->assertSee('Sin cobro (quedan)')->assertSee('1 sin marcar siguen en la salida')->assertSee('inputmode="decimal"', false);
        $datos = $this->datos([$e->id => ['monto_cobrado' => '75.25', 'forma_cobro' => 'transferencia']]);
        $this->cerrar(array_replace($datos, ['entrega_dinero_personal_id' => PersonalRuta::create(['nombre' => 'Ajeno'])->id]))->assertUnprocessable();
        $this->persona->update(['activo' => false]);
        $this->actingAs($this->oficina)->postJson(route('rutas.salidas.cerrar', $this->salida), $datos)->assertUnprocessable();
        $this->persona->update(['activo' => true]);
        $this->post(route('rutas.salidas.cerrar', $this->salida), $datos)->assertRedirect();
        $this->assertSame('100.25', SalidaRutaCierre::sole()->transferencias_esperado);
        $this->assertSame('75.25', SalidaRutaCierre::sole()->transferencias_cobrado);
        $this->assertSame('0.00', SalidaRutaCierre::sole()->a_entregar);
        $this->get(route('rutas.salidas.cierre', $this->salida))->assertOk()->assertSee('Motivo de reapertura');
    }

    public function test_cierre_sin_cobros_no_exige_persona_y_no_permite_inyectar_entregas(): void
    {
        $e = $this->entrega(null);
        $this->cerrar(['entregas' => [$e->id => ['monto_cobrado' => '10', 'forma_cobro' => 'efectivo']]])->assertUnprocessable();
        $this->cerrar()->assertRedirect();
        $this->assertNull(SalidaRutaCierre::sole()->entrega_dinero_personal_id);
        $this->assertSame('0.00', SalidaRutaCierre::sole()->a_entregar);
    }

    public function test_oficina_registra_cobro_y_recierra_fecha_anterior_sin_entregas(): void
    {
        $e = $this->entrega('cheque', false);
        $this->actingAs($this->oficina)->patch(route('rutas.salidas.entregas.update', [$this->salida, $e]), ['resultado' => 'entregado', 'entregado_por_id' => $this->persona->id])->assertRedirect();
        $this->assertSame('cheque', $e->refresh()->forma_cobro->value);
        $this->assertSame('100.25', $e->monto_esperado);
        $fecha = HoraNegocio::fechaHoy();
        $this->post(route('rutas.salidas.cerrar', $this->salida), $this->datos([$e->id => ['monto_cobrado' => '100.25', 'forma_cobro' => 'cheque']]))->assertRedirect();
        $id = SalidaRutaCierre::sole()->id;
        $this->travel(1)->days();
        $this->post(route('rutas.salidas.reabrir', [$this->salida, $fecha]), ['motivo' => 'Entrega anotada por error'])->assertRedirect();
        $this->patch(route('rutas.salidas.entregas.deshacer', [$this->salida, $e]))->assertRedirect();
        $this->get(route('rutas.salidas.cierre', ['salida' => $this->salida, 'fecha' => $fecha]))->assertOk()->assertSee('Cerrar el día');
        $this->post(route('rutas.salidas.cerrar', $this->salida), ['fecha' => $fecha])->assertRedirect();
        $cierre = SalidaRutaCierre::sole();
        $this->assertSame($id, $cierre->id);
        $this->assertSame('0.00', $cierre->cheques_cobrado);
        $this->assertTrue($cierre->estaCerrado());
    }

    public function test_policy_reapertura_y_recierre_misma_fila(): void
    {
        $e = $this->entrega();
        $datos = $this->datos([$e->id => ['monto_cobrado' => '100.25', 'forma_cobro' => 'efectivo']]);
        $this->cerrar($datos)->assertRedirect();
        $c = SalidaRutaCierre::sole();
        $pendiente = $this->entrega(null, false);
        $this->patch(route('rutas.mi-salida.update', [$this->salida, $pendiente]), ['resultado' => 'entregado'])->assertForbidden();
        $this->patch(route('rutas.mi-salida.deshacer', [$this->salida, $e]))->assertForbidden();
        $url = route('rutas.salidas.reabrir', [$this->salida, HoraNegocio::fechaHoy()]);
        $this->post($url, ['motivo' => 'Corregir cobro'])->assertForbidden();
        $this->actingAs($this->oficina)->patch(route('rutas.salidas.entregas.deshacer', [$this->salida, $e]))->assertForbidden();
        $this->patch(route('rutas.salidas.entregas.update', [$this->salida, $pendiente]), ['resultado' => 'entregado', 'entregado_por_id' => $this->persona->id])->assertForbidden();
        $this->postJson($url)->assertUnprocessable();
        $this->post($url, ['motivo' => 'Corregir cobro'])->assertRedirect();
        $this->assertFalse($c->refresh()->estaCerrado());
        $this->assertDatabaseHas('activity_log', ['log_name' => 'salida_ruta_cierre', 'description' => 'reabrió el día']);
        $this->actingAs($this->vendedor)->patch(route('rutas.mi-salida.deshacer', [$this->salida, $e]))->assertRedirect();
        $this->patch(route('rutas.mi-salida.update', [$this->salida, $e]), ['resultado' => 'entregado'])->assertRedirect();
        $this->travelTo(now()->addMicroseconds(123456));
        $this->cerrar($datos)->assertRedirect();
        $this->assertSame($c->id, SalidaRutaCierre::sole()->id);
        $this->assertTrue($c->refresh()->estaCerrado());
        $this->travel(1)->days();
        $this->patch(route('rutas.mi-salida.update', [$this->salida, $pendiente]), ['resultado' => 'entregado'])->assertRedirect();
        $this->patch(route('rutas.mi-salida.deshacer', [$this->salida, $e]))->assertForbidden();
    }

    public function test_pdf_autorizacion_y_saldo_en_visita_posterior(): void
    {
        $e = $this->entrega();
        $url = route('rutas.mi-salida.liquidacion', [$this->salida, HoraNegocio::fechaHoy()]);
        $this->get($url)->assertNotFound();
        $this->cerrar($this->datos([$e->id => ['monto_cobrado' => '0.00', 'forma_cobro' => 'cheque']]))->assertRedirect();
        $pdf = $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame(1, preg_match_all('~/Type\\s*/Page\\b~', $pdf->getContent()));
        $this->assertStringContainsString('liquidacion-centro-'.HoraNegocio::fechaHoy().'.pdf', $pdf->headers->get('content-disposition'));
        $html = view('rutas.cierres.pdf', [
            'salida' => $this->salida, 'fecha' => HoraNegocio::fechaHoy(), 'cierre' => SalidaRutaCierre::sole(),
            'entregas' => app(CierreDia::class)->entregas($this->salida, HoraNegocio::fechaHoy()),
            'empresa' => Empresa::first(),
        ])->render();
        foreach ([$e->dte->numero_control, 'Pruebas', 'Carlos', 'Efectivo a entregar', 'Cheques', 'Transferencias', 'Gastos', 'Recibe'] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }
        $this->actingAs($this->oficina)->get(route('rutas.salidas.liquidacion', [$this->salida, HoraNegocio::fechaHoy()]))->assertOk()->assertHeader('content-type', 'application/pdf');
        $otro = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->actingAs($otro)->get($url)->assertForbidden();
        $this->get(route('rutas.mi-salida.cierre', $this->salida))->assertForbidden();
        $this->postJson(route('rutas.mi-salida.cerrar', $this->salida))->assertForbidden();
        $this->actingAs($this->vendedor);
        $posterior = SalidaRuta::create(['ruta_id' => $this->salida->ruta_id, 'fecha_inicio' => '2026-10-29', 'estado' => EstadoSalidaRuta::EnCurso]);
        app(ParticipantesSalida::class)->sincronizar($posterior, [$this->persona->id], null);
        $posterior->entregas()->create(['dte_id' => $e->dte_id, 'cliente_sucursal_id' => $e->cliente_sucursal_id]);
        $this->get(route('rutas.mi-salida.show', $posterior))->assertOk()->assertSee('Saldo de visitas anteriores: $100.25');
        $posterior->update(['fecha_inicio' => '2026-10-28']);
        $this->get(route('rutas.mi-salida.show', $posterior))->assertOk()->assertSee('Saldo de visitas anteriores: $100.25');
        $this->get(route('rutas.mi-salida.liquidacion', [$this->salida, '2026-02-30']))->assertNotFound();
    }

    public function test_tres_migraciones_reversibles(): void
    {
        $archivos = glob(database_path('migrations/2026_10_28_18000*_*.php'));
        $this->assertCount(3, $archivos);
        foreach (array_reverse($archivos) as $archivo) {
            (require $archivo)->down();
        }
        $this->assertFalse(Schema::hasColumn('clientes', 'cobro_en_entrega'));
        $this->assertFalse(Schema::hasColumn('salida_ruta_entregas', 'monto_cobrado'));
        $this->assertFalse(Schema::hasTable('salida_ruta_cierres'));
        foreach ($archivos as $archivo) {
            (require $archivo)->up();
        }
        $this->assertTrue(Schema::hasTable('salida_ruta_cierres'));
    }
}
