<?php

namespace App\Exceptions\Dte;

use RuntimeException;

/**
 * Otro pedido está generando, firmando o transmitiendo este mismo documento.
 * El mensaje va tal cual a la pantalla: no se hizo nada y se puede reintentar.
 */
class DteEmisionEnCursoException extends RuntimeException {}
