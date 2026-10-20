<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Exceptions\Dte\DocumentoInmutableException;
use App\Facades\Ajustes;
use App\Jobs\EnviarDteCorreo;
use App\Models\Cliente;
use App\Models\Contingencia;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Producto;
use App\Models\User;
use App\Services\Dte\ContingenciaAutomaticaService;
use App\Services\Dte\ContingenciaEventoService;
use App\Services\Dte\ContingenciaService;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteFirmaService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

class ContingenciaAutomaticaTest extends TestCase
{
    use PreparaEmisorDte, RefreshDatabase;

    private User $usuario;

    private array $emisor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-13 10:00:00', 'America/El_Salvador'));
        config(['dte.contingencia.enabled' => true, 'dte.storage.disk' => 'local',
            'dte.firma.enabled' => true, 'dte.firma.mock' => false,
            'dte.firma.nit' => '06141234561018', 'dte.firma.cert_password' => 'clave-inventada',
            'dte.transmision.enabled' => true, 'dte.transmision.real_confirmation' => true,
            'dte.transmision.dry_run' => false, 'dte.transmision.sistema_actual_activo' => false,
            'dte.transmision.modo_operacion' => 'principal', 'dte.transmision.ambiente' => 'testing',
            'dte.transmision.token' => 'Bearer INVENTADO', 'dte.transmision.usuario_testing' => 'usuario-inventado',
            'dte.transmision.password_testing' => 'clave-inventada',
            'dte.invalidacion.responsable' => ['nombre' => 'Responsable Inventado', 'tipo_doc' => '13', 'num_doc' => '12345678-9']]);
        Storage::fake('local');
        Queue::fake();
        $this->seedCatalogosDte();
        $this->emisor = $this->crearEmisorDte();
        $this->usuario = User::factory()->create()->assignRole('facturacion');
        $this->actingAs($this->usuario);
        $this->simular();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function simular(bool $internet = true, bool $consultaIncierta = false, bool $mh = false): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*generate_204' => $internet ? Http::response('', 204) : Http::failedConnection(),
            '*firmardocumento*' => function ($request) {
                $b64 = fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');

                return Http::response(['status' => 'OK', 'body' => $b64(['alg' => 'RS512']).'.'.$b64($request['dteJson']).'.firma-inventada']);
            },
            '*recepciondte*' => Http::failedConnection(),
            '*consultadte*' => $consultaIncierta ? Http::failedConnection() : Http::response([], 404),
            '*seguridad/auth' => $mh ? Http::response(['status' => 'OK', 'body' => ['token' => 'TOKEN-INVENTADO-NUEVO']]) : Http::failedConnection(),
            '*fesv/contingencia' => Http::response(['estado' => 'RECIBIDO', 'selloRecibido' => 'SELLO-INVENTADO', 'fechaHora' => '13/10/2026 22:00:00']),
        ]);
    }

    private function documento(bool $firmar = true): Dte
    {
        Correlativo::firstOrCreate(['tipo_dte' => '01', 'establecimiento_id' => $this->emisor['estab']->id,
            'punto_venta_id' => $this->emisor['pv']->id, 'ambiente' => '00'], ['ultimo_numero' => 0, 'activo' => true]);
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador(['tipo_dte' => TipoDte::Factura,
            'cliente_id' => Cliente::factory()->contribuyente()->create(['correo' => 'cliente@example.test'])->id,
            'establecimiento_id' => $this->emisor['estab']->id, 'punto_venta_id' => $this->emisor['pv']->id]);
        $borradores->agregarLineaDesdeProducto($dte, Producto::factory()->create(['precio_unitario' => 10]), cantidad: 1);
        app(DteGeneracionService::class)->generar($dte->refresh());
        if ($firmar) {
            app(DteFirmaService::class)->firmar($dte);
        }

        return $dte->refresh();
    }

    public function test_entrada_inmediata_tipo_1_sin_permiso_y_misma_numeracion(): void
    {
        $dte = $this->documento(false);
        $codigo = $dte->codigo_generacion;
        $numero = $dte->numero_control;
        $original = Storage::get($dte->json_generado_path);
        $this->assertFalse($this->usuario->can('dte.contingencia'));
        $this->post(route('facturacion.firmar-transmitir', $dte))->assertRedirect()->assertSessionHas('status');
        $dte->refresh();
        $this->assertTrue($dte->esTransitorio());
        $this->assertSame($codigo, $dte->codigo_generacion);
        $this->assertSame($numero, $dte->numero_control);
        $this->assertSame(1, $dte->contingencia->tipo);
        $this->assertSame('automatica', $dte->contingencia->origen);
        $this->assertSame($this->usuario->id, $dte->contingencia->activada_por);
        $json = json_decode(Storage::get($dte->json_generado_path), true);
        $previo = json_decode($original, true);
        $previo['identificacion'] = array_replace($previo['identificacion'], ['tipoModelo' => 2, 'tipoOperacion' => 2,
            'tipoContingencia' => 1, 'motivoContin' => $dte->contingencia->motivo]);
        $this->assertSame($previo, $json);
        $payload = explode('.', Storage::get($dte->json_firmado_path))[1];
        $this->assertSame($json, json_decode(base64_decode(strtr($payload, '-_', '+/')), true));
        $this->assertCount(3, Http::recorded(fn ($r) => str_contains($r->url(), 'recepciondte')));
        $this->assertCount(3, Http::recorded(fn ($r) => str_contains($r->url(), 'consultadte')));
        $this->assertCount(2, Http::recorded(fn ($r) => str_contains($r->url(), 'firmardocumento')));
        $this->expectException(DocumentoInmutableException::class);
        $dte->update(['tipo_contingencia' => 3]);
    }

    public function test_sin_internet_tipo_3_desde_comando(): void
    {
        $dte = $this->documento();
        $this->simular(internet: false);
        $this->artisan('dte:transmitir', ['dte' => $dte->id])->assertSuccessful();
        $this->assertTrue($dte->refresh()->esTransitorio());
        $this->assertSame(3, $dte->contingencia->tipo);
    }

    public function test_consulta_incierta_no_activa_ni_refirma(): void
    {
        $dte = $this->documento();
        $ruta = $dte->json_firmado_path;
        $this->simular(consultaIncierta: true);
        $this->post(route('facturacion.firmar-transmitir', $dte))->assertSessionHas('error');
        $this->assertDatabaseCount('contingencias', 0);
        $this->assertSame($ruta, $dte->refresh()->json_firmado_path);
        Http::assertSentCount(1); // Consulta previa del documento ya firmado.
    }

    public function test_interruptor_apagado_no_activa_y_no_hace_sonda(): void
    {
        config(['dte.contingencia.enabled' => false]);
        $dte = $this->documento();
        $this->simular();
        $this->artisan('dte:transmitir', ['dte' => $dte->id])->assertFailed();
        $this->artisan('dte:contingencia-reconexion')->assertSuccessful();
        $this->assertDatabaseCount('contingencias', 0);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'generate_204') || str_contains($r->url(), '/auth'));
    }

    public function test_se_suma_a_activa_existente_sin_sonda(): void
    {
        $dte = $this->documento();
        $activa = app(ContingenciaService::class)->activar(4, 'Corte inventado', 'manual', null);
        $this->simular();
        $this->artisan('dte:transmitir', ['dte' => $dte->id])->assertSuccessful();
        $this->assertSame($activa->id, $dte->refresh()->contingencia_id);
        $this->assertDatabaseCount('contingencias', 1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'generate_204'));
    }

    /** El MH rechaza un documento emitido antes del inicio de la contingencia (apitest, 19/10/2026). */
    public function test_el_inicio_retrocede_hasta_la_emision_del_documento_que_la_dispara(): void
    {
        $dte = $this->documento(); // emitido a las 10:00:00
        Carbon::setTestNow(Carbon::parse('2026-10-13 10:00:45', 'America/El_Salvador'));
        $this->artisan('dte:transmitir', ['dte' => $dte->id])->assertSuccessful();

        $json = json_decode(Storage::get($dte->refresh()->json_generado_path), true);
        $emision = Carbon::parse($json['identificacion']['fecEmi'].' '.$json['identificacion']['horEmi'], 'America/El_Salvador');
        $this->assertSame('2026-10-13 10:00:00', $dte->contingencia->inicio->format('Y-m-d H:i:s'));
        $this->assertTrue($emision->gte($dte->contingencia->inicio));
    }

    public function test_al_sumarse_a_una_activa_posterior_el_inicio_tambien_retrocede(): void
    {
        $dte = $this->documento(); // emitido a las 10:00:00
        Carbon::setTestNow(Carbon::parse('2026-10-13 10:05:00', 'America/El_Salvador'));
        $activa = app(ContingenciaService::class)->activar(1, 'Corte inventado', 'manual', null);
        $this->artisan('dte:transmitir', ['dte' => $dte->id])->assertSuccessful();

        $this->assertSame($activa->id, $dte->refresh()->contingencia_id);
        $this->assertSame('2026-10-13 10:00:00', $activa->refresh()->inicio->format('Y-m-d H:i:s'));
    }

    public function test_documento_emitido_hace_mas_del_tope_no_activa_ni_convierte(): void
    {
        $dte = $this->documento(); // emitido a las 10:00:00; tope por defecto, 10 minutos
        $firma = $dte->json_firmado_path;
        Carbon::setTestNow(Carbon::parse('2026-10-13 10:30:00', 'America/El_Salvador'));
        $this->artisan('dte:transmitir', ['dte' => $dte->id]);

        $this->assertDatabaseCount('contingencias', 0);
        $this->assertNull($dte->refresh()->contingencia_id);
        $this->assertSame($firma, $dte->json_firmado_path);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'generate_204'));
    }

    public function test_documento_viejo_no_se_suma_a_una_contingencia_activa(): void
    {
        $dte = $this->documento(); // emitido a las 10:00:00
        Carbon::setTestNow(Carbon::parse('2026-10-13 10:30:00', 'America/El_Salvador'));
        $activa = app(ContingenciaService::class)->activar(1, 'Corte inventado', 'manual', null);
        $this->artisan('dte:transmitir', ['dte' => $dte->id]);

        $this->assertNull($dte->refresh()->contingencia_id);
        $this->assertSame('2026-10-13 10:30:00', $activa->refresh()->inicio->format('Y-m-d H:i:s'));
    }

    public function test_dentro_del_tope_se_convierte_y_el_inicio_retrocede(): void
    {
        $dte = $this->documento(); // emitido a las 10:00:00
        Carbon::setTestNow(Carbon::parse('2026-10-13 10:09:59', 'America/El_Salvador'));
        $this->artisan('dte:transmitir', ['dte' => $dte->id])->assertSuccessful();

        $this->assertTrue($dte->refresh()->esTransitorio());
        $this->assertSame('2026-10-13 10:00:00', $dte->contingencia->inicio->format('Y-m-d H:i:s'));
    }

    public function test_conversion_rechaza_resultado_sin_confirmacion(): void
    {
        $dte = $this->documento();
        $this->assertFalse(app(ContingenciaAutomaticaService::class)->convertirTrasReintentos($dte,
            ['resultado' => 'reintentos_agotados', 'contingencia_requerida' => true]));
        $this->assertDatabaseCount('contingencias', 0);
    }

    public function test_reconexion_real_sin_cache_cese_unico_y_manuales_intactas(): void
    {
        $activa = app(ContingenciaService::class)->activar(1, 'MH inventado', 'automatica', null);
        Cache::put('dte.transmision.token.test', 'Bearer CACHE-INVENTADO', 3600);
        $this->artisan('dte:contingencia-reconexion')->assertSuccessful();
        $this->assertNull($activa->refresh()->cese);
        Carbon::setTestNow(now()->addMinutes(15));
        $this->simular(mh: true);
        $this->artisan('dte:contingencia-reconexion')->assertSuccessful();
        $this->assertSame('10:15:00', $activa->refresh()->cese->format('H:i:s'));
        $cese = $activa->cese->toDateTimeString();
        Carbon::setTestNow(now()->addMinutes(15));
        $this->artisan('dte:contingencia-reconexion')->assertSuccessful();
        $this->assertSame($cese, $activa->refresh()->cese->toDateTimeString());
        Http::assertSentCount(1);
        $manual = app(ContingenciaService::class)->activar(2, 'Sistema inventado', 'manual', null);
        $this->artisan('dte:contingencia-reconexion')->assertSuccessful();
        $this->assertNull($manual->refresh()->cese);
        Http::assertSentCount(1);
    }

    public function test_evento_automatico_desde_12_horas_y_una_sola_vez(): void
    {
        $activa = app(ContingenciaService::class)->activar(3, 'Internet inventado', 'automatica', null);
        $this->documento();
        app(ContingenciaService::class)->terminar(null);
        Carbon::setTestNow(now()->addHours(12)->subSecond());
        $this->simular();
        $this->artisan('dte:contingencia-reconexion')->assertSuccessful();
        Http::assertNothingSent();
        Carbon::setTestNow(now()->addSecond());
        $this->artisan('dte:contingencia-reconexion')->assertSuccessful();
        $this->assertSame('informada', $activa->refresh()->estado);
        $this->assertDatabaseCount('contingencia_eventos', 1);
        $this->assertDatabaseHas('activity_log', ['description' => 'Envío automático del evento tras 12 horas del cese']);
        $this->artisan('dte:contingencia-reconexion')->assertSuccessful();
        Http::assertSentCount(3);
    }

    public function test_correccion_cese_limites_y_bloqueo_tipo_firmado(): void
    {
        $activa = app(ContingenciaService::class)->activar(3, 'Internet inventado', 'manual', null);
        $this->documento();
        Carbon::setTestNow(now()->addMinutes(15));
        app(ContingenciaService::class)->terminar(null);
        $this->actingAs(User::factory()->create()->assignRole('administrador'));
        $url = route('facturacion.contingencia.actualizar', $activa);
        $this->patch($url, ['cese' => '2026-10-13T09:59:59'])->assertSessionHasErrors('cese');
        $this->patch($url, ['cese' => '2026-10-13T10:15:01'])->assertSessionHasErrors('cese');
        $this->patch($url, ['cese' => '2026-10-13T10:00:00'])->assertSessionHasNoErrors();
        $this->patch($url, ['tipo' => 1])->assertSessionHasErrors('contingencia');
        app(ContingenciaEventoService::class)->enviar($activa->refresh());
        $this->patch($url, ['cese' => '2026-10-13T10:10:00'])->assertSessionHasErrors('contingencia');
    }

    public function test_correccion_sin_firmas_y_permiso(): void
    {
        $activa = app(ContingenciaService::class)->activar(3, 'Internet inventado', 'manual', null);
        $url = route('facturacion.contingencia.actualizar', $activa);
        $this->patch($url, ['tipo' => 1])->assertForbidden();
        $this->actingAs(User::factory()->create()->assignRole('administrador'));
        $this->patch($url, ['tipo' => 5, 'motivo' => ''])->assertSessionHasErrors('motivo');
        $this->patch($url, ['tipo' => 5, 'motivo' => 'Motivo corregido inventado'])->assertSessionHasNoErrors();
        $this->assertSame(5, $activa->refresh()->tipo);
    }

    public function test_avisos_bordes_24_72_horas_y_tres_dias(): void
    {
        $activa = app(ContingenciaService::class)->activar(3, 'Internet inventado', 'manual', null);
        Carbon::setTestNow(now()->addDays(3)->subSecond());
        $this->assertFalse(app(ContingenciaEventoService::class)->requiereInformeTecnico($activa));
        Carbon::setTestNow(now()->addSecond());
        $this->assertTrue(app(ContingenciaEventoService::class)->requiereInformeTecnico($activa));
        app(ContingenciaService::class)->terminar(null);
        $activa->refresh();
        Carbon::setTestNow($activa->cese->copy()->addHours(24));
        $this->assertStringNotContainsString('Vencido:', (string) $this->view('components.plazos-contingencia', ['contingencia' => $activa]));
        Carbon::setTestNow(now()->addSecond());
        $this->assertStringContainsString('Vencido:', (string) $this->view('components.plazos-contingencia', ['contingencia' => $activa]));
        $evento = $activa->eventos()->create(['parte' => 1, 'codigo_generacion' => '00000000-0000-4000-8000-000000000001',
            'estado' => 'recibido', 'fecha_transmision' => now(), 'sello_recibido' => 'SELLO-INVENTADO']);
        Dte::create(['tipo_dte' => '01', 'ambiente' => '00', 'estado' => 'firmado', 'fecha_emision' => now()->toDateString(),
            'establecimiento_id' => $this->emisor['estab']->id, 'punto_venta_id' => $this->emisor['pv']->id,
            'hora_emision' => '10:00:00', 'contingencia_id' => $activa->id, 'contingencia_evento_id' => $evento->id]);
        Carbon::setTestNow($evento->fecha_transmision->copy()->addHours(72));
        $this->assertStringNotContainsString('Vencido:', (string) $this->view('components.plazos-contingencia', ['contingencia' => $activa]));
        Carbon::setTestNow(now()->addSecond());
        $render = (string) $this->view('components.plazos-contingencia', ['contingencia' => $activa]);
        $this->assertStringContainsString('72 h (lote)', $render);
        $this->assertStringContainsString('Vencido:', $render);
    }

    public function test_aviso_sin_tabla_no_falla(): void
    {
        Schema::partialMock()->shouldReceive('hasTable')->with('contingencias')->andReturn(false);
        $this->assertStringNotContainsString('Modo contingencia desde', (string) $this->view('components.aviso-contingencia'));
    }

    public function test_cabecera_corregida_actualiza_json_generado_sin_firma(): void
    {
        $activa = app(ContingenciaService::class)->activar(3, 'Internet inventado', 'manual', null);
        $dte = $this->documento(false);
        $codigo = $dte->codigo_generacion;
        app(ContingenciaService::class)->corregir($activa, ['tipo' => 1, 'motivo' => 'MH inventado'], null);
        $dte->refresh();
        $json = json_decode(Storage::get($dte->json_generado_path), true);
        $this->assertSame(1, $json['identificacion']['tipoContingencia']);
        $this->assertSame('MH inventado', $json['identificacion']['motivoContin']);
        $this->assertSame($codigo, $json['identificacion']['codigoGeneracion']);
        $this->assertSame(EstadoDte::Generado, $dte->estado);
    }

    public function test_sello_no_envia_correo_y_boton_reutiliza_pipeline(): void
    {
        app(ContingenciaService::class)->activar(3, 'Internet inventado', 'manual', null);
        $dte = $this->documento();
        Ajustes::guardarComoSistema('correo.auto_envio', true);
        $dte->update(['estado' => EstadoDte::Aceptado, 'sello_recepcion' => 'SELLO-INVENTADO']);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('dte_envios', 0);
        $this->actingAs(User::factory()->create()->assignRole('administrador'));
        $this->get(route('facturacion.contingencia.pendientes'))->assertOk()->assertSee('Reenviar al cliente');
        $this->post(route('facturacion.correo.cliente', $dte))->assertRedirect()->assertSessionHas('status');
        Queue::assertPushed(EnviarDteCorreo::class, 1);
        $this->assertDatabaseHas('dte_envios', ['dte_id' => $dte->id, 'destinatario' => 'cliente@example.test']);
    }

    public function test_planificador_cada_15_minutos_con_interruptor_y_solapamiento(): void
    {
        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'dte:contingencia-reconexion'));
        $this->assertNotNull($evento);
        $this->assertSame('*/15 * * * *', $evento->expression);
        $this->assertTrue($evento->withoutOverlapping);
        $this->assertTrue($evento->filtersPass($this->app));
        config(['dte.contingencia.enabled' => false]);
        $this->assertFalse($evento->filtersPass($this->app));
    }

    public function test_carrera_de_activacion_usa_la_contingencia_que_quedo_activa(): void
    {
        $dte = $this->documento();
        $this->partialMock(ContingenciaService::class, function ($mock) {
            $mock->shouldReceive('activar')->once()->andReturnUsing(function () {
                // Simula la fila ya confirmada por el otro emisor al perder activa_unica.
                Contingencia::create(['tipo' => 3, 'motivo' => 'Otra conexión inventada', 'origen' => 'automatica',
                    'estado' => 'activa', 'activa_unica' => 1, 'inicio' => now()]);
                throw ValidationException::withMessages(['contingencia' => 'Ya hay una contingencia activa.']);
            });
        });
        $this->artisan('dte:transmitir', ['dte' => $dte->id])->assertSuccessful();
        $this->assertDatabaseCount('contingencias', 1);
        $this->assertSame(Contingencia::first()->id, $dte->refresh()->contingencia_id);
        $this->assertSame('3', $dte->tipo_contingencia);
    }

    public function test_firma_incorrecta_conserva_documento_y_evidencia_original(): void
    {
        $dte = $this->documento();
        $jsonPath = $dte->json_generado_path;
        $firmaPath = $dte->json_firmado_path;
        $firmaOriginal = Storage::get($firmaPath);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*generate_204' => Http::response('', 204), '*recepciondte*' => Http::failedConnection(),
            '*consultadte*' => Http::response([], 404),
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FIRMA.DE.OTRO'])]);
        $this->post(route('facturacion.firmar-transmitir', $dte))->assertSessionHas('error');
        $dte->refresh();
        $this->assertNull($dte->contingencia_id);
        $this->assertSame($jsonPath, $dte->json_generado_path);
        $this->assertSame($firmaPath, $dte->json_firmado_path);
        $this->assertSame($firmaOriginal, Storage::get($firmaPath));
        $this->assertSame(EstadoDte::Firmado, $dte->estado);
    }

    public function test_reconexion_apagada_no_cierra_ni_envia_pendientes(): void
    {
        $activa = app(ContingenciaService::class)->activar(3, 'Internet inventado', 'automatica', null);
        config(['dte.contingencia.enabled' => false]);
        Carbon::setTestNow(now()->addHours(12));
        $this->simular(mh: true);
        $this->artisan('dte:contingencia-reconexion')->assertSuccessful();
        $this->assertNull($activa->refresh()->cese);
        Http::assertNothingSent();
    }
}
