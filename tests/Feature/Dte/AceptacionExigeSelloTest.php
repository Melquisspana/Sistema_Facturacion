<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Models\Dte;
use App\Services\Cobros\AltaCobrosService;
use App\Services\Dte\DteTransmisionResiliente;
use App\Services\Dte\DteTransmisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\DocumentosParaEmitir;
use Tests\TestCase;

/**
 * SIN SELLO DE RECEPCIÓN NO HAY ACEPTACIÓN.
 *
 * Un envío al que Hacienda contesta «procesado» pero sin sello dejaba el documento
 * Aceptado y sin sello: no entraba en ningún circuito que pide aceptación real, y si
 * después una consulta traía el sello, se descartaba porque «ya estaba aceptado».
 *
 * Ahora esa respuesta no acepta: el documento queda firmado, anotado, y lo resuelve el
 * reintento de siempre, que consulta antes de reenviar. Y a los que ya estén aceptados sin
 * sello, una consulta comprobada les completa el sello sin repetir el paso a Aceptado.
 */
class AceptacionExigeSelloTest extends TestCase
{
    use DocumentosParaEmitir;
    use RefreshDatabase;

    private const COMPLETADO = 'Una consulta a Hacienda completó el sello de recepción de un documento que ya figuraba aceptado.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEmisor();
        $this->habilitar();
    }

    private function haciendaContesta(array $cuerpo, int $status = 200): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response($cuerpo, $status)]);
    }

    // ─────────── El envío directo ───────────

    public function test_un_envio_que_vuelve_procesado_sin_sello_no_acepta_el_documento(): void
    {
        $dte = $this->firmado();
        $this->haciendaContesta(['estado' => 'PROCESADO', 'descripcionMsg' => 'RECIBIDO']);

        $r = app(DteTransmisionService::class)->transmitir($dte);

        $this->assertNotSame('aceptado', $r['resultado']);
        $guardado = $dte->fresh();
        $this->assertSame(EstadoDte::Firmado, $guardado->estado);
        $this->assertNull($guardado->sello_recepcion);
        $this->assertSame(0, $guardado->historial()->where('estado_nuevo', 'aceptado')->count());
        $anotacion = Activity::where('log_name', 'dte_transmision')->where('subject_id', $dte->id)->sole();
        $this->assertStringContainsString('sello', $anotacion->properties['falta']);
    }

    public function test_por_la_pantalla_queda_firmado_y_dice_que_falto_el_sello(): void
    {
        $dte = $this->generado();
        $this->haciendaContesta(['estado' => 'PROCESADO', 'descripcionMsg' => 'RECIBIDO']);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.firmar-transmitir', $dte))
            ->assertRedirect(route('facturacion.show', $dte))
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'sello'));

        $this->assertSame(EstadoDte::Firmado, $dte->fresh()->estado);
        $this->assertNull($dte->fresh()->sello_recepcion);
    }

    public function test_el_reintento_de_siempre_lo_resuelve_cuando_la_consulta_trae_el_sello(): void
    {
        $dte = $this->firmado();
        $this->haciendaContesta(['estado' => 'PROCESADO', 'descripcionMsg' => 'RECIBIDO']);
        app(DteTransmisionService::class)->transmitir($dte);

        // Segundo intento: el documento sigue firmado, así que primero se consulta.
        $this->haciendaContesta(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-DE-PRUEBA-CONSULTA', 'codigoGeneracion' => $dte->codigo_generacion]);
        $r = app(DteTransmisionResiliente::class)->transmitir($dte->fresh(), true);

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertSame(EstadoDte::Aceptado, $dte->fresh()->estado);
        $this->assertSame('SELLO-DE-PRUEBA-CONSULTA', $dte->fresh()->sello_recepcion);
        Http::assertNotSent(fn ($pedido) => str_contains($pedido->url(), 'recepciondte'));
    }

    public function test_el_modo_de_prueba_sigue_aceptando_con_su_sello_simulado(): void
    {
        config()->set('dte.transmision.mock', true);
        $dte = $this->firmado();

        $r = app(DteTransmisionService::class)->transmitir($dte);

        $this->assertSame('aceptado', $r['resultado']);
        $guardado = $dte->fresh();
        $this->assertSame(EstadoDte::Aceptado, $guardado->estado);
        $this->assertStringStartsWith('MOCK', (string) $guardado->sello_recepcion);
        // Simulado: sigue sin contar como aceptado de verdad.
        $this->assertFalse($guardado->aceptadoRealmentePorMh());
    }

    // ─────────── Los que ya quedaron aceptados sin sello ───────────

    /** Un documento de antes: aceptado, pero sin sello de recepción. */
    private function aceptadoSinSello(): Dte
    {
        $dte = $this->firmado();
        Dte::whereKey($dte->id)->update(['estado' => EstadoDte::Aceptado->value, 'sello_recepcion' => null]);

        return $dte->refresh();
    }

    /** @return array<string, mixed> */
    private function consultaConSello(Dte $dte, array $cambios = []): array
    {
        return array_replace([
            'resultado' => 'aceptado', 'http_status' => 200, 'mensaje' => 'RECIBIDO', 'sello' => '2026'.str_repeat('B', 36),
            'cuerpo' => ['estado' => 'PROCESADO', 'selloRecibido' => '2026'.str_repeat('B', 36),
                'codigoGeneracion' => $dte->codigo_generacion, 'fhProcesamiento' => now()->format('d/m/Y H:i:s')],
        ], $cambios);
    }

    public function test_una_consulta_comprobada_le_completa_el_sello_sin_repetir_el_paso_a_aceptado(): void
    {
        $dte = $this->aceptadoSinSello();
        $transiciones = $dte->historial()->count();
        // Lo que cuelga de «aceptado» ocurre ahora, que ya es una aceptación real: una vez.
        $this->mock(AltaCobrosService::class, fn ($alta) => $alta->shouldReceive('darDeAlta')->once());

        $r = app(DteTransmisionService::class)->aplicarResultadoDeConsulta($dte, $this->consultaConSello($dte));

        $this->assertSame('aceptado', $r['resultado']);
        $guardado = $dte->fresh();
        $this->assertSame(EstadoDte::Aceptado, $guardado->estado);
        $this->assertSame('2026'.str_repeat('B', 36), $guardado->sello_recepcion);
        $this->assertTrue($guardado->aceptadoRealmentePorMh());
        $this->assertSame($transiciones, $guardado->historial()->count(), 'No hay un segundo paso a Aceptado.');
        $this->assertDatabaseHas('activity_log', ['log_name' => 'dte_transmision', 'subject_id' => $dte->id, 'description' => self::COMPLETADO]);

        // Otra consulta igual ya no cambia nada ni vuelve a disparar el alta.
        app(DteTransmisionService::class)->aplicarResultadoDeConsulta(Dte::findOrFail($dte->id), $this->consultaConSello($dte));
        $this->assertSame(1, Activity::where('description', self::COMPLETADO)->count());
    }

    public function test_el_sello_de_otro_documento_o_de_otro_ambiente_no_completa_nada(): void
    {
        $dte = $this->aceptadoSinSello();
        $servicio = app(DteTransmisionService::class);

        $ajeno = $this->consultaConSello($dte);
        $ajeno['cuerpo']['codigoGeneracion'] = strtoupper((string) Str::uuid());
        $servicio->aplicarResultadoDeConsulta(Dte::findOrFail($dte->id), $ajeno);
        $servicio->aplicarResultadoDeConsulta(Dte::findOrFail($dte->id), $this->consultaConSello($dte, ['ambiente' => '01']));
        $servicio->aplicarResultadoDeConsulta(Dte::findOrFail($dte->id), $this->consultaConSello($dte, ['sello' => null]));

        $this->assertNull($dte->fresh()->sello_recepcion);
        $this->assertSame(0, Activity::where('description', self::COMPLETADO)->count());
    }

    public function test_un_aceptado_que_ya_tiene_sello_no_lo_cambia_por_otro(): void
    {
        $dte = $this->aceptadoSinSello();
        Dte::whereKey($dte->id)->update(['sello_recepcion' => 'SELLO-QUE-YA-TENIA']);

        app(DteTransmisionService::class)->aplicarResultadoDeConsulta(Dte::findOrFail($dte->id), $this->consultaConSello($dte));

        $this->assertSame('SELLO-QUE-YA-TENIA', $dte->fresh()->sello_recepcion);
    }
}
