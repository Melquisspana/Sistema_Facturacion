<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Precios específicos de un producto por cliente y/o sucursal (sala).
 *
 * Prioridad al resolver: producto+sucursal → producto+cliente → precio del producto.
 * El precio resuelto se CONGELA como snapshot en la línea del DTE: si luego cambia,
 * el documento ya generado no se altera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_precios_cliente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('cliente_sucursal_id')->nullable()->constrained('cliente_sucursales')->nullOnDelete();

            $table->decimal('precio', 11, 4);
            $table->boolean('activo')->default(true);
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->text('observaciones')->nullable();

            $table->timestamps();

            $table->index(['producto_id', 'cliente_id', 'cliente_sucursal_id'], 'precio_producto_cliente_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_precios_cliente');
    }
};
