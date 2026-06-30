<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de productos/servicios. Los campos fiscales (tipo de producto y tipo
 * de impuesto) se guardan como código controlado por enum; la unidad de medida
 * es FK al catálogo. Se deja `maneja_inventario` como gancho para el inventario
 * futuro, SIN descontar stock todavía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->string('nombre');
            $table->text('descripcion')->nullable();

            $table->string('tipo_producto', 2)->comment('CAT-011: 1=bien,2=servicio,3=ambos,4=otros');
            $table->foreignId('unidad_medida_id')->constrained('unidades_medida');
            $table->decimal('precio_unitario', 11, 4)->default(0);
            $table->string('tipo_impuesto', 20)->comment('gravado | exento | no_sujeto');

            // Gancho para inventario futuro (sin lógica de stock todavía).
            $table->boolean('maneja_inventario')->default(false);
            $table->string('producto_inventario_ref')->nullable()->comment('Referencia a inventario externo (futuro)');

            $table->text('observaciones')->nullable();
            $table->boolean('activo')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('tipo_producto');
            $table->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
