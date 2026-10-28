<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salida_ruta_cierres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salida_ruta_id')->constrained('salidas_ruta')->restrictOnDelete();
            $table->date('fecha');
            $table->foreignId('entrega_dinero_personal_id')->nullable()->constrained('rutas_personal')->nullOnDelete();
            foreach (['efectivo_esperado', 'cheques_esperado', 'transferencias_esperado', 'efectivo_cobrado', 'cheques_cobrado', 'transferencias_cobrado', 'gastos_total', 'a_entregar'] as $campo) {
                $table->decimal($campo, 12, 2)->default(0);
            }
            $table->foreignId('cerrado_por')->constrained('users')->restrictOnDelete();
            $table->dateTime('cerrado_en');
            $table->foreignId('reabierto_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reabierto_en')->nullable();
            $table->string('reabierto_motivo', 300)->nullable();
            $table->timestamps();
            $table->unique(['salida_ruta_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salida_ruta_cierres');
    }
};
