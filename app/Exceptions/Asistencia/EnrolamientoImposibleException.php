<?php

namespace App\Exceptions\Asistencia;

use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaOrdenEnrolamiento;
use RuntimeException;

/**
 * No se puede iniciar el registro de una huella, y se puede explicar por qué.
 *
 * No es un error de programación: son los estados normales del sistema cuando
 * alguien pulsa el botón en el momento equivocado. Por eso cada caso trae un
 * mensaje que dice qué pasó Y qué hacer, en vez de un «no se pudo».
 */
class EnrolamientoImposibleException extends RuntimeException
{
    private function __construct(string $mensaje, public readonly string $motivo)
    {
        parent::__construct($mensaje);
    }

    /**
     * EL CASO QUE PIDIÓ EL ENCARGO. Sin índice del sensor, el servidor no sabe
     * cuántas ranuras hay ni cuáles tienen ya una plantilla física. Reservar
     * entonces sería apostar, y la apuesta se pierde contra los sensores que
     * traen plantillas de antes.
     */
    public static function sinSincronizar(AsistenciaDispositivo $lector): self
    {
        return new self(
            "El lector «{$lector->nombre}» todavía no ha sincronizado sus ranuras. "
            .'Hasta que reporte su capacidad y qué plantillas tiene grabadas, el sistema no puede '
            .'elegir una ranura sin arriesgarse a pisar una existente. Encendé el lector y esperá a '
            .'que se comunique.',
            'sin_sincronizar',
        );
    }

    public static function sensorLleno(AsistenciaDispositivo $lector): self
    {
        return new self(
            "El sensor del lector «{$lector->nombre}» no tiene ranuras libres "
            ."(capacidad {$lector->capacidad_sensor}). Liberá alguna asignación y borrá su plantilla "
            .'en el sensor antes de registrar otra huella.',
            'sensor_lleno',
        );
    }

    public static function ordenActiva(AsistenciaOrdenEnrolamiento $orden): self
    {
        $de = $orden->empleado?->nombreCompleto() ?? 'otra persona';

        return new self(
            "Ese lector ya está registrando la huella de {$de}. Esperá a que termine o cancelá "
            .'ese registro antes de iniciar otro.',
            'orden_activa',
        );
    }

    public static function lectorInactivo(AsistenciaDispositivo $lector): self
    {
        return new self(
            "El lector «{$lector->nombre}» está desactivado: no autentica, así que nunca recibiría la orden.",
            'lector_inactivo',
        );
    }

    public static function empleadoInactivo(): self
    {
        return new self(
            'Esa persona está desactivada. Reactivala antes de registrarle una huella: una huella '
            .'suya no podría marcar nada.',
            'empleado_inactivo',
        );
    }

    /** La ranura escrita a mano en «opciones avanzadas» no sirve. */
    public static function ranuraManualInvalida(string $detalle): self
    {
        return new self($detalle, 'ranura_manual_invalida');
    }
}
