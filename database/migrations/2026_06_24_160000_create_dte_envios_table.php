<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de envíos por correo de un DTE al cliente. Cada fila es un intento:
 * estado 'enviado' o 'error', con destinatario, adjuntos y fecha. El estado actual
 * del DTE ("no enviado" / "enviado" / "error") se deriva del último envío. El envío
 * es SIEMPRE manual (desde el botón); no hay envío automático.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dte_envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dte_id')->constrained('dtes')->cascadeOnDelete();
            $table->string('destinatario', 120);
            $table->string('estado', 12)->default('enviado')->comment('enviado | error');
            $table->string('adjuntos')->nullable()->comment('p.ej. "PDF, JSON, JWS"');
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['dte_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dte_envios');
    }
};
