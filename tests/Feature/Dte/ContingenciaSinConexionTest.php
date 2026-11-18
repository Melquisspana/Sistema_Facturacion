<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Exceptions\Dte\DteTransmisionException;
use App\Models\Cliente;
use App\Models\Contingencia;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Producto;
use App\Models\User;
use App\Services\Dte\BitacoraEnvioDte;
use App\Services\Dte\ContingenciaAutomaticaService;
use App\Services\Dte\ContingenciaService;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteFirmaService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteTransmisionService;
use Carbon\CarbonInterval;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\PreparaEmisorDte;
use Tests\Concerns\SimulaFallasDeConexion;
use Tests\TestCase;

/**
 * SE VA INTERNET: EL SISTEMA ENTRA SOLO EN CONTINGENCIA (simulacro del 17/11/2026, F1).
 *
 * Antes, para entrar en contingencia hacía falta que la consulta a Hacienda contestara
 * «no lo tengo». Sin internet la consulta tampoco contesta, así que el documento quedaba
 * firmado y trabado. Ahora también entra cuando está probado que NINGÚN envío de ese
 * documento llegó a salir de la máquina y Hacienda no contestó nada.
 *
 * Lo que no se negocia: nunca duplicar un documento en Hacienda. Si un solo envío pudo
 * salir, queda para revisión como siempre.
 *
 * Las fallas de conexión son las que informa el cURL de esta máquina, provocadas contra
 * 127.0.0.1 y repetidas sobre la dirección oficial ({@see SimulaFallasDeConexion}).
 */
class ContingenciaSinConexionTest extends TestCase
{
    use PreparaEmisorDte, RefreshDatabase;

    private const EN_CONTINGENCIA = 'Documento emitido en contingencia, pendiente de sello de recepción.';

    private User $usuario;

    private array $emisor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-17 10:00:00', 'America/El_Salvador'));
        config(['dte.contingencia.enabled' => true, 'dte.storage.disk' => 'local',
            'dte.firma.enabled' => true, 'dte.firma.mock' => false,
            'dte.firma.nit' => '06141234561018', 'dte.firma.cert_password' => 'clave-inventada',
            'dte.transmision.enabled' => true, 'dte.transmision.real_confirmation' => true,
            'dte.transmision.dry_run' => false, 'dte.transmision.sistema_actual_activo' => false,
            'dte.transmision.modo_operacion' => 'principal', 'dte.transmision.ambiente' => 'testing',
            'dte.transmision.token' => 'Bearer INVENTADO', 'dte.transmision.usuario_testing' => 'usuario-inventado',
            'dte.transmision.password_testing' => 'clave-inventada']);
        Storage::fake('local');
        Queue::fake();
        // La espera entre intentos sin salida no se hace de verdad en las pruebas.
        Sleep::fake();
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

    /**
     * Sin internet: nada contesta. Cada clave de `$cambios` reemplaza a uno de los
     * servicios (por ejemplo, la sonda de internet contestando). Empieza de cero la
     * cuenta de pedidos.
     *
     * @param  array<string, mixed>  $cambios
     */
    private function simular(array $cambios = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(array_replace([
            '*generate_204' => $this->falla('rechazada'),
            '*firmardocumento*' => $this->firmador(),
            '*recepciondte*' => $this->falla('rechazada'),
            '*consultadte*' => $this->falla('rechazada'),
            '*seguridad/auth' => $this->falla('rechazada'),
        ], $cambios));
    }

    /** Una falla de conexión real de esta máquina, repetida sobre la dirección que se pida. */
    private function falla(string $cual): Closure
    {
        return SimulaFallasDeConexion::repetir(SimulaFallasDeConexion::fallaReal($cual));
    }

    /** El firmador inventado: devuelve firmado lo mismo que le mandan. */
    private function firmador(): Closure
    {
        return function ($request) {
            $b64 = fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');

            return Http::response(['status' => 'OK', 'body' => $b64(['alg' => 'RS512']).'.'.$b64($request['dteJson']).'.firma-inventada']);
        };
    }

    /** Cada pedido recibe, por orden, la respuesta que le toca; la última se repite. */
    private function enOrden(mixed ...$respuestas): Closure
    {
        $n = 0;

        return function ($request) use (&$n, $respuestas) {
            $respuesta = $respuestas[min($n++, count($respuestas) - 1)];

            return $respuesta instanceof Closure ? $respuesta($request) : $respuesta;
        };
    }

