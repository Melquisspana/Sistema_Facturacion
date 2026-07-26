<?php

namespace App\Services\Dte;

use App\Models\Dte;
use App\Models\Secuencia;

/**
 * Asigna la NUMERACIÓN GLOBAL VISIBLE del sistema (`dtes.numero_sistema`): el número
 * comercial que se muestra en pantalla, único y compartido por todos los tipos de
 * documento (CCF 03, NC 05, factura 01, FEX 11).
 *
 * Qué NO es: no es el número de control del MH, no es el código de generación, no es el
 * número interno y no es el correlativo fiscal. No reemplaza ni altera ninguno de ellos.
 *
 * Reglas:
 *  - Solo PRODUCCIÓN (ambiente 01). En pruebas/APITEST no se consume número: la serie
 *    visible del negocio no debe tener huecos por documentos de simulación.
 *  - Solo en el punto IRREVERSIBLE de la generación (dentro de la transacción que consume
 *    el correlativo fiscal). Abrir un formulario o crear un borrador NO numera.
 *  - Idempotente: si el documento ya tiene número, no lo cambia ni consume otro.
 *  - El número consumido NO se libera nunca (rechazo, invalidación o archivado lo
 *    conservan), igual que el correlativo fiscal.
 */
class NumeroSistemaService
{
    /**
     * Asigna el número al documento SIN guardarlo (lo persiste quien generó, en su misma
     * transacción). Devuelve el número asignado, o null si a este documento no le
     * corresponde uno.
     *
     * Debe llamarse dentro de una transacción: {@see Secuencia::siguiente()} lo exige.
     */
    public function asignar(Dte $dte): ?int
    {
        if (! $this->corresponde($dte)) {
            return null;
        }

        $dte->numero_sistema = Secuencia::siguiente(Secuencia::NUMERO_SISTEMA);

        return $dte->numero_sistema;
    }

    /**
     * ¿A este documento le corresponde consumir número de sistema? Solo producción y solo
     * si todavía no tiene uno (nunca se renumera).
     */
    public function corresponde(Dte $dte): bool
    {
        return $dte->ambiente?->esProduccion() === true && $dte->numero_sistema === null;
    }
}
