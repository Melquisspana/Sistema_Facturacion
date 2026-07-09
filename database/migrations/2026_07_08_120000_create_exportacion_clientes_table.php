<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes de EXPORTACIÓN (lista de empaque). Independiente de la tabla clientes
 * de DTE: es el destinatario del embarque, con su dirección y FDA reg. number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exportacion_clientes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('direccion')->nullable();
            $table->string('fda_reg_number')->nullable();
            $table->string('contacto')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exportacion_clientes');
    }
};
