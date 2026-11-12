<?php

namespace App\Enums;

/**
 * Quién anotó el resultado de una entrega. La confirmación por albarán NO es un origen
 * guardado: se deriva al leer (ver la migración de `salida_ruta_entregas`).
 */
enum OrigenRegistroEntrega: string
{
    /** El vendedor, desde su celular (etapa 3). */
    case Vendedor = 'vendedor';
    /** La oficina, por el vendedor. */
    case Oficina = 'oficina';
    /**
     * La oficina, con el CCF que se facturó DESPUÉS de entregar el producto. Queda entregado
     * en la salida sin dinero: no entra en el cierre del día ni en la liquidación.
     */
    case FacturadoDespues = 'facturado_despues';
}
