<?php

namespace App\Services\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Dte;
use App\Support\Dte\CodigoGeneracion;
use App\Support\Dte\EmisorDte;
use App\Support\Dte\PoliticaInvalidacion;

/**
 * Verifica EN SERVIDOR el «documento sustituto» (`documento.codigoGeneracionR`) del
 * evento de invalidación, cuando {@see PoliticaInvalidacion} lo exige.
 *
 * ── Qué se verifica y por qué ─────────────────────────────────────────────────
 * El manual pide un documento «previamente aceptado» por Hacienda. Un UUID con formato
 * correcto NO es prueba de aceptación: el formato lo cumple cualquier cadena inventada.
 * Así que el sustituto tiene que EXISTIR en este sistema y, sobre esa fila, cumplir:
 *
 *  1. ser distinto del documento que se invalida;
 *  2. ser de un tipo admisible como sustituto ({@see PoliticaInvalidacion::tiposSustituto()});
 *  3. ser del MISMO emisor — mismo NIT ante Hacienda, no la misma fila de `empresas`
 *     (ver {@see EmisorDte}): una empresa no sustituye el documento de otra;
 *  4. ser del MISMO ambiente (un documento de apitest jamás reemplaza uno de producción);
 *  5. estar ACEPTADO REALMENTE por el MH — sello real, no MOCK, con fecha de procesamiento;
 *  6. no estar invalidado.
 *
 * ── Lo que NO se exige, a propósito ───────────────────────────────────────────
 * IGUALDAD DE `cliente_id`. Una corrección puede cambiar los datos del receptor —es el
 * caso típico del motivo 1— y exigir el mismo cliente bloquearía justo la operación que
 * la norma contempla. El buscador PRIORIZA el mismo cliente (comodidad), pero ni el
 * buscador ni esta verificación lo imponen. Cualquier restricción adicional tendría que
 * venir del manual o de sus anexos, no de una suposición.
 *
 * ── Ingreso manual ────────────────────────────────────────────────────────────
 * Escribir el código a mano NO elude nada: pasa por esta misma verificación. Si el
 * documento no existe localmente, el sistema lo dice con todas las letras y BLOQUEA la
 * operación, porque no hay ningún mecanismo autorizado implementado para comprobar
 * contra Hacienda la aceptación de un código ajeno. Inventar un sello o dar por bueno el
 * UUID sería fabricar evidencia fiscal. Queda documentado como limitación: un sustituto
 * emitido por OTRO sistema del mismo contribuyente no se puede usar por esta vía hasta
 * que exista una consulta verificable habilitada.
 */
class VerificadorDocumentoReemplazo
{
    /**
     * @return array{0: ?Dte, 1: array<int, string>} [sustituto verificado o null, problemas]
     */
    public function verificar(Dte $invalidado, ?string $codigo): array
    {
        $codigo = strtoupper(trim((string) $codigo));

        if ($codigo === '') {
            return [null, ['Falta el código de generación del documento que sustituye al invalidado.']];
        }

        if (! CodigoGeneracion::esValido($codigo)) {
            return [null, ['El código de generación del documento de reemplazo no tiene formato oficial (UUID v4 en mayúsculas).']];
        }

        if ($codigo === strtoupper((string) $invalidado->codigo_generacion)) {
            return [null, ['El documento de reemplazo no puede ser el mismo DTE que se está invalidando.']];
        }

        $sustituto = $this->buscar($invalidado, $codigo);

        if ($sustituto === null) {
            return [null, [
                'El documento de reemplazo '.$codigo.' no existe en este sistema, así que no se puede comprobar '
                .'que Hacienda lo haya aceptado. No se transmite una invalidación con un sustituto sin verificar: '
                .'elegí un documento de la lista o emití primero el sustituto en este sistema. '
                .'(Un sustituto emitido en otro sistema no se puede validar por esta vía.)',
            ]];
        }

        return [$sustituto, $this->problemasDelSustituto($invalidado, $sustituto)];
    }

    /**
     * La fila del sustituto, buscada por código de generación. Se busca SIN filtrar por
     * emisor/ambiente/tipo a propósito: encontrarla y explicar por qué no sirve es mucho
     * más útil que decir «no existe» cuando sí existe pero es de otro ambiente.
     */
    private function buscar(Dte $invalidado, string $codigo): ?Dte
    {
        return Dte::query()
            ->with('establecimiento.empresa')
            ->whereRaw('UPPER(codigo_generacion) = ?', [$codigo])
            ->when($invalidado->exists, fn ($q) => $q->whereKeyNot($invalidado->getKey()))
            ->first();
    }

    /**
     * Reglas 2-6 sobre una fila que YA se encontró.
     *
     * @return array<int, string>
     */
    private function problemasDelSustituto(Dte $invalidado, Dte $sustituto): array
    {
        $problemas = [];

        $tiposAdmitidos = $invalidado->tipo_dte !== null
            ? PoliticaInvalidacion::tiposSustituto($invalidado->tipo_dte)
            : [];

        if ($tiposAdmitidos !== [] && ! in_array($sustituto->tipo_dte, $tiposAdmitidos, true)) {
            $problemas[] = 'El documento de reemplazo es un '.($sustituto->tipo_dte?->label() ?? 'documento sin tipo')
                .' y para invalidar un '.$invalidado->tipo_dte->label().' el sustituto debe ser del mismo tipo ('
                .implode(', ', array_map(fn (TipoDte $t) => $t->value, $tiposAdmitidos)).').';
        }

        $nitInvalidado = EmisorDte::nit($invalidado);
        $nitSustituto = EmisorDte::nit($sustituto);
        // Dos null NO se dan por iguales: sin emisor resoluble no se puede afirmar que
        // sea el mismo contribuyente, y en la duda se bloquea.
        if ($nitInvalidado === null || $nitSustituto === null || $nitInvalidado !== $nitSustituto) {
            $problemas[] = 'El documento de reemplazo pertenece a otro emisor (o no se pudo determinar el emisor): '
                .'el sustituto debe haberlo emitido el mismo contribuyente que el documento que se invalida.';
        }

        if ($sustituto->ambiente !== $invalidado->ambiente) {
            $problemas[] = 'El documento de reemplazo es del ambiente '.($sustituto->ambiente?->value ?? '—')
                .' y el documento a invalidar es del ambiente '.($invalidado->ambiente?->value ?? '—')
                .'. Un documento de pruebas no puede sustituir a uno de producción (ni al revés).';
        }

        if ($sustituto->estado === EstadoDte::Invalidado) {
            $problemas[] = 'El documento de reemplazo está INVALIDADO ante Hacienda: no puede sustituir a nadie.';
        } elseif (! $sustituto->aceptadoRealmentePorMh()) {
            $problemas[] = 'El documento de reemplazo no está aceptado realmente por Hacienda (hace falta estado '
                .'aceptado, sello de recepción real —no MOCK— y fecha de procesamiento del MH). Estado actual: '
                .($sustituto->estado?->label() ?? '—').'.';
        }

        return $problemas;
    }

}