    /** Un CCF inventado: generado y, si se pide, firmado por fuera de «Firmar y transmitir». */
    private function documento(bool $firmar = false): Dte
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

    /**
     * Un documento firmado por «Firmar y transmitir» que todavía no hizo ningún envío: en
     * ese primer clic la transmisión estaba frenada por un candado, antes de salir.
     */
    private function firmadoSinEnvios(): Dte
    {
        $dte = $this->documento();
        config(['dte.transmision.dry_run' => true]);
        $this->clic($dte)->assertSessionHas('error');
        config(['dte.transmision.dry_run' => false]);

        $this->assertSame(EstadoDte::Firmado, $dte->refresh()->estado);
        $this->assertSame(['marca' => 1, 'empezo' => 0, 'no_salio' => 0], $this->anotaciones($dte));
        $this->simular();

        return $dte;
    }

    /** El botón «Firmar y transmitir» de la ficha. */
    private function clic(Dte $dte): TestResponse
    {
        return $this->post(route('facturacion.firmar-transmitir', $dte))->assertRedirect();
    }

    /** Quedó para revisión como siempre: firmado, sin contingencia y con el aviso de «incierto». */
    private function assertQuedoIncierto(TestResponse $respuesta, Dte $dte): void
    {
        $respuesta->assertSessionHas('error', fn ($aviso) => str_contains($aviso, 'estado_recepcion_incierto')
            && str_contains($aviso, 'NO se activó contingencia'));
        $this->assertDatabaseCount('contingencias', 0);
        $this->assertSame(EstadoDte::Firmado, $dte->refresh()->estado);
        $this->assertNull($dte->contingencia_id);
        $this->assertSame(0, $this->pedidos('generate_204'), 'no se llega ni a probar si hay internet');
    }

    private function pedidos(string $parte): int
    {
        return count(Http::recorded(fn ($pedido) => str_contains($pedido->url(), $parte)));
    }

    /**
     * Cuántas anotaciones de cada clase tiene el documento en la bitácora de envíos.
     *
     * @return array{marca: int, empezo: int, no_salio: int}
     */
    private function anotaciones(Dte $dte): array
    {
        $descripciones = Activity::where('log_name', BitacoraEnvioDte::BITACORA)
            ->where('subject_type', $dte->getMorphClass())->where('subject_id', $dte->id)
            ->pluck('description');

        return [
            'marca' => $descripciones->filter(fn ($d) => $d === BitacoraEnvioDte::FIRMA_NUEVA)->count(),
            'empezo' => $descripciones->filter(fn ($d) => $d === BitacoraEnvioDte::ENVIO_INICIADO)->count(),
            'no_salio' => $descripciones->filter(fn ($d) => $d === BitacoraEnvioDte::ENVIO_SIN_SALIDA)->count(),
        ];
    }

    /** Lo que quedó anotado de la conversión a contingencia. */
    private function conversion(): Activity
    {
        return Activity::where('log_name', 'dte_contingencia')
            ->where('description', 'Documento re-firmado en contingencia tras reintentos agotados')->sole();
    }

    // ------------------------------------------------------------------ entra sola

    public function test_sin_internet_entra_sola_en_contingencia_y_el_documento_sale_provisional(): void
    {
        $dte = $this->documento();
        $codigo = $dte->codigo_generacion;
        $numero = $dte->numero_control;

        $this->clic($dte)->assertSessionHas('status', self::EN_CONTINGENCIA);

        $dte->refresh();
        $this->assertTrue($dte->esTransitorio());
        $this->assertSame($codigo, $dte->codigo_generacion);
        $this->assertSame($numero, $dte->numero_control);
        $this->assertDatabaseCount('contingencias', 1);
        $this->assertSame(3, $dte->contingencia->tipo, 'la sonda tampoco contestó: es un corte de internet');
        $this->assertSame('automatica', $dte->contingencia->origen);

        $json = json_decode(Storage::get($dte->json_generado_path), true);
        $this->assertSame([2, 2, 3], [$json['identificacion']['tipoModelo'], $json['identificacion']['tipoOperacion'],
            $json['identificacion']['tipoContingencia']]);

        // Se reintentó como manda el manual: 3 envíos, cada uno con su consulta.
        $this->assertSame(3, $this->pedidos('recepciondte'));
        $this->assertSame(3, $this->pedidos('consultadte'));
        $this->assertSame(2, $this->pedidos('firmardocumento'));
        $this->assertSame(['marca' => 1, 'empezo' => 3, 'no_salio' => 3], $this->anotaciones($dte));

        // Entre un intento y el siguiente se completa el umbral del manual (8 s): un
        // parpadeo no es un corte. Después del último no se espera.
        Sleep::assertSleptTimes(2);
        Sleep::assertSlept(fn (CarbonInterval $espera) => $espera->totalSeconds > 6 && $espera->totalSeconds <= 8, 2);

        $this->assertTrue($this->conversion()->properties['sin_salida']);
        $this->assertNull($this->conversion()->properties['consulta_resultado']);
    }

