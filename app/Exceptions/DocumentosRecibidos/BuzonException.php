<?php

namespace App\Exceptions\DocumentosRecibidos;

use RuntimeException;

/**
 * Falla del buzón de compras que el operador TIENE que ver.
 *
 * Existe porque el lector antiguo devolvía `[]` ante cualquier problema: una
 * contraseña vencida y un buzón sin correos nuevos se veían idénticos en pantalla
 * («Revisión completada, 0 correos revisados», en verde). Un buzón que no se pudo
 * leer NO es una corrida sin novedades, y a partir de acá no puede parecerlo.
 *
 * El mensaje llega a la pantalla y al log, así que nunca lleva la contraseña:
 * {@see self::sanear()} la tacha y recorta lo que el servidor haya devuelto.
 */
abstract class BuzonException extends RuntimeException
{
    /** Tope del mensaje que se muestra y se guarda. */
    private const MAX_MENSAJE = 300;

    /** Frase corta, de una línea y sin credenciales. */
    public static function sanear(string $texto, string $password = ''): string
    {
        if ($password !== '') {
            $texto = str_replace($password, '••••••••', $texto);
        }

        $texto = trim((string) strtok($texto, "\r\n"));

        return mb_substr($texto !== '' ? $texto : 'sin motivo', 0, self::MAX_MENSAJE);
    }

    /** Etiqueta corta y estable del tipo de falla, para el resumen y el log. */
    abstract public function clave(): string;
}
