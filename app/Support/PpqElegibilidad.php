<?php

namespace App\Support;

use App\Models\Dte;
use App\Services\Rutas\CustodiaDocumental;

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
 * ──────────────── DOS preguntas distintas, y por qué no son la misma ────────────────
 *
 * Hay que leer con cuidado cuál de los dos métodos corresponde, porque contestan cosas
 * diferentes y confundirlos rompe la búsqueda:
 *
 *   · {@see motivo()} / {@see esElegible()} — ¿el documento EXISTE ante Hacienda?
 *     Es una pregunta puramente FISCAL, delegada en {@see VigenciaFiscalDte}. La usa la
 *     búsqueda para decidir si hace falta ir a Gmail.
 *
 *   · {@see motivoParaCobrar()} / {@see sePuedeCobrar()} — ¿se puede meter HOY a un lote?
 *     Es la anterior MÁS las exigencias documentales del cliente (hoy: el regreso del CCF
 *     físico firmado y sellado). La usan la vista y el controlador.
 *
 * La separación no es cosmética. Si la regla del papel entrara en `esElegible()`, un CCF
 * de producción perfectamente válido cuyo papel todavía no volvió dejaría de «resolver la
 * búsqueda», el sistema saldría a buscarlo a Gmail y podría terminar agregándolo como
 * SNAPSHOT histórico —duplicando un documento que ya tenemos localmente—. El papel es una
 * condición de COBRO, no una duda sobre la existencia del documento.
 *
 * ──────────────────── Lo que este candado NO gobierna ────────────────────
 *
 * Los documentos HISTÓRICOS que llegan por Gmail (ContaPortable / P001). Esos no
 * tienen DTE local que evaluar —los emitió otro sistema— y se agregan como snapshot
 * por su propio camino (PpqItemController::agregarDesdeGmail()).
 * Aplicarles esta regla los bloquearía a todos, que es exactamente lo contrario de lo que
 * hace falta. Eso vale para las dos preguntas, incluida la del papel físico.
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
     * reexpone con el nombre por el que ya la conoce la búsqueda. Deliberadamente NO
     * incluye la regla del papel físico: esto ORDENA resultados, y un documento válido
     * cuyo papel no volvió sigue siendo el resultado más relevante de su búsqueda.
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

    /** ¿El documento existe ante Hacienda y es de un tipo cobrable? (FISCAL, sin el papel) */
    public static function esElegible(Dte $dte): bool
    {
        return self::motivo($dte) === null;
    }

    /**
     * POR QUÉ este documento no se puede cobrar por PPQ, en una frase para el usuario;
     * `null` si sí se puede. SOLO condiciones fiscales.
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

    /**
     * ¿Se puede agregar HOY a un lote de cobro? Fiscal + exigencias documentales del
     * cliente.
     */
    public static function sePuedeCobrar(Dte $dte): bool
    {
        return self::motivoParaCobrar($dte) === null;
    }

    /**
     * POR QUÉ no se puede agregar a un lote, contando también el documento físico;
     * `null` si se puede.
     *
     * El orden importa: primero lo fiscal. A quien intenta cobrar un documento rechazado
     * no le sirve que le digan que falta el papel —el papel no arreglaría nada—, así que
     * la causa raíz se informa primero.
     */
    public static function motivoParaCobrar(Dte $dte): ?string
    {
        return self::motivo($dte) ?? app(CustodiaDocumental::class)->motivoBloqueo($dte);
    }

    /**
     * Aviso que NO impide cobrar, para los clientes que pidieron solo advertencia sobre
     * el documento físico. `null` cuando no hay nada que advertir.
     */
    public static function advertenciaParaCobrar(Dte $dte): ?string
    {
        return self::motivo($dte) === null
            ? app(CustodiaDocumental::class)->advertencia($dte)
            : null;
    }
}
