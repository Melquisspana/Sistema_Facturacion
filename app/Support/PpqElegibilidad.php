<?php

namespace App\Support;

use App\Enums\AmbienteHacienda;
use App\Enums\EstadoDte;
use App\Models\Dte;

/**
 * REGLA ÚNICA de elegibilidad de un DTE LOCAL para el cobro por PPQ.
 *
 * Un lote PPQ es un cobro real contra Calleja. Solo puede llevar documentos que
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
 *      (resources/views/ppq/partials/resultado.blade.php);
 *   3. el CONTROLADOR — para rechazar de verdad (PpqItemController::store()).
 *
 * Se las nombra en texto y no con {@see}: esta clase es la capa de abajo, y no tiene
 * por qué importar al controlador que la usa solo para poder enlazarlo.
 *
 * Escrita tres veces, tarde o temprano las tres copias dirían cosas distintas: la
 * pantalla ofrecería un botón que el backend rechaza, o —mucho peor— el backend
 * aceptaría algo que la búsqueda ya había marcado como no confiable. Está en un solo
 * lugar para que esa divergencia no sea posible.
 *
 * ──────────────────── Lo que este candado NO gobierna ────────────────────
 *
 * Los documentos HISTÓRICOS que llegan por Gmail (ContaPortable / P001). Esos no
 * tienen DTE local que evaluar —los emitió otro sistema— y se agregan como snapshot
 * por su propio camino. Aplicarles esta regla los bloquearía a todos, que es
 * exactamente lo contrario de lo que hace falta.
 */
final class PpqElegibilidad
{
    /** Tipos de documento cobrables vía PPQ: CCF y nota de crédito. */
    public const TIPOS = ['03', '05'];

    /**
     * La MISMA regla en SQL, para ordenar la búsqueda sin traerse la tabla a PHP.
     * Devuelve 0 si el documento es elegible y 1 si no.
     *
     * Tiene que decir lo mismo que {@see self::motivo()} o la primera página mostraría
     * un documento y la decisión se tomaría sobre otro. Las dos condiciones que faltan
     * acá —tipo 03/05 y no archivado— las aplica la propia consulta antes de ordenar
     * (`whereIn('tipo_dte', …)` y `noArchivados()`), así que no se repiten.
     *
     * `estado = 'aceptado'` es lo que deja fuera, de una sola vez, a los borradores, los
     * rechazados y los invalidados.
     */
    public const SQL_PRIORIDAD = <<<'SQL'
        CASE WHEN estado = ?
              AND ambiente = ?
              AND sello_recepcion IS NOT NULL
              AND sello_recepcion <> ''
              AND UPPER(sello_recepcion) NOT LIKE 'MOCK%'
              AND fecha_procesamiento_mh IS NOT NULL
             THEN 0 ELSE 1 END
        SQL;

    /**
     * Parámetros de {@see self::SQL_PRIORIDAD}, en orden.
     *
     * @return array<int, string>
     */
    public static function bindingsPrioridad(): array
    {
        return [EstadoDte::Aceptado->value, AmbienteHacienda::Produccion->value];
    }

    /** ¿Se puede agregar este documento a un lote PPQ? */
    public static function esElegible(Dte $dte): bool
    {
        return self::motivo($dte) === null;
    }

    /**
     * POR QUÉ este documento no se puede cobrar por PPQ, en una frase para el usuario;
     * `null` si sí se puede.
     *
     * El orden de las comprobaciones importa: se busca la causa RAÍZ, no la primera
     * condición que falle. Un rechazado que además está archivado se explica por el
     * rechazo —lo de archivarlo fue la consecuencia—, así que el rechazo se mira antes.
     */
    public static function motivo(Dte $dte): ?string
    {
        if (! in_array($dte->tipo_dte?->value, self::TIPOS, true)) {
            return 'No es un CCF ni una nota de crédito: PPQ solo cobra documentos tipo 03 y 05.';
        }

        if ($dte->estado === EstadoDte::Invalidado) {
            return 'Fue invalidado ante Hacienda: ya no ampara ningún cobro.';
        }

        if ($dte->estado === EstadoDte::Rechazado) {
            return 'Hacienda lo rechazó: no llegó a existir como documento tributario.';
        }

        if ($dte->archivado) {
            return 'Está archivado: quedó retirado de la operación diaria.';
        }

        if ($dte->ambiente !== AmbienteHacienda::Produccion) {
            return 'Es un documento del ambiente de pruebas: no ampara ningún cobro real.';
        }

        if ($dte->estado !== EstadoDte::Aceptado) {
            return 'Todavía no está aceptado por Hacienda (estado: '.($dte->estado?->label() ?? '—').').';
        }

        // Aceptado, pero sin la huella real del MH: sello vacío, sello MOCK (aceptación
        // simulada de una prueba) o sin fecha de procesamiento. Su código de generación
        // no existe en Hacienda, así que cobrarlo sería cobrar un documento inexistente.
        if (! $dte->aceptadoRealmentePorMh()) {
            return 'No tiene sello de recepción real de Hacienda: la aceptación es simulada o quedó incompleta.';
        }

        return null;
    }
}
