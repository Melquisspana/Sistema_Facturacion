<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renglones de un lote PPQ: cada item es un CCF/NC (dte_id) incluido en el lote,
 * opcionalmente vinculado a un albarán. Guarda snapshots de montos/OC para la
 * conciliación y el Excel, sin depender de cambios futuros en el DTE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppq_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ppq_lote_id')->constrained('ppq_lotes')->cascadeOnDelete();
            $table->foreignId('dte_id')->constrained('dtes')->cascadeOnDelete()->comment('CCF o NC incluido');
            $table->foreignId('ppq_albaran_id')->nullable()->constrained('ppq_albaranes')->nullOnDelete();
            // Snapshots (para Excel/conciliación):
            $table->string('numero_orden_compra')->nullable();
            $table->decimal('monto_dte', 11, 2)->default(0);
            $table->decimal('monto_albaran', 11, 2)->nullable();
            $table->decimal('diferencia', 11, 2)->nullable()->comment('monto_dte - monto_albaran');
            $table->text('observaciones')->nullable();
            $table->timestamps();

            // Anti-duplicado: un mismo CCF/NC no puede repetirse dentro del mismo lote.
            $table->unique(['ppq_lote_id', 'dte_id'], 'ppq_item_lote_dte_unico');
            $table->index('dte_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppq_items');
    }
};
