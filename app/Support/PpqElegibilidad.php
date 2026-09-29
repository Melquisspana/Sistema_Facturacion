<?php

namespace App\Support;

use App\Models\Dte;

/**
 * REGLA ÚNICA de elegibilidad de un DTE LOCAL para el cobro por PPQ.
 *
 * Un lote PPQ es un cobro real contra el cliente. Solo puede llevar documentos que
 * existan de verdad ante Hacienda: si un borrador, un documento del ambiente de
 * pruebas o uno rechazado entrara al lote, el Excel le cobraría al cliente algo que
 * tributariamente no existe. Por eso hace falta un candado, y por eso está acá.
 *
 * ─────────────────────────── Por qué UNA sola clase ───────────────────────────
 *
 * Esta condición la necesitan tres lugares distintos:
 *
 *   1. la BÚSQUEDA — para decidir si un documento local cierra la consulta o hay que
 *      seguir buscando en Gmail (PpqBusquedaService);
 *   2. la VISTA — para no dibujar botones que el backend va a rechazar
 *      (resources/views/ppq/busqueda.blade.php);
 *   3. el CONTROLADOR — para rechazar de verdad (PpqItemController::store()).
 *
 * Escrita tres veces, tarde o temprano las tres copias dirían cosas distintas: la
 * pantalla ofrecería un botón que el backend rechaza, o —mucho peor— el backend
 * aceptaría algo que la búsqueda ya había marcado como no confiable. Está en un solo
 * lugar para que esa divergencia no sea posible.
 *
 * Es una pregunta puramente FISCAL —¿el documento EXISTE ante Hacienda?—, delegada en
 * {@see VigenciaFiscalDte}. Hubo una segunda, «¿se puede cobrar HOY?», que sumaba el
 * regreso del CCF físico firmado; se retiró con la custodia (26/09/2026): el papel no es
 * requisito para cobrar.
 *
 * ──────────────────── Lo que este candado NO gobierna ────────────────────
 *
 * Los documentos HISTÓRICOS que llegan por Gmail (ContaPortable / P001). Esos no
 * tienen DTE local que evaluar —los emitió otro sistema— y se agregan como snapshot
 * por su propio camino (PpqItemController::agregarDesdeGmail()).
 * Aplicarles esta regla los bloquearía a todos, que es exactamente lo contrario de lo que
 * hace falta.
 */
final class PpqElegibilidad
{
    /** Tipos de documento cobrables vía PPQ: CCF y nota de crédito. */
    public const TIPOS = ['03', '05'];

    /**
     * La MISMA regla FISCAL en SQL, para ordenar la búsqueda sin traerse la tabla a PHP.
     * Devuelve 0 si el documento es elegible y 1 si no.
     *
     * Vive en {@see VigenciaFiscalDte}, que es donde está escrita la condición; acá se
     * reexpone con el nombre por el que ya la conoce la búsqueda.
     */
    public const SQL_PRIORIDAD = VigenciaFiscalDte::SQL_PRIORIDAD;

    /**
     * Parámetros de {@see self::SQL_PRIORIDAD}, en orden.
     *
     * @return array<int, string>
     */
    public static function bindingsPrioridad(): array
    {
        return VigenciaFiscalDte::bindingsPrioridad();
    }

    /** ¿El documento existe ante Hacienda y es de un tipo cobrable? */
    public static function esElegible(Dte $dte): bool
    {
        return self::motivo($dte) === null;
    }

    /**
     * POR QUÉ este documento no se puede cobrar por PPQ, en una frase para el usuario;
     * `null` si sí se puede.
     *
     * El tipo se mira primero porque es lo que define si este módulo tiene algo que decir
     * sobre el documento; el resto de la vigencia la resuelve {@see VigenciaFiscalDte}.
     */
    public static function motivo(Dte $dte): ?string
    {
        if (! in_array($dte->tipo_dte?->value, self::TIPOS, true)) {
            return 'No es un CCF ni una nota de crédito: PPQ solo cobra documentos tipo 03 y 05.';
        }

        return VigenciaFiscalDte::motivo($dte);
    }
}
