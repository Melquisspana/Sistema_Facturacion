<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COPIA ARCHIVADA del archivo de cada lote de notas de crédito.
 *
 * Hasta ahora cada descarga volvía a generar el Excel. Eso no prueba qué bytes se
 * sirvieron: si cambia el exportador, la plantilla o el perfil, el archivo regenerado
 * puede ser otro. Desde esta migración el archivo de la primera descarga se guarda con
 * su SHA-256 y las descargas siguientes devuelven esa misma copia, verificada. Que el
 * navegador o el cliente lo recibieran sigue sin constar.
 *
 * `archivo_origen` dice DE DÓNDE sale la copia, porque no todas valen lo mismo:
 *
 *   · primera_descarga — se archivó al preparar la primera descarga;
 *   · reconstruccion   — el lote ya se había descargado ANTES de existir el archivado, así
 *                        que la base no demuestra qué bytes se bajaron entonces. La copia es
 *                        una reconstrucción desde las notas del lote, y se dice así.
 *
 * Las cuatro columnas van juntas: si falta cualquiera, el registro es incompleto y la
 * descarga se bloquea en vez de adivinar.
 *
 * Aditiva y nullable: los lotes existentes quedan sin copia y se archivan en su próxima
 * descarga. No toca las columnas ni el contenido de los formatos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nc_exportaciones', function (Blueprint $table) {
            $table->string('archivo_hash', 64)->nullable()->after('archivo_nombre');
            $table->string('archivo_path')->nullable()->after('archivo_hash');
            $table->string('archivo_origen', 20)->nullable()->after('archivo_path');
            $table->timestamp('archivado_en')->nullable()->after('archivo_origen');
        });
    }

    public function down(): void
    {
        Schema::table('nc_exportaciones', function (Blueprint $table) {
            $table->dropColumn(['archivo_hash', 'archivo_path', 'archivo_origen', 'archivado_en']);
        });
    }
};
