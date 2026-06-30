<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos de la anulación INTERNA/preliminar (estado invalidado). El usuario que
 * anula se guarda en la columna existente `invalidado_by`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->string('motivo_anulacion', 40)->nullable()->after('motivo');
            $table->text('observacion_anulacion')->nullable()->after('motivo_anulacion');
            $table->dateTime('fecha_anulacion')->nullable()->after('observacion_anulacion');
        });
    }

    public function down(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropColumn(['motivo_anulacion', 'observacion_anulacion', 'fecha_anulacion']);
        });
    }
};
