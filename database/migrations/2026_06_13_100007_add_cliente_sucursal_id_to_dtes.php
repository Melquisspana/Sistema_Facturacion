<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referencia comercial a la sala/sucursal del cliente en el DTE.
 * El receptor fiscal sigue siendo `cliente_id`; esta columna solo indica a qué
 * sala se facturó/entregó. Nullable: muchos documentos no usan sucursal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->foreignId('cliente_sucursal_id')
                ->nullable()
                ->after('cliente_id')
                ->constrained('cliente_sucursales')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cliente_sucursal_id');
        });
    }
};