    /** Hay internet (la sonda contesta) pero Hacienda no: es el tipo 1, no el 3. */
    public function test_con_internet_y_hacienda_caida_entra_sola_como_tipo_1(): void
    {
        $this->simular(['*generate_204' => Http::response('', 204)]);
        $dte = $this->documento();

        $this->clic($dte)->assertSessionHas('status', self::EN_CONTINGENCIA);

        $this->assertTrue($dte->refresh()->esTransitorio());
        $this->assertSame(1, $dte->contingencia->tipo);
        $this->assertSame(1, json_decode(Storage::get($dte->json_generado_path), true)['identificacion']['tipoContingencia']);
        $this->assertTrue($this->conversion()->properties['sin_salida']);
    }

    /** Conecta pero el saludo TLS no termina: el pedido tampoco llegó a escribirse. */
    public function test_saludo_tls_que_no_termina_tambien_entra_sola(): void
    {
        $this->simular(['*recepciondte*' => $this->falla('tls_colgado'), '*consultadte*' => $this->falla('tls_colgado')]);
        $dte = $this->documento();

        $this->clic($dte)->assertSessionHas('status', self::EN_CONTINGENCIA);

        $this->assertTrue($dte->refresh()->esTransitorio());
        $this->assertSame(['marca' => 1, 'empezo' => 3, 'no_salio' => 3], $this->anotaciones($dte));
    }

    /** Sin token guardado ni forma de pedirlo, el envío ni se arma: tampoco salió nada. */
    public function test_sin_internet_y_sin_token_guardado_tambien_entra_sola(): void
    {
        config(['dte.transmision.token' => '']);
        $dte = $this->documento();

        $this->clic($dte)->assertSessionHas('status', self::EN_CONTINGENCIA);

        $this->assertTrue($dte->refresh()->esTransitorio());
        $this->assertSame(3, $dte->contingencia->tipo);
        $this->assertSame(0, $this->pedidos('recepciondte'));
        $this->assertSame(0, $this->pedidos('consultadte'));
        // Tres intentos; en cada uno se pidió el token para enviar y para consultar.
        $this->assertSame(6, $this->pedidos('seguridad/auth'));
        $this->assertSame(['marca' => 1, 'empezo' => 0, 'no_salio' => 0], $this->anotaciones($dte));
        Sleep::assertSleptTimes(2);
    }

    // ------------------------------------------------------------- nunca duplicar

    /** El pedido SALIÓ y se perdió la respuesta: Hacienda puede tenerlo. No se toca nada. */
    public function test_pedido_que_salio_y_perdio_la_respuesta_no_entra_y_queda_para_revision(): void
    {
        $this->simular(['*recepciondte*' => $this->falla('salio_sin_respuesta')]);
        $dte = $this->documento();

        $respuesta = $this->clic($dte);

        $this->assertQuedoIncierto($respuesta, $dte);
        $this->assertSame(1, $this->pedidos('recepciondte'), 'no se reenvía lo que pudo haber llegado');
        $this->assertSame(1, $this->pedidos('firmardocumento'), 'no se vuelve a firmar');
        // El envío empezó y nunca se cerró como «no salió»: queda en duda.
        $this->assertSame(['marca' => 1, 'empezo' => 1, 'no_salio' => 0], $this->anotaciones($dte));
        Sleep::assertNeverSlept();
    }

