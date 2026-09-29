<?php

namespace App\Support\Dte;

use App\Enums\TipoAnulacionMh;
use App\Enums\TipoDte;

/**
 * POLÍTICA DE DOMINIO de la invalidación oficial (evento anulardte): qué exige el MH
 * según el TIPO DE DOCUMENTO que se invalida y el MOTIVO de CAT-024.
 *
 * ── Por qué existe ────────────────────────────────────────────────────────────
 * La regla NO es una propiedad del motivo. Antes vivía en
 * `TipoAnulacionMh::requiereDocumentoReemplazo()` —un método del motivo, sin contexto
 * documental— y por eso exigía sustituto para CUALQUIER motivo 1 y lo prohibía para el
 * motivo 3. El Manual Funcional del Sistema de Transmisión v2.0 (mayo 2026), páginas
 * impresas 13-16, dice otra cosa:
 *
 * | Documento      | 1 · Error       | 2 · Rescindir | 3 · Otro                      |
 * |----------------|-----------------|---------------|-------------------------------|
 * | FE  01         | sustituto       | null          | sustituto + motivo en texto   |
 * | CCF 03         | sustituto       | null          | sustituto + motivo en texto   |
 * | NC  05         | null            | null          | null + motivo en texto        |
 * | FEX 11         | sustituto       | null          | sustituto + motivo en texto   |
 *
 * «Sustituto» siempre significa un documento PREVIAMENTE ACEPTADO por Hacienda; quién
 * comprueba esa aceptación es {@see \App\Services\Dte\VerificadorDocumentoReemplazo},
 * no esta clase (aquí no hay base de datos: solo la matriz).
 *
 * Para la NC, el manual describe el orden inverso: primero se invalida la nota incorrecta
 * y DESPUÉS se emite la corregida. Eso es una nota operativa, no una acción automática:
 * este módulo nunca crea ni transmite otra NC por su cuenta.
 *
 * ── Tipos no soportados ───────────────────────────────────────────────────────
 * Solo se responde por los cuatro tipos habilitados ({@see TipoDte::habilitados()}).
 * Cualquier otro —hoy, la Nota de Débito 06, que no tiene flujo de emisión— devuelve
 * `soportado = false` con su explicación. NUNCA se adopta la regla del CCF por defecto:
 * inventar la matriz de un documento que no conocemos es exactamente como se llegó al
 * comportamiento que este cambio corrige.
 */
final class PoliticaInvalidacion
{
    /**
     * Tipos que exigen documento SUSTITUTO en los motivos 1 y 3 (manual, págs. 13-16).
     * La NC 05 queda fuera a propósito: sus tres motivos llevan `codigoGeneracionR` null.
     *
     * @var array<int, string>
     */
    private const EXIGEN_SUSTITUTO = [
        TipoDte::Factura->value,
        TipoDte::CreditoFiscal->value,
        TipoDte::FacturaExportacion->value,
    ];

    /**
     * Tipos cuya invalidación queda PROHIBIDA mientras exista en su contra una nota de
     * crédito o de débito validada vigente (manual, págs. impresas 13-16).
     *
     * Solo el CCF, y eso es deliberado. Que el modelo PUEDA representar una nota contra
     * una FE, una FEX o una NC no convierte esa relación en una prohibición fiscal: la
     * fuente revisada enuncia la regla para el comprobante de crédito fiscal y para nadie
     * más. Extenderla «por prudencia» sería inventar una restricción del MH, que es
     * exactamente el error que esta política existe para no repetir.
     *
     * Para FE/FEX el manual contempla además relaciones con eventos de RETORNO (págs.
     * 26-28), que no están implementados y que tendrán su propia política cuando lo estén.
     * Hasta entonces no se finge cubrirlas con esta.
     *
     * @var array<int, string>
     */
    private const DEPENDEN_DE_NOTAS_VIGENTES = [
        TipoDte::CreditoFiscal->value,
    ];

