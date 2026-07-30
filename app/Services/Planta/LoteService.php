<?php

namespace App\Services\Planta;

use App\Exceptions\Planta\LoteGenericoNoAplicableException;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Resuelve el LOTE GENÉRICO de un insumo que no controla lotes.
 *
 * El problema que resuelve: el bucket de inventario tiene cinco dimensiones y
 * una de ellas es el lote, sin excepción. Pero hay insumos —bolsas, viñetas—
 * cuya trazabilidad por lote no aporta nada y que nadie va a capturar. Sin una
 * respuesta a «¿qué lote?», esos insumos necesitarían un camino paralelo con
 * lote NULL, y un NULL en una dimensión del bucket rompe el UNIQUE que impide
 * duplicados: en MySQL y en SQLite dos NULL no son iguales.
 *
 * La solución es un lote de sistema, uno por insumo, con código determinista
 * `GEN-<insumo_id>`. Así el bucket sigue teniendo siempre cinco dimensiones
 * reales y el motor no necesita ninguna rama especial.
 *
 * REGLAS:
 *   - Solo para insumos con `controla_lotes = false`. Dárselo a un insumo
 *     trazable destruiría la trazabilidad que ese insumo existe para tener.
 *   - Único por insumo. Lo garantiza el UNIQUE de `codigo_interno`, no una
 *     comprobación previa.
 *   - `fecha_recepcion` es la fecha OPERATIVA del documento que provocó su
 *     creación, y se conserva para siempre: al reutilizarlo NO se toca. Es la
 *     fecha en que ese insumo entró por primera vez al inventario, y reescribirla
 *     en cada uso la convertiría en «la última vez que se movió algo», que ya
 *     está en el mayor.
 *   - No editable ni eliminable: lo bloquea {@see PlantaLote} en `updating` y
 *     `deleting`.
 *   - No aparece como lote real: {@see PlantaLote::scopeReales()} es lo que
 *     deben usar los selectores de la interfaz.
 *
 * Este servicio NO crea lotes reales de recepción: eso llega con el paso 6.
 */
class LoteService
{
    /** Prefijo del código determinista. Coincide con el documentado en la migración. */
    public const PREFIJO_GENERICO = 'GEN-';

    /** Código del lote genérico de un insumo. Determinista: no depende del orden ni del reloj. */
    public static function codigoGenerico(PlantaInsumo|int $insumo): string
    {
        return self::PREFIJO_GENERICO.($insumo instanceof PlantaInsumo ? $insumo->id : $insumo);
    }

    /**
     * Devuelve el lote genérico del insumo, creándolo si es la primera vez.
     *
     * CREACIÓN CONCURRENTE SEGURA. Dos recepciones simultáneas del mismo insumo
     * llegan aquí a la vez y ninguna ve el lote. La secuencia
     * «¿existe? -> no -> créalo» produciría dos filas o un error de duplicado
     * según quién gane, así que no se usa: se INTENTA insertar y se deja que el
     * UNIQUE de `codigo_interno` decida.
     *
     *   1. Lectura optimista: en el caso normal —el lote ya existe— sale aquí,
     *      sin escribir nada.
     *   2. `insertOrIgnore`: si dos procesos llegan juntos, uno inserta y el
     *      otro recibe 0 filas afectadas en vez de una excepción.
     *   3. Relectura BLOQUEANTE del perdedor. Este paso es el que no se puede
     *      omitir: bajo REPEATABLE READ —el aislamiento por defecto de MySQL—
     *      un `SELECT` normal lee del snapshot tomado al abrir la transacción y
     *      NO vería la fila que el ganador acaba de confirmar, así que el
     *      método devolvería null justo en la carrera que intenta resolver.
     *      `lockForUpdate()` fuerza una lectura de la última versión confirmada
     *      y además espera si el ganador todavía no ha cerrado.
     *
     * @param  Carbon|string  $fechaOperativa  Fecha del documento que provoca la creación.
     */
    public function resolverGenerico(PlantaInsumo $insumo, Carbon|string $fechaOperativa): PlantaLote
    {
        if ($insumo->controla_lotes) {
            throw LoteGenericoNoAplicableException::insumoControlaLotes($insumo->id);
        }

        $codigo = self::codigoGenerico($insumo);

        // 1. El camino habitual: ya existe y no hay nada que escribir.
        $existente = PlantaLote::where('codigo_interno', $codigo)->first();

        if ($existente !== null) {
            return $existente;
        }

        $ahora = now();

        // 2. Intento de creación. Se salta Eloquent a propósito: el modelo bloquea
        //    la escritura de lotes genéricos y aquí es donde nacen.
        DB::table('planta_lotes')->insertOrIgnore([
            'planta_insumo_id' => $insumo->id,
            'planta_proveedor_id' => null,
            'codigo_interno' => $codigo,
            'codigo_proveedor' => null,
            'es_generico' => true,
            'fecha_recepcion' => Carbon::parse($fechaOperativa)->toDateString(),
            'fecha_elaboracion' => null,
            'fecha_vencimiento' => null,
            'activo' => true,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        // 3. Relectura bloqueante: ve la fila del ganador aunque el snapshot no la tenga.
        return PlantaLote::where('codigo_interno', $codigo)->lockForUpdate()->firstOrFail();
    }

    /**
     * ¿Este lote es el genérico del insumo indicado? Se comprueba por bandera Y
     * por pertenencia: un lote genérico de OTRO insumo no sirve.
     */
    public function esGenericoDe(PlantaLote $lote, PlantaInsumo $insumo): bool
    {
        return $lote->es_generico && $lote->planta_insumo_id === $insumo->id;
    }
}