    /** Un solo intento en duda alcanza, aunque los demás no hayan salido. */
    public function test_un_intento_en_duda_entre_otros_que_no_salieron_no_entra(): void
    {
        $this->simular(['*recepciondte*' => $this->enOrden($this->falla('rechazada'), $this->falla('salio_sin_respuesta'))]);
        $dte = $this->documento();

        $respuesta = $this->clic($dte);

        $this->assertQuedoIncierto($respuesta, $dte);
        $this->assertSame(2, $this->pedidos('recepciondte'));
        $this->assertSame(['marca' => 1, 'empezo' => 2, 'no_salio' => 1], $this->anotaciones($dte));
    }

    /**
     * La duda no se borra: un envío que pudo salir cierra el camino aunque después la
     * consulta diga «no lo tengo» y los envíos siguientes ya no salgan.
     */
    public function test_un_envio_en_duda_no_se_borra_aunque_despues_la_consulta_diga_que_no_esta(): void
    {
        $this->simular([
            '*recepciondte*' => $this->enOrden($this->falla('salio_sin_respuesta'), $this->falla('rechazada')),
            '*consultadte*' => $this->enOrden(Http::response([], 404), $this->falla('rechazada')),
        ]);
        $dte = $this->documento();

        $respuesta = $this->clic($dte);

        $this->assertQuedoIncierto($respuesta, $dte);
        $this->assertSame(2, $this->pedidos('recepciondte'));
        $this->assertSame(['marca' => 1, 'empezo' => 2, 'no_salio' => 1], $this->anotaciones($dte));
    }

    /** La duda de un intento anterior vale para siempre: otro clic no envía nada. */
    public function test_un_envio_en_duda_de_un_clic_anterior_cierra_el_camino(): void
    {
        $this->simular(['*recepciondte*' => $this->falla('salio_sin_respuesta')]);
        $dte = $this->documento();
        $this->clic($dte)->assertSessionHas('error');
        $this->assertSame(1, $this->pedidos('recepciondte'));

        // Segundo clic, ahora sí sin conexión del todo.
        $this->simular();
        $respuesta = $this->clic($dte);

        $this->assertQuedoIncierto($respuesta, $dte);
        $this->assertSame(0, $this->pedidos('recepciondte'), 'ningún envío nuevo');
        $this->assertSame(1, $this->pedidos('consultadte'));
        $this->assertSame(['marca' => 1, 'empezo' => 1, 'no_salio' => 0], $this->anotaciones($dte));
    }

    /**
     * Si algo contesta a la consulta (Hacienda, su balanceador, el portal del proveedor
     * de internet), ya no es silencio: manda lo que contestó, y eso no prueba nada.
     */
    #[DataProvider('respuestasQueNoPruebanNada')]
    public function test_si_algo_contesta_a_la_consulta_no_entra(int $estado, string $cuerpo): void
    {
        $this->simular(['*consultadte*' => Http::response($cuerpo, $estado)]);
        $dte = $this->documento();

        $respuesta = $this->clic($dte);

        $this->assertQuedoIncierto($respuesta, $dte);
        $this->assertSame(1, $this->pedidos('recepciondte'));
        Sleep::assertNeverSlept();
    }

    /** @return array<string, array{int, string}> */
    public static function respuestasQueNoPruebanNada(): array
    {
        return [
            'servicio caído (503)' => [503, ''],
            'token rechazado (401)' => [401, '{"status":"ERROR"}'],
            'página de otro (200 con HTML)' => [200, '<html><body>Sin saldo: recargue su plan</body></html>'],
        ];
    }

    /** Y si la consulta dice que Hacienda SÍ lo tiene, vale el sello: no hay contingencia. */
    public function test_si_la_consulta_dice_que_hacienda_lo_tiene_se_guarda_el_sello(): void
    {
        $dte = $this->documento();
        $this->simular(['*consultadte*' => Http::response(['estado' => 'PROCESADO', 'descripcionMsg' => 'RECIBIDO',
            'selloRecibido' => 'SELLO-INVENTADO-0001', 'codigoGeneracion' => $dte->codigo_generacion])]);

        $this->clic($dte)->assertSessionHas('status', fn ($aviso) => str_contains($aviso, 'SELLO-INVENTADO-0001'));

        $this->assertSame(EstadoDte::Aceptado, $dte->refresh()->estado);
        $this->assertSame('SELLO-INVENTADO-0001', $dte->sello_recepcion);
        $this->assertDatabaseCount('contingencias', 0);
        $this->assertSame(1, $this->pedidos('recepciondte'));
    }

