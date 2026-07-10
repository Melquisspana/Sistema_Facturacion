<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos recibidos: CCF/facturas que LLEGAN por correo (donde somos el
 * receptor), para prepararlos y luego enviarlos a contabilidad. Fase 1 solo
 * lectura: NO emite, NO transmite, NO toca DTE emitidos ni correlativos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_recibidos', function (Blueprint $table) {
            $table->id();
            // Id del mensaje en Gmail: dedupe entre revisiones (cubre PDF sin JSON).
            $table->string('gmail_message_id')->nullable()->unique();
            $table->string('origen_email')->nullable()->comment('Cuenta/origen donde se recibió');
            $table->string('asunto')->nullable();
            $table->string('remitente')->nullable();
            $table->timestamp('fecha_correo')->nullable();

            $table->string('tipo_documento', 2)->nullable()->comment('CAT-002 si viene en el JSON');
            $table->string('numero_control')->nullable();
            $table->string('codigo_generacion')->nullable()->unique();
            $table->string('sello_recepcion')->nullable();

            $table->string('emisor_nombre')->nullable();
            $table->string('emisor_nit')->nullable();
            $table->string('emisor_nrc')->nullable();
            $table->decimal('total', 12, 2)->nullable();

            $table->boolean('tiene_pdf')->default(false);
            $table->boolean('tiene_json')->default(false);

            $table->string('estado', 20)->default('pendiente')->comment('pendiente | enviado | ignorado');
            $table->json('metadata_json')->nullable();

            $table->timestamps();

            $table->index('estado');
            $table->index('emisor_nombre');
            $table->index('fecha_correo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_recibidos');
    }
};
