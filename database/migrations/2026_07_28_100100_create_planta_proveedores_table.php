<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quién se le compran los insumos de Planta.
 *
 * Catálogo PROPIO y deliberadamente separado de `clientes` y de los documentos
 * recibidos de Facturación: aquí no hay integración contable, ni DTE de compra,
 * ni crédito fiscal. Solo el dato operativo de quién entregó un lote.
 *
 * `nit` y `nrc` van SIN unique a propósito: se capturan como texto libre, con
 * frecuencia llegan vacíos y se corrigen después. Un unique convertiría un dato
 * auxiliar en un bloqueo para registrar mercancía que ya está en la bodega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planta_proveedores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->string('nombre_comercial', 150)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('correo', 150)->nullable();
            $table->string('contacto', 150)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->string('nit', 20)->nullable()->comment('Texto libre, sin unique');
            $table->string('nrc', 20)->nullable()->comment('Texto libre, sin unique');
            $table->text('observaciones')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('activo', 'planta_prov_activo_idx');
            $table->index('nombre', 'planta_prov_nombre_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planta_proveedores');
    }
};