    /** Firmado por fuera de «Firmar y transmitir»: no tiene marca, sigue como siempre. */
    public function test_documento_sin_marca_de_firma_queda_para_revision_como_siempre(): void
    {
        $dte = $this->documento(firmar: true);
        $this->simular();

        $respuesta = $this->clic($dte);

        $this->assertQuedoIncierto($respuesta, $dte);
        Http::assertSentCount(1); // Solo la consulta previa del documento que ya venía firmado.
        $this->assertSame(1, $this->pedidos('consultadte'));
        $this->assertSame(['marca' => 0, 'empezo' => 0, 'no_salio' => 0], $this->anotaciones($dte));
    }

    /** La conversión no le cree al resultado que recibe: vuelve a leer la bitácora. */
    public function test_un_resultado_armado_a_mano_no_convierte_sin_la_prueba_en_la_bitacora(): void
    {
        $armado = ['resultado' => 'reintentos_agotados', 'contingencia_requerida' => true, 'sin_salida' => true];

        $sinMarca = $this->documento(firmar: true);

        $this->simular(['*recepciondte*' => $this->falla('salio_sin_respuesta')]);
        $enDuda = $this->documento();
        $this->clic($enDuda)->assertSessionHas('error');
        $this->assertSame(['marca' => 1, 'empezo' => 1, 'no_salio' => 0], $this->anotaciones($enDuda));

        $this->simular();
        $conversion = app(ContingenciaAutomaticaService::class);
        $this->assertFalse($conversion->convertirTrasReintentos($sinMarca->refresh(), $armado));
        $this->assertFalse($conversion->convertirTrasReintentos($enDuda->refresh(), $armado));

        $this->assertDatabaseCount('contingencias', 0);
        Http::assertNothingSent();
    }

    /** Un login que Hacienda contestó (rechazo, error, cualquier cosa) no es «sin conexión». */
    #[DataProvider('respuestasDelLogin')]
    public function test_login_contestado_no_cuenta_como_sin_conexion(int $estado, string $cuerpo): void
    {
        config(['dte.transmision.token' => '']);
        $this->simular(['*seguridad/auth' => Http::response($cuerpo, $estado)]);
        $dte = $this->documento();

        $this->clic($dte)->assertSessionHas('error', fn ($aviso) => str_starts_with($aviso, 'Firmado, pero no se pudo transmitir:'));

        $this->assertDatabaseCount('contingencias', 0);
        $this->assertSame(EstadoDte::Firmado, $dte->refresh()->estado);
        $this->assertNull($dte->contingencia_id);
        $this->assertSame(1, $this->pedidos('seguridad/auth'), 'un login contestado no se reintenta');
        $this->assertSame(0, $this->pedidos('recepciondte'));
    }

    /** @return array<string, array{int, string}> */
    public static function respuestasDelLogin(): array
    {
        return [
            'credenciales rechazadas (401)' => [401, '{"status":"ERROR","body":{"descripcionMsg":"inventado"}}'],
            'servicio caído (503)' => [503, ''],
            'página de otro (200 con HTML)' => [200, '<html><body>Sin saldo</body></html>'],
        ];
    }

    /**
     * El login no conectó por un problema de este servidor (no confía en el certificado
     * de Hacienda, el saludo TLS falla), con internet y Hacienda funcionando: no es «sin
     * conexión». Se ve el error de verdad y no se declara una contingencia que no ocurrió.
     */
    #[DataProvider('fallasDelLoginQueNoSonUnCorte')]
    public function test_login_con_error_de_certificado_o_de_tls_no_cuenta_como_sin_conexion(int $errno, string $error): void
    {
        config(['dte.transmision.token' => '']);
        $this->simular(['*seguridad/auth' => $this->fallaDeCurl($errno, $error)]);
        $dte = $this->documento();

        $this->clic($dte)->assertSessionHas('error', fn ($aviso) => str_starts_with($aviso,
            'Firmado, pero no se pudo transmitir: No se pudo conectar con el servicio de autenticación: '
                ."cURL error {$errno}: {$error}"));

        $this->assertDatabaseCount('contingencias', 0);
        $this->assertSame(EstadoDte::Firmado, $dte->refresh()->estado);
        $this->assertNull($dte->contingencia_id);
        $this->assertSame(1, $this->pedidos('seguridad/auth'), 'no se reintenta');
        $this->assertSame(0, $this->pedidos('recepciondte'));
        $this->assertSame(0, $this->pedidos('generate_204'));
        Sleep::assertNeverSlept();
    }

