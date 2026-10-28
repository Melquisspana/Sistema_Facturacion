<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `numero_control` es el número OFICIAL de Hacienda: su consecutivo reinicia
 * desde uno con el primer DTE de cada ejercicio (01 de enero al 31 de diciembre).
 * Se acota la unicidad a (ambiente, ejercicio, numero_control), usando el año
 * de fecha_emision para los documentos que ya tienen número de control.
 *
 * NO cambia el formato del número, NO reasigna numero_control a ningún DTE.
 * Los índices se buscan por columnas: sus nombres reales en producción pueden variar.
 * Cada paso comprueba lo ya hecho para retomar una corrida cortada: en MySQL el DDL
 * no se revierte si falla un paso posterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dtes', 'ejercicio')) {
            Schema::table('dtes', fn (Blueprint $table) => $table->unsignedSmallInteger('ejercicio')->nullable());
        }

        DB::table('dtes')->whereNull('ejercicio')->whereNotNull('numero_control')->whereNotNull('fecha_emision')
            ->select(['id', 'fecha_emision'])->chunkById(500, function ($documentos) {
                foreach ($documentos as $documento) {
                    DB::table('dtes')->where('id', $documento->id)->update([
                        'ejercicio' => (int) substr($documento->fecha_emision, 0, 4),
                    ]);
                }
            });

        if ($this->unicosPorColumnas('dtes', ['ambiente', 'ejercicio', 'numero_control']) === []) {
            Schema::table('dtes', fn (Blueprint $table) => $table->unique(['ambiente', 'ejercicio', 'numero_control'], 'dtes_ambiente_ejercicio_numero_control_unique'));
        }
        foreach ($this->unicosPorColumnas('dtes', ['ambiente', 'numero_control']) as $viejo) {
            Schema::table('dtes', fn (Blueprint $table) => $table->dropUnique($viejo));
        }
    }

    /**
     * Nombres de los únicos que cubren exactamente esas columnas, en ese orden, sin la primaria.
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
        // Comprobar antes de cualquier DDL; seleccionar solo columnas agrupadas para MySQL estricto.
        $repetidos = DB::table('dtes')->select('ambiente', 'numero_control')->whereNotNull('numero_control')
            ->groupBy('ambiente', 'numero_control')->havingRaw('COUNT(*) > 1')->exists();
        if ($repetidos) {
            throw new RuntimeException('No se puede revertir: hay números de control repetidos entre ejercicios.');
        }
        if ($this->unicosPorColumnas('dtes', ['ambiente', 'numero_control']) === []) {
            Schema::table('dtes', fn (Blueprint $table) => $table->unique(['ambiente', 'numero_control'], 'dtes_ambiente_numero_control_unique'));
        }
        foreach ($this->unicosPorColumnas('dtes', ['ambiente', 'ejercicio', 'numero_control']) as $nuevo) {
            Schema::table('dtes', fn (Blueprint $table) => $table->dropUnique($nuevo));
        }
        if (Schema::hasColumn('dtes', 'ejercicio')) {
            Schema::table('dtes', fn (Blueprint $table) => $table->dropColumn('ejercicio'));
        }
    }
};
