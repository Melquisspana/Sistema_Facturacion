<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Código de barra del producto. Opcional (algunos productos aún no lo tienen),
 * pero único cuando viene lleno. Solo se guarda el valor; no se generan códigos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            // Único cuando viene lleno; en MySQL un índice único permite varios NULL.
            $table->string('codigo_barra', 50)->nullable()->unique()->after('codigo');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropUnique(['codigo_barra']);
            $table->dropColumn('codigo_barra');
        });
    }
};