    /** @return array<string, array{int, string}> */
    public static function fallasDelLoginQueNoSonUnCorte(): array
    {
        return [
            'certificado no confiable (60)' => [60, 'SSL certificate problem: unable to get local issuer certificate'],
            'saludo TLS fallido (35)' => [35, 'OpenSSL SSL_connect: SSL_ERROR_SYSCALL'],
        ];
    }

    /**
     * Una falla de cURL con su número, armada como la arma Guzzle: el 35 como falla de
     * conexión y el 60 como falla del pedido sin respuesta. Los contadores en cero, como
     * cuando no se llegó a escribir nada.
     */
    private function fallaDeCurl(int $errno, string $error): Closure
    {
        $contexto = ['errno' => $errno, 'error' => $error, 'request_size' => 0, 'size_upload' => 0.0, 'pretransfer_time' => 0.0];
        $mensaje = "cURL error {$errno}: {$error}";

        return fn ($request) => Create::rejectionFor($errno === 60
            ? new RequestException($mensaje, $request->toPsrRequest(), null, null, $contexto)
            : new ConnectException($mensaje, $request->toPsrRequest(), null, $contexto));
    }

    // ------------------------------------------------------------ lo que no cambia

    public function test_con_el_interruptor_apagado_no_cambia_nada(): void
    {
        config(['dte.contingencia.enabled' => false]);
        $dte = $this->documento();

        $this->clic($dte)->assertSessionHas('error', fn ($aviso) => str_contains($aviso, 'estado_recepcion_incierto'));

        $this->assertSame(EstadoDte::Firmado, $dte->refresh()->estado);
        $this->assertNull($dte->contingencia_id);
        $this->assertDatabaseCount('contingencias', 0);
        // Un envío y su consulta, como siempre: sin respuesta de la consulta no se reenvía.
        $this->assertSame(1, $this->pedidos('recepciondte'));
        $this->assertSame(1, $this->pedidos('consultadte'));
        $this->assertSame(0, $this->pedidos('generate_204'));
        // Y no se anota nada.
        $this->assertSame(0, Activity::where('log_name', BitacoraEnvioDte::BITACORA)->count());
        Sleep::assertNeverSlept();
    }

    public function test_con_el_interruptor_apagado_y_sin_token_sale_el_error_de_siempre(): void
    {
        config(['dte.contingencia.enabled' => false, 'dte.transmision.token' => '']);
        $dte = $this->documento();

        $this->clic($dte)->assertSessionHas('error', fn ($aviso) => str_starts_with($aviso,
            'Firmado, pero no se pudo transmitir: No se pudo conectar con el servicio de autenticación:'));

        $this->assertSame(EstadoDte::Firmado, $dte->refresh()->estado);
        $this->assertSame(1, $this->pedidos('seguridad/auth'));
        $this->assertSame(0, Activity::where('log_name', BitacoraEnvioDte::BITACORA)->count());
        Sleep::assertNeverSlept();
    }

    /**
     * Pasado el tope de minutos desde la emisión, el documento ya no puede pasar a
     * contingencia: tampoco se hace la vuelta sin conexión. Corta enseguida como incierto,
     * igual que antes, sin repetir envíos ni dejar renglones de «revisión manual».
     */
    public function test_documento_emitido_hace_mas_del_tope_no_hace_la_vuelta_sin_conexion(): void
    {
        $dte = $this->documento(); // emitido a las 10:00:00; tope de fábrica, 10 minutos
        Carbon::setTestNow(Carbon::parse('2026-11-17 10:30:00', 'America/El_Salvador'));

        $respuesta = $this->clic($dte);

        $this->assertQuedoIncierto($respuesta, $dte);
        $this->assertSame(1, $this->pedidos('recepciondte'), 'el envío inicial y ningún reenvío');
        $this->assertSame(1, $this->pedidos('consultadte'));
        $this->assertSame(1, $this->pedidos('firmardocumento'), 'no se vuelve a firmar');
        Sleep::assertNeverSlept();
        $this->assertSame(0, Activity::where('log_name', 'dte_contingencia')->count(), 'ninguna revisión manual');
    }

