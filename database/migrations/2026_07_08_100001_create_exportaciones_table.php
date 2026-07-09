<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exportaciones / listas de empaque. Documento administrativo para generar el
 * Excel de lista de empaque; NO es un DTE y no interviene en la emisión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exportaciones', function (Blueprint $table) {
            $table->id();
            $table->string('cliente_nombre');
            $table->string('cliente_direccion')->nullable();
            $table->string('exportador_nombre');
            $table->string('exportador_direccion')->nullable();
            $table->date('fecha');
            $table->string('factura')->nullable()->comment('Texto libre; la factura comercial NO se implementa todavía');
            $table->string('fda_reg_number')->nullable();
            $table->text('observaciones')->nullable();
            $table->string('estado', 20)->default('borrador');
            $table->timestamps();

            $table->index('estado');
            $table->index('fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exportaciones');
    }
};
