<?php

use App\Support\EjercicioControl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IDENTIDAD POR EJERCICIO en Cobros y PPQ (#121).
 *
 * Desde el 01/01/2027 el correlativo del número de control vuelve a 1 cada ejercicio
 * (Normativa 2.0, regla 7.1; #42). El mismo número puede ser un CCF de 2026 y otro de 2027,
 * así que `numero_control_norm` deja de ser identidad por sí solo: pasa a ser número +
 * `ejercicio_control`.
 *
 * ─────────────────────── Por qué max(año, 2026) y no el año real ───────────────────────
 *
 * Antes de 2027 el número ya era único sin ayuda (lo garantizaba el único global de
 * `cobro_documentos`). Todo lo anterior al reinicio comparte entonces el valor 2026, y un
 * histórico sin fecha —externos incorporados a mano, snapshots de Gmail— queda con su
 * identidad EXACTA sin adivinarle un año. Desde 2027 vale el año de emisión.
 *
 * ─────────────────────────────── Relleno y NULL ───────────────────────────────
 *
 * Fecha propia → fecha del DTE vinculado → 2026. Nunca queda NULL: en un índice único
 * MySQL y SQLite consideran distintos los NULL, y esas filas dejarían de excluirse. Hoy no
 * hay dos filas con el mismo número, así que el relleno no puede chocar con los índices.
 * La regla se escribe acá en línea a propósito: una migración no depende de código que
 * pueda cambiar ({@see EjercicioControl} es la versión viva).
 *
 * Cada índice nuevo se crea ANTES de borrar el viejo. `down()` se niega, sin tocar nada, si
 * ya hay números repetidos entre ejercicios: revertir fundiría dos documentos en uno.
 */
return new class extends Migration
{
    /**
     * Índices únicos: [tabla, columnas viejas, columnas nuevas, nombre del nuevo].
     *
     * Los viejos se buscan por sus COLUMNAS, no por su nombre: producción no se armó con
     * git y el nombre real puede no ser el de la convención. Borrar por un nombre que no
     * existe haría fallar `up()` a la mitad, y en MySQL el DDL ya hecho no se revierte.
     */
    private const UNICOS = [
        ['cobro_documentos', ['numero_control_norm'], ['numero_control_norm', 'ejercicio_control'], 'cobro_doc_control_ejercicio_unico'],
        ['ppq_items', ['ppq_lote_id', 'numero_control'], ['ppq_lote_id', 'numero_control', 'ejercicio_control'], 'ppq_item_lote_control_ejercicio_unico'],
    ];

    /**
     * Cada paso comprueba antes de actuar: si una corrida se corta a la mitad, la siguiente
     * retoma donde quedó en vez de chocar con lo que ya se hizo.
     */
    public function up(): void
    {
        foreach (['cobro_documentos' => 'fecha_emision', 'ppq_items' => 'fecha_documento'] as $tabla => $fecha) {
            if (! Schema::hasColumn($tabla, 'ejercicio_control')) {
                Schema::table($tabla, fn (Blueprint $t) => $t->unsignedSmallInteger('ejercicio_control')->nullable());
            }
            DB::table($tabla)->whereNull('ejercicio_control')->orderBy('id')->chunkById(200, function ($filas) use ($tabla, $fecha) {
                $fechas = DB::table('dtes')->whereIn('id', $filas->pluck('dte_id')->filter())->pluck('fecha_emision', 'id');
                foreach ($filas as $fila) {
                    $valor = $fila->{$fecha} ?: ($fechas[$fila->dte_id] ?? null);
                    $ejercicio = $valor ? max((int) Carbon::parse($valor)->year, 2026) : 2026;
                    DB::table($tabla)->where('id', $fila->id)->update(['ejercicio_control' => $ejercicio]);
                }
            });
            Schema::table($tabla, fn (Blueprint $t) => $t->unsignedSmallInteger('ejercicio_control')->nullable(false)->change());
        }

        foreach (self::UNICOS as [$tabla, $viejas, $nuevas, $nombre]) {
            if ($this->unicosPorColumnas($tabla, $nuevas) === []) {
                Schema::table($tabla, fn (Blueprint $t) => $t->unique($nuevas, $nombre));
            }
            foreach ($this->unicosPorColumnas($tabla, $viejas) as $viejo) {
                Schema::table($tabla, fn (Blueprint $t) => $t->dropUnique($viejo));
            }
        }
    }

    /**
     * Nombres de los índices ÚNICOS de la tabla que cubren exactamente esas columnas, en ese
     * orden. La clave primaria no cuenta.
     *
     * @param  array<int, string>  $columnas
     * @return array<int, string>
     */
    private function unicosPorColumnas(string $tabla, array $columnas): array
    {
        return collect(Schema::getIndexes($tabla))
            ->filter(fn (array $i) => $i['unique'] && ! $i['primary'] && $i['columns'] === $columnas)
            ->pluck('name')->values()->all();
    }

    public function down(): void
    {
        // Verificar ambos conjuntos antes de cualquier DDL: la reversa no pierde identidad.
        // Se seleccionan solo las columnas agrupadas: MySQL estricto (ONLY_FULL_GROUP_BY)
        // rechaza un `select *` con GROUP BY.
        $cobros = DB::table('cobro_documentos')->select('numero_control_norm')->whereNotNull('numero_control_norm')
            ->groupBy('numero_control_norm')->havingRaw('COUNT(*) > 1')->exists();
        $items = DB::table('ppq_items')->select('ppq_lote_id', 'numero_control')->whereNotNull('numero_control')
            ->groupBy('ppq_lote_id', 'numero_control')->havingRaw('COUNT(*) > 1')->exists();
        if ($cobros || $items) {
            throw new RuntimeException('No se puede revertir: hay números repetidos entre ejercicios.');
        }
        $nombresViejos = ['cobro_documentos' => 'cobro_documentos_numero_control_norm_unique', 'ppq_items' => 'ppq_item_lote_control_unico'];
        foreach (self::UNICOS as [$tabla, $viejas, $nuevas]) {
            if ($this->unicosPorColumnas($tabla, $viejas) === []) {
                Schema::table($tabla, fn (Blueprint $t) => $t->unique($viejas, $nombresViejos[$tabla]));
            }
            foreach ($this->unicosPorColumnas($tabla, $nuevas) as $nuevo) {
                Schema::table($tabla, fn (Blueprint $t) => $t->dropUnique($nuevo));
            }
            if (Schema::hasColumn($tabla, 'ejercicio_control')) {
                Schema::table($tabla, fn (Blueprint $t) => $t->dropColumn('ejercicio_control'));
            }
        }
    }
};
