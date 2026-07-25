<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de envíos de un DOCUMENTO RECIBIDO (compra) a contabilidad por correo.
 * Tabla propia, deliberadamente separada de `dte_envios`: esa está acoplada por FK a
 * `dtes` (documentos que EMITIMOS) y tiene historial real de producción.
 *
 * Cada fila es un intento: 'pendiente' (en cola) → 'enviado' | 'simulado' | 'error'.
 * El documento recibido solo pasa a estado 'enviado' cuando un intento termina en
 * 'enviado' (simulado/error/en cola lo dejan pendiente). No toca el buzón Yahoo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documento_recibido_envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('documento_recibido_id')->constrained('documentos_recibidos')->cascadeOnDelete();
            $table->string('destinatario', 120)->nullable();
            $table->json('destinatarios')->nullable();
            $table->string('estado', 12)->default('pendiente')->comment('pendiente | enviado | simulado | error');
            $table->string('adjuntos')->nullable()->comment('Nombres de los archivos realmente adjuntados');
            $table->string('adjuntos_omitidos')->nullable()->comment('Adjuntos omitidos por el límite de tamaño');
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['documento_recibido_id', 'created_at']);
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documento_recibido_envios');
    }
};
