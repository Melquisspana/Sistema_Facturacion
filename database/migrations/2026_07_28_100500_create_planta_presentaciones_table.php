<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo se presenta un producto base: «Coco 85 g», «Leche 1 lb».
 *
 * A la presentación pertenece todo lo que varía por formato comercial
 * (contenido, unidad, unidades por bulto). NO es un producto fiscal ni tiene
 * precio: Planta no factura.
 *
 * `restrictOnDelete` sobre el producto base a propósito: borrar un producto que
 * tiene presentaciones dejaría formatos huérfanos sin identidad. Para retirarlo
 * de la operación está `activo = false` y el softDelete del propio catálogo.
 *
 * La bolsa y la viñeta que corresponden a cada presentación NO viven aquí: son
 * de `planta_empaque_configs`, que llega en el paso 4 junto con sus columnas
 * derivadas y su servicio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planta_presentaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planta_producto_base_id')
                ->constrained('planta_productos_base')->restrictOnDelete();
            $table->string('codigo', 30);
            $table->string('nombre', 150)->comment('Ej. "Coco 85 g"');
            $table->decimal('contenido', 14, 4)->nullable();
            $table->string('unidad_contenido', 10)->nullable()->comment('g, lb, unidad');
            $table->unsignedInteger('unidades_por_bulto')->nullable()->comment('Informativo');
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('codigo', 'planta_present_codigo_unico');
            $table->unique(['planta_producto_base_id', 'nombre'], 'planta_present_base_nombre_unico');
            $table->index('activo', 'planta_present_activo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planta_presentaciones');
    }
};
