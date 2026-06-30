<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Almacenamiento de la respuesta de recepción del Ministerio de Hacienda.
 * `respuesta_mh` guarda los campos interpretados (estado, codigoMsg, descripcionMsg,
 * selloRecibido, clasificaMsg, observaciones, http_status, fhProcesamiento) tanto para
 * documentos ACEPTADOS como RECHAZADOS; `respuesta_mh_path` apunta al JSON crudo
 * guardado en disco (dte/respuestas/). El sello sigue en `sello_recepcion` y la fecha
 * de procesamiento en la columna ya existente `fecha_procesamiento_mh`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->json('respuesta_mh')->nullable()->after('sello_recepcion');
            $table->string('respuesta_mh_path')->nullable()->after('respuesta_mh');
        });
    }

    public function down(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropColumn(['respuesta_mh', 'respuesta_mh_path']);
        });
    }
};
