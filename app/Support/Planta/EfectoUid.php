<?php

namespace App\Support\Planta;

/**
 * Huella determinista de UN efecto sobre UN bucket. Es la idempotencia dura del
 * libro mayor: el UNIQUE de `planta_movimientos.efecto_uid` convierte «no
 * apliques dos veces lo mismo» en una garantía del motor, no en una intención
 * del código.
 *
 * QUÉ ENTRA EN EL HASH, y por qué cada pieza:
 *
 *   1. `documento_type` + `documento_id` — de qué documento nace el efecto. Dos
 *      recepciones distintas que mueven lo mismo son hechos distintos.
 *   2. `documento_detalle_id` — la línea concreta. Un documento con dos líneas
 *      del mismo insumo, lote y ubicación produce dos efectos legítimos; sin el
 *      detalle serían el mismo hash y el segundo se perdería.
 *   3. `transicion` — el mismo documento pasa por varias transiciones y cada una
 *      escribe: confirmar, enviar, recibir, reversar. Son efectos distintos del
 *      mismo documento.
 *   4. `tipo` — el porqué contable del efecto. Separa el recorrido físico de la
 *      compensación aunque coincidan documento, detalle y bucket.
 *   5. El bucket COMPLETO, sus cinco dimensiones — el mismo detalle puede
 *      afectar a dos buckets a la vez (un cambio de disponibilidad resta de
 *      `retenido` y suma a `disponible`); cada uno es su propio efecto.
 *   6. `secuencia` — el lado o el orden lógico cuando una sola operación genera
 *      MÁS DE UN efecto sobre el MISMO bucket. Sin ella, esos efectos
 *      colisionarían y el segundo sería rechazado como duplicado siendo
 *      legítimo. Con ella, dos efectos sobre el mismo bucket coexisten si y solo
 *      si el llamador declara explícitamente que son distintos.
 *
 * QUÉ NO ENTRA, deliberadamente:
 *
 *   - La CANTIDAD. Es lo que hace útil al uid: si un reintento llega con una
 *     cantidad distinta para el mismo efecto, no queremos dos filas —una por
 *     importe—, queremos que la segunda sea rechazada y que alguien mire por qué
 *     el mismo efecto cambió de importe.
 *   - `grupo_uuid`. Agrupa los movimientos de una operación; usarlo como
 *     idempotencia impediría el caso normal de varios efectos por operación.
 *   - `user_id`, `created_at`, `fecha_efectiva`, `metadata`. Si el mismo efecto
 *     se reintenta con otro usuario u otra fecha sigue siendo el mismo efecto:
 *     incluirlos abriría un hueco por el que colar duplicados.
 *
 * FORMATO. SHA-256 en hexadecimal, 64 caracteres exactos, que es el ancho de la
 * columna `char(64)`. El prefijo de versión `v1` permite cambiar el algoritmo en
 * el futuro sin que los uid nuevos choquen con los viejos ni los invaliden.
 *
 * El separador `|` no puede aparecer dentro de ningún campo: cuatro son enteros,
 * dos son enums cerrados, `transicion` es un verbo del dominio y
 * `documento_type` es un FQCN de PHP. Aun así el `documento_type` se normaliza
 * para que no dependa de la barra inicial con la que se haya escrito.
 */
final class EfectoUid
{
    /** Versión del algoritmo. Cambiarla invalida la correspondencia con los uid ya escritos. */
    private const VERSION = 'v1';

    /** Calcula la huella del efecto que `$contexto` provoca sobre `$bucket`. */
    public static function calcular(BucketInventario $bucket, ContextoMovimiento $contexto): string
    {
        return hash('sha256', implode('|', [
            self::VERSION,
            ltrim($contexto->documentoType, '\\'),
            $contexto->documentoId,
            // El nulo se codifica como '-', no como cadena vacía: así «sin detalle»
            // y «detalle vacío» no pueden confundirse nunca.
            $contexto->documentoDetalleId ?? '-',
            $contexto->transicion,
            $contexto->tipo->value,
            $bucket->claveCanonica(),
            $contexto->secuencia,
        ]));
    }
}
