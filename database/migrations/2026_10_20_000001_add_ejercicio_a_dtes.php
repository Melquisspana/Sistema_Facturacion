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
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->unsignedSmallInteger('ejercicio')->nullable();
        });

        DB::table('dtes')->whereNotNull('numero_control')->whereNotNull('fecha_emision')
            ->select(['id', 'fecha_emision'])->chunkById(500, function ($documentos) {
                foreach ($documentos as $documento) {
                    DB::table('dtes')->where('id', $documento->id)->update([
                        'ejercicio' => (int) substr($documento->fecha_emision, 0, 4),
                    ]);
                }
            });

        Schema::table('dtes', function (Blueprint $table) {
            $table->dropUnique(['ambiente', 'numero_control']);
            $table->unique(['ambiente', 'ejercicio', 'numero_control']);
        });
    }

    public function down(): void
    {
        // down() falla si ya hay números repetidos en distintos ejercicios,
        // y eso es lo correcto: no se puede restaurar la unicidad anterior.
        Schema::table('dtes', function (Blueprint $table) {
            $table->unique(['ambiente', 'numero_control']);
            $table->dropUnique(['ambiente', 'ejercicio', 'numero_control']);
            $table->dropColumn('ejercicio');
        });
    }
};
