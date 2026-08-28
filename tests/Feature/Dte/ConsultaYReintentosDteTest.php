<?php

namespace Tests\Feature\Dte;

use App\Enums\AmbienteHacienda;
use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Exceptions\Dte\DteTransmisionException;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteConsultaService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteTransmisionAuthService;
use App\Services\Dte\DteTransmisionResiliente;
use App\Support\Dte\EndpointsHacienda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * CONSULTA INDIVIDUAL y POLÍTICA DE REINTENTOS previa a contingencia.
 *
 * LO QUE ESTOS TESTS PROTEGEN NO ES "QUE EL DTE ENTRE". Es que no entre dos veces.
 * Cuando recepción no responde, el documento pudo haber sido recibido igual —la
 * petición se perdió de vuelta, no de ida—. Reenviar a ciegas ahí transmite el mismo
 * hecho económico dos veces, con dos códigos de generación, y eso no se deshace: se
 * corrige invalidando ante Hacienda. Por eso la propiedad central que se fija acá es
 * **antes de cada reenvío se consulta**, y **ante la duda no se reenvía**.
 *
 * NINGUNA PETICIÓN SALE A LA RED. El candado global de {@see TestCase} lo garantiza y
 * varias pruebas lo comprueban con `Http::assertNothingSent()` / conteo de intentos.
 * Nada acá activa contingencia: cuando los reintentos se agotan, se verifica
 * justamente que NO se haya creado ni transmitido ningún evento.
 */
class ConsultaYReintentosDteTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private const JWS = 'eyJhbGciOiJSUzI1NiJ9.eyJkdGUiOiJmYWtlIn0.firma-falsa';

    private const CG = 'B58C589F-F27A-43EE-8EE8-A6E9B4C968BF';

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedCatalogosDte();

        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id, 'ambiente' => '00',
            'ultimo_numero' => 0, 'activo' => true,
        ]);
    }

    /**
     * Abre los candados para que el camino HTTP se ejecute. Deja un token manual
     * FICTICIO puesto para que ninguna prueba tenga que autenticarse: lo que se está
     * probando es la política, no el login.
     */
    private function habilitarTransmision(): void
    {
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);
        config()->set('dte.transmision.sistema_actual_activo', false);
        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.allow_production', false);
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.token', 'Bearer TOKEN_FAKE_NO_REAL');
    }

    private function ccfFirmado(): Dte
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $producto = Producto::factory()->create([
            'precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
        ]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);
        app(DteGeneracionService::class)->generar($dte);
        $dte->refresh();

        $rutaJson = 'dte/json/dte-03-'.$dte->id.'-'.self::CG.'.json';
        $rutaJws = 'dte/firmados/dte-03-'.$dte->id.'-'.self::CG.'.jws';
        Storage::disk('local')->put($rutaJson, '{"identificacion":{"codigoGeneracion":"'.self::CG.'"}}');
        Storage::disk('local')->put($rutaJws, self::JWS);

        $dte->numero_control = 'DTE-03-M001P001-000000000000012';
        $dte->codigo_generacion = self::CG;
        $dte->json_generado_path = $rutaJson;
        $dte->json_firmado_path = $rutaJws;
        $dte->estado = EstadoDte::Firmado;
        $dte->save();

        return $dte->refresh();
    }

    private function consulta(): DteConsultaService
    {
        return app(DteConsultaService::class);
    }

    private function resiliente(): DteTransmisionResiliente
    {
        return app(DteTransmisionResiliente::class);
    }

    /**
     * Respuesta de recepción aceptada, con sello.
     *
     * El tipo de retorno es PromiseInterface, no Response: `Http::response()` devuelve
     * una promesa. Tiparlo mal no daba un error visible — el TypeError caía dentro del
     * `catch (Throwable)` de DteTransmisionService y salía disfrazado de 'error_conexion',
     * es decir, el test "fallaba" simulando justo lo contrario de lo que quería probar.
     */
    private function recepcionAceptada(): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response([
            'estado' => 'PROCESADO',
            'selloRecibido' => '2026SELLOFICTICIO000000000000000000001',
            'descripcionMsg' => 'Recibido',
        ], 200);
    }

    // ================================================== CONSULTA INDIVIDUAL

    public function test_la_consulta_usa_el_endpoint_oficial_de_cada_ambiente(): void
    {
        $this->assertSame(
            'https://apitest.dtes.mh.gob.sv/fesv/recepcion/consultadte',
            EndpointsHacienda::consulta(AmbienteHacienda::Pruebas)
        );
        $this->assertSame(
            'https://api.dtes.mh.gob.sv/fesv/recepcion/consultadte',
            EndpointsHacienda::consulta(AmbienteHacienda::Produccion)
        );
        // La referencia oficial no la mueve ningún override.
        config()->set('dte.transmision.url_base', 'https://otro.example');
        $this->assertSame(
            'https://api.dtes.mh.gob.sv/fesv/recepcion/consultadte',
            EndpointsHacienda::consultaOficial(AmbienteHacienda::Produccion)
        );
    }

    public function test_el_cuerpo_de_la_consulta_lleva_los_tres_campos_del_documento(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();

        $body = $this->consulta()->prepararPayload($dte);

        $this->assertSame(['nitEmisor', 'tdte', 'codigoGeneracion'], array_keys($body));
        $this->assertSame('03', $body['tdte']);
        $this->assertSame(self::CG, $body['codigoGeneracion']);
        // NIT solo dígitos: el del EMISOR del documento, no el de configuración.
        $this->assertMatchesRegularExpression('/^\d+$/', $body['nitEmisor']);
    }

    public function test_la_consulta_envia_authorization_y_user_agent(): void
    {
        $this->habilitarTransmision();
        config()->set('dte.transmision.user_agent', 'AgenteDePrueba/9.9');
        Http::fake(['*' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'X'], 200)]);
        $dte = $this->ccfFirmado();

        $this->consulta()->consultar($dte);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/fesv/recepcion/consultadte')
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization')
                && $request->hasHeader('User-Agent', 'AgenteDePrueba/9.9')
                && str_contains(strtolower($request->header('Content-Type')[0] ?? ''), 'json');
        });
    }

    public function test_la_consulta_interpreta_un_dte_aceptado(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        Http::fake(['*' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO1'], 200)]);

        $r = $this->consulta()->consultar($dte);

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertTrue($r['recibido']);
        $this->assertSame('SELLO1', $r['sello']);
    }

    /**
     * Rechazado también cuenta como RECIBIDO. Es la parte contraintuitiva y la que más
     * importa: el MH ya tiene el documento, así que reenviarlo duplicaría igual que si
     * lo hubiera aceptado.
     */
    public function test_la_consulta_trata_un_rechazo_como_recibido(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        Http::fake(['*' => Http::response(['estado' => 'RECHAZADO', 'descripcionMsg' => 'no válido'], 200)]);

        $r = $this->consulta()->consultar($dte);

        $this->assertSame('rechazado', $r['resultado']);
        $this->assertTrue($r['recibido']);
        $this->assertNull($r['sello']);
    }

    public function test_la_consulta_interpreta_un_dte_no_encontrado(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        Http::fake(['*' => Http::response([], 404)]);

        $r = $this->consulta()->consultar($dte);

        $this->assertSame('no_encontrado', $r['resultado']);
        $this->assertFalse($r['recibido']);
    }

    /** Si no se pudo preguntar, la respuesta NO es "no recibido": es "no se sabe". */
    public function test_si_la_consulta_no_responde_no_afirma_que_no_fue_recibido(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        Http::fake(function () {
            throw new ConnectionException('timeout simulado');
        });

        $r = $this->consulta()->consultar($dte);

        $this->assertSame('error_conexion', $r['resultado']);
        $this->assertFalse($r['recibido']);
    }

    public function test_la_consulta_esta_bloqueada_si_la_integracion_esta_apagada(): void
    {
        Http::fake();
        config()->set('dte.transmision.enabled', false);
        config()->set('dte.transmision.test_enabled', false);
        $dte = $this->ccfFirmado();

        $this->expectException(DteTransmisionDeshabilitadaException::class);
        try {
            $this->consulta()->consultar($dte);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_la_consulta_no_cambia_el_estado_del_documento(): void
    {
        $this->habilitarTransmision();
        Http::fake(['*' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO'], 200)]);
        $dte = $this->ccfFirmado();

        $this->consulta()->consultar($dte);

        $dte->refresh();
        $this->assertSame(EstadoDte::Firmado, $dte->estado);
        $this->assertNull($dte->sello_recepcion);
    }

    // ============================================== POLÍTICA DE REINTENTOS

    /**
     * EL CASO QUE JUSTIFICA TODO. Recepción no responde, pero el documento SÍ había
     * entrado. No se puede reenviar: hay que quedarse con la respuesta de la consulta.
     */
    public function test_sin_respuesta_y_ya_recibido_no_reenvia_y_toma_el_sello(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        $enviosRecepcion = 0;

        Http::fake(function ($request) use (&$enviosRecepcion) {
            if (str_contains($request->url(), 'recepciondte')) {
                $enviosRecepcion++;
                throw new ConnectionException('sin respuesta');
            }

            return Http::response([
                'estado' => 'PROCESADO',
                'selloRecibido' => '2026SELLODELACONSULTA',
                'descripcionMsg' => 'Recibido',
            ], 200);
        });

        $r = $this->resiliente()->transmitir($dte);

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertSame(1, $enviosRecepcion, 'No debía reenviarse: el MH ya lo tenía.');
        $this->assertSame(1, $r['consultas']);
        $this->assertFalse($r['contingencia_requerida']);
        // El sello de la consulta se persistió por el camino normal.
        $dte->refresh();
        $this->assertSame('2026SELLODELACONSULTA', $dte->sello_recepcion);
        $this->assertSame(EstadoDte::Aceptado, $dte->estado);
    }

    /** Sin respuesta y el MH confirma que NO lo tiene: ahí sí se reenvía. */
    public function test_sin_respuesta_y_no_recibido_reenvia_una_vez(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        $envios = 0;

        Http::fake(function ($request) use (&$envios) {
            if (str_contains($request->url(), 'recepciondte')) {
                $envios++;
                if ($envios === 1) {
                    throw new ConnectionException('sin respuesta');
                }

                return $this->recepcionAceptada();
            }

            return Http::response([], 404); // no encontrado
        });

        $r = $this->resiliente()->transmitir($dte);

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertSame(2, $envios, 'Debía haber exactamente un reenvío.');
        $this->assertSame(2, $r['envios']);
        $this->assertSame(1, $r['consultas']);
        $this->assertSame(EstadoDte::Aceptado, $dte->refresh()->estado);
    }

    /** Falla dos veces: consulta, reenvía, vuelve a fallar, consulta, reenvía y entra. */
    public function test_dos_fallos_seguidos_llegan_hasta_el_segundo_reenvio(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        $envios = 0;
        $consultas = 0;

        Http::fake(function ($request) use (&$envios, &$consultas) {
            if (str_contains($request->url(), 'recepciondte')) {
                $envios++;
                if ($envios <= 2) {
                    throw new ConnectionException('sin respuesta');
                }

                return $this->recepcionAceptada();
            }
            $consultas++;

            return Http::response([], 404);
        });

        $r = $this->resiliente()->transmitir($dte);

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertSame(3, $envios, 'Envío inicial + 2 reenvíos.');
        $this->assertSame(2, $consultas, 'Una consulta antes de cada reenvío.');
        $this->assertFalse($r['contingencia_requerida']);
    }

    /**
     * Nunca responde y el MH nunca lo tiene: se agotan los reintentos. El resultado
     * es explícito y NO se activa contingencia.
     */
    public function test_al_agotar_los_reintentos_devuelve_error_explicito_sin_activar_contingencia(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        $envios = 0;

        Http::fake(function ($request) use (&$envios) {
            if (str_contains($request->url(), 'recepciondte')) {
                $envios++;
                throw new ConnectionException('sin respuesta');
            }

            return Http::response([], 404);
        });

        $r = $this->resiliente()->transmitir($dte);

        $this->assertSame('reintentos_agotados', $r['resultado']);
        $this->assertTrue($r['contingencia_requerida']);
        $this->assertStringContainsString('contingencia requerida', $r['mensaje']);
        $this->assertStringContainsString('NO se activó', $r['mensaje']);

        // NUNCA más de 3 envíos (inicial + 2 reenvíos).
        $this->assertSame(3, $envios);
        $this->assertSame(3, $r['envios']);

        // Y el documento NO se movió: sigue firmado, sin sello.
        $dte->refresh();
        $this->assertSame(EstadoDte::Firmado, $dte->estado);
        $this->assertNull($dte->sello_recepcion);

        // Ningún evento de contingencia: no se tocó /fesv/contingencia ni el lote.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'contingencia')
            || str_contains($request->url(), 'recepcionlote'));
    }

    /** El límite es configurable, y bajarlo a 0 significa un solo envío. */
    public function test_el_maximo_de_reenvios_es_configurable_y_se_respeta(): void
    {
        $this->habilitarTransmision();
        config()->set('dte.transmision.reintentos.max_reenvios', 0);
        $dte = $this->ccfFirmado();
        $envios = 0;

        Http::fake(function ($request) use (&$envios) {
            if (str_contains($request->url(), 'recepciondte')) {
                $envios++;
                throw new ConnectionException('sin respuesta');
            }

            return Http::response([], 404);
        });

        $r = $this->resiliente()->transmitir($dte);

        $this->assertSame(1, $envios, 'Con max_reenvios=0 no puede haber reenvíos.');
        $this->assertSame('reintentos_agotados', $r['resultado']);
    }

    /**
     * Silencio en recepción Y en la consulta: no se sabe si entró. NO se reenvía —el
     * riesgo de duplicar pesa más que el de no haber enviado, porque el duplicado ya
     * quedó emitido y hay que invalidarlo.
     */
    public function test_si_no_se_puede_consultar_no_reenvia_para_no_duplicar(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        $envios = 0;

        Http::fake(function ($request) use (&$envios) {
            if (str_contains($request->url(), 'recepciondte')) {
                $envios++;
            }
            throw new ConnectionException('todo caído');
        });

        $r = $this->resiliente()->transmitir($dte);

        $this->assertSame('estado_recepcion_incierto', $r['resultado']);
        $this->assertSame(1, $envios, 'Sin poder determinar el estado, no se reenvía.');
        $this->assertFalse($r['contingencia_requerida']);
        $this->assertStringContainsString('duplicar', $r['mensaje']);
        // El documento queda intacto: ni sello ni cambio de estado.
        $dte->refresh();
        $this->assertNull($dte->sello_recepcion);
        $this->assertSame(EstadoDte::Firmado, $dte->estado);
    }

    /** Una respuesta definitiva a la primera no dispara ni consultas ni reenvíos. */
    public function test_una_respuesta_definitiva_no_dispara_la_politica(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        Http::fake(['*' => $this->recepcionAceptada()]);

        $r = $this->resiliente()->transmitir($dte);

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertSame(1, $r['envios']);
        $this->assertSame(0, $r['consultas']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'consultadte'));
    }

    /**
     * Un rechazo es una respuesta, no un fallo: se aplica y se termina. Reintentar un
     * rechazo sería reenviar un documento que el MH ya evaluó.
     */
    public function test_un_rechazo_no_se_reintenta(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        $envios = 0;

        Http::fake(function ($request) use (&$envios) {
            $envios++;

            return Http::response(['estado' => 'RECHAZADO', 'descripcionMsg' => 'esquema inválido'], 200);
        });

        $r = $this->resiliente()->transmitir($dte);

        $this->assertSame('rechazado', $r['resultado']);
        $this->assertSame(1, $envios);
        $this->assertSame(EstadoDte::Rechazado, $dte->refresh()->estado);
    }

    /**
     * Un HTTP 500 o un token rechazado SON respuestas: el servidor contestó. No entran
     * en la política —insistir ahí no es lo que describe el manual y con un 500 podría
     * duplicar—.
     */
    public function test_un_error_http_del_servidor_no_dispara_reenvios(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        $envios = 0;

        Http::fake(function ($request) use (&$envios) {
            $envios++;

            return Http::response(['mensaje' => 'boom'], 500);
        });

        $r = $this->resiliente()->transmitir($dte);

        $this->assertSame(1, $envios, 'Un 500 es una respuesta, no silencio.');
        $this->assertSame(0, $r['consultas']);
        $this->assertNotSame('reintentos_agotados', $r['resultado']);
    }

    /** Con la política apagada, el comportamiento es exactamente el de antes. */
    public function test_con_la_politica_apagada_solo_hay_un_envio(): void
    {
        $this->habilitarTransmision();
        config()->set('dte.transmision.reintentos.enabled', false);
        $dte = $this->ccfFirmado();
        $envios = 0;

        Http::fake(function ($request) use (&$envios) {
            $envios++;
            throw new ConnectionException('sin respuesta');
        });

        $r = $this->resiliente()->transmitir($dte);

        $this->assertSame('error_conexion', $r['resultado']);
        $this->assertSame(1, $envios);
        $this->assertSame(0, $r['consultas']);
    }

    /**
     * El reenvío es del MISMO documento. Si el código de generación o el número de
     * control cambiaran, un reintento se habría convertido en un documento nuevo.
     */
    public function test_los_reenvios_no_regeneran_codigo_de_generacion_ni_numero_de_control(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        $cgOriginal = $dte->codigo_generacion;
        $ncOriginal = $dte->numero_control;
        $vistos = [];

        Http::fake(function ($request) use (&$vistos) {
            if (str_contains($request->url(), 'recepciondte')) {
                $vistos[] = $request->data()['codigoGeneracion'] ?? null;
                throw new ConnectionException('sin respuesta');
            }

            return Http::response([], 404);
        });

        $this->resiliente()->transmitir($dte);

        $this->assertCount(3, $vistos);
        $this->assertSame([$cgOriginal, $cgOriginal, $cgOriginal], $vistos);
        $dte->refresh();
        $this->assertSame($cgOriginal, $dte->codigo_generacion);
        $this->assertSame($ncOriginal, $dte->numero_control);
    }

    /** Los candados siguen mandando: con la transmisión apagada no se envía nada. */
    public function test_la_politica_no_abre_ningun_candado(): void
    {
        Http::fake();
        config()->set('dte.transmision.enabled', false);
        config()->set('dte.transmision.test_enabled', false);
        $dte = $this->ccfFirmado();

        $this->expectException(DteTransmisionDeshabilitadaException::class);
        try {
            $this->resiliente()->transmitir($dte);
        } finally {
            Http::assertNothingSent();
        }
    }

    // ============================== CASO 2 DEL MANUAL (estado incierto)

    /**
     * Caso 2: el desenlace del intento anterior no se conoce. Se consulta ANTES del
     * primer envio; si el MH ya lo tenia, no sale ninguna peticion de recepcion.
     */
    public function test_estado_incierto_consulta_antes_de_enviar_y_no_envia_si_ya_entro(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        $envios = 0;
        $consultas = 0;

        Http::fake(function ($request) use (&$envios, &$consultas) {
            if (str_contains($request->url(), 'consultadte')) {
                $consultas++;

                return Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLOPREVIO'], 200);
            }
            $envios++;

            return $this->recepcionAceptada();
        });

        $r = $this->resiliente()->transmitir($dte, estadoIncierto: true);

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertSame(1, $consultas);
        $this->assertSame(0, $envios, 'No debia enviarse: ya habia entrado.');
        $this->assertSame(0, $r['envios']);
        $this->assertSame('SELLOPREVIO', $dte->refresh()->sello_recepcion);
    }

    /** Caso 2 con respuesta negativa: se envia, porque no hay riesgo de duplicar. */
    public function test_estado_incierto_envia_si_la_consulta_previa_dice_que_no_entro(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        $envios = 0;

        Http::fake(function ($request) use (&$envios) {
            if (str_contains($request->url(), 'consultadte')) {
                return Http::response([], 404);
            }
            $envios++;

            return $this->recepcionAceptada();
        });

        $r = $this->resiliente()->transmitir($dte, estadoIncierto: true);

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertSame(1, $envios);
    }

    /**
     * CONSULTA PREVIA INCONCLUSA → NO SE ENVÍA.
     *
     * Esta prueba fija una corrección de criterio. Antes, si la consulta previa fallaba
     * se enviaba igual, razonando que no había ninguna petición en curso. El
     * razonamiento estaba mal: el manual manda reenviar cuando se DETERMINA que no fue
     * recibido, y una consulta que falla no determina nada. Los dos errores no son
     * simétricos — no enviar se arregla enviando más tarde; enviar dos veces deja un
     * duplicado emitido que hay que invalidar ante Hacienda.
     */
    public function test_si_la_consulta_previa_no_concluye_no_se_envia(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        $envios = 0;

        Http::fake(function ($request) use (&$envios) {
            if (str_contains($request->url(), 'consultadte')) {
                return Http::response(['mensaje' => 'boom'], 500);
            }
            $envios++;

            return $this->recepcionAceptada();
        });

        $r = $this->resiliente()->transmitir($dte, estadoIncierto: true);

        $this->assertSame('estado_recepcion_incierto', $r['resultado']);
        $this->assertSame(0, $envios, 'No se puede enviar sin haber determinado el estado.');
        $this->assertSame(0, $r['envios']);
        $this->assertFalse($r['contingencia_requerida']);
        $this->assertStringContainsString('no es seguro reenviarlo', $r['mensaje']);
        $this->assertStringContainsString('NO se activó contingencia', $r['mensaje']);

        // El documento no se tocó.
        $dte->refresh();
        $this->assertNull($dte->sello_recepcion);
        $this->assertSame(EstadoDte::Firmado, $dte->estado);
    }

    /** Lo mismo cuando la consulta previa ni siquiera llega a conectar. */
    public function test_si_la_consulta_previa_no_conecta_tampoco_se_envia(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        $envios = 0;

        Http::fake(function ($request) use (&$envios) {
            if (str_contains($request->url(), 'recepciondte')) {
                $envios++;
            }
            throw new ConnectionException('consulta caída');
        });

        $r = $this->resiliente()->transmitir($dte, estadoIncierto: true);

        $this->assertSame('estado_recepcion_incierto', $r['resultado']);
        $this->assertSame(0, $envios);
        $this->assertSame(EstadoDte::Firmado, $dte->refresh()->estado);
    }

    /**
     * Y el corte NO se salta la numeración: ni el código de generación ni el número de
     * control cambian cuando la política se detiene por incertidumbre.
     */
    public function test_el_corte_por_incertidumbre_no_altera_la_numeracion(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        $cg = $dte->codigo_generacion;
        $nc = $dte->numero_control;

        Http::fake(fn () => Http::response(['mensaje' => 'boom'], 500));

        $this->resiliente()->transmitir($dte, estadoIncierto: true);

        $dte->refresh();
        $this->assertSame($cg, $dte->codigo_generacion);
        $this->assertSame($nc, $dte->numero_control);
    }

    /** Sin la marca de incertidumbre, el comportamiento es el de siempre: no consulta. */
    public function test_sin_estado_incierto_no_hay_consulta_previa(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        Http::fake(['*' => $this->recepcionAceptada()]);

        $r = $this->resiliente()->transmitir($dte);

        $this->assertSame(0, $r['consultas']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'consultadte'));
    }

    /** En MOCK no se consulta nada: es el unico modo que promete no tocar la red. */
    public function test_en_modo_mock_el_estado_incierto_no_dispara_consulta(): void
    {
        $this->habilitarTransmision();
        config()->set('dte.transmision.mock', true);
        Http::fake();
        $dte = $this->ccfFirmado();

        $r = $this->resiliente()->transmitir($dte, estadoIncierto: true);

        $this->assertSame(0, $r['consultas']);
        Http::assertNothingSent();
    }

    /**
     * EL TOPE SE CUENTA SOBRE EL DOCUMENTO, NO SOBRE LA LLAMADA.
     *
     * Caso 2 con la consulta previa confirmando que el MH no lo tiene: se puede
     * reenviar, pero el envío inicial YA lo gastó el intento anterior. A esta llamada le
     * quedan 2 envíos, no 3. Si contara 3, dos pulsaciones del botón sumarían 4
     * transmisiones del mismo documento — justo lo que el tope existe para impedir.
     */
    public function test_estado_incierto_confirmado_no_recibido_no_supera_dos_reenvios(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        $envios = 0;
        $consultas = 0;

        Http::fake(function ($request) use (&$envios, &$consultas) {
            if (str_contains($request->url(), 'consultadte')) {
                $consultas++;

                return Http::response([], 404);
            }
            $envios++;
            throw new ConnectionException('sin respuesta');
        });

        $r = $this->resiliente()->transmitir($dte, estadoIncierto: true);

        $this->assertSame('reintentos_agotados', $r['resultado']);
        $this->assertSame(2, $envios, 'El envío inicial ya lo hizo el intento anterior: quedan 2.');
        $this->assertSame(2, $r['envios']);
        $this->assertSame(3, $consultas, 'La previa, más una antes de cada reenvío.');
        $this->assertTrue($r['contingencia_requerida']);

        // Y siguen siendo el mismo documento en los dos reenvíos.
        $dte->refresh();
        $this->assertSame(self::CG, $dte->codigo_generacion);
        $this->assertSame(EstadoDte::Firmado, $dte->estado);
    }

    /**
     * Borde del tope: con `max_reenvios = 0` y un Caso 2 confirmado NO recibido no queda
     * ningún envío permitido, así que no sale ninguno. No es un fallo del sistema, es el
     * tope funcionando — y el mensaje lo dice así en vez de "se hicieron 0 envíos".
     */
    public function test_sin_reenvios_permitidos_el_caso_dos_no_envia_nada(): void
    {
        $this->habilitarTransmision();
        config()->set('dte.transmision.reintentos.max_reenvios', 0);
        $dte = $this->ccfFirmado();
        $envios = 0;

        Http::fake(function ($request) use (&$envios) {
            if (str_contains($request->url(), 'consultadte')) {
                return Http::response([], 404);
            }
            $envios++;

            return $this->recepcionAceptada();
        });

        $r = $this->resiliente()->transmitir($dte, estadoIncierto: true);

        $this->assertSame(0, $envios, 'El envío inicial ya se hizo antes y no quedan reenvíos.');
        $this->assertSame('reintentos_agotados', $r['resultado']);
        $this->assertStringContainsString('No quedaba ningún reenvío permitido', $r['mensaje']);
        $this->assertTrue($r['contingencia_requerida']);
        $this->assertSame(EstadoDte::Firmado, $dte->refresh()->estado);
    }

    /**
     * El corte no obliga a nadie a deducir por qué se cortó: el resultado lo dice con
     * dos campos —`consulta_no_disponible` y el resultado crudo de la consulta—, y en
     * el camino normal esos campos dicen lo contrario.
     */
    public function test_el_corte_por_incertidumbre_lo_declara_en_el_resultado(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'consultadte')) {
                return Http::response(['mensaje' => 'boom'], 500);
            }

            return $this->recepcionAceptada();
        });

        $r = $this->resiliente()->transmitir($dte, estadoIncierto: true);

        $this->assertSame('estado_recepcion_incierto', $r['resultado']);
        $this->assertTrue($r['consulta_no_disponible']);
        $this->assertSame('error_http', $r['consulta_resultado'], 'Se conserva el motivo real.');

        // El corte lo dejó intacto, así que el MISMO documento sirve para comprobar el
        // contraste: por el camino normal esos campos dicen lo contrario.
        $this->assertSame(EstadoDte::Firmado, $dte->refresh()->estado);
        Http::fake(['*' => $this->recepcionAceptada()]);
        $ok = $this->resiliente()->transmitir($dte);

        $this->assertSame('aceptado', $ok['resultado']);
        $this->assertFalse($ok['consulta_no_disponible']);
        $this->assertNull($ok['consulta_resultado']);
    }

    /**
     * "Consulta que falla" incluye la que NO SE PUEDE NI FORMULAR. Si falta el código de
     * generación no hay nada que preguntarle al MH, y por lo tanto tampoco hay nada
     * determinado: no se envía, y el corte se devuelve como resultado en vez de escapar
     * como excepción.
     */
    public function test_si_la_consulta_previa_no_se_puede_ni_formular_no_se_envia(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        $dte->codigo_generacion = '';
        $dte->save();

        Http::fake();

        $r = $this->resiliente()->transmitir($dte, estadoIncierto: true);

        $this->assertSame('estado_recepcion_incierto', $r['resultado']);
        $this->assertTrue($r['consulta_no_disponible']);
        $this->assertNull($r['consulta_resultado'], 'No hubo consulta que dar un resultado.');
        $this->assertSame(0, $r['envios']);
        $this->assertFalse($r['contingencia_requerida']);
        Http::assertNothingSent();
    }

    /**
     * Lo mismo DENTRO del bucle: si la consulta que decide el reenvío no se puede
     * hacer, no hay reenvío. Acá el riesgo es más directo todavía —el envío mudo pudo
     * haber entrado— así que la política se detiene con un solo envío hecho.
     */
    public function test_si_la_consulta_del_bucle_no_se_puede_hacer_no_hay_otro_reenvio(): void
    {
        $this->habilitarTransmision();
        $dte = $this->ccfFirmado();
        $cg = $dte->codigo_generacion;
        $nc = $dte->numero_control;
        $envios = 0;

        // La consulta no llega a salir: falla como precondición, no como HTTP.
        $this->instance(DteConsultaService::class, new class(app(DteTransmisionAuthService::class)) extends DteConsultaService
        {
            public function consultar(Dte $dte): array
            {
                throw new DteTransmisionException('no se puede consultar este documento');
            }
        });

        Http::fake(function ($request) use (&$envios) {
            $envios++;
            throw new ConnectionException('sin respuesta');
        });

        $r = $this->resiliente()->transmitir($dte);

        $this->assertSame('estado_recepcion_incierto', $r['resultado']);
        $this->assertTrue($r['consulta_no_disponible']);
        $this->assertSame(1, $envios, 'Sin consulta no hay reenvío.');
        $this->assertSame(1, $r['envios']);
        $this->assertFalse($r['contingencia_requerida']);

        // El corte del bucle tampoco toca la numeración.
        $dte->refresh();
        $this->assertSame($cg, $dte->codigo_generacion);
        $this->assertSame($nc, $dte->numero_control);
        $this->assertSame(EstadoDte::Firmado, $dte->estado);
    }

    /**
     * El candado NO se degrada a resultado. Con la integración apagada, el Caso 2 no
     * devuelve 'estado_recepcion_incierto': lanza, como cualquier otro camino, porque
     * quien abre un candado es una persona y no un reintento.
     */
    public function test_el_candado_manda_tambien_en_el_camino_del_estado_incierto(): void
    {
        Http::fake();
        config()->set('dte.transmision.enabled', false);
        config()->set('dte.transmision.test_enabled', false);
        $dte = $this->ccfFirmado();

        $this->expectException(DteTransmisionDeshabilitadaException::class);
        try {
            $this->resiliente()->transmitir($dte, estadoIncierto: true);
        } finally {
            Http::assertNothingSent();
        }
    }

    // ==================================== USER-AGENT Y CANDADO DE ENDPOINT

    /**
     * El User-Agent es obligatorio en TODOS los servicios del MH y sale de una sola
     * clave de configuración. Se comprueba junto —auth, recepción y consulta en una
     * misma corrida— porque el riesgo real no es que falte en el que se está
     * escribiendo, sino que alguien añada un servicio nuevo y se olvide.
     */
    public function test_todos_los_servicios_del_mh_envian_el_user_agent_configurado(): void
    {
        $this->habilitarTransmision();
        config()->set('dte.transmision.token', '');   // fuerza el login real (fakeado)
        config()->set('dte.transmision.user_agent', 'AgenteUnico/1.0');
        config()->set('dte.transmision.usuario_testing', 'usuario-apitest-ficticio');
        config()->set('dte.transmision.password_testing', 'clave-apitest-ficticia');

        Http::fake([
            '*seguridad/auth' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer T']], 200),
            '*consultadte' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'S'], 200),
            '*' => $this->recepcionAceptada(),
        ]);

        $dte = $this->ccfFirmado();
        app(\App\Services\Dte\DteTransmisionService::class)->transmitir($dte);
        $this->consulta()->consultar($dte);

        foreach (['seguridad/auth', 'recepciondte', 'consultadte'] as $servicio) {
            Http::assertSent(fn ($request) => str_contains($request->url(), $servicio)
                && $request->hasHeader('User-Agent', 'AgenteUnico/1.0'));
        }
    }

    /**
     * BRECHA QUE ESTO CIERRA. La invalidación ya exigía que el endpoint productivo
     * fuera el oficial exacto; recepción no. Sin esta comprobación, un `url_base` mal
     * puesto mandaba un DTE de PRODUCCIÓN a un host cualquiera —y la numeración
     * oficial ya estaba gastada—.
     */
    public function test_produccion_con_endpoint_no_oficial_queda_bloqueada(): void
    {
        Http::fake();
        $this->habilitarTransmision();
        config()->set('dte.transmision.ambiente', 'produccion');
        config()->set('dte.transmision.allow_production', true);
        config()->set('dte.transmision.url_base', 'https://recepcion.impostora.test');

        $candados = app(\App\Services\Dte\DteTransmisionService::class)->evaluarCandados();

        $this->assertTrue($candados['bloqueado']);
        $this->assertStringContainsString('productivo exacto', implode(' ', $candados['razones']));
        Http::assertNothingSent();
    }

    /** Y con el host oficial, ese candado concreto deja de aparecer. */
    public function test_produccion_con_el_endpoint_oficial_no_dispara_ese_candado(): void
    {
        Http::fake();
        $this->habilitarTransmision();
        config()->set('dte.transmision.ambiente', 'produccion');
        config()->set('dte.transmision.allow_production', true);
        config()->set('dte.transmision.url_base', '');   // host oficial

        $candados = app(\App\Services\Dte\DteTransmisionService::class)->evaluarCandados();

        $this->assertStringNotContainsString('productivo exacto', implode(' ', $candados['razones']));
        Http::assertNothingSent();
    }

    /**
     * Apitest exige SU endpoint oficial, igual que producción exige el suyo. Antes este
     * ambiente admitía cualquier host «para facilitar las pruebas»; pero transmitir a
     * apitest es una operación real, con token real, y el aviso del MH no distingue
     * ambientes: solo endpoints publicados.
     */
    public function test_el_candado_del_endpoint_tambien_cierra_el_ambiente_de_pruebas(): void
    {
        Http::fake();
        $this->habilitarTransmision();
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.url_base', 'https://recepcion.test');

        $candados = app(\App\Services\Dte\DteTransmisionService::class)->evaluarCandados();

        $this->assertTrue($candados['bloqueado']);
        $this->assertStringContainsString('de apitest exacto', implode(' ', $candados['razones']));
        Http::assertNothingSent();
    }

    /** Y con el host oficial de apitest, ese candado concreto no aparece. */
    public function test_apitest_con_su_endpoint_oficial_no_dispara_el_candado(): void
    {
        Http::fake();
        $this->habilitarTransmision();
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.url_base', EndpointsHacienda::HOST_PRUEBAS);

        $candados = app(\App\Services\Dte\DteTransmisionService::class)->evaluarCandados();

        $this->assertStringNotContainsString('apitest exacto', implode(' ', $candados['razones']));
        $this->assertFalse($candados['bloqueado'], implode(' | ', $candados['razones']));
        Http::assertNothingSent();
    }

    // ============================================ TIMEOUT / UMBRAL / TOKEN

    public function test_el_timeout_por_defecto_es_el_umbral_de_ocho_segundos(): void
    {
        $fuente = (string) file_get_contents(config_path('dte.php'));

        $this->assertStringContainsString(
            "'timeout' => (int) env('DTE_TRANSMISION_TIMEOUT', 8)",
            $fuente
        );
    }

    /** El TTL del cache del token vive SIEMPRE por debajo de la vigencia configurada. */
    public function test_el_ttl_del_token_queda_por_debajo_de_la_vigencia(): void
    {
        $auth = app(\App\Services\Dte\DteTransmisionAuthService::class);

        foreach ([24, 12, 48, 1] as $horas) {
            config()->set('dte.transmision.token_vigencia_horas', $horas);

            $this->assertSame($horas, $auth->vigenciaHoras());

            $ttl = (new \ReflectionMethod($auth, 'ttlSegundos'))->invoke($auth);
            $this->assertLessThan($horas * 3600, $ttl, "El TTL no puede alcanzar la vigencia ({$horas} h).");
            $this->assertGreaterThan(0, $ttl);
        }
    }

    /**
     * El modelo es "una vez al día" y es el MISMO en los dos ambientes. Antes producción
     * valía 24 h y pruebas 48 h, hardcoded; ese 48 no tenía respaldo localizable.
     */
    public function test_la_vigencia_no_depende_del_ambiente(): void
    {
        $auth = app(\App\Services\Dte\DteTransmisionAuthService::class);
        config()->set('dte.transmision.token_vigencia_horas', 24);

        config()->set('dte.transmision.ambiente', 'testing');
        $this->assertSame(24, $auth->vigenciaHoras());

        config()->set('dte.transmision.ambiente', 'produccion');
        $this->assertSame(24, $auth->vigenciaHoras());
    }

    /**
     * CONFIRMADO contra el Manual V2.0: «El servicio de autorización se deberá ejecutar
     * una vez en el día o según sea el modelo de facturación del contribuyente». El
     * cache NUNCA puede reutilizar un token más allá de ese ciclo diario.
     */
    public function test_el_token_nunca_se_reutiliza_mas_alla_del_ciclo_diario(): void
    {
        $auth = app(\App\Services\Dte\DteTransmisionAuthService::class);
        config()->set('dte.transmision.token_vigencia_horas', 24);

        foreach (['testing', 'produccion'] as $ambiente) {
            config()->set('dte.transmision.ambiente', $ambiente);
            $ttl = (new \ReflectionMethod($auth, 'ttlSegundos'))->invoke($auth);

            $this->assertLessThanOrEqual(24 * 3600, $ttl, "El cache excede el ciclo diario en {$ambiente}.");
            $this->assertLessThan(24 * 3600, $ttl, 'El TTL debe quedar POR DEBAJO, no igual.');
        }
    }

    /** No queda ninguna referencia a las 48 h de apitest en codigo ni configuracion. */
    public function test_no_queda_rastro_de_las_48_horas_de_apitest(): void
    {
        foreach ([config_path('dte.php'), app_path('Services/Dte/DteTransmisionAuthService.php')] as $archivo) {
            $fuente = (string) file_get_contents($archivo);

            $this->assertStringNotContainsString('47 * 3600', $fuente);
            $this->assertStringNotContainsString("? 24 : 48", $fuente);
        }
    }

    /**
     * Y el default declarado en el archivo de configuración es 24. Se mira el TEXTO
     * porque config() ya devuelve el valor resuelto del .env de la máquina, que podría
     * traer el suyo y tapar justo el default que se quiere fijar.
     */
    public function test_config_declara_veinticuatro_horas_como_vigencia_por_defecto(): void
    {
        $fuente = (string) file_get_contents(config_path('dte.php'));

        $this->assertStringContainsString(
            "'token_vigencia_horas' => (int) env('DTE_TRANSMISION_TOKEN_VIGENCIA_HORAS', 24)",
            $fuente
        );
    }

    // ==================================== CANDADO DEL ENDPOINT OFICIAL DE CONSULTA

    /**
     * Espía del servicio de autenticación: cuenta si alguien pidió el token, sin hacer
     * HTTP. Es la única forma de comprobar el ORDEN —endpoint primero, token después—,
     * que es la mitad de lo que protege el candado: un endpoint no oficial no debe
     * llegar a ver un Bearer, ni siquiera en memoria.
     */
    private function espiaDeToken(): DteTransmisionAuthService
    {
        $espia = new class extends DteTransmisionAuthService
        {
            public int $llamadas = 0;

            public function obtenerToken(): string
            {
                $this->llamadas++;

                return 'Bearer TOKEN_QUE_NO_DEBERIA_PEDIRSE';
            }
        };
        $this->app->instance(DteTransmisionAuthService::class, $espia);

        return $espia;
    }

    /** Producción + endpoint oficial exacto: la consulta procede con normalidad. */
    public function test_produccion_con_el_endpoint_oficial_de_consulta_procede(): void
    {
        $this->habilitarTransmision();
        config()->set('dte.transmision.ambiente', 'produccion');
        config()->set('dte.transmision.url_base', '');   // host oficial
        $espia = $this->espiaDeToken();
        Http::fake(['*' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'S'], 200)]);
        $dte = $this->ccfFirmado();

        $r = $this->consulta()->consultar($dte);

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertSame(1, $espia->llamadas, 'Superado el candado, el token sí se pide.');
        Http::assertSent(fn ($request) => $request->url()
            === 'https://api.dtes.mh.gob.sv/fesv/recepcion/consultadte');
    }

    /**
     * Producción + endpoint alterado: bloqueo duro. No se pide token y no sale ni una
     * petición. El aviso del MH exige consumir únicamente endpoints oficiales.
     */
    public function test_produccion_con_endpoint_de_consulta_no_oficial_bloquea_sin_token_ni_http(): void
    {
        $this->habilitarTransmision();
        config()->set('dte.transmision.ambiente', 'produccion');
        config()->set('dte.transmision.url_base', 'https://consulta.impostora.test');
        $espia = $this->espiaDeToken();
        Http::fake();
        $dte = $this->ccfFirmado();

        try {
            $this->consulta()->consultar($dte);
            $this->fail('Un endpoint de consulta no oficial en producción debe bloquear.');
        } catch (DteTransmisionDeshabilitadaException $e) {
            $this->assertStringContainsString('productivo exacto', $e->getMessage());
        }

        $this->assertSame(0, $espia->llamadas, 'El token NO puede pedirse antes de validar el endpoint.');
        Http::assertNothingSent();
    }

    /**
     * Ninguna variante engañosa pasa: subdominio que "termina bien", puerto, esquema,
     * el host de pruebas usado como si fuera producción, query string, fragmento y
     * rutas parecidas. La comparación es de igualdad exacta justamente para no tener
     * que confiar en un parser.
     */
    public function test_ninguna_variante_del_endpoint_de_produccion_pasa_el_candado(): void
    {
        $this->habilitarTransmision();
        config()->set('dte.transmision.ambiente', 'produccion');
        $dte = $this->ccfFirmado();

        $hosts = [
            'https://api.dtes.mh.gob.sv.impostor.test',  // subdominio engañoso
            'https://api-dtes.mh.gob.sv',                // parecido, no es
            'https://api.dtes.mh.gob.sv:8443',           // puerto
            'http://api.dtes.mh.gob.sv',                 // esquema
            'https://apitest.dtes.mh.gob.sv',            // pruebas mientras el ambiente es producción
        ];

        foreach ($hosts as $base) {
            config()->set('dte.transmision.url_base', $base);
            config()->set('dte.transmision.endpoint_consulta', '');
            $espia = $this->espiaDeToken();
            Http::fake();

            try {
                $this->consulta()->consultar($dte);
                $this->fail("Debe bloquear el host: {$base}");
            } catch (DteTransmisionDeshabilitadaException $e) {
                $this->assertStringContainsString('productivo exacto', $e->getMessage());
            }
            $this->assertSame(0, $espia->llamadas, "No debe pedirse token para {$base}");
            Http::assertNothingSent();
        }

        // Y las rutas alteradas sobre el host oficial tampoco pasan.
        $rutas = [
            '/fesv/recepcion/consultadte?forzar=1',
            '/fesv/recepcion/consultadte#frag',
            '/fesv/recepcion/consultadtelote',
            '/fesv/recepciondte',
        ];

        foreach ($rutas as $ruta) {
            config()->set('dte.transmision.url_base', '');
            config()->set('dte.transmision.endpoint_consulta', $ruta);
            $espia = $this->espiaDeToken();
            Http::fake();

            try {
                $this->consulta()->consultar($dte);
                $this->fail("Debe bloquear la ruta: {$ruta}");
            } catch (DteTransmisionDeshabilitadaException $e) {
                $this->assertStringContainsString('productivo exacto', $e->getMessage());
            }
            $this->assertSame(0, $espia->llamadas, "No debe pedirse token para {$ruta}");
            Http::assertNothingSent();
        }
    }

    /** Pruebas resuelve SIEMPRE a su propio endpoint oficial, nunca al de producción. */
    public function test_pruebas_usa_su_endpoint_oficial_y_no_el_de_produccion(): void
    {
        $this->habilitarTransmision();
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.url_base', '');
        $this->espiaDeToken();
        Http::fake(['*' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'S'], 200)]);
        $dte = $this->ccfFirmado();

        $this->consulta()->consultar($dte);

        Http::assertSent(fn ($request) => $request->url()
            === 'https://apitest.dtes.mh.gob.sv/fesv/recepcion/consultadte');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '//api.dtes.mh.gob.sv'));
    }

    /**
     * Apitest con endpoint alterado: BLOQUEADO, igual que producción. Un mock local no
     * justifica abrir la puerta —se hace con Http::fake() sobre la URL oficial, como en
     * estas mismas pruebas, o con el modo mock, que ni construye la petición—, y a
     * cambio un `url_base` mal puesto mandaría el token a cualquier host.
     */
    public function test_apitest_con_endpoint_alterado_bloquea_sin_token_ni_http(): void
    {
        $this->habilitarTransmision();
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.url_base', 'https://mock.local.test');
        $espia = $this->espiaDeToken();
        Http::fake();
        $dte = $this->ccfFirmado();

        try {
            $this->consulta()->consultar($dte);
            $this->fail('Un endpoint de consulta alterado en apitest debe bloquear.');
        } catch (DteTransmisionDeshabilitadaException $e) {
            $this->assertStringContainsString('de apitest exacto', $e->getMessage());
        }

        $this->assertSame(0, $espia->llamadas, 'El token NO puede pedirse con el endpoint bloqueado.');
        Http::assertNothingSent();
    }

    /** Y el endpoint OFICIAL de apitest sí procede, con Http::fake sobre la URL real. */
    public function test_apitest_con_su_endpoint_oficial_procede(): void
    {
        $this->habilitarTransmision();
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.url_base', EndpointsHacienda::HOST_PRUEBAS);
        $espia = $this->espiaDeToken();
        Http::fake(['*' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'S'], 200)]);
        $dte = $this->ccfFirmado();

        $r = $this->consulta()->consultar($dte);

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertSame(1, $espia->llamadas);
        Http::assertSent(fn ($request) => $request->url()
            === 'https://apitest.dtes.mh.gob.sv/fesv/recepcion/consultadte');
    }

    /**
     * LO QUE NO PUEDE PASAR: que un endpoint de consulta bloqueado acabe en un reenvío.
     * Con el estado incierto (CASO 2), la consulta previa se corta y el DTE NO se
     * transmite: ni un POST a recepción, ni cambio de estado, ni contingencia.
     */
    public function test_un_endpoint_de_consulta_bloqueado_no_reenvia_el_dte(): void
    {
        $this->habilitarTransmision();
        config()->set('dte.transmision.ambiente', 'produccion');
        config()->set('dte.transmision.allow_production', true);
        config()->set('dte.transmision.url_base', 'https://consulta.impostora.test');
        Http::fake();
        $dte = $this->ccfFirmado();
        $estadoPrevio = $dte->estado;

        try {
            $this->resiliente()->transmitir($dte, estadoIncierto: true);
            $this->fail('La consulta bloqueada debe cortar la transmisión, no seguir.');
        } catch (DteTransmisionDeshabilitadaException $e) {
            $this->assertStringContainsString('productivo exacto', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame($estadoPrevio, $dte->fresh()->estado);
        $this->assertNull($dte->fresh()->sello_recepcion);
    }

    /**
     * El fallback interno de timeout es 8 s en TODOS los servicios que hablan con el
     * MH, igual que el declarado en config/dte.php. Antes convivían 15 y 8: no se
     * notaba (la clave siempre existe), pero dos números para un mismo umbral es
     * exactamente como se cuela el tercero.
     */
    public function test_los_fallbacks_internos_de_timeout_son_de_ocho_segundos(): void
    {
        $archivos = [
            app_path('Services/Dte/DteConsultaService.php'),
            app_path('Services/Dte/DteTransmisionService.php'),
            app_path('Services/Dte/DteTransmisionAuthService.php'),
            app_path('Services/Dte/DteInvalidacionService.php'),
            app_path('Ajustes/Fiscal/InventarioFiscal.php'),
        ];

        foreach ($archivos as $archivo) {
            $fuente = (string) file_get_contents($archivo);

            $this->assertStringNotContainsString("'dte.transmision.timeout', 15", $fuente,
                basename($archivo).' conserva el fallback viejo de 15 s.');
        }
    }
}
