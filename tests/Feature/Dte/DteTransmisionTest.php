<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Exceptions\Dte\DteTransmisionException;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteTransmisionService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Preparación de transmisión DTE: SIMULADA / BLOQUEADA. La transmisión está
 * deshabilitada por defecto; cuando se habilita en tests se usa Http::fake. NUNCA
 * se transmite a Hacienda real, no se persiste sello, no se cambia estado a aceptado.
 */
class DteTransmisionTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private const JWS = 'eyJhbGciOiJSUzI1NiJ9.eyJkdGUiOiJmYWtlIn0.firma-falsa';

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedCatalogosDte();

        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
    }

    private function servicio(): DteTransmisionService
    {
        return app(DteTransmisionService::class);
    }

    private function habilitarTransmision(): void
    {
        config()->set('dte.transmision.enabled', true);
        // Abrir todos los candados de seguridad (estos tests prueban el camino HTTP).
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);
        config()->set('dte.transmision.sistema_actual_activo', false);
        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.allow_production', false);
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.url_base', 'https://recepcion.test');
        config()->set('dte.transmision.endpoint_recepcion', '/fesv/recepciondte');
        config()->set('dte.transmision.token', 'TOKEN_FAKE_NO_REAL');
    }

    private function ccfGenerado(): Dte
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
        ]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    private function ccfFirmado(): Dte
    {
        $ccf = $this->ccfGenerado();
        $cg = 'B58C589F-F27A-43EE-8EE8-A6E9B4C968BF';
        $rutaJson = 'dte/json/dte-03-'.$ccf->id.'-'.$cg.'.json';
        $rutaJws = 'dte/firmados/dte-03-'.$ccf->id.'-'.$cg.'.jws';
        Storage::disk('local')->put($rutaJson, '{"identificacion":{"codigoGeneracion":"'.$cg.'"}}');
        Storage::disk('local')->put($rutaJws, self::JWS);

        $ccf->numero_control = 'DTE-03-M001P001-000000000000012';
        $ccf->codigo_generacion = $cg;
        $ccf->json_generado_path = $rutaJson;
        $ccf->json_firmado_path = $rutaJws;
        // El flujo alineado exige estado Firmado para transmitir (firmar primero).
        $ccf->estado = EstadoDte::Firmado;
        $ccf->save();

        return $ccf->refresh();
    }

    // --- Bloqueo por configuración / precondiciones ---

    public function test_no_transmite_si_deshabilitada(): void
    {
        config()->set('dte.transmision.enabled', false);
        Http::fake();
        $ccf = $this->ccfFirmado();

        try {
            $this->servicio()->transmitir($ccf);
            $this->fail('Debió lanzar DteTransmisionDeshabilitadaException.');
        } catch (DteTransmisionDeshabilitadaException $e) {
            $this->assertStringContainsString('No se envió nada', $e->getMessage());
        }

        Http::assertNothingSent(); // no hubo request HTTP
        $ccf->refresh();
        $this->assertNull($ccf->sello_recepcion);             // no se guardó sello
        $this->assertSame(EstadoDte::Firmado, $ccf->estado);  // bloqueada: sigue Firmado, no avanza
    }

    public function test_no_transmite_sin_json_firmado(): void
    {
        $this->habilitarTransmision();
        $ccf = $this->ccfGenerado(); // sin json_firmado_path

        $this->expectException(DteTransmisionException::class);
        $this->servicio()->transmitir($ccf);
    }

    public function test_no_transmite_si_jws_no_existe(): void
    {
        $this->habilitarTransmision();
        $ccf = $this->ccfFirmado();
        Storage::disk('local')->delete($ccf->json_firmado_path);

        $this->expectException(DteTransmisionException::class);
        $this->servicio()->transmitir($ccf);
    }

    public function test_no_transmite_si_ya_tiene_sello(): void
    {
        $this->habilitarTransmision();
        $ccf = $this->ccfFirmado();
        $ccf->sello_recepcion = 'SELLO-EXISTENTE';
        $ccf->save();

        $this->expectException(DteTransmisionException::class);
        $this->servicio()->transmitir($ccf->refresh());
    }

    public function test_no_transmite_si_aceptado(): void
    {
        $this->habilitarTransmision();
        $ccf = $this->ccfFirmado();
        $ccf->estado = EstadoDte::Aceptado;
        $ccf->save();

        $this->expectException(DteTransmisionException::class);
        $this->servicio()->transmitir($ccf->refresh());
    }

    public function test_no_transmite_si_invalidado(): void
    {
        $this->habilitarTransmision();
        $ccf = $this->ccfFirmado();
        $ccf->estado = EstadoDte::Invalidado;
        $ccf->save();

        $this->expectException(DteTransmisionException::class);
        $this->servicio()->transmitir($ccf->refresh());
    }

    // --- Respuestas simuladas con Http::fake ---

    public function test_aceptado_persiste_sello_y_avanza_a_aceptado(): void
    {
        $this->habilitarTransmision();
        Http::fake(['*' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-SIMULADO', 'descripcionMsg' => 'RECIBIDO'], 200)]);
        $ccf = $this->ccfFirmado();

        $r = $this->servicio()->transmitir($ccf);

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertSame('SELLO-SIMULADO', $r['sello']);
        // Respuesta definitiva aceptada: persiste sello y avanza Firmado → Enviado → Aceptado.
        $ccf->refresh();
        $this->assertSame('SELLO-SIMULADO', $ccf->sello_recepcion);
        $this->assertSame(EstadoDte::Aceptado, $ccf->estado);
    }

    public function test_simula_respuesta_rechazada(): void
    {
        $this->habilitarTransmision();
        Http::fake(['*' => Http::response(['estado' => 'RECHAZADO', 'descripcionMsg' => 'Documento inválido'], 200)]);
        $ccf = $this->ccfFirmado();

        $r = $this->servicio()->transmitir($ccf);

        $this->assertSame('rechazado', $r['resultado']);
        $ccf->refresh();
        $this->assertNull($ccf->sello_recepcion);            // rechazado: sin sello
        $this->assertSame(EstadoDte::Rechazado, $ccf->estado); // avanza Firmado → Enviado → Rechazado
    }

    public function test_simula_error_de_conexion(): void
    {
        $this->habilitarTransmision();
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });
        $ccf = $this->ccfFirmado();

        $r = $this->servicio()->transmitir($ccf);

        $this->assertSame('error_conexion', $r['resultado']);
        $this->assertNull($ccf->refresh()->sello_recepcion);
    }

    public function test_simula_token_invalido(): void
    {
        $this->habilitarTransmision();
        Http::fake(['*' => Http::response('', 401)]);
        $ccf = $this->ccfFirmado();

        $r = $this->servicio()->transmitir($ccf);

        $this->assertSame('token_invalido', $r['resultado']);
    }

    public function test_simula_respuesta_malformada(): void
    {
        $this->habilitarTransmision();
        Http::fake(['*' => Http::response('no es json', 200)]);
        $ccf = $this->ccfFirmado();

        $r = $this->servicio()->transmitir($ccf);

        $this->assertSame('respuesta_malformada', $r['resultado']);
    }

    public function test_no_toca_calculos_ni_detalles(): void
    {
        $this->habilitarTransmision();
        Http::fake(['*' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'S1'], 200)]);
        $ccf = $this->ccfFirmado();
        $totalAntes = $ccf->total_pagar;
        $lineasAntes = $ccf->lineas()->count();

        $this->servicio()->transmitir($ccf);
        $ccf->refresh();

        $this->assertSame($totalAntes, $ccf->total_pagar);
        $this->assertSame($lineasAntes, $ccf->lineas()->count());
    }

    public function test_payload_recepcion_segun_manual(): void
    {
        $ccf = $this->ccfFirmado();

        $payload = $this->servicio()->prepararPayloadRecepcion($ccf);

        // Campos del body uno-a-uno (Manual 4.2.1).
        $this->assertSame(self::JWS, $payload['documento']);
        $this->assertSame($ccf->codigo_generacion, $payload['codigoGeneracion']);
        $this->assertSame('03', $payload['tipoDte']);
        $this->assertIsInt($payload['idEnvio']);
        $this->assertSame('00', $payload['ambiente']);
        // numeroControl NO va en el body (viaja dentro del JWS firmado).
        $this->assertArrayNotHasKey('numeroControl', $payload);
    }

    public function test_envia_headers_authorization_y_user_agent(): void
    {
        $this->habilitarTransmision();
        Http::fake(['*' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'S1'], 200)]);
        $ccf = $this->ccfFirmado();

        $this->servicio()->transmitir($ccf);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer TOKEN_FAKE_NO_REAL')
                && $request->hasHeader('User-Agent');
        });
    }

    public function test_transmitir_usa_token_obtenido_del_login(): void
    {
        $this->habilitarTransmision();
        config()->set('dte.transmision.token', '');          // sin override → login real
        config()->set('dte.transmision.usuario_api', 'facturador01');
        config()->set('dte.transmision.password', 'PW_FAKE');
        Http::fake([
            '*/seguridad/auth' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer LOGINTOKEN']], 200),
            '*/fesv/recepciondte' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'S1'], 200),
        ]);
        $ccf = $this->ccfFirmado();

        $r = $this->servicio()->transmitir($ccf);

        $this->assertSame('aceptado', $r['resultado']);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/fesv/recepciondte')
            && $req->hasHeader('Authorization', 'Bearer LOGINTOKEN'));
        $this->assertSame('S1', $ccf->refresh()->sello_recepcion); // aceptado: persiste el sello recibido
    }

    public function test_rechazado_con_http_400_se_clasifica_por_estado(): void
    {
        $this->habilitarTransmision();
        Http::fake(['*' => Http::response(['estado' => 'RECHAZADO', 'selloRecibido' => null, 'descripcionMsg' => 'ERROR', 'observaciones' => ['campo X invalido']], 400)]);
        $ccf = $this->ccfFirmado();

        $r = $this->servicio()->transmitir($ccf);

        $this->assertSame('rechazado', $r['resultado']);
        $this->assertSame(['campo X invalido'], $r['observaciones']);
        $ccf->refresh();
        $this->assertNull($ccf->sello_recepcion);
        $this->assertSame(EstadoDte::Rechazado, $ccf->estado);
    }

    public function test_procesado_con_observaciones_sigue_aceptado(): void
    {
        $this->habilitarTransmision();
        Http::fake(['*' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO', 'descripcionMsg' => 'RECIBIDO CON OBSERVACIONES', 'observaciones' => ['fecEmi difiere']], 200)]);
        $ccf = $this->ccfFirmado();

        $r = $this->servicio()->transmitir($ccf);

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertSame(['fecEmi difiere'], $r['observaciones']);
        $this->assertSame('SELLO', $ccf->refresh()->sello_recepcion); // aceptado con observaciones: persiste sello
    }

    // --- Vía dedicada de pruebas (apitest) + almacenamiento de respuesta ---

    public function test_via_de_pruebas_habilita_apitest_y_guarda_respuesta(): void
    {
        // SOLO el flag de pruebas + ambiente testing; los candados de producción
        // (enabled/real_confirmation/dry_run/modo) quedan en sus defaults cerrados.
        config()->set('dte.transmision.test_enabled', true);
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.url_base', 'https://apitest.dtes.mh.gob.sv');
        config()->set('dte.transmision.endpoint_recepcion', '/fesv/recepciondte');
        config()->set('dte.transmision.token', 'TOKEN_FAKE');

        Http::fake(['*' => Http::response([
            'estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-APITEST', 'descripcionMsg' => 'RECIBIDO',
            'codigoMsg' => '001', 'fhProcesamiento' => '19/06/2026 10:30:45',
        ], 200)]);
        $ccf = $this->ccfFirmado();

        $r = $this->servicio()->transmitir($ccf);

        $this->assertSame('aceptado', $r['resultado']);
        $ccf->refresh();
        $this->assertSame('SELLO-APITEST', $ccf->sello_recepcion);
        $this->assertSame(EstadoDte::Aceptado, $ccf->estado);
        // Respuesta completa persistida (columna + archivo crudo en disco).
        $this->assertNotNull($ccf->respuesta_mh_path);
        $this->assertTrue(Storage::disk('local')->exists($ccf->respuesta_mh_path));
        $this->assertSame('001', $ccf->respuesta_mh['codigoMsg']);
        $this->assertSame('SELLO-APITEST', $ccf->respuesta_mh['selloRecibido']);
        $this->assertSame('2026-06-19', $ccf->fecha_procesamiento_mh->toDateString());
    }

    public function test_via_de_pruebas_no_aplica_a_produccion(): void
    {
        // Aunque test_enabled=true, si el ambiente es producción NO abre la vía.
        config()->set('dte.transmision.test_enabled', true);
        config()->set('dte.transmision.ambiente', 'produccion');
        Http::fake();
        $ccf = $this->ccfFirmado();

        $this->expectException(DteTransmisionDeshabilitadaException::class);
        $this->servicio()->transmitir($ccf);
    }

    public function test_rechazado_guarda_respuesta_con_motivo(): void
    {
        $this->habilitarTransmision();
        Http::fake(['*' => Http::response([
            'estado' => 'RECHAZADO', 'descripcionMsg' => 'Documento inválido',
            'codigoMsg' => '002', 'observaciones' => ['NRC no existe'],
        ], 200)]);
        $ccf = $this->ccfFirmado();

        $this->servicio()->transmitir($ccf);
        $ccf->refresh();

        $this->assertSame(EstadoDte::Rechazado, $ccf->estado);
        $this->assertNull($ccf->sello_recepcion);
        $this->assertSame('002', $ccf->respuesta_mh['codigoMsg']);
        $this->assertSame(['NRC no existe'], $ccf->respuesta_mh['observaciones']);
        $this->assertTrue(Storage::disk('local')->exists($ccf->respuesta_mh_path));
    }

    // --- Comandos ---

    public function test_comando_transmision_check_muestra_bloqueada(): void
    {
        config()->set('dte.transmision.enabled', false);
        $ccf = $this->ccfFirmado();

        $this->artisan('dte:transmision-check', ['dte' => $ccf->id])
            ->expectsOutputToContain('BLOQUEADA')
            ->expectsOutputToContain('NO TRANSMITE / SOLO DIAGNÓSTICO')
            ->assertExitCode(0);
    }

    public function test_comando_transmitir_bloqueado_si_deshabilitado(): void
    {
        config()->set('dte.transmision.enabled', false);
        Http::fake();
        $ccf = $this->ccfFirmado();

        $this->artisan('dte:transmitir', ['dte' => $ccf->id])
            ->expectsOutputToContain('Transmisión deshabilitada. No se envió nada a Hacienda.')
            ->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertNull($ccf->refresh()->sello_recepcion);
    }
}
