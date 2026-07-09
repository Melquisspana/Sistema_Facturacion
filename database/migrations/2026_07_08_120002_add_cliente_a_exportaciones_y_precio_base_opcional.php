<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exportaciones referencian al cliente de exportación (los campos de texto
 * cliente_nombre/direccion/fda se mantienen como SNAPSHOT del encabezado).
 * El precio del catálogo pasa a ser PRECIO BASE opcional (referencia): el
 * precio real al crear una lista viene de exportacion_cliente_productos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exportaciones', function (Blueprint $table) {
            $table->foreignId('exportacion_cliente_id')
                ->nullable()
                ->after('id')
                ->constrained('exportacion_clientes')
                ->nullOnDelete();
        });

        Schema::table('exportacion_productos', function (Blueprint $table) {
            $table->decimal('precio_caja', 12, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('exportaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exportacion_cliente_id');
        });

        Schema::table('exportacion_productos', function (Blueprint $table) {
            $table->decimal('precio_caja', 12, 2)->nullable(false)->change();
        });
    }
};
