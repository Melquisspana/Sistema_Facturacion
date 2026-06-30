<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas del DTE. Cada línea guarda un SNAPSHOT del producto al momento de
 * capturarla, de modo que el documento no cambie si luego cambia el producto.
 * Los montos los calculará la CalculadoraDte (fase posterior); aquí solo la
 * estructura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dte_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dte_id')->constrained('dtes')->cascadeOnDelete();
            $table->unsignedInteger('numero_linea');

            // Referencia blanda al producto (snapshot la independiza).
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();

            // Snapshot del producto.
            $table->string('codigo')->nullable();
            $table->string('codigo_barra')->nullable();
            $table->string('descripcion');
            $table->foreignId('unidad_medida_id')->nullable()->constrained('unidades_medida')->nullOnDelete();
            $table->string('unidad_codigo', 3)->nullable();
            $table->string('unidad_nombre')->nullable();
            $table->string('tipo_producto', 2)->nullable()->comment('Snapshot TipoProducto');
            $table->string('tipo_impuesto', 20)->comment('Snapshot TipoImpuesto: gravado/exento/no_sujeto');

            // Cantidades y precios capturados.
            $table->decimal('cantidad', 11, 4)->default(0);
            $table->decimal('precio_unitario', 11, 6)->default(0);
            $table->decimal('descuento_monto', 11, 2)->default(0);
            $table->decimal('descuento_porcentaje', 5, 2)->nullable();

            // Resultado del cálculo (lo escribe la CalculadoraDte).
            $table->decimal('venta_no_sujeta', 11, 2)->default(0);
            $table->decimal('venta_exenta', 11, 2)->default(0);
            $table->decimal('venta_gravada', 11, 2)->default(0);
            $table->decimal('iva_linea', 11, 2)->default(0);
            $table->decimal('total_linea', 11, 2)->default(0);

            // Para nota de crédito: línea del documento original que se acredita.
            $table->foreignId('dte_linea_original_id')->nullable()->constrained('dte_lineas')->nullOnDelete();

            $table->timestamps();

            $table->index('dte_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dte_lineas');
    }
};
