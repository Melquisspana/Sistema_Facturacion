<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identidad correcta del correo de compras.
 *
 * ADITIVA Y NO DESTRUCTIVA. `gmail_message_id` se conserva EXACTAMENTE como está —con
 * su índice único y con los UID crudos que guardan las filas históricas—: esta
 * migración no lo renombra, no lo reinterpreta y no lo vacía. Las columnas nuevas
 * quedan en NULL hasta que corra el backfill explícito (`compras:backfill-identidad`),
 * y mientras tanto la deduplicación sigue reconociendo esas filas por el camino viejo.
 *
 * POR QUÉ HACEN FALTA. El UID de IMAP solo es único dentro de UNA carpeta y mientras
 * el `UIDVALIDITY` de esa carpeta no cambie. Como identidad se rompe de dos formas, y
 * las dos importan cuando se relee con solape:
 *   - mover un correo de carpeta le cambia el UID → se registraría dos veces;
 *   - reconstruir el buzón reinicia los UID → el UID 1803 pasa a ser otro correo, y se
 *     descartaría como "ya visto" un documento que nunca se leyó.
 *
 * Las columnas nuevas:
 *   - `identidad`      clave de deduplicación (`mid:` | `hash:` | `legado:`), única.
 *   - `message_id`     Message-ID normalizado del encabezado, para buscar y diagnosticar.
 *   - `buzon_carpeta`  carpeta donde se leyó (diagnóstico; ya no es parte de la identidad).
 *   - `uid`            UID de esa carpeta (diagnóstico).
 *   - `uid_validity`   UIDVALIDITY vigente al leerlo (diagnóstico).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos_recibidos', function (Blueprint $table) {
            // Nullable a propósito: las 50 filas existentes quedan en NULL y las adopta
            // el backfill explícito, no esta migración.
            $table->string('identidad', 191)->nullable()->unique()->after('gmail_message_id');
            $table->string('message_id', 191)->nullable()->after('identidad');
            $table->string('buzon_carpeta', 64)->nullable()->after('message_id');
            $table->unsignedBigInteger('uid')->nullable()->after('buzon_carpeta');
            $table->unsignedBigInteger('uid_validity')->nullable()->after('uid');

            $table->index('message_id');
            // Para responder "¿qué UID de esta carpeta ya tengo?" sin recorrer la tabla.
            $table->index(['buzon_carpeta', 'uid_validity', 'uid'], 'documentos_recibidos_ubicacion_index');
        });
    }

    public function down(): void
    {
        Schema::table('documentos_recibidos', function (Blueprint $table) {
            $table->dropIndex('documentos_recibidos_ubicacion_index');
            $table->dropIndex(['message_id']);
            $table->dropUnique(['identidad']);
            $table->dropColumn(['identidad', 'message_id', 'buzon_carpeta', 'uid', 'uid_validity']);
        });
    }
};
