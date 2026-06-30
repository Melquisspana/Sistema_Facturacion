<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca explícita "sin albarán": permite agregar un CCF/NC al lote aunque no haya
 * albarán encontrado o esté incompleto (notas de crédito / casos especiales). Es
 * distinto de "albarán aún no encontrado": aquí el usuario decidió incluirlo así.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppq_items', function (Blueprint $table) {
            $table->boolean('sin_albaran')->default(false)->after('ppq_albaran_id');
        });
    }

    public function down(): void
    {
        Schema::table('ppq_items', function (Blueprint $table) {
            $table->dropColumn('sin_albaran');
        });
    }
};
