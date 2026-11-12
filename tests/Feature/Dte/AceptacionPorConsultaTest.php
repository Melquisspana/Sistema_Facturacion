<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Models\Dte;
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
 * UNA ACEPTACIÓN QUE VIENE DE UNA CONSULTA SE GUARDA SOLO SI SE PUEDE COMPROBAR.
 *
 * Cuando Hacienda contesta a un ENVÍO, la respuesta es de ese documento. Una CONSULTA es
 * otra cosa: se pregunta por un código y se confía en lo que vuelve. Para pasar un
 * documento a Aceptado por una consulta —desde el comando, un lote, la consulta previa
 * de un reintento o el aviso de contingencia— hace falta: sello de recepción, el mismo
 * código de generación (el que se consultó y, si la respuesta lo trae, el suyo) y el
 * mismo ambiente. Sin eso el documento queda como estaba y la bitácora dice qué faltó.
 */
class AceptacionPorConsultaTest extends TestCase
{
    use DocumentosParaEmitir;
    use RefreshDatabase;

    private const ANOTACION = 'No se guardó una aceptación informada por una consulta a Hacienda.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEmisor();
        $this->habilitar();
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function consultaDice(Dte $dte, array $cambios = []): array
    {
        return array_replace([
            'resultado' => 'aceptado', 'http_status' => 200, 'mensaje' => 'RECIBIDO', 'sello' => 'SELLO-DE-PRUEBA-CONSULTA',
            'cuerpo' => ['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-DE-PRUEBA-CONSULTA', 'codigoGeneracion' => $dte->codigo_generacion],
        ], $cambios);
    }

    private function assertSigueFirmadoPorque(Dte $dte, array $respuesta, string $falta): void
    {
        $this->assertNotSame('aceptado', $respuesta['resultado']);
        $guardado = $dte->fresh();
        $this->assertSame(EstadoDte::Firmado, $guardado->estado);
        $this->assertNull($guardado->sello_recepcion);
        $anotacion = Activity::where('log_name', 'dte_transmision')->where('subject_id', $dte->id)->where('description', self::ANOTACION)->sole();
        $this->assertStringContainsString($falta, $anotacion->properties['falta']);
    }

    public function test_el_caso_bueno_con_sello_mismo_codigo_y_mismo_ambiente_queda_aceptado(): void
    {
        $dte = $this->firmado();

        $r = app(DteTransmisionService::class)->aplicarResultadoDeConsulta($dte, $this->consultaDice($dte));

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertSame(EstadoDte::Aceptado, $dte->fresh()->estado);
        $this->assertSame('SELLO-DE-PRUEBA-CONSULTA', $dte->fresh()->sello_recepcion);
        $this->assertSame(0, Activity::where('description', self::ANOTACION)->count());
    }

    public function test_un_firmado_que_la_consulta_informa_procesado_sin_sello_no_se_acepta(): void
    {
        $dte = $this->firmado();

        $r = app(DteTransmisionService::class)->aplicarResultadoDeConsulta($dte, $this->consultaDice($dte, [
            'sello' => null, 'cuerpo' => ['estado' => 'PROCESADO', 'codigoGeneracion' => $dte->codigo_generacion],
        ]));

        $this->assertSigueFirmadoPorque($dte, $r, 'sello');
    }

    public function test_un_firmado_no_se_acepta_con_la_respuesta_de_otro_codigo_de_generacion(): void
    {
        $dte = $this->firmado();

        $r = app(DteTransmisionService::class)->aplicarResultadoDeConsulta($dte, $this->consultaDice($dte, [
            'cuerpo' => ['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-DE-PRUEBA-CONSULTA', 'codigoGeneracion' => strtoupper((string) Str::uuid())],
        ]));

        $this->assertSigueFirmadoPorque($dte, $r, 'código de generación');
    }

