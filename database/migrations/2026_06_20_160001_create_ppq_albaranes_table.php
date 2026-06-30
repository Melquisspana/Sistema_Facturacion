<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Albaranes de Calleja. Se vinculan al CCF/NC por número de orden de compra. La
 * sala se extrae de la OC (posición fija). En la fase 1 se cargan manualmente; la
 * fase 2 los importará desde Gmail (label Calleja_Albaranes) — por eso ya quedan
 * los campos de origen/correo/archivo preparados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppq_albaranes', function (Blueprint $table) {
            $table->id();
            $table->string('numero_albaran')->index();
            $table->date('fecha_albaran')->nullable();
            $table->decimal('monto_albaran', 11, 2)->nullable();
            $table->string('numero_orden_compra')->nullable()->index();
            $table->string('sala_codigo', 10)->nullable()->comment('Extraído de la OC (ej. 0232)');
            // Vínculos best-effort (pueden quedar nulos hasta conciliar):
            $table->foreignId('cliente_sucursal_id')->nullable()->constrained('cliente_sucursales')->nullOnDelete();
            $table->foreignId('dte_id')->nullable()->constrained('dtes')->nullOnDelete()->comment('Posible CCF/NC relacionado');
            // Preparación fase 2 (Gmail):
            $table->string('origen', 20)->default('manual')->comment('manual | gmail');
            $table->string('gmail_message_id')->nullable()->index();
            $table->string('archivo_path')->nullable()->comment('PDF/JSON del albarán guardado');
            $table->timestamps();
            $table->softDeletes();

            // Un mismo número de albarán no debería repetirse para el mismo cliente/sala.
            $table->unique(['numero_albaran', 'numero_orden_compra'], 'ppq_albaran_oc_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppq_albaranes');
    }
};
