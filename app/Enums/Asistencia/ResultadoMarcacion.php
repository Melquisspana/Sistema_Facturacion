<?php

namespace App\Enums\Asistencia;

use App\Services\Asistencia\RegistrarMarcacion;
use Symfony\Component\HttpFoundation\Response;

/**
 * Los desenlaces posibles de un intento de marcación. Existe para que el
 * contrato con el dispositivo sea CERRADO: el firmware ramifica sobre `estado`,
 * una cadena estable, en vez de sobre el texto del mensaje —que se puede reescribir
 * sin avisar— o sobre el código HTTP a secas.
 *
 * El mensaje que acompaña a cada caso está pensado para caber en una pantalla de
 * 128x128 y para no contarle NADA del sistema a quien no debía llegar hasta acá.
 */
enum ResultadoMarcacion: string
{
    /** Se escribió la marcación. Es el único caso que crea una fila. */
    case Registrada = 'registrada';

    /** El número de ranura no está asociado a nadie (o su huella está de baja). */
    case HuellaDesconocida = 'huella_desconocida';

    /** La huella es de alguien que ya no marca (empleado desactivado). */
    case EmpleadoInactivo = 'empleado_inactivo';

    /** Dedo repetido dentro de la ventana de cortesía: NO se escribe nada. */
    case Cooldown = 'cooldown';

    // ─────────────────── Desenlaces ANTERIORES a la regla ───────────────────
    //
    // Estos dos no los produce RegistrarMarcacion: ocurren antes de llegar a él
    // —en el middleware de credenciales y en la validación del cuerpo—. Viven
    // igual en este enum porque el contrato con el firmware es UNO SOLO: el
    // dispositivo ramifica sobre `estado` y no le importa en qué capa del
    // servidor se decidió. Mientras estas dos cadenas eran literales sueltas,
    // «los estados posibles» solo se podían averiguar leyendo tres archivos, y
    // nada impedía que uno escribiera `dispositivo_no_autorizado` y otro
    // `no_autorizado`.

    /** El cuerpo no traía un `fingerprint_id` utilizable. */
    case PayloadInvalido = 'payload_invalido';

    /** Sin credencial válida de lector. Lo decide el middleware. */
    case DispositivoNoAutorizado = 'dispositivo_no_autorizado';

    public function httpStatus(): int
    {
        return match ($this) {
            self::Registrada => Response::HTTP_OK,
            self::HuellaDesconocida => Response::HTTP_NOT_FOUND,
            self::EmpleadoInactivo => Response::HTTP_FORBIDDEN,
            // 409 y no 429: 429 es lo que devuelve el limitador de peticiones y
            // el firmware tiene que poder distinguir «marcaste hace un momento»
            // de «estás saturando el servidor».
            self::Cooldown => Response::HTTP_CONFLICT,
            self::PayloadInvalido => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::DispositivoNoAutorizado => Response::HTTP_UNAUTHORIZED,
        };
    }

    public function esExito(): bool
    {
        return $this === self::Registrada;
    }

    /**
     * ¿Puede devolverlo {@see RegistrarMarcacion}?
     *
     * Lo usa el controlador para dejar la distinción escrita en el código en vez
     * de en un comentario: los dos estados de arriba nunca llegan a él, y si
     * alguna vez llegaran sería un fallo de programación, no una respuesta.
     */
    public function loDecideLaRegla(): bool
    {
        return match ($this) {
            self::Registrada, self::HuellaDesconocida, self::EmpleadoInactivo, self::Cooldown => true,
            self::PayloadInvalido, self::DispositivoNoAutorizado => false,
        };
    }
}
