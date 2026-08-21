<?php

namespace App\Services\Asistencia;

use App\Models\Asistencia\AsistenciaDispositivo;
use Illuminate\Http\Request;

/**
 * Resuelve QUÉ lector está hablando, a partir de dos cabeceras:
 *
 *   X-Dispositivo:        codigo del lector (no es secreto)
 *   X-Dispositivo-Token:  su token (sí lo es)
 *
 * Por qué cabeceras y no un campo del cuerpo: así la credencial no depende del
 * verbo ni del formato del payload, y el mismo firmware la manda igual en el ping
 * y en la marcación. Por qué no Sanctum: agregar un paquete y una tabla de tokens
 * personales para un dispositivo que no es una persona sería más superficie, no
 * menos; acá el «usuario» es una fila de `asistencia_dispositivos`.
 *
 * Devuelve null ante CUALQUIER problema —falta una cabecera, el código no existe,
 * el lector está inactivo, el token no coincide— sin distinguir cuál. Un atacante
 * en la red no debe poder usar las respuestas para averiguar qué códigos de lector
 * existen.
 *
 * El token NUNCA se registra en log, ni se devuelve, ni se guarda en claro.
 */
class AutenticadorDispositivo
{
    public const CABECERA_CODIGO = 'X-Dispositivo';

    public const CABECERA_TOKEN = 'X-Dispositivo-Token';

    public function resolver(Request $request): ?AsistenciaDispositivo
    {
        $codigo = trim((string) $request->headers->get(self::CABECERA_CODIGO, ''));
        $token = (string) $request->headers->get(self::CABECERA_TOKEN, '');

        if ($codigo === '' || $token === '') {
            return null;
        }

        $dispositivo = AsistenciaDispositivo::query()
            ->where('codigo', $codigo)
            ->where('activo', true)
            ->first();

        // La comparación corre igual aunque el dispositivo no exista, para no
        // regalar por tiempo de respuesta qué códigos están dados de alta.
        $hashFalso = str_repeat('0', 64);
        $coincide = hash_equals($dispositivo?->token_hash ?? $hashFalso, AsistenciaDispositivo::hashDeToken($token));

        return ($dispositivo !== null && $coincide) ? $dispositivo : null;
    }

    /** ¿Vino alguna credencial en la petición? (no dice si es válida). */
    public function traeCredenciales(Request $request): bool
    {
        return $request->headers->has(self::CABECERA_CODIGO)
            || $request->headers->has(self::CABECERA_TOKEN);
    }
}
