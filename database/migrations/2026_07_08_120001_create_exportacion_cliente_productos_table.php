<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lista de precios / productos permitidos POR CLIENTE de exportación.
 * Regla: si solo cambia el precio entre clientes es el MISMO producto del
 * catálogo con precio específico; si cambia empaque/unidades/pesos es otra
 * presentación en exportacion_productos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exportacion_cliente_productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exportacion_cliente_id')->constrained('exportacion_clientes')->cascadeOnDelete();
            $table->foreignId('exportacion_producto_id')->constrained('exportacion_productos')->cascadeOnDelete();
            $table->decimal('precio_caja', 12, 2);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            // Un producto no puede duplicarse dentro del mismo cliente.
            $table->unique(['exportacion_cliente_id', 'exportacion_producto_id'], 'ecp_cliente_producto_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exportacion_cliente_productos');
    }
};
