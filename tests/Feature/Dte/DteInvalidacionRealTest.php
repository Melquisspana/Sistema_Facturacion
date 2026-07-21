<?php

namespace Tests\Feature\Dte;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\EstadoDte;
use App\Enums\TipoAnulacionMh;
use App\Enums\TipoDte;
use App\Exceptions\Dte\DteInvalidacionException;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Services\Dte\DteInvalidacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class DteInvalidacionRealTest extends TestCase
{
    use RefreshDatabase;

    private const NC_CODIGO_GENERACION = '437F5D8B-A746-46E1-8A60-BF74C17FE309';

    private const NC_SELLO = '2026A77BCED2A5C249999ECD1C51427B05A5ERRH';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Entorno "todo verde" para la transmisión real contra apitest (mockeado).
        config()->set('dte.invalidacion.mock', false);
        config()->set('dte.invalidacion.real_confirmation', true);
        config()->set('dte.firma.enabled', true);
        config()->set('dte.firma.mock', false);
        config()->set('dte.firma.nit', '10132512610012');
        config()->set('dte.firma.cert_password', 'secreto');
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.test_enabled', true);
        config()->set('dte.ambientes.00.anulacion_url', 'https://apitest.dtes.mh.gob.sv/fesv/anulardte');
    }

    /** Firmador + auth + anulardte mockeados (firma y transmisión reales NO ocurren). */
    private function fakeHttp(array $anulardteResponse): void
    {
        Http::fake([
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FAKE.JWS.SIGNATURE'], 200),
            '*seguridad/auth*' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer FAKE-TOKEN']], 200),
            '*anulardte*' => Http::response($anulardteResponse, $anulardteResponse['_http'] ?? 200),
        ]);
    }

    private function ncAceptada(bool $aceptada = true): Dte
    {
        $empresa = Empresa::create([
            'razon_social' => 'Elsa Fidelina Hernández Cañas', 'nombre_comercial' => 'Dulces La Negrita',
            'nit' => '10132512610012', 'nrc' => '1014765', 'telefono' => '71276473',
            'correo' => 'dulceslanegrita@yahoo.com', 'ambiente' => '00', 'activo' => true,
        ]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        $cliente = Cliente::factory()->contribuyente()->create([
            'nombre' => 'Calleja, S.A. de C.V.', 'num_documento' => '0614-110169-001-1',
            'telefono' => '67652343', 'correo' => 'melquicedeespana@gmail.com',
        ]);

        return Dte::create([
            'tipo_dte' => TipoDte::NotaCredito->value,
            'estado' => $aceptada ? EstadoDte::Aceptado->value : EstadoDte::Generado->value,
            'ambiente' => '00',
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'cliente_id' => $cliente->id,
            'numero_control' => 'DTE-05-M001P001-000000000000020',
            'codigo_generacion' => self::NC_CODIGO_GENERACION,
            'sello_recepcion' => $aceptada ? self::NC_SELLO : null,
            'respuesta_mh' => $aceptada ? ['estado' => 'PROCESADO', 'selloRecibido' => self::NC_SELLO] : null,
            'fecha_procesamiento_mh' => $aceptada ? '2026-06-30 22:48:44' : null,
            'fecha_emision' => '2026-06-30', 'hora_emision' => '22:26:52',
        ]);
    }

    private function evento(): EventoInvalidacionData
    {
        return new EventoInvalidacionData(
            tipoAnulacion: TipoAnulacionMh::RescindirOperacion,
            nombreResponsable: 'Melqui Administrador', tipoDocResponsable: '13', numDocResponsable: '040000000',
            nombreSolicita: 'Calleja CxP', tipoDocSolicita: '36', numDocSolicita: '06141101690011',
        );
    }

    private function servicio(): DteInvalidacionService
    {
        return app(DteInvalidacionService::class);
    }

    // ---------- Candados ----------

    public function test_bloquea_si_faltan_las_confirmaciones(): void
    {
        Http::fake();
        $dte = $this->ncAceptada();

        try {
            $this->servicio()->transmitir($dte, $this->evento(), transmitirReal: false, confirmoInvalidar: false);
            $this->fail('Debió bloquear por falta de confirmaciones.');
        } catch (DteInvalidacionException $e) {
            $this->assertStringContainsString('--transmitir-real', $e->getMessage());
            $this->assertStringContainsString('--confirmo-invalidar', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_bloquea_produccion(): void
    {
        Http::fake();
        config()->set('dte.transmision.ambiente', 'produccion');
        $dte = $this->ncAceptada();

        $this->expectException(DteInvalidacionException::class);
        try {
            $this->servicio()->transmitir($dte, $this->evento(), true, true);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_bloquea_mock_activo(): void
    {
        Http::fake();
        config()->set('dte.invalidacion.mock', true);
        $dte = $this->ncAceptada();

        $this->expectException(DteInvalidacionException::class);
        $this->servicio()->transmitir($dte, $this->evento(), true, true);
    }

    public function test_bloquea_si_falta_real_confirmation(): void
    {
        Http::fake();
        config()->set('dte.invalidacion.real_confirmation', false);
        $dte = $this->ncAceptada();

        $this->expectException(DteInvalidacionException::class);
        $this->servicio()->transmitir($dte, $this->evento(), true, true);
    }

    public function test_bloquea_si_faltan_datos_responsable_o_solicitante(): void
    {
        Http::fake();
        $dte = $this->ncAceptada();
        $eventoSinResp = new EventoInvalidacionData(tipoAnulacion: TipoAnulacionMh::RescindirOperacion);

        $c = $this->servicio()->evaluarCandados($dte, $eventoSinResp, true, true);
        $this->assertTrue($c['bloqueado']);
        $this->assertStringContainsString('responsable', implode(' ', $c['razones']));
        $this->assertStringContainsString('solicitante', implode(' ', $c['razones']));
    }

    public function test_bloquea_si_la_nc_no_esta_aceptada_por_mh(): void
    {
        Http::fake();
        $dte = $this->ncAceptada(aceptada: false);

        $this->expectException(DteInvalidacionException::class);
        $this->servicio()->transmitir($dte, $this->evento(), true, true);
    }

    // ---------- Dry-run ----------

    public function test_dry_run_no_firma_ni_transmite(): void
    {
        Http::fake();
        $dte = $this->ncAceptada();

        $d = $this->servicio()->dryRun($dte, $this->evento(), true, true);

        $this->assertTrue($d['transmitiria']);
        $this->assertFalse($d['candados']['bloqueado']);
        $this->assertTrue($d['schema']['valido']);
        $this->assertSame('https://apitest.dtes.mh.gob.sv/fesv/anulardte', $d['endpoint']);
        $this->assertStringContainsString('JWS firmado', $d['cuerpo_envio']['documento']);
        Http::assertNothingSent();
        // No persistió nada.
        $dte->refresh();
        $this->assertNull($dte->sello_invalidacion);
    }

    // ---------- Transmisión (mockeada) ----------

    public function test_aceptado_guarda_columnas_y_cambia_estado_a_invalidado(): void
    {
        $this->fakeHttp([
            'estado' => 'PROCESADO',
            'selloRecibido' => 'SELLO-INVAL-REAL-XYZ',
            'descripcionMsg' => 'Recibido',
            'fhProcesamiento' => '01/07/2026 10:00:00',
        ]);
        $dte = $this->ncAceptada();

        $r = $this->servicio()->transmitir($dte, $this->evento(), true, true);

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertTrue($r['invalidado']);

        $dte->refresh();
        $this->assertSame(EstadoDte::Invalidado, $dte->estado);
        $this->assertSame('SELLO-INVAL-REAL-XYZ', $dte->sello_invalidacion);
        $this->assertSame(TipoAnulacionMh::RescindirOperacion, $dte->tipo_anulacion);
        $this->assertNotNull($dte->codigo_generacion_invalidacion);
        $this->assertNotSame($dte->codigo_generacion, $dte->codigo_generacion_invalidacion);
        $this->assertNotNull($dte->fecha_procesamiento_invalidacion);
        $this->assertIsArray($dte->respuesta_mh_invalidacion);
        Storage::disk('local')->assertExists($dte->json_invalidacion_path);
        Storage::disk('local')->assertExists($dte->jws_invalidacion_path);
        Storage::disk('local')->assertExists($dte->respuesta_mh_invalidacion_path);
    }

    public function test_rechazado_guarda_respuesta_pero_no_cambia_estado(): void
    {
        $this->fakeHttp([
            '_http' => 400,
            'estado' => 'RECHAZADO',
            'descripcionMsg' => 'Documento ya invalidado',
            'observaciones' => ['Sello no corresponde'],
        ]);
        $dte = $this->ncAceptada();

        $r = $this->servicio()->transmitir($dte, $this->evento(), true, true);

        $this->assertSame('rechazado', $r['resultado']);
        $this->assertFalse($r['invalidado']);

        $dte->refresh();
        $this->assertSame(EstadoDte::Aceptado, $dte->estado);       // sin cambio
        $this->assertNull($dte->sello_invalidacion);                 // rechazado no trae sello
        $this->assertIsArray($dte->respuesta_mh_invalidacion);       // pero sí guarda el motivo
        $this->assertSame('rechazado', $dte->respuesta_mh_invalidacion['resultado']);
    }

    public function test_no_toca_evidencia_de_recepcion_original(): void
    {
        $this->fakeHttp([
            'estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-INVAL-REAL-XYZ',
            'descripcionMsg' => 'Recibido', 'fhProcesamiento' => '01/07/2026 10:00:00',
        ]);
        $dte = $this->ncAceptada();
        $selloRec = $dte->sello_recepcion;
        $respRec = $dte->respuesta_mh;
        $fechaRec = $dte->fecha_procesamiento_mh;

        $this->servicio()->transmitir($dte, $this->evento(), true, true);

        $dte->refresh();
        $this->assertSame($selloRec, $dte->sello_recepcion);
        $this->assertSame($respRec, $dte->respuesta_mh);
        $this->assertEquals($fechaRec, $dte->fecha_procesamiento_mh);
        $this->assertNotSame($dte->sello_recepcion, $dte->sello_invalidacion);
    }

    public function test_permite_reintento_tras_rechazo_previo(): void
    {
        // Un rechazo anterior dejó evidencia (respuesta + codigo/paths) pero sello null y
        // estado aceptado. NO debe bloquear el reintento (solo bloquea sello aceptado /
        // estado Invalidado).
        $dte = $this->ncAceptada();
        $dte->respuesta_mh_invalidacion = ['resultado' => 'rechazado', 'descripcionMsg' => '[identificacion.fecEmi] DATO NO COINCIDE CON DTE'];
        $dte->codigo_generacion_invalidacion = '641929A0-FB67-4A1D-AA2E-6C2D33E6355C';
        $dte->respuesta_mh_invalidacion_path = 'dte/invalidacion/respuestas/previo.json';
        $dte->tipo_anulacion = TipoAnulacionMh::RescindirOperacion->value;
        $dte->save();
        $dte->refresh();

        // No bloqueado por el rechazo previo.
        $c = $this->servicio()->evaluarCandados($dte, $this->evento(), true, true);
        $this->assertNotContains('La NC ya tiene un evento de invalidación o está invalidada.', $c['razones']);
        $this->assertFalse($c['bloqueado']);

        // El reintento (ahora aceptado) invalida correctamente.
        $this->fakeHttp([
            'estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-INVAL-REAL-XYZ',
            'descripcionMsg' => 'Recibido', 'fhProcesamiento' => '01/07/2026 10:00:00',
        ]);
        $r = $this->servicio()->transmitir($dte, $this->evento(), true, true);

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertTrue($r['invalidado']);
    }

    public function test_bloquea_reintento_si_ya_tiene_sello_de_invalidacion(): void
    {
        // Ya invalidado realmente (sello presente): NO se reintenta.
        $dte = $this->ncAceptada();
        $dte->sello_invalidacion = 'SELLO-INVAL-REAL-YA';
        $dte->save();

        $this->expectException(DteInvalidacionException::class);
        $this->servicio()->transmitir($dte->refresh(), $this->evento(), true, true);
    }

    public function test_bloquea_reintento_si_estado_es_invalidado(): void
    {
        Http::fake();
        // Estado Invalidado (sin sello): igual se bloquea (esAnulado()).
        $dte = $this->ncAceptada();
        $dte->estado = EstadoDte::Invalidado->value;
        $dte->save();

        $c = $this->servicio()->evaluarCandados($dte->refresh(), $this->evento(), true, true);
        $this->assertTrue($c['bloqueado']);
        $this->assertContains('La NC ya tiene un evento de invalidación o está invalidada.', $c['razones']);

        $this->expectException(DteInvalidacionException::class);
        try {
            $this->servicio()->transmitir($dte, $this->evento(), true, true);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_conserva_evidencia_de_intentos_rechazado_y_aceptado(): void
    {
        $dte = $this->ncAceptada();

        // anulardte: 1º RECHAZADO, 2º (reintento) ACEPTADO. (Http::fake gana el 1er
        // stub que matchea, por eso se usa una SECUENCIA y no dos fake() seguidos.)
        Http::fake([
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FAKE.JWS.SIGNATURE'], 200),
            '*seguridad/auth*' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer FAKE']], 200),
            '*anulardte*' => Http::sequence()
                ->push(['estado' => 'RECHAZADO', 'descripcionMsg' => '[identificacion.fecEmi] DATO NO COINCIDE CON DTE'], 400)
                ->push(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-INVAL-REAL-XYZ', 'descripcionMsg' => 'ok', 'fhProcesamiento' => '01/07/2026 10:00:00'], 200),
        ]);

        // Intento 1: RECHAZADO → deja evidencia en disco, sin cambiar estado.
        $this->servicio()->transmitir($dte, $this->evento(), true, true);
        $dte->refresh();
        $pathRechazo = $dte->respuesta_mh_invalidacion_path;
        Storage::disk('local')->assertExists($pathRechazo);
        $this->assertSame(EstadoDte::Aceptado, $dte->estado);

        // Intento 2 (reintento): ACEPTADO → nueva evidencia, sin borrar la anterior.
        $r2 = $this->servicio()->transmitir($dte, $this->evento(), true, true);
        $this->assertSame('aceptado', $r2['resultado']);
        $dte->refresh();
        $pathAcepta = $dte->respuesta_mh_invalidacion_path;

        $this->assertNotSame($pathRechazo, $pathAcepta, 'cada intento genera un archivo nuevo (codigoGeneracion distinto)');
        Storage::disk('local')->assertExists($pathRechazo); // evidencia del RECHAZO conservada
        Storage::disk('local')->assertExists($pathAcepta);   // evidencia de la ACEPTACIÓN
        $this->assertSame(EstadoDte::Invalidado, $dte->estado);
        $this->assertSame('SELLO-INVAL-REAL-XYZ', $dte->sello_invalidacion);
    }

    public function test_comando_real_muestra_mensaje_amable_si_ya_esta_invalidado(): void
    {
        $dte = $this->ncAceptada();
        $dte->estado = EstadoDte::Invalidado->value;
        $dte->sello_invalidacion = '20262DD5C477E6474FAA9771D46EFADE2B53JAEU';
        $dte->codigo_generacion_invalidacion = '05F93C81-D31B-437A-87FE-4AA453EBADD9';
        $dte->tipo_anulacion = TipoAnulacionMh::RescindirOperacion->value;
        $dte->save();

        Http::fake();
        $this->artisan('dte:invalidacion-real', ['dte' => $dte->id])
            ->expectsOutputToContain('Este DTE ya fue invalidado oficialmente')
            ->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_fecemi_del_evento_transmitido_coincide_con_la_fecha_del_dte(): void
    {
        // El cuerpo que viaja (evento firmado) debe llevar identificacion.fecEmi = fecha
        // del DTE. Aquí verificamos el evento serializado vía dry-run (sin firmar).
        Http::fake();
        $dte = $this->ncAceptada();

        $d = $this->servicio()->dryRun($dte, $this->evento(), true, true);

        $this->assertSame('2026-06-30', $d['evento']['identificacion']['fecEmi']);
        $this->assertSame('2026-06-30', $d['evento']['documento']['fecEmi']);
    }

    public function test_firma_y_transmision_se_mockean_via_http_fake(): void
    {
        $this->fakeHttp([
            'estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-INVAL-REAL-XYZ',
            'descripcionMsg' => 'Recibido', 'fhProcesamiento' => '01/07/2026 10:00:00',
        ]);
        $dte = $this->ncAceptada();

        $this->servicio()->transmitir($dte, $this->evento(), true, true);

        // Se firmó (firmador), se autenticó (auth) y se transmitió (anulardte).
        Http::assertSent(fn ($req) => str_contains($req->url(), 'firmardocumento'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'anulardte'));
        // El JWS firmado viajó como `documento`.
        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'anulardte') && ($req->data()['documento'] ?? null) === 'FAKE.JWS.SIGNATURE';
        });
    }

    // ---------- Auditoría de la transmisión real (activity log, simétrico al mock) ----------

    public function test_aceptado_registra_activity_log_con_datos_suficientes_y_sin_secretos(): void
    {
        $this->fakeHttp([
            'estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-INVAL-REAL-XYZ',
            'descripcionMsg' => 'Recibido', 'fhProcesamiento' => '01/07/2026 10:00:00',
        ]);
        $dte = $this->ncAceptada();

        $this->servicio()->transmitir($dte, $this->evento(), true, true);

        $log = Activity::where('log_name', 'dte_invalidacion')->latest('id')->first();
        $this->assertNotNull($log, 'debe quedar un registro de auditoría de la transmisión real');
        $this->assertSame($dte->id, $log->subject_id);
        $this->assertStringContainsString('ACEPTÓ', $log->description);

        $props = $log->properties->toArray();
        $this->assertTrue($props['aceptado']);
        $this->assertSame('aceptado', $props['resultado_mh']);
        $this->assertSame(TipoAnulacionMh::RescindirOperacion->value, $props['tipo_anulacion']);
        $this->assertSame('00', $props['ambiente']);
        $this->assertSame('consola', $props['origen']); // corrido desde el test = fuera de una request web
        $this->assertArrayHasKey('fecha', $props);
        $this->assertArrayHasKey('codigo_generacion_evento', $props);

        // Nunca contraseñas, tokens ni JSON/JWS completo en la auditoría.
        $plano = json_encode($props);
        $this->assertStringNotContainsString('FAKE-TOKEN', (string) $plano);
        $this->assertStringNotContainsString('secreto', (string) $plano);
        $this->assertStringNotContainsString('FAKE.JWS.SIGNATURE', (string) $plano);
    }

    public function test_rechazado_tambien_registra_activity_log(): void
    {
        $this->fakeHttp([
            '_http' => 400, 'estado' => 'RECHAZADO', 'descripcionMsg' => 'Documento ya invalidado',
        ]);
        $dte = $this->ncAceptada();

        $this->servicio()->transmitir($dte, $this->evento(), true, true);

        $log = Activity::where('log_name', 'dte_invalidacion')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('RECHAZÓ', $log->description);
        $props = $log->properties->toArray();
        $this->assertFalse($props['aceptado']);
        $this->assertSame('rechazado', $props['resultado_mh']);
    }

    public function test_error_de_conexion_no_registra_activity_log(): void
    {
        Http::fake([
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FAKE.JWS.SIGNATURE'], 200),
            '*seguridad/auth*' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer FAKE-TOKEN']], 200),
            '*anulardte*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'),
        ]);
        $dte = $this->ncAceptada();

        $r = $this->servicio()->transmitir($dte, $this->evento(), true, true);

        $this->assertSame('error_conexion', $r['resultado']);
        $this->assertNull(Activity::where('log_name', 'dte_invalidacion')->latest('id')->first());
    }
}
