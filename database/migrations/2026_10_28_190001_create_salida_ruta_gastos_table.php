<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salida_ruta_gastos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salida_ruta_id')->constrained('salidas_ruta')->restrictOnDelete();
            $table->date('fecha');
            $table->foreignId('personal_id')->nullable()->constrained('rutas_personal')->nullOnDelete();
            $table->string('concepto', 20);
            $table->string('detalle', 200)->nullable();
            $table->decimal('monto', 12, 2);
            $table->string('archivo_ruta')->nullable();
            $table->string('archivo_mime', 50)->nullable();
            $table->unsignedInteger('archivo_bytes')->nullable();
            $table->char('archivo_sha256', 64)->nullable();
            $table->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['salida_ruta_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salida_ruta_gastos');
    }
};