    /** Requisitos oficiales para invalidar `$documento` por `$motivo`. */
    public static function requisitos(?TipoDte $documento, TipoAnulacionMh $motivo): RequisitosInvalidacion
    {
        // El motivo 3 exige texto en CUALQUIER documento: esa sí es una regla del motivo.
        $requiereMotivoTexto = $motivo === TipoAnulacionMh::Otro;

        if ($documento === null || ! in_array($documento, TipoDte::habilitados(), true)) {
            return new RequisitosInvalidacion(
                motivo: $motivo,
                documento: $documento,
                soportado: false,
                requiereReemplazo: false,
                requiereMotivoTexto: $requiereMotivoTexto,
                razonNoSoportado: 'No hay regla de invalidación definida para el tipo de documento '
                    .($documento?->value ?? '(desconocido)').' ('.($documento?->label() ?? 'sin tipo').'). '
                    .'Este sistema solo invalida los tipos habilitados '.self::listaHabilitados().'. '
                    .'No se aplica por defecto la regla del CCF.',
            );
        }

        // Motivo 2 (rescindir) nunca lleva sustituto, en ningún tipo. Los motivos 1 y 3
        // lo exigen solo en los tipos de la lista; la NC los lleva en null.
        $requiereReemplazo = $motivo !== TipoAnulacionMh::RescindirOperacion
            && in_array($documento->value, self::EXIGEN_SUSTITUTO, true);

        return new RequisitosInvalidacion(
            motivo: $motivo,
            documento: $documento,
            soportado: true,
            requiereReemplazo: $requiereReemplazo,
            requiereMotivoTexto: $requiereMotivoTexto,
            tiposSustituto: $requiereReemplazo ? self::tiposSustituto($documento) : [],
            notaOperativa: self::notaOperativa($documento, $motivo),
        );
    }

    /**
     * ¿La invalidación de `$documento` está prohibida mientras tenga notas de
     * crédito/débito vigentes en su contra? Ver {@see self::DEPENDEN_DE_NOTAS_VIGENTES}.
     *
     * LISTAR las notas relacionadas y DECIDIR que prohíben invalidar son cosas distintas:
     * `Dte::notasFiscalesVigentes()` responde lo primero para cualquier documento, y esta
     * política responde lo segundo. La UI, el serializador y el servicio consumen esta
     * decisión; ninguno la deduce por su cuenta.
     */
    public static function dependeDeNotasVigentes(?TipoDte $documento): bool
    {
        return $documento !== null
            && in_array($documento->value, self::DEPENDEN_DE_NOTAS_VIGENTES, true);
    }

    /**
     * Tipos de DTE admisibles como SUSTITUTO de `$documento`.
     *
     * El manual presenta el sustituto dentro de la misma clase documental: lo que
     * reemplaza a un CCF es otro CCF, y lo mismo con FE y FEX. Por eso la lista es el
     * propio tipo, y por eso el buscador ({@see \App\Services\Dte\BusquedaDocumentoReemplazo})
     * filtra por ella en vez de ofrecer cualquier documento aceptado.
     *
     * LIMITACIÓN CONOCIDA, registrada para el cierre fiscal: un error de clasificación del
     * receptor (consumidor final facturado como contribuyente o al revés) se corrige
     * emitiendo un documento de OTRA clase. Si la Normativa de Cumplimiento y sus anexos
     * confirman ese cruce, se amplía AQUÍ —en un solo lugar— y no en cada consumidor.
     *
     * @return array<int, TipoDte>
     */
    public static function tiposSustituto(TipoDte $documento): array
    {
        return [$documento];
    }

    /**
     * Aclaración operativa que la UI muestra junto al motivo elegido. Describe el ORDEN
     * de trabajo; no dispara ninguna acción.
     */
    private static function notaOperativa(TipoDte $documento, TipoAnulacionMh $motivo): ?string
    {
        if ($documento === TipoDte::NotaCredito && $motivo !== TipoAnulacionMh::RescindirOperacion) {
            return 'La nota de crédito se invalida SIN documento de reemplazo. Si hace falta corregirla, '
                .'emití la nota correcta DESPUÉS de que Hacienda acepte esta invalidación; el sistema no '
                .'la crea ni la transmite solo.';
        }

        return null;
    }

    /** «01, 03, 05 y 11» para los mensajes de tipo no soportado. */
    private static function listaHabilitados(): string
    {
        return implode(', ', array_map(fn (TipoDte $t) => $t->value, TipoDte::habilitados()));
    }
}
