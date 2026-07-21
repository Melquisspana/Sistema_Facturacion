<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aditiva: distingue POR QUÉ un documento recibido quedó sin datos DTE
 * (no es DTE / JSON inválido / tipo sin mapeo de total / falta el JSON),
 * en vez de mostrar guiones indistinguibles de un error. No toca `estado`
 * (pendiente/enviado/ignorado, que sigue siendo el triage manual del
 * usuario) ni los adjuntos guardados en disco. No borra ni recalcula nada:
 * los registros existentes quedan con `clasificacion` NULL hasta el
 * backfill explícito (comando aparte, con --apply).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos_recibidos', function (Blueprint $table) {
            $table->string('clasificacion', 20)->nullable()->after('estado')
                ->comment('dte_valido | no_es_dte | json_invalido | tipo_no_soportado | falta_adjunto');
            $table->json('clasificacion_diagnostico')->nullable()->after('clasificacion')
                ->comment('Diagnóstico breve y no sensible (motivo, tamaño, codificación, error), nunca contenido del correo/DTE');

            $table->index('clasificacion');
        });
    }

    public function down(): void
    {
        Schema::table('documentos_recibidos', function (Blueprint $table) {
            $table->dropIndex(['clasificacion']);
            $table->dropColumn(['clasificacion', 'clasificacion_diagnostico']);
        });
    }
};
