<?php

namespace App\Support;

use App\Enums\AmbienteHacienda;
use App\Enums\EstadoDte;
use App\Models\Dte;
use Illuminate\Database\Eloquent\Builder;

/**
 * REGLA ÚNICA de VIGENCIA FISCAL: ¿este documento existe de verdad ante Hacienda y
 * todavía ampara algo?
 *
 * ─────────────────────────── Por qué se extrajo ───────────────────────────
 *
 * La condición ya existía, escrita una sola vez, dentro de {@see PpqElegibilidad}. Pero
 * PPQ no es el único que la necesita: el módulo de Rutas asocia CCF a salidas de ruta y
 * hasta ahora solo comprobaba «tipo 03 y no archivado», así que un BORRADOR o un
 * documento del ambiente de PRUEBAS podía asociarse solo a una salida y aparecer entre
 * los candidatos que se le ofrecen a una persona.
 *
 * Copiar la condición a Rutas habría creado la segunda versión de la verdad que
 * PpqElegibilidad existe para evitar. Meter Rutas dentro de PpqElegibilidad tampoco
 * sirve: PPQ cobra tipos 03 y 05, y Rutas transporta únicamente CCF. Lo COMPARTIDO es
 * exactamente esto —la vigencia fiscal— y lo que cambia es el tipo de documento que cada
 * módulo admite, así que eso se queda en cada uno.
 *
 * PpqElegibilidad NO desaparece ni cambia de comportamiento: sigue siendo la puerta de
 * PPQ y ahora delega acá la parte fiscal.
 *
 * ─────────────────────── Qué deja fuera, y por qué cada cosa ───────────────────────
 *
 *  - INVALIDADO   — se anuló ante Hacienda: ya no ampara nada.
 *  - RECHAZADO    — Hacienda no lo aceptó: nunca llegó a existir.
 *  - ARCHIVADO    — se retiró de la operación diaria.
 *  - PRUEBAS      — ambiente 00: no ampara ninguna operación real.
 *  - NO ACEPTADO  — borrador, generado, firmado o enviado: todavía no es definitivo.
 *  - SIN SELLO REAL — aceptado en apariencia, pero con sello vacío, sello MOCK (una
 *    aceptación simulada de una prueba) o sin fecha de procesamiento del MH. Su código de
 *    generación no existe en Hacienda.
 *
 * El ORDEN de las comprobaciones importa y no es casual: se busca la causa RAÍZ, no la
 * primera condición que falle. Un rechazado que además está archivado se explica por el
 * rechazo —archivarlo fue la consecuencia—, así que el rechazo se mira antes.
 */
final class VigenciaFiscalDte
{
    /**
     * La MISMA regla en SQL, para ordenar o filtrar sin traerse la tabla a PHP.
     * Devuelve 0 si el documento está vigente y 1 si no.
     *
     * Tiene que decir lo mismo que {@see self::motivo()} o una pantalla mostraría un
     * documento y la decisión se tomaría sobre otro. La condición de «no archivado» NO va
     * acá: las consultas que la usan ya aplican `noArchivados()` por su cuenta, y
     * repetirla obligaría a mantener dos copias sincronizadas.
     *
     * `estado = ?` deja fuera de una sola vez a los borradores, los rechazados y los
     * invalidados.
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

    /** ¿El documento existe de verdad ante Hacienda y sigue vigente? */
    public static function esVigente(Dte $dte): bool
    {
        return self::motivo($dte) === null;
    }

    /**
     * POR QUÉ este documento no está fiscalmente vigente, en una frase dirigida a quien
     * está mirando la pantalla; `null` si sí lo está.
     */
    public static function motivo(Dte $dte): ?string
    {
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
            return 'Es un documento del ambiente de pruebas: no ampara ninguna operación real.';
        }

        if ($dte->estado !== EstadoDte::Aceptado) {
            return 'Todavía no está aceptado por Hacienda (estado: '.($dte->estado?->label() ?? '—').').';
        }

        if (! $dte->aceptadoRealmentePorMh()) {
            return 'No tiene sello de recepción real de Hacienda: la aceptación es simulada o quedó incompleta.';
        }

        return null;
    }

    /**
     * La MISMA regla como FILTRO de consulta, para las pantallas que no deben ni ofrecer
     * los documentos que no están vigentes.
     *
     * Se arma componiendo los scopes que ya existen en {@see Dte} en vez de reescribir
     * las condiciones: si algún día cambia qué significa «aceptado realmente por el MH»,
     * cambia en un solo sitio y esto lo sigue.
     *
     * @param  Builder<Dte>  $q
     * @return Builder<Dte>
     */
    public static function filtrar(Builder $q): Builder
    {
        return $q->produccion()->noArchivados()->aceptadoRealMh();
    }
}
