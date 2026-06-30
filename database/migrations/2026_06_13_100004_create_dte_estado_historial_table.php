<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de transiciones de estado del DTE. Solo se INSERTA (nunca se edita
 * ni se borra): es trazabilidad fiscal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dte_estado_historial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dte_id')->constrained('dtes')->cascadeOnDelete();
            $table->string('estado_anterior', 20)->nullable();
            $table->string('estado_nuevo', 20);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('comentario')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('dte_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dte_estado_historial');
    }
};