    public function test_un_firmado_no_se_acepta_si_se_consulto_por_un_codigo_que_ya_no_es_el_suyo(): void
    {
        $dte = $this->firmado();
        // Quien consultó tenía en la mano otro código que el que hoy tiene el documento.
        $copia = Dte::findOrFail($dte->id);
        $copia->codigo_generacion = strtoupper((string) Str::uuid());

        $r = app(DteTransmisionService::class)->aplicarResultadoDeConsulta($copia, $this->consultaDice($dte, [
            'cuerpo' => ['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-DE-PRUEBA-CONSULTA'],
        ]));

        $this->assertSigueFirmadoPorque($dte, $r, 'código de generación');
    }

    public function test_un_firmado_no_se_acepta_con_una_consulta_hecha_en_otro_ambiente(): void
    {
        $dte = $this->firmado();

        // La respuesta dice de qué ambiente es…
        $r = app(DteTransmisionService::class)->aplicarResultadoDeConsulta($dte, $this->consultaDice($dte, ['ambiente' => '01']));
        $this->assertSigueFirmadoPorque($dte, $r, 'ambiente');

        // …y si no lo dice, vale el que está configurado para consultar.
        Activity::query()->delete();
        config()->set('dte.transmision.ambiente', 'produccion');
        $r = app(DteTransmisionService::class)->aplicarResultadoDeConsulta($dte, $this->consultaDice($dte));
        $this->assertSigueFirmadoPorque($dte, $r, 'ambiente');
    }

    public function test_la_consulta_previa_de_un_reintento_sin_sello_no_acepta_ni_reenvia(): void
    {
        $dte = $this->firmado();
        Http::swap(new Factory);
        Http::fake(['*consultadte*' => Http::response(['estado' => 'PROCESADO', 'codigoGeneracion' => $dte->codigo_generacion], 200)]);

        $r = app(DteTransmisionResiliente::class)->transmitir($dte, true);

        $this->assertSigueFirmadoPorque($dte, $r, 'sello');
        // Hacienda dice que lo tiene: reenviarlo podría duplicarlo.
        Http::assertNotSent(fn ($pedido) => str_contains($pedido->url(), 'recepciondte'));
    }