    /**
     * Un firmado que nunca salió y vuelve a intentarse después del tope (por ejemplo, la
     * tienda web cada 5 minutos): ni un envío, con o sin token guardado.
     */
    #[DataProvider('tokenGuardadoONo')]
    public function test_firmado_sin_envios_despues_del_tope_queda_incierto_sin_enviar(string $token, string $unicoPedido): void
    {
        $dte = $this->firmadoSinEnvios();
        config(['dte.transmision.token' => $token]);
        Carbon::setTestNow(Carbon::parse('2026-11-17 10:30:00', 'America/El_Salvador'));

        $respuesta = $this->clic($dte);

        $this->assertQuedoIncierto($respuesta, $dte);
        Http::assertSentCount(1);
        $this->assertSame(1, $this->pedidos($unicoPedido), 'solo la consulta previa');
        Sleep::assertNeverSlept();
        $this->assertSame(0, Activity::where('log_name', 'dte_contingencia')->count(), 'ninguna revisión manual');
        $this->assertSame(['marca' => 1, 'empezo' => 0, 'no_salio' => 0], $this->anotaciones($dte));
    }

    /** @return array<string, array{string, string}> */
    public static function tokenGuardadoONo(): array
    {
        return [
            'con token guardado' => ['Bearer INVENTADO', 'consultadte'],
            'sin token guardado' => ['', 'seguridad/auth'],
        ];
    }

    /** La entrada de siempre (la consulta dice «no lo tengo») sigue igual y no espera. */
    public function test_la_entrada_por_consulta_sigue_igual_y_no_espera(): void
    {
        $this->simular(['*recepciondte*' => Http::failedConnection(), '*consultadte*' => Http::response([], 404)]);
        $dte = $this->documento();

        $this->clic($dte)->assertSessionHas('status', self::EN_CONTINGENCIA);

        $this->assertTrue($dte->refresh()->esTransitorio());
        $this->assertSame('no_encontrado', $this->conversion()->properties['consulta_resultado']);
        $this->assertFalse($this->conversion()->properties['sin_salida']);
        // La falla inventada no trae los datos de cURL: esos envíos quedan en duda, y no
        // importa, porque acá la prueba es lo que contestó la consulta.
        $this->assertSame(['marca' => 1, 'empezo' => 3, 'no_salio' => 0], $this->anotaciones($dte));
        Sleep::assertNeverSlept();
    }

    // ------------------------------------------- un firmado que quedó sin enviar

    /** Alguien activa la contingencia a mano: el firmado que nunca salió entra en ESA. */
    public function test_firmado_que_nunca_salio_pasa_a_la_contingencia_activada_a_mano(): void
    {
        $dte = $this->firmadoSinEnvios();
        $activa = app(ContingenciaService::class)->activar(3, 'Corte inventado', 'manual', null);

        $this->clic($dte)->assertSessionHas('status', self::EN_CONTINGENCIA);

        $this->assertTrue($dte->refresh()->esTransitorio());
        $this->assertSame($activa->id, $dte->contingencia_id);
        $this->assertDatabaseCount('contingencias', 1);
        $this->assertSame(0, $this->pedidos('generate_204'), 'ya hay una activa: no se prueba internet');
        // Antes de convertir se vuelve a intentar: consulta previa y el ciclo completo.
        $this->assertSame(3, $this->pedidos('recepciondte'));
        $this->assertSame(4, $this->pedidos('consultadte'));
        $this->assertSame(['marca' => 1, 'empezo' => 3, 'no_salio' => 3], $this->anotaciones($dte));
    }

    /** El que quedó en duda no pasa, aunque la contingencia esté activa. */
    public function test_firmado_en_duda_no_pasa_a_la_contingencia_activada_a_mano(): void
    {
        $this->simular(['*recepciondte*' => $this->falla('salio_sin_respuesta')]);
        $dte = $this->documento();
        $this->clic($dte)->assertSessionHas('error');
        $activa = app(ContingenciaService::class)->activar(3, 'Corte inventado', 'manual', null);

        $this->simular();
        $this->clic($dte)->assertSessionHas('error', fn ($aviso) => str_contains($aviso, 'estado_recepcion_incierto'));

        $this->assertSame(EstadoDte::Firmado, $dte->refresh()->estado);
        $this->assertNull($dte->contingencia_id);
        $this->assertSame(0, $activa->dtes()->count());
        $this->assertSame(0, $this->pedidos('recepciondte'));
    }

