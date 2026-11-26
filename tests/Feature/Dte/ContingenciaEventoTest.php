<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Models\Contingencia;
use App\Models\ContingenciaEvento;
use App\Models\Dte;
use App\Models\User;
use App\Services\Dte\ContingenciaEventoService;
use App\Services\Dte\DteSchemaValidator;
use App\Services\Dte\LoteDteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\PreparaEmisorDte;
use Tests\Concerns\SimulaFallasDeConexion;
use Tests\TestCase;

class ContingenciaEventoTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private Contingencia $contingencia;

    private array $emisor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-10 11:00:00', 'America/El_Salvador'));
        config(['dte.contingencia.enabled' => true, 'dte.storage.disk' => 'local',
            'dte.invalidacion.responsable' => ['nombre' => 'Responsable Inventado', 'tipo_doc' => '13', 'num_doc' => '12345678-9'],
            'dte.invalidacion.cod_estable_mh' => '', 'dte.invalidacion.cod_punto_venta_mh' => '',
            'dte.firma.enabled' => true, 'dte.firma.mock' => false,
            'dte.firma.nit' => '06141234561018', 'dte.firma.cert_password' => 'clave-inventada',
            'dte.transmision.enabled' => true, 'dte.transmision.dry_run' => false,
            'dte.transmision.real_confirmation' => true, 'dte.transmision.ambiente' => 'testing',
            'dte.transmision.token' => 'Bearer TOKEN-INVENTADO']);
        Storage::fake('local');
        $this->seedCatalogosDte();
        $this->emisor = $this->crearEmisorDte();
        $this->emisor['empresa']->update(['razon_social' => 'Emisor Inventado', 'nit' => '06141234561018', 'direccion' => 'Calle Inventada 123']);
        $this->contingencia = Contingencia::create(['tipo' => 3, 'motivo' => 'Internet inventado interrumpido',
            'origen' => 'manual', 'inicio' => '2026-10-10 09:00:00', 'cese' => '2026-10-10 10:15:00', 'estado' => 'cerrada']);
        $this->actingAs(User::factory()->create()->assignRole('administrador'));
        $this->simular();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function simular(string $estado = 'RECIBIDO', bool $conexion = false, ?string $recibido = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FIRMA.INVENTADA.JWS']),
            '*consultadte*' => function ($request) use ($recibido) {
                return $request['codigoGeneracion'] === $recibido
                    ? Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-DTE-INVENTADO', 'fhProcesamiento' => '10/10/2026 10:30:00'])
                    : Http::response([], 404);
            },
            '*fesv/contingencia' => $conexion ? Http::failedConnection() : Http::response([
                'estado' => $estado, 'selloRecibido' => $estado === 'RECIBIDO' ? 'SELLO-EVENTO-INVENTADO' : null,
                'fechaHora' => '10/10/2026 11:00:00', 'mensaje' => $estado === 'RECIBIDO' ? 'Recibido' : 'Responsable incorrecto',
                'observaciones' => $estado === 'RECIBIDO' ? [] : ['Corregir responsable'],
            ], $estado === 'RECIBIDO' ? 200 : 400),
        ]);
    }

    private function documento(): Dte
    {
        $dte = Dte::create(['tipo_dte' => '01', 'ambiente' => '00', 'estado' => 'firmado',
            'establecimiento_id' => $this->emisor['estab']->id, 'punto_venta_id' => $this->emisor['pv']->id,
            'contingencia_id' => $this->contingencia->id, 'codigo_generacion' => strtoupper((string) Str::uuid()),
            'numero_control' => 'DTE-01-M001P001-'.str_pad((string) (Dte::count() + 1), 15, '0', STR_PAD_LEFT),
            'fecha_emision' => '2026-10-10', 'hora_emision' => '09:30:00']);

        return $dte;
    }

    private function service(): ContingenciaEventoService
    {
        return app(ContingenciaEventoService::class);
    }

    private function leerEvento(ContingenciaEvento $evento): array
    {
        return json_decode(Storage::get($evento->json_path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_esquema_division_1001_y_codigos_propios(): void
    {
        for ($i = 0; $i < 1001; $i++) {
            $this->documento();
        }
        $partes = $this->service()->preparar($this->contingencia);
        $this->assertCount(2, $partes);
        $this->assertNotSame($partes[0]->codigo_generacion, $partes[1]->codigo_generacion);
        foreach ([1000, 1] as $i => $cantidad) {
            $json = $this->leerEvento($partes[$i]);
            $this->assertCount($cantidad, $json['detalleDTE']);
            $this->assertSame(1, $json['detalleDTE'][0]['noItem']);
            $this->assertTrue(app(DteSchemaValidator::class)->validarContingencia($json)['valido']);
            $this->assertSame($cantidad, $partes[$i]->dtes()->count());
        }
        $this->assertCount(2, $this->service()->preparar($this->contingencia));
        Http::assertSentCount(1001);
    }

    public function test_recibido_guarda_evidencia_y_no_envia_dos_veces(): void
    {
        $this->documento();
        $this->assertSame('recibido', $this->service()->enviar($this->contingencia)[0]['resultado']);
        $evento = ContingenciaEvento::first();
        $this->assertSame('informada', $this->contingencia->refresh()->estado);
        $this->assertSame('SELLO-EVENTO-INVENTADO', $evento->sello_recibido);
        Storage::assertExists([$evento->json_path, $evento->jws_path, $evento->respuesta_mh_path]);
        $this->assertSame('FIRMA.INVENTADA.JWS', Storage::get($evento->jws_path));
        $this->assertSame('RECIBIDO', json_decode(Storage::get($evento->respuesta_mh_path), true)['estado']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/fesv/contingencia')
            && $request['nit'] === '06141234561018' && $request['documento'] === 'FIRMA.INVENTADA.JWS');
        $this->assertSame([], $this->service()->enviar($this->contingencia));
        Http::assertSentCount(3);
    }

    public function test_rechazo_visible_y_correccion_con_codigo_nuevo_en_24_horas(): void
    {
        $dte = $this->documento();
        $this->simular('RECHAZADO');
        $this->assertSame('rechazado', $this->service()->enviar($this->contingencia)[0]['resultado']);
        $anterior = ContingenciaEvento::first();
        $this->assertSame('cerrada', $this->contingencia->refresh()->estado);
        $this->get(route('facturacion.contingencia.show', $this->contingencia))->assertOk()->assertSee('Corregir responsable');
        config(['dte.invalidacion.responsable.nombre' => 'Responsable Corregido']);
        Carbon::setTestNow(Carbon::parse('2026-10-11 10:59:59', 'America/El_Salvador'));
        $this->simular();
        $this->assertSame('recibido', $this->service()->enviar($this->contingencia)[0]['resultado']);
        $nuevo = ContingenciaEvento::latest('id')->first();
        $this->assertNotSame($anterior->codigo_generacion, $nuevo->codigo_generacion);
        $this->assertSame('Responsable Corregido', $this->leerEvento($nuevo)['emisor']['nombreResponsable']);
        $this->assertSame('rechazado', $anterior->refresh()->estado);
        $this->assertSame($nuevo->id, $dte->refresh()->contingencia_evento_id);
        Storage::assertExists([$anterior->json_path, $anterior->jws_path, $anterior->respuesta_mh_path]);
    }

    public function test_envio_a_101459_del_dia_siguiente_en_plazo(): void
    {
        $this->documento();
        Carbon::setTestNow(Carbon::parse('2026-10-11 10:14:59', 'America/El_Salvador'));
        $this->assertSame('recibido', $this->service()->enviar($this->contingencia)[0]['resultado']);
    }

    public function test_envio_a_101501_del_dia_siguiente_vencido(): void
    {
        $this->documento();
        Carbon::setTestNow(Carbon::parse('2026-10-11 10:15:01', 'America/El_Salvador'));
        $this->expectException(ValidationException::class);
        try {
            $this->service()->enviar($this->contingencia);
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('contingencia_eventos', 0);
        }
    }

    public function test_cruce_medianoche_local_y_plazo_exacto_inclusivo(): void
    {
        $this->contingencia->update(['cese' => '2026-10-10 23:59:59']);
        $this->documento();
        Carbon::setTestNow(Carbon::parse('2026-10-11 23:59:59', 'America/El_Salvador'));
        $parte = $this->service()->preparar($this->contingencia)->first();
        $this->assertSame('2026-10-11', $this->leerEvento($parte)['identificacion']['fTransmision']);
        $this->assertSame('23:59:59', $this->leerEvento($parte)['identificacion']['hTransmision']);
        Carbon::setTestNow(Carbon::parse('2026-10-12 00:00:00', 'America/El_Salvador'));
        $this->expectException(ValidationException::class);
        $this->service()->enviar($this->contingencia);
    }

    public function test_informe_tecnico_desde_tres_dias_exactos_no_antes(): void
    {
        $this->contingencia->update(['cese' => null, 'estado' => 'activa']);
        Carbon::setTestNow(Carbon::parse('2026-10-13 08:59:59', 'America/El_Salvador'));
        $this->assertFalse($this->service()->requiereInformeTecnico($this->contingencia));
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'America/El_Salvador'));
        $this->assertTrue($this->service()->requiereInformeTecnico($this->contingencia));
        $this->get(route('facturacion.contingencia.show', $this->contingencia))->assertOk()->assertSee('Informe Técnico');
    }

    public function test_documento_recibido_se_excluye_y_acepta_por_camino_normal(): void
    {
        $recibido = $this->documento();
        $pendiente = $this->documento();
        $this->simular(recibido: $recibido->codigo_generacion);
        $parte = $this->service()->preparar($this->contingencia)->first();
        $this->assertSame(EstadoDte::Aceptado, $recibido->refresh()->estado);
        $this->assertSame('SELLO-DTE-INVENTADO', $recibido->sello_recepcion);
        $this->assertNotNull($recibido->fecha_procesamiento_mh);
        $this->assertNull($recibido->contingencia_id);
        $this->assertNull($recibido->contingencia_evento_id);
        $this->assertCount(1, $this->leerEvento($parte)['detalleDTE']);
        $this->assertSame($pendiente->codigo_generacion, $this->leerEvento($parte)['detalleDTE'][0]['codigoGeneracion']);
        $this->assertSame(2, $recibido->historial()->count());
    }

    public function test_error_conexion_no_cambia_parte_y_permite_reintentar(): void
    {
        $this->documento();
        $parte = $this->service()->preparar($this->contingencia)->first();
        $antes = $parte->getAttributes();
        $this->simular(conexion: true);
        $this->assertSame('error_conexion', $this->service()->enviar($this->contingencia)[0]['resultado']);
        $this->assertSame($antes, $parte->refresh()->getAttributes());
        $this->assertSame('cerrada', $this->contingencia->refresh()->estado);
        $this->simular();
        $this->assertSame('recibido', $this->service()->enviar($this->contingencia)[0]['resultado']);
        $this->assertDatabaseCount('contingencia_eventos', 1);
        $this->assertSame($parte->codigo_generacion, ContingenciaEvento::first()->codigo_generacion);
    }

    public function test_sin_cese_no_firma_ni_consulta(): void
    {
        $this->contingencia->update(['cese' => null, 'estado' => 'activa']);
        $this->documento();
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertSessionHasErrors('contingencia');
        Http::assertNothingSent();
    }

    public function test_rutas_sin_permiso_403_y_apagadas_404(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('facturacion'));
        $this->get(route('facturacion.contingencia.show', $this->contingencia))->assertForbidden();
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertForbidden();
        config(['dte.contingencia.enabled' => false]);
        $this->get(route('facturacion.contingencia.show', $this->contingencia))->assertNotFound();
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_responsable_vacio_bloquea_antes_de_firmar(): void
    {
        $this->documento();
        config(['dte.invalidacion.responsable.nombre' => '']);
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertSessionHasErrors('contingencia');
        $this->assertDatabaseCount('contingencia_eventos', 0);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'firmardocumento') || str_ends_with($request->url(), '/contingencia'));
    }

    public function test_reenvio_fuera_de_plazo_del_rechazo_no_crea_otro_evento(): void
    {
        $this->documento();
        $this->simular('RECHAZADO');
        $this->service()->enviar($this->contingencia);
        Carbon::setTestNow(Carbon::parse('2026-10-11 11:00:01', 'America/El_Salvador'));
        $this->simular();
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertSessionHasErrors('contingencia');
        $this->assertDatabaseCount('contingencia_eventos', 1);
        Http::assertNothingSent();
    }

    public function test_todas_las_partes_deben_recibirse_para_marcar_informada(): void
    {
        for ($i = 0; $i < 1001; $i++) {
            $this->documento();
        }
        $partes = $this->service()->preparar($this->contingencia);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FIRMA.INVENTADA.JWS']),
            '*fesv/contingencia' => Http::sequence()
                ->push(['estado' => 'RECIBIDO', 'selloRecibido' => 'SELLO-PRIMERA-PARTE'])
                ->push(['estado' => 'RECHAZADO', 'mensaje' => 'Corregir segunda parte'], 400),
        ]);
        $resultados = $this->service()->enviar($this->contingencia);
        $this->assertSame(['recibido', 'rechazado'], array_column($resultados, 'resultado'));
        $this->assertSame('cerrada', $this->contingencia->refresh()->estado);
        $this->simular();
        $this->service()->enviar($this->contingencia);
        $this->assertSame('informada', $this->contingencia->refresh()->estado);
        $this->assertSame($partes[0]->codigo_generacion, $this->service()->partesVigentes($this->contingencia)[0]->codigo_generacion);
        $this->assertDatabaseCount('contingencia_eventos', 3);
        Http::assertSentCount(3); // Solo consulta, firma y envio de la segunda parte.
    }

    public function test_unico_documento_ya_recibido_se_acepta_sin_evento_vacio(): void
    {
        $dte = $this->documento();
        $this->simular(recibido: $dte->codigo_generacion);
        $this->assertSame('sin_documentos', $this->service()->enviar($this->contingencia)[0]['resultado']);
        $this->assertSame(EstadoDte::Aceptado, $dte->refresh()->estado);
        $this->assertNull($dte->contingencia_id);
        $this->assertDatabaseCount('contingencia_eventos', 0);
        Http::assertSentCount(1);
    }

    public function test_consulta_incierta_bloquea_inclusion(): void
    {
        $this->documento();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*consultadte*' => Http::failedConnection()]);
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertSessionHasErrors('contingencia');
        $this->assertDatabaseCount('contingencia_eventos', 0);
        Http::assertSentCount(1);
    }

    public function test_endpoint_no_oficial_se_bloquea_antes_de_token_o_firma(): void
    {
        $this->documento();
        config(['dte.ambientes.00.contingencia_url' => 'https://impostor.example.test/fesv/contingencia']);
        $this->expectException(DteTransmisionDeshabilitadaException::class);
        try {
            $this->service()->enviar($this->contingencia);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_respuesta_sin_sello_no_marca_recibido(): void
    {
        $this->documento();
        $parte = $this->service()->preparar($this->contingencia)->first();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FIRMA.INVENTADA.JWS']),
            '*fesv/contingencia' => Http::response(['estado' => 'RECIBIDO']),
        ]);
        $this->assertSame('respuesta_incierta', $this->service()->enviar($this->contingencia)[0]['resultado']);
        $this->assertSame('preparado', $parte->refresh()->estado);
        $this->assertSame('cerrada', $this->contingencia->refresh()->estado);
    }

    public function test_servicio_apagado_no_prepara_ni_envia(): void
    {
        config(['dte.contingencia.enabled' => false]);
        $this->expectException(HttpException::class);
        try {
            $this->service()->enviar($this->contingencia);
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('contingencia_eventos', 0);
        }
    }

    public function test_una_consulta_que_contesta_por_otro_codigo_no_acepta_el_documento_ni_lo_saca_de_la_contingencia(): void
    {
        $dte = $this->documento();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*consultadte*' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-DTE-INVENTADO',
            'codigoGeneracion' => strtoupper((string) Str::uuid()), 'fhProcesamiento' => '10/10/2026 10:30:00'])]);

        try {
            $this->service()->preparar($this->contingencia);
            $this->fail('Con una respuesta de otro documento no se puede dar por recibido este.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($dte->codigo_generacion, implode(' ', $e->validator->errors()->all()));
        }

        $dte->refresh();
        $this->assertSame(EstadoDte::Firmado, $dte->estado);
        $this->assertNull($dte->sello_recepcion);
        $this->assertSame($this->contingencia->id, $dte->contingencia_id);
    }

    // ─────────── Firmador o inicio de sesión caídos al enviar el aviso ───────────
    //
    // En el simulacro del 17/11/2026 «Enviar aviso de contingencia» daba un error 500 con
    // el firmador caído o sin poder iniciar sesión en Hacienda. No se perdía nada, pero la
    // pantalla no decía qué pasaba ni que se podía volver a probar.

    /** Sin token guardado: cada envío tiene que iniciar sesión en Hacienda. */
    private function sinTokenGuardado(): void
    {
        config(['dte.transmision.token' => '', 'dte.transmision.usuario_testing' => 'usuario-inventado',
            'dte.transmision.password_testing' => 'clave-inventada']);
    }

    public function test_con_el_firmador_caido_el_aviso_dice_que_paso_y_se_puede_reintentar(): void
    {
        $this->documento();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*firmardocumento*' => Http::failedConnection(), '*consultadte*' => Http::response([], 404)]);

        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertRedirect()
            ->assertSessionHasErrors(['contingencia' => 'No se pudo firmar el aviso. No se envió nada a Hacienda: revisá que el firmador esté encendido y probá de nuevo en unos minutos.']);
        $this->assertSame('preparado', ContingenciaEvento::sole()->estado);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/fesv/contingencia'));

        $this->simular();
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertSessionHasNoErrors();
        $this->assertSame('recibido', ContingenciaEvento::sole()->estado);
    }

    public function test_sin_poder_iniciar_sesion_el_aviso_armado_dice_que_paso_y_se_puede_reintentar(): void
    {
        $this->documento();
        $parte = $this->service()->preparar($this->contingencia)->sole();
        $this->sinTokenGuardado();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*seguridad/auth' => Http::failedConnection(),
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FIRMA.INVENTADA.JWS'])]);

        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertRedirect()
            ->assertSessionHasErrors(['contingencia' => 'No se pudo iniciar sesión en Hacienda. No se envió nada: probá de nuevo en unos minutos.']);
        $this->assertSame('preparado', $parte->refresh()->estado);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/fesv/contingencia'));

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*seguridad/auth' => Http::response(['status' => 'OK', 'body' => ['token' => 'TOKEN-INVENTADO-NUEVO']]),
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FIRMA.INVENTADA.JWS']),
            '*fesv/contingencia' => Http::response(['estado' => 'RECIBIDO', 'selloRecibido' => 'SELLO-EVENTO-INVENTADO'])]);
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertSessionHasNoErrors();
        $this->assertSame('recibido', $parte->refresh()->estado);
        $this->assertDatabaseCount('contingencia_eventos', 1);
    }

    public function test_sin_poder_iniciar_sesion_la_consulta_previa_al_aviso_dice_que_paso(): void
    {
        $dte = $this->documento();
        $this->sinTokenGuardado();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*seguridad/auth' => Http::failedConnection()]);

        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertRedirect()
            ->assertSessionHasErrors(['contingencia' => 'No se pudo consultar en Hacienda el documento '.$dte->codigo_generacion.'. No se envió nada: probá de nuevo en unos minutos.']);
        $this->assertDatabaseCount('contingencia_eventos', 0);
        Http::assertNotSent(fn ($request) => ! str_contains($request->url(), 'seguridad/auth'));
    }

    // ─────────── Aviso con la respuesta perdida ───────────
    //
    // En el simulacro el primer envío se cortó por tiempo, pero Hacienda lo guardó. El
    // reintento, con el mismo aviso, recibió «YA EXISTE UN REGISTRO CON ESE VALOR», quedó
    // «rechazado» y el clic siguiente mandó otro aviso para los mismos documentos: dos en
    // Hacienda. Qué contesta Hacienda real a un aviso repetido no está documentado, así
    // que no se adivina: queda en duda y una persona lo verifica.

    private function avisoConLaRespuestaPerdidaRechazadoAlReintentar(?string $horaDelReintento = null): ContingenciaEvento
    {
        $this->simular(conexion: true);
        $this->assertSame('error_conexion', $this->service()->enviar($this->contingencia)[0]['resultado']);
        if ($horaDelReintento !== null) {
            Carbon::setTestNow(Carbon::parse($horaDelReintento, 'America/El_Salvador'));
        }
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'OTRA.FIRMA.JWS']),
            '*fesv/contingencia' => Http::response(['estado' => 'RECHAZADO', 'fechaHora' => '10/10/2026 11:00:08', 'mensaje' => 'Rechazado inventado',
                'observaciones' => ['[identificacion.codigoGeneracion] YA EXISTE UN REGISTRO CON ESE VALOR']], 400),
        ]);

        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Verificá en Hacienda'));
        // El reintento manda el mismo aviso, con la misma firma.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/fesv/contingencia') && $request['documento'] === 'FIRMA.INVENTADA.JWS');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'firmardocumento'));

        return ContingenciaEvento::sole();
    }

    public function test_un_aviso_con_la_respuesta_perdida_que_hacienda_rechaza_al_reintentar_queda_en_duda_y_no_se_reemplaza_solo(): void
    {
        $dte = $this->documento();
        $parte = $this->avisoConLaRespuestaPerdidaRechazadoAlReintentar();
        $this->assertSame('incierto', $parte->estado);
        // Si Hacienda no lo tiene, el rechazo fue real: guarda su hora para el plazo de corrección.
        $this->assertSame('2026-10-10 11:00:00', $parte->rechazado_en?->format('Y-m-d H:i:s'));
        $this->assertSame($parte->id, $dte->refresh()->contingencia_evento_id);
        $this->assertSame('cerrada', $this->contingencia->refresh()->estado);

        // El clic siguiente avisa y no arma otro aviso con código nuevo.
        $this->simular();
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Verificá en Hacienda'));
        $this->assertDatabaseCount('contingencia_eventos', 1);
        Http::assertNothingSent();
        $this->get(route('facturacion.contingencia.show', $this->contingencia))->assertOk()
            ->assertSee('YA EXISTE UN REGISTRO CON ESE VALOR')->assertSee('Ya verifiqué en Hacienda');

        // Tampoco el envío automático de las 2 horas.
        Carbon::setTestNow(Carbon::parse('2026-10-10 22:16:00', 'America/El_Salvador'));
        $this->artisan('dte:contingencia-reconexion')->assertSuccessful();
        $this->assertDatabaseCount('contingencia_eventos', 1);
        $this->assertDatabaseMissing('activity_log', ['description' => 'Envío automático del evento tras 2 horas del cese']);
        Http::assertNothingSent();
    }

    public function test_con_la_verificacion_marcada_el_aviso_en_duda_se_reemplaza_por_uno_nuevo_y_queda_registrado(): void
    {
        $dte = $this->documento();
        $parte = $this->avisoConLaRespuestaPerdidaRechazadoAlReintentar();

        $this->simular();
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia), ['reemplazar_incierto' => '1'])
            ->assertSessionHasNoErrors();

        $nuevo = ContingenciaEvento::latest('id')->first();
        $this->assertNotSame($parte->codigo_generacion, $nuevo->codigo_generacion);
        $this->assertSame('recibido', $nuevo->estado);
        $this->assertSame('incierto', $parte->refresh()->estado);
        $this->assertSame($nuevo->id, $dte->refresh()->contingencia_evento_id);
        $this->assertSame('informada', $this->contingencia->refresh()->estado);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'dte_contingencia', 'subject_id' => $parte->id,
            'description' => 'Aviso en duda reemplazado por uno nuevo: se verificó en Hacienda que no lo tiene']);
    }

    public function test_el_envio_automatico_reintenta_el_reemplazo_de_una_parte_en_duda_que_se_corto(): void
    {
        $this->documento();
        $parte = $this->avisoConLaRespuestaPerdidaRechazadoAlReintentar();
        $this->simular(conexion: true);
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia), ['reemplazar_incierto' => '1']);
        $nuevo = ContingenciaEvento::latest('id')->first();
        $this->assertNotSame($parte->id, $nuevo->id);
        $this->assertSame('preparado', $nuevo->estado);

        // La parte en duda ya se reemplazó: lo que queda es un corte común, que se reintenta solo.
        Carbon::setTestNow(Carbon::parse('2026-10-10 22:16:00', 'America/El_Salvador'));
        $this->simular();
        $this->artisan('dte:contingencia-reconexion')->assertSuccessful();
        $this->assertSame('recibido', $nuevo->refresh()->estado);
        $this->assertSame('informada', $this->contingencia->refresh()->estado);
        $this->assertDatabaseCount('contingencia_eventos', 2);
    }

    public function test_si_hacienda_si_tiene_el_aviso_en_duda_se_da_por_recibido_sin_mandar_otro_y_salen_los_lotes(): void
    {
        // El aviso se armó y salió a las 11:00; el reintento, rechazado, fue a las 12:00.
        $dte = $this->documento();
        $dte->json_firmado_path = 'inventados/'.$dte->codigo_generacion.'.jws';
        $dte->save();
        Storage::put($dte->json_firmado_path, 'FIRMA.INVENTADA.'.$dte->codigo_generacion);
        $parte = $this->avisoConLaRespuestaPerdidaRechazadoAlReintentar('2026-10-10 12:00:00');
        $this->get(route('facturacion.contingencia.show', $this->contingencia))->assertOk()
            ->assertSee('Hacienda sí lo tiene')->assertSee($parte->codigo_generacion);

        // Sin permiso no se puede, y sin marcar la casilla tampoco.
        $persona = auth()->user();
        $this->actingAs(User::factory()->create()->assignRole('facturacion'))
            ->post(route('facturacion.contingencia.eventos.recibido', $parte), ['confirmo_recibido' => '1'])->assertForbidden();
        $this->actingAs($persona)->post(route('facturacion.contingencia.eventos.recibido', $parte))
            ->assertSessionHasErrors('confirmo_recibido');
        $this->assertSame('incierto', $parte->refresh()->estado);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake();
        $this->post(route('facturacion.contingencia.eventos.recibido', $parte), ['confirmo_recibido' => '1'])
            ->assertRedirect(route('facturacion.contingencia.show', $this->contingencia))->assertSessionHasNoErrors();
        Http::assertNothingSent();

        $parte->refresh();
        $this->assertSame('recibido', $parte->estado);
        $this->assertNull($parte->rechazado_en);
        $this->assertDatabaseCount('contingencia_eventos', 1);
        $this->assertSame('informada', $this->contingencia->refresh()->estado);
        // Las 72 h de los lotes cuentan desde la hora del aviso, antes de que pudiera llegar.
        $this->assertSame('2026-10-13 11:00:00', app(LoteDteService::class)->plazo($parte)?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('activity_log', ['log_name' => 'dte_contingencia', 'subject_id' => $parte->id,
            'causer_id' => $persona->id, 'description' => 'Aviso en duda dado por recibido: se verificó en Hacienda que lo tiene']);

        // Un segundo clic no cambia nada.
        $this->post(route('facturacion.contingencia.eventos.recibido', $parte), ['confirmo_recibido' => '1'])
            ->assertSessionHasErrors('contingencia');

        // Y sus documentos salen en lote.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*recepcionlote' => fn ($r) => Http::response(['estado' => 'RECIBIDO', 'codigoLote' => 'LOTE-'.$r['idEnvio']])]);
        $this->post(route('facturacion.contingencia.lotes.enviar', $parte))->assertSessionHasNoErrors();
        $this->assertSame('recibido', $dte->refresh()->loteDte?->estado);
    }

    /**
     * Simulacro del 26/11 (F17): después de «Hacienda sí lo tiene», la ficha seguía
     * mostrando el rechazo del reintento, en rojo. La parte está recibida: no se muestra
     * como rechazo, y la respuesta original sigue guardada.
     */
    public function test_la_parte_dada_por_recibida_no_se_muestra_como_rechazada(): void
    {
        $this->documento();
        $parte = $this->avisoConLaRespuestaPerdidaRechazadoAlReintentar();
        $this->post(route('facturacion.contingencia.eventos.recibido', $parte), ['confirmo_recibido' => '1'])
            ->assertSessionHasNoErrors();

        $this->get(route('facturacion.contingencia.show', $this->contingencia))->assertOk()
            ->assertDontSee('YA EXISTE UN REGISTRO CON ESE VALOR')->assertDontSee('Rechazado inventado')
            ->assertSee('Dada por recibida: se verificó en Hacienda que tiene este aviso.');
        $this->assertSame('RECHAZADO', $parte->refresh()->respuesta_mh['estado']);
    }

    public function test_una_parte_en_duda_se_reemplaza_en_las_24_horas_desde_su_rechazo_aunque_ya_pasaron_24_desde_el_cese(): void
    {
        // Cese el 10/10 a las 10:15: el aviso sale y se rechaza al reintentar el 11/10 a las 10:00.
        $dte = $this->documento();
        Carbon::setTestNow(Carbon::parse('2026-10-11 10:00:00', 'America/El_Salvador'));
        $parte = $this->avisoConLaRespuestaPerdidaRechazadoAlReintentar();

        // La bandeja y los correos cuentan el plazo desde el rechazo, no desde el cese.
        $this->assertSame('2026-10-12 10:00:00', app(LoteDteService::class)->plazoDocumento($dte->refresh())?->format('Y-m-d H:i:s'));

        // A las 10:30 ya pasaron 24 h del cese, pero no del rechazo: el reemplazo sale.
        Carbon::setTestNow(Carbon::parse('2026-10-11 10:30:00', 'America/El_Salvador'));
        $this->simular();
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia), ['reemplazar_incierto' => '1'])
            ->assertSessionHasNoErrors();

        $nuevo = ContingenciaEvento::latest('id')->first();
        $this->assertNotSame($parte->id, $nuevo->id);
        $this->assertSame('recibido', $nuevo->estado);
        $this->assertSame($nuevo->id, $dte->refresh()->contingencia_evento_id);
    }

    public function test_un_rechazo_despues_de_no_poder_iniciar_sesion_sigue_siendo_un_rechazo_comun(): void
    {
        $this->documento();
        $parte = $this->service()->preparar($this->contingencia)->sole();
        $this->sinTokenGuardado();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*seguridad/auth' => Http::failedConnection()]);
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertSessionHasErrors('contingencia');

        // Sin sesión no se firmó ni se mandó nada: el rechazo siguiente es del primer envío.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*seguridad/auth' => Http::response(['status' => 'OK', 'body' => ['token' => 'TOKEN-INVENTADO-NUEVO']]),
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FIRMA.INVENTADA.JWS']),
            '*fesv/contingencia' => Http::response(['estado' => 'RECHAZADO', 'mensaje' => 'Responsable incorrecto'], 400)]);
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia));

        $this->assertSame('rechazado', $parte->refresh()->estado);
        $this->assertNotNull($parte->rechazado_en);
    }

    /** @return array<string, array{string, string}> */
    public static function primerEnvioCortado(): array
    {
        return [
            'no pudo conectar: el aviso no salió' => ['rechazada', 'rechazado'],
            'salió y se perdió la respuesta' => ['salio_sin_respuesta', 'incierto'],
        ];
    }

    /**
     * Con Hacienda caída del todo y el token guardado, el primer envío ni conecta. El
     * rechazo siguiente es de ese primer envío: un rechazo común, con su plazo para
     * corregir, sin ir a verificar en Hacienda. Si el pedido pudo salir, sigue en duda.
     */
    #[DataProvider('primerEnvioCortado')]
    public function test_un_rechazo_despues_de_un_envio_que_no_salio_de_la_maquina_es_un_rechazo_comun(string $falla, string $estado): void
    {
        $this->documento();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FIRMA.INVENTADA.JWS']),
            '*consultadte*' => Http::response([], 404),
            '*fesv/contingencia' => SimulaFallasDeConexion::repetir(SimulaFallasDeConexion::fallaReal($falla))]);
        $this->assertSame('error_conexion', $this->service()->enviar($this->contingencia)[0]['resultado']);

        $this->simular('RECHAZADO');
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia));

        $parte = ContingenciaEvento::sole();
        $this->assertSame($estado, $parte->estado);
        $this->assertSame('2026-10-10 11:00:00', $parte->rechazado_en?->format('Y-m-d H:i:s'));
    }

    /** @return array<string, array{string, string}> */
    public static function avisoSinRespuesta(): array
    {
        return [
            'no pudo conectar: el aviso no salió' => ['rechazada', 'El aviso no salió: no hubo conexión con Hacienda. Se puede reintentar.'],
            'salió y se perdió la respuesta' => ['salio_sin_respuesta', 'El aviso salió, pero Hacienda no contestó: puede que lo tenga. Se puede reintentar; sale el mismo aviso, no uno nuevo.'],
        ];
    }

    /**
     * Simulacro del 26/11 (F18): un aviso que salió y quedó sin respuesta decía
     * «error_conexion: No se pudo conectar con el MH». Ahora distingue si salió, y lo
     * que lee la persona no empieza con el código interno del resultado.
     */
    #[DataProvider('avisoSinRespuesta')]
    public function test_el_aviso_sin_respuesta_dice_si_salio_y_sin_el_codigo_interno(string $falla, string $mensaje): void
    {
        $this->documento();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FIRMA.INVENTADA.JWS']),
            '*consultadte*' => Http::response([], 404),
            '*fesv/contingencia' => SimulaFallasDeConexion::repetir(SimulaFallasDeConexion::fallaReal($falla))]);

        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertSessionHas('status', $mensaje);

        $this->simular();
        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertSessionHas('status', 'Hacienda recibió el aviso.');
    }

    public function test_el_rechazo_del_aviso_se_lee_sin_el_codigo_interno(): void
    {
        $this->documento();
        $this->simular('RECHAZADO');

        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))
            ->assertSessionHas('status', 'Hacienda rechazó el aviso. Responsable incorrecto');
    }

    public function test_con_la_firma_apagada_el_aviso_muestra_su_propio_mensaje_y_no_un_error_del_servidor(): void
    {
        $this->documento();
        config(['dte.firma.enabled' => false]);

        $this->post(route('facturacion.contingencia.enviar', $this->contingencia))->assertRedirect()
            ->assertSessionHasErrors(['contingencia' => 'La firma está deshabilitada (dte.firma.enabled=false). No se firmó el evento.']);
        $this->assertSame('preparado', ContingenciaEvento::sole()->estado);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/fesv/contingencia'));
    }
}
