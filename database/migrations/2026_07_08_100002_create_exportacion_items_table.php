<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Items de una exportación: SNAPSHOT del producto de catálogo al momento de
 * agregarlo. Si después cambia el precio/peso del catálogo, la exportación
 * vieja no cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exportacion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exportacion_id')->constrained('exportaciones')->cascadeOnDelete();
            $table->foreignId('exportacion_producto_id')->nullable()->constrained('exportacion_productos')->nullOnDelete();
            $table->unsignedInteger('cantidad_cajas');
            $table->string('nombre_es');
            $table->string('nombre_en');
            $table->string('unidad')->nullable();
            $table->unsignedInteger('unidades_por_caja');
            $table->decimal('gramos_por_unidad', 10, 2);
            $table->decimal('onzas_por_unidad', 10, 2);
            $table->decimal('precio_caja', 12, 2);
            $table->decimal('peso_neto_caja_kg', 10, 2);
            $table->decimal('peso_bruto_caja_kg', 10, 2);
            $table->decimal('peso_neto_caja_lb', 10, 2);
            $table->decimal('peso_bruto_caja_lb', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exportacion_items');
    }
};
