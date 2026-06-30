<?php

namespace App\Enums;

/**
 * Tipo de movimiento/acción registrado en el historial y la auditoría de un DTE.
 *
 * Distinto de EstadoDte: el estado es la situación actual del documento; el
 * movimiento es la acción que ocurrió (quién hizo qué). Se usará en las tablas
 * de historial y transmisiones del motor DTE (fases posteriores).
 */
enum TipoMovimientoDte: string
{
    case Creacion = 'creacion';
    case Modificacion = 'modificacion';
    case Generacion = 'generacion';
    case Firma = 'firma';
    case Transmision = 'transmision';
    case RespuestaHacienda = 'respuesta_hacienda';
    case Invalidacion = 'invalidacion';
    case Consulta = 'consulta';
    case GeneracionPdf = 'generacion_pdf';
    case EnvioCorreo = 'envio_correo';

    public function label(): string
    {
        return match ($this) {
            self::Creacion => 'Creación',
            self::Modificacion => 'Modificación',
            self::Generacion => 'Generación de JSON',
            self::Firma => 'Firma',
            self::Transmision => 'Transmisión a Hacienda',
            self::RespuestaHacienda => 'Respuesta de Hacienda',
            self::Invalidacion => 'Invalidación',
            self::Consulta => 'Consulta',
            self::GeneracionPdf => 'Generación de PDF',
            self::EnvioCorreo => 'Envío por correo',
        };
    }
}