    public function test_el_comando_no_guarda_una_aceptacion_sin_sello(): void
    {
        $dte = $this->firmado();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['estado' => 'PROCESADO', 'codigoGeneracion' => $dte->codigo_generacion], 200)]);

        $this->artisan('dte:consultar', ['dte' => $dte->id, '--consultar' => true, '--aplicar' => true])
            ->expectsOutputToContain('no se guardó')
            ->assertExitCode(1);

        $this->assertSame(EstadoDte::Firmado, $dte->fresh()->estado);
    }

    public function test_una_transmision_directa_con_su_sello_sigue_aceptando_como_siempre(): void
    {
        $dte = $this->firmado();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-DE-PRUEBA-ENVIO', 'descripcionMsg' => 'RECIBIDO'], 200)]);

        $r = app(DteTransmisionService::class)->transmitir($dte);

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertSame(EstadoDte::Aceptado, $dte->fresh()->estado);
    }

    // ─────────── Un RECHAZO por consulta también tiene que ser de este documento ───────────
    //
    // El sello solo hace falta para aceptar. La IDENTIDAD —el código por el que se preguntó,
    // el que trae la respuesta y el ambiente— hace falta para los dos: un rechazo de otro
    // documento dejaba este como Rechazado, y de ahí a «corregirlo y duplicarlo» hay un clic.

    private const RECHAZO_NO_GUARDADO = 'No se guardó un rechazo informado por una consulta a Hacienda.';

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function consultaRechaza(Dte $dte, array $cambios = []): array
    {
        return array_replace([
            'resultado' => 'rechazado', 'http_status' => 200, 'mensaje' => 'Rechazo de prueba', 'sello' => null,
            'cuerpo' => ['estado' => 'RECHAZADO', 'descripcionMsg' => 'Rechazo de prueba', 'codigoGeneracion' => $dte->codigo_generacion],
        ], $cambios);
    }

    private function assertNoSeRechazoPorque(Dte $dte, array $respuesta, string $falta): void
    {
        $this->assertNotSame('rechazado', $respuesta['resultado']);
        $guardado = $dte->fresh();
        $this->assertSame(EstadoDte::Firmado, $guardado->estado);
        $this->assertSame(0, $guardado->historial()->where('estado_nuevo', 'rechazado')->count());
        $anotacion = Activity::where('log_name', 'dte_transmision')->where('subject_id', $dte->id)->where('description', self::RECHAZO_NO_GUARDADO)->sole();
        $this->assertStringContainsString($falta, $anotacion->properties['falta']);
    }

    public function test_un_rechazo_por_consulta_del_mismo_documento_y_ambiente_se_guarda_como_siempre(): void
    {
        $dte = $this->firmado();

        $r = app(DteTransmisionService::class)->aplicarResultadoDeConsulta($dte, $this->consultaRechaza($dte));

        $this->assertSame('rechazado', $r['resultado']);
        $this->assertSame(EstadoDte::Rechazado, $dte->fresh()->estado);
    }

    public function test_un_firmado_no_se_rechaza_con_la_respuesta_de_otro_codigo_de_generacion(): void
    {
        $dte = $this->firmado();

        $r = app(DteTransmisionService::class)->aplicarResultadoDeConsulta($dte, $this->consultaRechaza($dte, [
            'cuerpo' => ['estado' => 'RECHAZADO', 'descripcionMsg' => 'Rechazo de prueba', 'codigoGeneracion' => strtoupper((string) Str::uuid())],
        ]));

        $this->assertNoSeRechazoPorque($dte, $r, 'código de generación');
    }

    public function test_un_firmado_no_se_rechaza_si_se_consulto_por_un_codigo_que_ya_no_es_el_suyo(): void
    {
        $dte = $this->firmado();
        $copia = Dte::findOrFail($dte->id);
        $copia->codigo_generacion = strtoupper((string) Str::uuid());

        $r = app(DteTransmisionService::class)->aplicarResultadoDeConsulta($copia, $this->consultaRechaza($dte, [
            'cuerpo' => ['estado' => 'RECHAZADO', 'descripcionMsg' => 'Rechazo de prueba'],
        ]));

        $this->assertNoSeRechazoPorque($dte, $r, 'código de generación');
    }

    public function test_un_firmado_no_se_rechaza_con_una_consulta_hecha_en_otro_ambiente(): void
    {
        $dte = $this->firmado();

        $r = app(DteTransmisionService::class)->aplicarResultadoDeConsulta($dte, $this->consultaRechaza($dte, ['ambiente' => '01']));
        $this->assertNoSeRechazoPorque($dte, $r, 'ambiente');

        Activity::query()->delete();
        config()->set('dte.transmision.ambiente', 'produccion');
        $r = app(DteTransmisionService::class)->aplicarResultadoDeConsulta($dte, $this->consultaRechaza($dte));
        $this->assertNoSeRechazoPorque($dte, $r, 'ambiente');
    }

    public function test_el_comando_no_guarda_un_rechazo_de_otro_documento(): void
    {
        $dte = $this->firmado();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['estado' => 'RECHAZADO', 'descripcionMsg' => 'Rechazo de prueba',
            'codigoGeneracion' => strtoupper((string) Str::uuid())], 200)]);

        $this->artisan('dte:consultar', ['dte' => $dte->id, '--consultar' => true, '--aplicar' => true])
            ->expectsOutputToContain('no se guardó')
            ->assertExitCode(1);

        $this->assertSame(EstadoDte::Firmado, $dte->fresh()->estado);
    }

    public function test_la_respuesta_a_un_envio_que_nombra_otro_documento_tampoco_cambia_este(): void
    {
        $dte = $this->firmado();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['estado' => 'RECHAZADO', 'descripcionMsg' => 'Rechazo de prueba',
            'codigoGeneracion' => strtoupper((string) Str::uuid())], 400)]);

        $r = app(DteTransmisionService::class)->transmitir($dte);

        $this->assertNotSame('rechazado', $r['resultado']);
        $this->assertSame(EstadoDte::Firmado, $dte->fresh()->estado);
    }
}
