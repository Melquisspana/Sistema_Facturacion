<?php

namespace App\Observers;

use App\Exceptions\Dte\DocumentoInmutableException;
use App\Models\DteLinea;

/**
 * Las líneas son contenido del documento: solo se pueden crear/editar/eliminar
 * mientras el DTE padre esté en borrador.
 */
class DteLineaObserver
{
    public function saving(DteLinea $linea): void
    {
        $this->verificarEditable($linea);
    }

    public function deleting(DteLinea $linea): void
    {
        $this->verificarEditable($linea);
    }

    private function verificarEditable(DteLinea $linea): void
    {
        $dte = $linea->dte;

        if ($dte && ! $dte->esEditable()) {
            throw new DocumentoInmutableException(
                'No se pueden modificar las líneas de un DTE en estado '.$dte->estado->label().'.'
            );
        }
    }
}
