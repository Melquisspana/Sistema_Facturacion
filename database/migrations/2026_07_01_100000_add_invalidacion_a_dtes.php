<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidencia del EVENTO DE INVALIDACIÓN oficial (anulación de un DTE ya aceptado por
 * el MH) en COLUMNAS DEDICADAS. NO reutiliza ni toca las columnas de recepción del
 * DTE original (sello_recepcion, respuesta_mh, fecha_procesamiento_mh): la
 * invalidación es un evento aparte con su propio código de generación, JWS, sello,
 * respuesta y fechas.
 *
 * Fase C: estas columnas se pueblan en modo MOCK (firma simulada, sin transmitir).
 * respuesta_mh_invalidacion / fecha_procesamiento_invalidacion quedan listas para la
 * fase de transmisión real posterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            // Código de generación del EVENTO (UUID propio, distinto al del DTE).
            $table->char('codigo_generacion_invalidacion', 36)->nullable()->after('fecha_anulacion');
            // Tipo de anulación CAT-024 (1 error info, 2 rescindir, 3 otro).
            $table->unsignedTinyInteger('tipo_anulacion')->nullable()->after('codigo_generacion_invalidacion');
            // Archivos del evento en disco.
            $table->string('json_invalidacion_path')->nullable()->after('tipo_anulacion');
            $table->string('jws_invalidacion_path')->nullable()->after('json_invalidacion_path');
            // Acuse del MH para la invalidación (en mock: sello ficticio marcado).
            $table->string('sello_invalidacion')->nullable()->after('jws_invalidacion_path');
            // Respuesta del MH a la invalidación (interpretada + cruda en disco).
            $table->json('respuesta_mh_invalidacion')->nullable()->after('sello_invalidacion');
            $table->string('respuesta_mh_invalidacion_path')->nullable()->after('respuesta_mh_invalidacion');
            // Fechas: cuándo se generó/firmó el evento y cuándo lo procesó el MH.
            $table->dateTime('fecha_invalidacion')->nullable()->after('respuesta_mh_invalidacion_path');
            $table->dateTime('fecha_procesamiento_invalidacion')->nullable()->after('fecha_invalidacion');
        });
    }

    public function down(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropColumn([
                'codigo_generacion_invalidacion',
                'tipo_anulacion',
                'json_invalidacion_path',
                'jws_invalidacion_path',
                'sello_invalidacion',
                'respuesta_mh_invalidacion',
                'respuesta_mh_invalidacion_path',
                'fecha_invalidacion',
                'fecha_procesamiento_invalidacion',
            ]);
        });
    }
};