    /** La contingencia se activó pero el firmador falló al re-firmar: el clic siguiente lo pasa. */
    public function test_documento_trabado_por_el_firmador_entra_en_el_clic_siguiente(): void
    {
        // La firma del documento sale bien; la re-firma de contingencia vuelve mal.
        $this->simular(['*firmardocumento*' => $this->enOrden($this->firmador(),
            Http::response(['status' => 'OK', 'body' => 'FIRMA.DE.OTRO']))]);
        $dte = $this->documento();

        $this->clic($dte)->assertSessionHas('error', fn ($aviso) => str_contains($aviso, 'no se pudo re-firmar'));
        $this->assertSame(EstadoDte::Firmado, $dte->refresh()->estado);
        $this->assertNull($dte->contingencia_id);
        $activa = Contingencia::sole();

        $this->simular();
        $this->clic($dte)->assertSessionHas('status', self::EN_CONTINGENCIA);

        $this->assertTrue($dte->refresh()->esTransitorio());
        $this->assertSame($activa->id, $dte->contingencia_id);
        $this->assertDatabaseCount('contingencias', 1);
        $this->assertSame(['marca' => 1, 'empezo' => 6, 'no_salio' => 6], $this->anotaciones($dte));
    }

    // ---------------------------------------------------- la bitácora de envíos

    /** Sin poder anotar el envío no se envía: un envío sin anotar sería una duda invisible. */
    public function test_si_no_se_puede_anotar_el_envio_no_se_envia_nada(): void
    {
        $dte = $this->firmadoSinEnvios();
        activity()->disableLogging();

        $this->clic($dte)->assertSessionHas('error', fn ($aviso) => str_contains($aviso, 'No se pudo anotar el envío'));

        activity()->enableLogging();
        $this->assertSame(0, $this->pedidos('recepciondte'));
        $this->assertSame(EstadoDte::Firmado, $dte->refresh()->estado);
        $this->assertDatabaseCount('contingencias', 0);
    }

    /** Lo que el pedido traía en memoria ya no vale: se vuelve a mirar antes de enviar. */
    #[DataProvider('cambiosDelDocumento')]
    public function test_documento_que_cambio_mientras_se_emitia_no_se_envia(string $cambio): void
    {
        $dte = $this->firmadoSinEnvios();
        $enMemoria = Dte::findOrFail($dte->id);
        Dte::whereKey($dte->id)->update($cambio === 'sello'
            ? ['sello_recepcion' => 'SELLO-INVENTADO-0002']
            : ['contingencia_id' => app(ContingenciaService::class)->activar(3, 'Corte inventado', 'manual', null)->id]);

        try {
            app(DteTransmisionService::class)->transmitir($enMemoria);
            $this->fail('No tenía que enviarse.');
        } catch (DteTransmisionException $e) {
            $this->assertStringContainsString('El documento cambió mientras se emitía', $e->getMessage());
        }

        $this->assertSame(0, $this->pedidos('recepciondte'));
        $this->assertSame(['marca' => 1, 'empezo' => 0, 'no_salio' => 0], $this->anotaciones($dte));
    }

    /** @return array<string, array{string}> */
    public static function cambiosDelDocumento(): array
    {
        return ['ya tiene sello' => ['sello'], 'ya está en contingencia' => ['contingencia']];
    }

    /** Apagar el interruptor no deja de anotar a un documento con marca: la duda no se pierde. */
    public function test_apagar_y_encender_el_interruptor_no_borra_la_duda(): void
    {
        $dte = $this->firmadoSinEnvios();

        // Con el interruptor apagado sale un envío que se pierde (la consulta dice «no lo tengo»).
        config(['dte.contingencia.enabled' => false]);
        $this->simular(['*recepciondte*' => $this->falla('salio_sin_respuesta'), '*consultadte*' => Http::response([], 404)]);
        $this->clic($dte)->assertSessionHas('error');
        $this->assertSame(2, $this->pedidos('recepciondte'));
        $this->assertSame(['marca' => 1, 'empezo' => 2, 'no_salio' => 0], $this->anotaciones($dte));

        // Encendido de nuevo y sin conexión del todo: sigue en duda.
        config(['dte.contingencia.enabled' => true]);
        $this->simular();
        $respuesta = $this->clic($dte);

        $this->assertQuedoIncierto($respuesta, $dte);
        $this->assertSame(0, $this->pedidos('recepciondte'));
    }
}
