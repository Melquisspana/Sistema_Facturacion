<?php

namespace Tests\Concerns;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Fallas de conexión DE VERDAD para las pruebas, sin salir de esta máquina.
 *
 * Qué pasó con un pedido que no tuvo respuesta lo dice cURL, y eso no se puede inventar:
 * hay que provocarlo. Acá se provoca contra 127.0.0.1 (nunca contra Hacienda) y se
 * guarda lo que cURL informó. Después {@see repetir()} lo devuelve sobre la dirección
 * oficial con un fake que no contesta, así la aplicación recibe los datos reales de
 * cURL y ningún pedido sale a la red.
 *
 * Usa un cliente HTTP propio, no el facade `Http`: el candado de red de la suite
 * ({@see TestCase::bloquearHttpReal()}) queda como está. La dirección está fija
 * acá a propósito: de este archivo no sale un pedido a ningún otro lado.
 *
 * Cada falla se provoca una sola vez por corrida.
 */
final class SimulaFallasDeConexion
{
    private const LOCAL = '127.0.0.1';

    /** @var array<string, array{excepcion: ConnectionException, transferencias: int, url: string}> */
    private static array $capturas = [];

    /** @var resource|null Escucha y nunca acepta ni contesta. Vive lo que dura la corrida. */
    private static $mudo = null;

    /**
     * `rechazada`: nadie escucha en el puerto, la conexión no se establece.
     * `tls_colgado`: conecta, pero el saludo TLS no termina; el pedido no se escribe.
     * `salio_sin_respuesta`: el pedido SALIÓ y nadie contestó.
     */
    public static function fallaReal(string $cual): ConnectionException
    {
        return self::captura($cual)['excepcion'];
    }

    /** Cuántas transferencias informó cURL (`on_stats`) en esa falla. */
    public static function transferencias(string $cual): int
    {
        return self::captura($cual)['transferencias'];
    }

    /** La dirección local a la que se le hizo el pedido de esa falla. */
    public static function url(string $cual): string
    {
        return self::captura($cual)['url'];
    }

    /**
     * Para `Http::fake`: el pedido de la aplicación, con su dirección, falla con los datos
     * de cURL de una falla real. Nada sale a la red.
     *
     * @return Closure(Request): PromiseInterface
     */
    public static function repetir(ConnectionException $real): Closure
    {
        $contexto = $real->getPrevious()->getHandlerContext();

        return fn ($request) => Create::rejectionFor(
            new ConnectException($real->getMessage(), $request->toPsrRequest(), null, $contexto)
        );
    }

    /** @return array{excepcion: ConnectionException, transferencias: int, url: string} */
    private static function captura(string $cual): array
    {
        return self::$capturas[$cual] ??= match ($cual) {
            // En Windows el rechazo tarda unos 2 s; con menos espera saldría como tiempo agotado.
            'rechazada' => self::provocar('http://'.self::LOCAL.':'.self::puertoCerrado().'/fesv/recepciondte', 4),
            'tls_colgado' => self::provocar('https://'.self::LOCAL.':'.self::puertoMudo().'/fesv/recepciondte', 1),
            'salio_sin_respuesta' => self::provocar('http://'.self::LOCAL.':'.self::puertoMudo().'/fesv/recepciondte', 1),
            default => throw new InvalidArgumentException("Falla de conexión desconocida: {$cual}"),
        };
    }

    /** @return array{excepcion: ConnectionException, transferencias: int, url: string} */
    private static function provocar(string $url, int $espera): array
    {
        $transferencias = 0;

        try {
            (new Factory)->timeout($espera)->acceptJson()
                ->withHeaders(['Authorization' => 'Bearer INVENTADO', 'User-Agent' => 'Prueba/1.0'])
                ->withOptions(['on_stats' => function () use (&$transferencias): void {
                    $transferencias++;
                }])
                ->post($url, ['documento' => str_repeat('x', 6000), 'codigoGeneracion' => 'INVENTADO']);
        } catch (ConnectionException $e) {
            return ['excepcion' => $e, 'transferencias' => $transferencias, 'url' => $url];
        }

        throw new RuntimeException("Se esperaba una falla de conexión en {$url} y hubo respuesta.");
    }

    /** Un puerto local en el que no escucha nadie: se abre, se anota y se cierra. */
    private static function puertoCerrado(): int
    {
        $socket = self::escuchar();
        $puerto = self::puertoDe($socket);
        fclose($socket);

        return $puerto;
    }

    private static function puertoMudo(): int
    {
        return self::puertoDe(self::$mudo ??= self::escuchar());
    }

    /** @return resource */
    private static function escuchar()
    {
        $socket = stream_socket_server('tcp://'.self::LOCAL.':0', $codigo, $mensaje);
        if ($socket === false) {
            throw new RuntimeException("No se pudo abrir un puerto local de prueba: {$mensaje}");
        }

        return $socket;
    }

    /** @param  resource  $socket */
    private static function puertoDe($socket): int
    {
        $nombre = (string) stream_socket_get_name($socket, false);

        return (int) substr($nombre, strrpos($nombre, ':') + 1);
    }
}
