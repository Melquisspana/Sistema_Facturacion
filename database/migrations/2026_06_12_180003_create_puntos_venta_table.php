<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Puntos de venta / terminales de facturación, dependientes de un establecimiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('puntos_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('establecimiento_id')->constrained('establecimientos')->cascadeOnDelete();
            $table->string('codigo', 4)->comment('Código de punto de venta MH (ej. P001)');
            $table->string('nombre');
            $table->string('descripcion')->nullable();
            $table->boolean('activo')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['establecimiento_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('puntos_venta');
    }
};
