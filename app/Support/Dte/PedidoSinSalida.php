<?php

namespace App\Support\Dte;

use App\Services\Dte\DteTransmisionResiliente;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * ¿ESTE PEDIDO NUNCA SALIÓ DE LA MÁQUINA?
 *
 * Cuando un envío a Hacienda falla sin respuesta hay dos historias muy distintas:
 *   · el pedido NO llegó a salir (no resolvió el nombre, no pudo conectar, no terminó el
 *     saludo TLS): Hacienda no pudo recibir nada;
 *   · el pedido SALIÓ y se perdió la respuesta: Hacienda puede tenerlo.
 * Solo la primera permite dar el documento por no recibido sin que Hacienda lo confirme
 * ({@see DteTransmisionResiliente}). La segunda es duda, y la duda no autoriza nada.
 *
 * LA DIFERENCIA LA DICE cURL, NO EL TEXTO DEL ERROR. La dirección de Hacienda es https:
 * el pedido, que lleva el documento, recién se escribe cuando terminan la conexión y el
 * saludo TLS. Tres contadores de cURL lo muestran y tienen que coincidir:
 *   · `request_size`: bytes del pedido entregados a la conexión;
 *   · `size_upload`: bytes del cuerpo subidos;
 *   · `pretransfer_time`: el momento en que quedó listo para empezar a enviar.
 * Con los tres en cero, no se escribió nada. Medido el 17/11/2026 (cURL 8.16): con el
 * saludo TLS colgado dan 0, 0 y 0; con el pedido enviado y sin respuesta, 6235, 6039 y
 * mayor que cero. El número de error solo no alcanza: el 28 (tiempo agotado) sale en
 * los dos casos.
 *
 * NO se usan `connect_time`, `appconnect_time`, `starttransfer_time` ni `http_code`:
 * valen cero también cuando el pedido salió.
 *
 * ANTE CUALQUIER COSA QUE FALTE, LA RESPUESTA ES «NO SE SABE». Otro tipo de excepción,
 * un dato ausente o que no es número, otro error, otra dirección, más de una
 * transferencia: todo eso es `false`. Equivocarse para ese lado deja un documento sin
 * enviar, que se envía después; equivocarse para el otro lo duplica en Hacienda.
 *
 * Clase pura: no hace HTTP ni toca la base.
 */
final class PedidoSinSalida
{
    /** Los únicos errores de cURL que pueden darse sin haber escrito el pedido. */
    private const ERRORES_SIN_ENVIO = [
        6.0,  // CURLE_COULDNT_RESOLVE_HOST: no resolvió el nombre
        7.0,  // CURLE_COULDNT_CONNECT: no pudo conectar
        28.0, // CURLE_OPERATION_TIMEDOUT: se acabó el tiempo (antes o después de enviar)
    ];

    /** Lo que tiene que informar cURL para poder decidir. */
    private const DATOS = ['errno', 'request_size', 'size_upload', 'pretransfer_time'];

    /**
     * @param  string  $url  la dirección exacta a la que se hizo el pedido
     * @param  int  $transferencias  cuántas informó cURL por `on_stats`; más de una quiere
     *                               decir redirección o reintento interno, y el error que
     *                               se ve es solo el de la última
     */
    public static function probado(Throwable $e, string $url, int $transferencias = 0): bool
    {
        if ($transferencias > 1) {
            return false;
        }

        $contexto = self::contexto($e);
        if ($contexto === null) {
            return false;
        }

        foreach (self::DATOS as $dato) {
            if (! array_key_exists($dato, $contexto) || ! is_numeric($contexto[$dato])) {
                return false;
            }
        }

        return self::noConecto($e)
            && (float) $contexto['request_size'] === 0.0
            && (float) $contexto['size_upload'] === 0.0
            && (float) $contexto['pretransfer_time'] === 0.0
            && (string) $e->getPrevious()->getRequest()->getUri() === $url;
    }

    /**
     * ¿La falla es de no poder hablar con el servidor? Solo los errores 6, 7 y 28 de cURL:
     * no resolvió el nombre, no pudo conectar o se acabó el tiempo. Un certificado que este
     * servidor no acepta (60, 77) o un saludo TLS que falla (35) NO son eso: hay conexión,
     * y el problema es otro.
     *
     * No dice si un pedido se escribió: para eso está {@see probado()}. Sirve sola para el
     * pedido del token, que no lleva el documento.
     */
    public static function noConecto(Throwable $e): bool
    {
        $errno = self::contexto($e)['errno'] ?? null;

        return is_numeric($errno) && in_array((float) $errno, self::ERRORES_SIN_ENVIO, true);
    }

    /**
     * Lo que midió cURL, para dejarlo anotado. Sin token, sin el cuerpo del pedido y sin
     * la excepción.
     *
     * @return array<string, mixed>
     */
    public static function evidencia(Throwable $e): array
    {
        $contexto = self::contexto($e);
        if ($contexto === null) {
            return [];
        }

        $evidencia = [];
        foreach (['errno', 'error', 'request_size', 'size_upload', 'pretransfer_time'] as $dato) {
            $evidencia[$dato] = $contexto[$dato] ?? null;
        }

        return $evidencia;
    }

    /**
     * Los datos de cURL de una falla de conexión del cliente HTTP, o null si la excepción
     * no es eso. Los errores 6, 7 y 28 llegan siempre como `ConnectException` de Guzzle
     * envuelta por la `ConnectionException` de Laravel.
     *
     * @return array<string, mixed>|null
     */
    private static function contexto(Throwable $e): ?array
    {
        if (! $e instanceof ConnectionException) {
            return null;
        }

        $previa = $e->getPrevious();

        return $previa instanceof ConnectException ? $previa->getHandlerContext() : null;
    }
}
