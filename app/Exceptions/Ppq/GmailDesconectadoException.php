<?php

namespace App\Exceptions\Ppq;

use RuntimeException;

/**
 * Gmail dejó de estar autorizado (token expirado/revocado por Google:
 * invalid_grant, 401, 403). Se distingue de otros errores (parseo, red) para
 * que el llamador pueda degradar a la búsqueda local en vez de romper con 500.
 */
class GmailDesconectadoException extends RuntimeException {}
