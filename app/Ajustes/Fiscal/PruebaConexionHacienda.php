<?php

namespace App\Ajustes\Fiscal;

use App\Ajustes\Integraciones\ResultadoPruebaIntegracion;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use App\Services\Dte\DteTransmisionAuthService;

/**
 * «Probar conexión» con el Ministerio de Hacienda, desde la pantalla de
 * configuración.
 *
 * QUÉ HACE, EXACTAMENTE: pide un token al servicio de seguridad del ambiente de
 * PRUEBAS. Nada más. **No transmite ningún documento, no firma, no toca
 * correlativos y no crea ni modifica ningún DTE.** Es la misma comprobación que
 * hace `php artisan dte:auth-test`, con el mismo candado, ejecutada desde la web.
 *
 * POR QUÉ SOLO CONTRA PRUEBAS. Contra producción existe una comprobación
 * equivalente ({@see DteTransmisionAuthService::pruebaAuthProduccion()}) que
 * inicia sesión con la cuenta REAL y descarta el token. Existe, tiene su propio
 * candado y se usa desde la consola. NO se expone en esta pantalla: un botón que
 * usa la credencial de producción con un clic es un botón que se pulsa por
 * curiosidad, y ninguna advertencia en la interfaz lo evita. En una pantalla,
 * «probar» tiene que significar «probar contra pruebas».
 *
 * POR QUÉ SE MIRAN LAS PRECONDICIONES DOS VECES. El servicio de autenticación ya
 * las comprueba, pero devuelve `bloqueado = true` tanto cuando NO llegó a
 * intentarlo como cuando lo intentó y el MH lo rechazó. Para el historial esas
 * dos cosas no pueden ser la misma: «el candado está cerrado» no es un fallo de
 * Hacienda, y anotarlo como tal llenaría el registro de errores que no lo son y
 * taparía los que sí. Resolviendo las precondiciones antes, un bloqueo posterior
 * solo puede significar una cosa: se intentó y no entró.
 */
class PruebaConexionHacienda
{
    public function __construct(
        private readonly DteTransmisionAuthService $auth,
        private readonly EstadoHaciendaApi $estado,
        private readonly RegistroVerificaciones $verificaciones,
    ) {}

    public function ejecutar(): ResultadoPruebaIntegracion
    {
        $disponible = $this->estado->pruebaDisponible();

        if (! $disponible['puede']) {
            return ResultadoPruebaIntegracion::sinComprobar(
                'No se comprobó nada. '.$disponible['razon']
            );
        }

        $resultado = $this->auth->pruebaAuthTesting();

        if (! $resultado['token_obtenido']) {
            $prueba = ResultadoPruebaIntegracion::fallo(
                'El Ministerio de Hacienda no aceptó el acceso al ambiente de pruebas. '
                .($resultado['razon'] ?? 'Sin detalle.').' No se envió ningún documento.'
            );

            $this->verificaciones->registrar(
                EstadoHaciendaApi::CLAVE_VERIFICACION,
                $prueba->resultado(),
                // Los mensajes del servicio no llevan usuario, contraseña ni token.
                (string) ($resultado['razon'] ?? 'Acceso rechazado.'),
            );

            return $prueba;
        }

        $prueba = ResultadoPruebaIntegracion::exito(
            'Acceso correcto al ambiente de pruebas del Ministerio de Hacienda ('.$resultado['url'].'). No se envió ningún documento.'
        );

        $this->verificaciones->registrar(
            EstadoHaciendaApi::CLAVE_VERIFICACION,
            $prueba->resultado(),
            'Acceso correcto al ambiente de pruebas.',
        );

        return $prueba;
    }
}
