<?php

namespace App\Exceptions\Ppq;

use RuntimeException;

/**
 * Gmail dejó de estar autorizado (Google respondió `invalid_grant` al renovar el
 * token, o no hay refresh_token con el que intentarlo). Se distingue de otros
 * errores (parseo, red) para que el llamador pueda degradar a la búsqueda local
 * en vez de romper con 500.
 *
 * Su subclase GmailNoDisponibleException cubre los fallos pasajeros, en los que
 * la cuenta sigue conectada.
 */
class GmailDesconectadoException extends RuntimeException {}
