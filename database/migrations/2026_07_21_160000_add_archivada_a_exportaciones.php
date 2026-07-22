<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aditiva y reversible: agrega `archivada`/`archivada_en` a `exportaciones` para
 * poder ocultar del listado normal una Lista de empaque de PRUEBA (no real) sin
 * borrarla ni tocar su relación con la FEX que ya generó (dte_id intacto), sus
 * items, precios ni totales. No existía ningún mecanismo de archivado/soft-delete
 * en esta tabla (auditado antes de escribir esto). No cambia `estado`
 * (borrador/aprobada) ni ninguna otra columna existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exportaciones', function (Blueprint $table) {
            $table->boolean('archivada')->default(false)->after('estado');
            $table->timestamp('archivada_en')->nullable()->after('archivada');
        });
    }

    public function down(): void
    {
        Schema::table('exportaciones', function (Blueprint $table) {
            $table->dropColumn(['archivada', 'archivada_en']);
        });
    }
};
