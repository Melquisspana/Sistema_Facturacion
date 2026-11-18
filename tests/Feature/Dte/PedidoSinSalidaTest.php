<?php

namespace Tests\Feature\Dte;

use App\Support\Dte\PedidoSinSalida;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\SimulaFallasDeConexion;
use Tests\TestCase;
use Throwable;

/**
 * ¿EL PEDIDO NUNCA SALIÓ DE ESTA MÁQUINA? (simulacro de contingencia del 17/11/2026, F1)
 *
 * Es la pregunta de la que depende entrar sola en contingencia sin duplicar: solo si el
 * documento no llegó a salir se puede dar por no recibido sin que Hacienda lo confirme.
 * Si salió y se perdió la respuesta, Hacienda puede tenerlo: eso es duda, y la duda no
 * autoriza nada.
 *
 * Las tres primeras pruebas provocan la falla DE VERDAD contra esta misma máquina
 * (127.0.0.1), para leer lo que informa el cURL instalado y no lo que uno supone.
 */
class PedidoSinSalidaTest extends TestCase
{
    private const URL = 'https://apitest.dtes.mh.gob.sv/fesv/recepciondte';

    public function test_conexion_rechazada_de_verdad_el_pedido_no_salio(): void
    {
        $falla = SimulaFallasDeConexion::fallaReal('rechazada');

        $this->assertSame(1, SimulaFallasDeConexion::transferencias('rechazada'));
        $this->assertTrue(PedidoSinSalida::probado($falla, SimulaFallasDeConexion::url('rechazada'), 1));
    }

    public function test_saludo_tls_que_no_termina_de_verdad_el_pedido_no_salio(): void
    {
        $falla = SimulaFallasDeConexion::fallaReal('tls_colgado');

        $this->assertSame(1, SimulaFallasDeConexion::transferencias('tls_colgado'));
        $this->assertTrue(PedidoSinSalida::probado($falla, SimulaFallasDeConexion::url('tls_colgado'), 1));
    }

    /** Lo que no se negocia: un pedido que salió nunca se da por «no salió». */
    public function test_pedido_que_salio_de_verdad_y_no_tuvo_respuesta_queda_en_duda(): void
    {
        $falla = SimulaFallasDeConexion::fallaReal('salio_sin_respuesta');

        $this->assertGreaterThan(0, $falla->getPrevious()->getHandlerContext()['request_size']);
        $this->assertFalse(PedidoSinSalida::probado($falla, SimulaFallasDeConexion::url('salio_sin_respuesta'), 1));
    }

    /** La evidencia que se guarda en la bitácora: lo que midió cURL y nada más. */
    public function test_la_evidencia_son_solo_los_datos_de_curl(): void
    {
        $evidencia = PedidoSinSalida::evidencia(SimulaFallasDeConexion::fallaReal('salio_sin_respuesta'));

        $this->assertSame(['errno', 'error', 'request_size', 'size_upload', 'pretransfer_time'], array_keys($evidencia));
        $this->assertSame(28, $evidencia['errno']);
        $this->assertGreaterThan(0, $evidencia['request_size']);
        $this->assertSame([], PedidoSinSalida::evidencia(new RuntimeException('otra cosa')));
    }

    #[DataProvider('pedidosQueNoSalieron')]
    public function test_no_salio(int $errno): void
    {
        $this->assertTrue(PedidoSinSalida::probado($this->falla(['errno' => $errno]), self::URL, 1));
        // Una falla repetida por un fake no informa transferencias: cuenta igual.
        $this->assertTrue(PedidoSinSalida::probado($this->falla(['errno' => $errno]), self::URL));
    }

    /** @return array<string, array{int}> */
    public static function pedidosQueNoSalieron(): array
    {
        return [
            'no resolvió el nombre (6)' => [6],
            'no pudo conectar (7)' => [7],
            'se acabó el tiempo sin llegar a enviar (28)' => [28],
        ];
    }

    /**
     * @param  array<string, mixed>  $contexto  lo que cambia respecto de un «no salió» limpio
     * @param  list<string>  $sinClaves  datos de cURL que faltan
     */
    #[DataProvider('pedidosEnDuda')]
    public function test_en_duda(array $contexto, array $sinClaves = [], string $url = self::URL, int $transferencias = 1): void
    {
        $this->assertFalse(PedidoSinSalida::probado($this->falla($contexto, $sinClaves), $url, $transferencias));
    }

