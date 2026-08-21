<?php

namespace App\Http\Middleware;

use App\Enums\Asistencia\ResultadoMarcacion;
use App\Services\Asistencia\AutenticadorDispositivo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Candado de las rutas del lector que ESCRIBEN. Sin credencial válida de
 * dispositivo, 401 con un cuerpo JSON del mismo formato que el resto del módulo
 * —el firmware ramifica siempre sobre `estado`— y sin ninguna pista de por qué
 * falló: no se distingue «no mandaste token» de «el código no existe» ni de «el
 * token está mal».
 *
 * El dispositivo autenticado queda en los atributos de la petición para que el
 * controlador no tenga que volver a resolverlo (ni volver a tocar la base).
 */
class AutenticaDispositivoAsistencia
{
    /** Clave con la que el controlador recupera el lector ya autenticado. */
    public const ATRIBUTO = 'asistencia_dispositivo';

    public function __construct(private readonly AutenticadorDispositivo $autenticador) {}

    public function handle(Request $request, Closure $next): Response
    {
        $dispositivo = $this->autenticador->resolver($request);

        if ($dispositivo === null) {
            // El `estado` y su código HTTP salen del enum del contrato, no de
            // literales: es la misma cadena sobre la que ramifica el firmware y
            // no puede depender de que tres archivos la escriban igual.
            $estado = ResultadoMarcacion::DispositivoNoAutorizado;

            return response()->json([
                'ok' => false,
                'estado' => $estado->value,
                'mensaje' => 'Dispositivo no autorizado',
            ], $estado->httpStatus());
        }

        // Telemetría del lector (última vez que se le vio y desde dónde). Es un
        // update silencioso: no dispara eventos ni auditoría.
        $dispositivo->registrarConexion($request->ip());

        $request->attributes->set(self::ATRIBUTO, $dispositivo);

        return $next($request);
    }
}
