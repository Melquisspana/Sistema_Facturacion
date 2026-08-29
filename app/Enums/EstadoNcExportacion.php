<?php

namespace App\Enums;

/**
 * Hasta dónde llegó un lote de notas de crédito.
 *
 * Solo hay dos estados porque solo hay dos hechos comprobables: el archivo se ARMÓ, y
 * alguien lo DESCARGÓ. Deliberadamente NO existe un estado «enviado»: hoy el envío al
 * cliente se hace fuera del sistema —se adjunta el .xlsx a un correo a mano—, así que
 * marcarlo como enviado sería afirmar algo de lo que no tenemos ni una prueba. Un lote
 * descargado y nunca adjuntado se vería idéntico a uno entregado, y el día que falte un
 * abono nadie podría distinguirlos.
 *
 * Cuando el envío por correo se implemente de verdad —con su registro de destinatarios,
 * fecha y resultado, como ya hace {@see \App\Models\DteEnvio} para los DTE— entonces sí
 * corresponde un caso `Enviado`, respaldado por esa evidencia y no por una suposición.
 */
enum EstadoNcExportacion: string
{
    /** El archivo se armó y quedó registrado, pero nadie lo ha bajado todavía. */
    case Generado = 'generado';

    /** Alguien descargó el archivo al menos una vez (queda fecha y contador). */
    case Descargado = 'descargado';

    public function label(): string
    {
        return match ($this) {
            self::Generado => 'Generado',
            self::Descargado => 'Descargado',
        };
    }

    /** Texto de apoyo para la pantalla: qué significa y qué NO significa. */
    public function detalle(): string
    {
        return match ($this) {
            self::Generado => 'El archivo está listo; todavía no se ha descargado.',
            self::Descargado => 'El archivo se descargó. El envío al cliente se hace fuera del sistema.',
        };
    }

    /** Clases del badge. Ninguno usa verde: verde daría a entender «entregado». */
    public function clase(): string
    {
        return match ($this) {
            self::Generado => 'bg-gray-100 text-gray-600',
            self::Descargado => 'bg-indigo-100 text-indigo-700',
        };
    }
}
