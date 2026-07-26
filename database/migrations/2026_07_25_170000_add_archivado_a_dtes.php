<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aditiva y reversible: permite ARCHIVAR un DTE RECHAZADO por Hacienda para sacarlo
 * de la operación diaria sin borrarlo.
 *
 * NO se usa SoftDeletes (el modelo lo tiene, pero DteObserver::deleting() prohíbe
 * borrar cualquier DTE fuera de borrador, y un borrado ocultaría el documento incluso
 * de la auditoría). Archivar NO toca líneas, JSON, respuesta del MH, número de control,
 * código de generación ni el historial de estados, y NUNCA libera ni retrocede un
 * correlativo: el número consumido sigue consumido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->boolean('archivado')->default(false)->after('estado')
                ->comment('Rechazado retirado de la operación diaria; nunca se borra ni libera correlativo');
            $table->timestamp('archivado_en')->nullable()->after('archivado');

            $table->index('archivado');
        });
    }

    public function down(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropIndex(['archivado']);
            $table->dropColumn(['archivado', 'archivado_en']);
        });
    }
};
