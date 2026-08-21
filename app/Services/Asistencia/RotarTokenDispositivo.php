<?php

namespace App\Services\Asistencia;

use App\Models\Asistencia\AsistenciaDispositivo;
use Illuminate\Support\Facades\DB;

/**
 * Genera un token NUEVO para un lector y devuelve el valor EN CLARO — una vez.
 *
 * Es el único sitio del sistema que produce ese secreto, y lo usan por igual el
 * comando de consola y la pantalla de administración. Antes la lógica vivía
 * suelta dentro del comando; con dos vías para el mismo acto, una de las dos
 * acabaría olvidándose de auditar o de hashear.
 *
 * ─────────────────────── Lo que devuelve, y qué NO se guarda ───────────────────────
 *
 * El valor en claro sale por el `return` y **no se persiste en ninguna parte**:
 * en base queda solo su SHA-256. Quien llama es responsable de mostrarlo una vez
 * y olvidarlo. Si se pierde, no se recupera: se rota otra vez, que es justo lo
 * que hay que hacer si alguien pudo haberlo visto.
 *
 * ─────────────────────────── Por qué audita a mano ───────────────────────────
 *
 * `AsistenciaDispositivo` audita con `logOnly(['codigo','nombre','activo'])`, que
 * deja fuera `token_hash` a propósito. El efecto secundario es que una rotación
 * —donde ESO es lo único que cambia— producía un diff vacío y
 * `dontSubmitEmptyLogs()` la descartaba entera: **el acto más sensible del lector
 * no dejaba rastro**. Se comprobó ejecutándolo antes de escribir esta clase.
 *
 * La corrección no es ampliar la lista de columnas auditadas —eso metería el hash
 * en el log— sino registrar el HECHO explícitamente. Queda quién lo hizo y sobre
 * qué lector, y no queda ni el token ni su hash.
 */
class RotarTokenDispositivo
{
    /**
     * @param  string|null  $token  Token a fijar. NULL = generar uno nuevo al azar.
     *                              Solo se pasa desde el comando, para poder usar
     *                              el valor de provisión del archivo del servidor.
     * @return string El token EN CLARO. No se vuelve a poder obtener.
     */
    public function __invoke(AsistenciaDispositivo $dispositivo, ?string $token = null): string
    {
        $token = ($token !== null && trim($token) !== '')
            ? trim($token)
            : AsistenciaDispositivo::generarToken();

        DB::transaction(function () use ($dispositivo, $token) {
            // `saveQuietly` no: el modelo debe seguir viendo el cambio por si
            // mañana se audita otra columna. Lo que se evita es que el hash entre
            // al log, y de eso ya se encarga `logOnly` en el modelo.
            $dispositivo->update(['token_hash' => AsistenciaDispositivo::hashDeToken($token)]);

            activity('asistencia')
                ->performedOn($dispositivo)
                // Solo el código, que no es secreto: es lo que el lector manda en
                // la cabecera y lo que hace falta para saber A CUÁL se le rotó.
                ->withProperties(['codigo' => $dispositivo->codigo])
                ->log('rotó el token del lector de asistencia');
        });

        return $token;
    }
}
