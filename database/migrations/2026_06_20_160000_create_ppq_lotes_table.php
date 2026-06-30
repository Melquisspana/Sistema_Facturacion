<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lotes de Prontos Pagos (PPQ): cada lote agrupa varios CCF/NC de un cliente
 * (típicamente Calleja) para generar el Excel de cobro. Módulo de gestión: NO
 * toca la emisión de DTE, solo consulta y vincula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppq_lotes', function (Blueprint $table) {
            $table->id();
            $table->string('referencia')->comment('Nombre o referencia del lote');
            $table->date('fecha');
            $table->string('estado', 20)->default('borrador')->comment('EstadoPpq');
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->comment('Quién creó el lote');
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('estado');
            $table->index('cliente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppq_lotes');
    }
};
