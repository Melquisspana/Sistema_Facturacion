<?php

namespace App\Enums;

/**
 * De dónde sale la COPIA ARCHIVADA del archivo de un lote de notas de crédito.
 *
 * La distinción existe porque la base no guardaba los bytes antes de esta función: un lote
 * que ya se había descargado solo puede reconstruirse desde sus notas, y llamar a eso
 * «el archivo entregado» afirmaría algo que no consta.
 */
enum ProcedenciaArchivoNc: string
{
    /**
     * Se archivó al preparar la primera descarga: son los bytes que se sirvieron entonces.
     * Que el navegador o el cliente los recibieran no consta.
     */
    case PrimeraDescarga = 'primera_descarga';

    /** Reconstruida desde las notas de un lote que ya se había bajado sin copia. */
    case Reconstruccion = 'reconstruccion';

    public function label(): string
    {
        return match ($this) {
            self::PrimeraDescarga => 'Copia de la primera descarga',
            self::Reconstruccion => 'Reconstrucción histórica',
        };
    }

    public function detalle(): string
    {
        return match ($this) {
            self::PrimeraDescarga => 'Se guardó al preparar la primera descarga; cada descarga sirve esos mismos bytes. '
                .'Descargar no prueba que el cliente lo haya recibido.',
            self::Reconstruccion => 'Este lote se había descargado antes de que existiera el archivado: la copia se '
                .'reconstruyó desde sus notas y no prueba cuál fue el archivo descargado entonces. '
                .'Desde ahora cada descarga sirve esta misma reconstrucción.',
        };
    }
}
