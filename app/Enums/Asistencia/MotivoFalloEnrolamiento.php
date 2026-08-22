<?php

namespace App\Enums\Asistencia;

/**
 * POR QUÉ falló un enrolamiento. Códigos ESTABLES: el firmware ramifica sobre
 * ellos y la pantalla del TFT elige qué mensaje pintar, así que cambiar un valor
 * rompe el contrato con el lector aunque el texto quede igual.
 *
 * Se separan los que decide el LECTOR (mira el sensor) de los que decide el
 * SERVIDOR (mira la base y el reloj), porque el firmware solo puede enviar los
 * primeros y debe aceptar cualquiera de los otros como respuesta.
 */
enum MotivoFalloEnrolamiento: string
{
    // ─────────────── Los reporta el LECTOR ───────────────

    /** El AS608 no responde. */
    case SinSensor = 'sin_sensor';

    /** Nadie puso el dedo en el tiempo que da el lector. */
    case TimeoutDedo = 'timeout_dedo';

    /** El sensor leyó, pero la imagen no sirve (dedo sucio, mal apoyado). */
    case CapturaDefectuosa = 'captura_defectuosa';

    /** Las dos capturas no son del mismo dedo. */
    case DedosNoCoinciden = 'dedos_no_coinciden';

    /** El sensor no pudo componer la plantilla a partir de las dos capturas. */
    case FalloModelo = 'fallo_modelo';

    /**
     * La ranura que el servidor reservó tiene YA una plantilla física que el
     * servidor no conocía. NO se sobrescribe: el lector reporta esto junto con su
     * índice real y el servidor reserva otra en una orden nueva.
     */
    case RanuraOcupadaEnSensor = 'ranura_ocupada_en_sensor';

    /** El sensor rechazó el `storeModel` por cualquier otra causa. */
    case FalloGuardado = 'fallo_guardado';

    /** Alguien la abortó desde el propio lector. */
    case CanceladaEnDispositivo = 'cancelada_en_dispositivo';

    // ─────────────── Los decide el SERVIDOR ───────────────

    /** Se agotó el tiempo de la orden antes de que el lector la resolviera. */
    case Expirada = 'expirada';

    /** Una persona la canceló desde la web. */
    case CanceladaPorOperador = 'cancelada_por_operador';

    /**
     * Entre la reserva y la confirmación, esa ranura pasó a estar ASIGNADA a
     * alguien. Lo detecta el único de la base al llamar a AsignarHuella, y por eso
     * no se crea ninguna huella.
     */
    case RanuraYaAsignada = 'ranura_ya_asignada';

    /** El lector dice haber grabado en una ranura distinta de la reservada. */
    case RanuraNoCoincide = 'ranura_no_coincide';

    /** El empleado dejó de poder marcar mientras la orden estaba viva. */
    case EmpleadoNoElegible = 'empleado_no_elegible';

    /** ¿Puede enviarlo el lector, o solo lo produce el servidor? */
    public function loReportaElLector(): bool
    {
        return match ($this) {
            self::SinSensor, self::TimeoutDedo, self::CapturaDefectuosa,
            self::DedosNoCoinciden, self::FalloModelo, self::RanuraOcupadaEnSensor,
            self::FalloGuardado, self::CanceladaEnDispositivo => true,
            default => false,
        };
    }

    /** @return array<int, string> Valores que el FormRequest acepta del lector. */
    public static function reportablesPorElLector(): array
    {
        return array_values(array_map(
            fn (self $m) => $m->value,
            array_filter(self::cases(), fn (self $m) => $m->loReportaElLector()),
        ));
    }

    /** Texto para quien administra. Corto: también cabe en la pantalla del lector. */
    public function explicacion(): string
    {
        return match ($this) {
            self::SinSensor => 'El lector no pudo comunicarse con el sensor de huella.',
            self::TimeoutDedo => 'Nadie colocó el dedo a tiempo.',
            self::CapturaDefectuosa => 'La lectura no salió bien. Limpiá el dedo y el sensor, y probá de nuevo.',
            self::DedosNoCoinciden => 'Las dos capturas no eran del mismo dedo.',
            self::FalloModelo => 'El sensor no pudo componer la plantilla con las dos capturas.',
            self::RanuraOcupadaEnSensor => 'Esa ranura ya tenía una plantilla grabada que el sistema no conocía. No se sobrescribió nada.',
            self::FalloGuardado => 'El sensor no pudo guardar la plantilla.',
            self::CanceladaEnDispositivo => 'Se canceló desde el lector.',
            self::Expirada => 'Pasó demasiado tiempo sin completarse.',
            self::CanceladaPorOperador => 'Se canceló desde el sistema.',
            self::RanuraYaAsignada => 'Esa ranura se asignó a otra persona mientras el registro estaba en curso.',
            self::RanuraNoCoincide => 'El lector grabó en una ranura distinta de la reservada. No se asoció nada.',
            self::EmpleadoNoElegible => 'La persona dejó de estar activa mientras el registro estaba en curso.',
        };
    }
}
