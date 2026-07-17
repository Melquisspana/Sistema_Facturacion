<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula el cliente administrativo de Exportaciones (exportacion_clientes) con su
 * Cliente DTE real (clientes), para poder crear una FEX a nombre del receptor
 * correcto. Nullable: los registros existentes (Carolinas, Diamond Rocks, Solfi)
 * no tienen hoy un Cliente DTE y deben vincularse manualmente, uno por uno.
 *
 * Sin unique: un mismo Cliente DTE podría en teoría atender más de un registro
 * administrativo de exportación (aunque en la práctica hoy sea 1 a 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exportacion_clientes', function (Blueprint $table) {
            $table->foreignId('cliente_id')->nullable()->after('id')
                ->constrained('clientes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exportacion_clientes', function (Blueprint $table) {
            $table->dropForeign(['cliente_id']);
            $table->dropColumn('cliente_id');
        });
    }
};
