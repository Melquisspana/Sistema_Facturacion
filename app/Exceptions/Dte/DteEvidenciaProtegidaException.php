<?php

namespace App\Exceptions\Dte;

/**
 * Se lanza cuando se intenta invalidar (mock o real) un DTE marcado como PROTEGIDO
 * en `config('dte.invalidacion.protegidos_numero_control' / 'protegidos_codigo_generacion')`
 * — p.ej. evidencia de un cierre de fase de pruebas en APITEST. No tiene flag de
 * override: mientras la protección siga configurada, no hay forma de evadirla desde
 * el comando ni desde la UI.
 */
class DteEvidenciaProtegidaException extends DteInvalidacionException
{
}