    /** @return array<string, array<int, mixed>> */
    public static function pedidosEnDuda(): array
    {
        $casos = [
            'tiempo agotado con pedido escrito' => [['errno' => 28, 'request_size' => 6235]],
            'tiempo agotado con cuerpo subido' => [['errno' => 28, 'size_upload' => 6039.0]],
            'tiempo agotado después de conectar' => [['errno' => 28, 'pretransfer_time' => 0.001642]],
            'no pudo conectar pero figura pedido escrito' => [['errno' => 7, 'request_size' => 1]],
            'error sin número' => [['errno' => 0]],
            'número de error con decimales' => [['errno' => 7.5]],
            'número de error con texto' => [['errno' => '7: no pudo conectar']],
            'dato que no es número' => [['errno' => 7, 'request_size' => 'cero']],
            'dato nulo' => [['errno' => 7, 'pretransfer_time' => null]],
            'dato falso' => [['errno' => 7, 'size_upload' => false]],
            'otra dirección que la pedida' => [['errno' => 7], [], 'https://apitest.dtes.mh.gob.sv/fesv/recepciondte/'],
            'la de producción cuando se pidió pruebas' => [['errno' => 7], [], 'https://api.dtes.mh.gob.sv/fesv/recepciondte'],
            'dos transferencias (redirección o reintento interno)' => [['errno' => 7], [], self::URL, 2],
        ];

        // Errores que pueden ocurrir con el pedido ya en viaje, o que no prueban nada.
        foreach ([35 => 'saludo TLS cortado', 52 => 'respuesta vacía', 55 => 'falla al enviar',
            56 => 'falla al recibir', 60 => 'certificado no confiable', 18 => 'respuesta incompleta',
            65 => 'no pudo rebobinar', 77 => 'certificados no leídos'] as $errno => $nombre) {
            $casos["{$nombre} ({$errno})"] = [['errno' => $errno]];
        }

        foreach (['errno', 'request_size', 'size_upload', 'pretransfer_time'] as $clave) {
            $casos["falta {$clave}"] = [['errno' => 7], [$clave]];
        }

        return $casos;
    }

    /** Para el pedido del token: solo no resolver, no conectar o quedarse sin tiempo es «no conectó». */
    public function test_no_conecto_solo_con_los_errores_de_conexion(): void
    {
        foreach ([6, 7, 28] as $errno) {
            $this->assertTrue(PedidoSinSalida::noConecto($this->falla(['errno' => $errno])), "error {$errno}");
        }

        foreach ([35, 52, 56, 60, 77, 0] as $errno) {
            $this->assertFalse(PedidoSinSalida::noConecto($this->falla(['errno' => $errno])), "error {$errno}");
        }

        $this->assertFalse(PedidoSinSalida::noConecto($this->falla(['errno' => '7: no pudo conectar'])));
        $this->assertFalse(PedidoSinSalida::noConecto($this->falla(['errno' => 7], ['errno'])));
        $this->assertFalse(PedidoSinSalida::noConecto($this->fallaInventada()), 'sin datos de cURL');
        $this->assertFalse(PedidoSinSalida::noConecto(new RuntimeException('cURL error 7: no pudo conectar')));
    }

    public function test_sin_los_datos_de_curl_no_hay_prueba(): void
    {
        $pedido = new Request('POST', self::URL);
        $contexto = ['errno' => 7, 'request_size' => 0, 'size_upload' => 0.0, 'pretransfer_time' => 0.0];

        $casos = [
            'sin excepción anterior' => new ConnectionException('cURL error 7: no pudo conectar'),
            'la anterior no es de conexión' => new ConnectionException('cURL error 7', 0,
                new RequestException('cURL error 7', $pedido, null, null, $contexto)),
            'no es una falla del cliente HTTP' => new RuntimeException('cURL error 7: no pudo conectar'),
            'la de conexión sin envolver' => new ConnectException('cURL error 7', $pedido, null, $contexto),
            'la falla inventada de las pruebas' => $this->fallaInventada(),
        ];

        foreach ($casos as $nombre => $falla) {
            $this->assertFalse(PedidoSinSalida::probado($falla, self::URL, 1), $nombre);
        }
    }

    /**
     * @param  array<string, mixed>  $contexto
     * @param  list<string>  $sinClaves
     */
    private function falla(array $contexto, array $sinClaves = []): ConnectionException
    {
        $contexto = array_diff_key(
            $contexto + ['error' => 'inventado', 'request_size' => 0, 'size_upload' => 0.0, 'pretransfer_time' => 0.0],
            array_flip($sinClaves),
        );

        return new ConnectionException('cURL error inventado', 0,
            new ConnectException('cURL error inventado', new Request('POST', self::URL), null, $contexto));
    }

    /** La de `Http::failedConnection()`: dice «cURL error 6» en el texto y no trae ningún dato. */
    private function fallaInventada(): Throwable
    {
        $cliente = new Factory;
        $cliente->fake(['*' => Factory::failedConnection()]);

        try {
            $cliente->post(self::URL, []);
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('cURL error 6', $e->getMessage());

            return $e;
        }

        $this->fail('La falla inventada no falló.');
    }
}
