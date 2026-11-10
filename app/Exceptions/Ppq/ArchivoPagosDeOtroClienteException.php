<?php

namespace App\Exceptions\Ppq;

use App\Models\Cobros\CobroAjuste;
use App\Services\Cobros\AplicadorPagosTxt;
use RuntimeException;

/**
 * Un ajuste (QD) de este TXT —misma huella y línea— ya está registrado en OTRO cliente.
 *
 * La huella y la línea identifican el ajuste en todo el sistema, así que reutilizarlo
 * pondría en este cliente la deducción (y su nota de crédito) de otro. Al hacer la nota
 * desde la revisión se rechaza; al aplicar el TXT, {@see AplicadorPagosTxt::aplicar()}
 * salta solo esa línea, la informa y aplica el resto.
 *
 * @see AplicadorPagosTxt::registrarAjuste()
 */
class ArchivoPagosDeOtroClienteException extends RuntimeException
{
    public function __construct(public readonly CobroAjuste $ajuste)
    {
        parent::__construct(sprintf(
            'La línea %s de este TXT ya está registrada en otro cliente (%s, ajuste #%d). '
            .'No se aplicó nada: revisá a qué cliente corresponde el archivo.',
            $ajuste->referencia_linea,
            $ajuste->cliente?->nombre ?? 'id '.$ajuste->cliente_id,
            $ajuste->id,
        ));
    }
}
