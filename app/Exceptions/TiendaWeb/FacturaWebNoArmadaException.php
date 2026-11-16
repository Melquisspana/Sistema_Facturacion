<?php

namespace App\Exceptions\TiendaWeb;

use RuntimeException;

/**
 * El borrador de la factura de un pedido web no se pudo armar o no cuadra con lo cobrado.
 * Revierte la transacción entera y el pedido queda «para revisar» con este mensaje, que
 * nunca lleva datos del cliente.
 */
class FacturaWebNoArmadaException extends RuntimeException {}
