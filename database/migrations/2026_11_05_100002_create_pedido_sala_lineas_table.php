<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Productos de cada pedido de sala. La cantidad tiene el mismo tipo que las líneas de
 * facturación (`dte_lineas.cantidad`). Lo enviado y el motivo de lo que no se mandó
 * quedan vacíos hasta la pantalla de producción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_sala_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_sala_id')->constrained('pedidos_sala')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->decimal('cantidad', 11, 4);
            $table->decimal('cantidad_enviada', 11, 4)->nullable();
            $table->string('motivo_no_enviado', 200)->nullable();
            $table->timestamps();

            $table->unique(['pedido_sala_id', 'producto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_sala_lineas');
    }
};
