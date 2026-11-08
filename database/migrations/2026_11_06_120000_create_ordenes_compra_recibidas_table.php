<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Órdenes de compra de Calleja recibidas por correo (etiqueta «Ordenes» de Gmail).
 *
 * Una fila por correo: `gmail_message_id` es la identidad y hace idempotente la
 * sincronización. `numero` queda nulo cuando el PDF no se pudo leer (estado `sin_leer`):
 * la orden se guarda igual para revisarla a mano, nunca se descarta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes_compra_recibidas', function (Blueprint $table) {
            $table->id();
            $table->string('numero')->nullable()->unique();
            $table->string('sala_codigo', 4)->nullable()->index();
            $table->foreignId('cliente_sucursal_id')->nullable()->constrained('cliente_sucursales')->nullOnDelete();
            $table->date('fecha_creacion')->nullable();
            $table->date('fecha_despacho')->nullable();
            $table->decimal('total', 14, 2)->nullable();
            $table->json('lineas')->nullable();
            $table->string('pdf_path');
            $table->string('pdf_hash', 64);
            $table->string('gmail_message_id')->unique();
            $table->string('estado', 20)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes_compra_recibidas');
    }
};
