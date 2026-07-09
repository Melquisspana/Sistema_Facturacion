<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de productos de EXPORTACIÓN (lista de empaque). Módulo administrativo
 * paralelo: NO toca productos DTE, ni emisión, ni correlativos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exportacion_productos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->nullable();
            $table->string('nombre_es');
            $table->string('nombre_en');
            $table->string('unidad')->nullable()->comment('Empaque, ej. "Bolsa de polipropileno 12X18"');
            $table->unsignedInteger('unidades_por_caja');
            $table->decimal('gramos_por_unidad', 10, 2);
            $table->decimal('onzas_por_unidad', 10, 2);
            $table->decimal('precio_caja', 12, 2);
            $table->decimal('peso_neto_caja_kg', 10, 2);
            $table->decimal('peso_bruto_caja_kg', 10, 2);
            $table->decimal('peso_neto_caja_lb', 10, 2);
            $table->decimal('peso_bruto_caja_lb', 10, 2);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('activo');
            $table->index('nombre_es');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exportacion_productos');
    }
};
