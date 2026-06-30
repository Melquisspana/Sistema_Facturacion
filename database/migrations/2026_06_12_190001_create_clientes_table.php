<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes (receptores). Una sola tabla para nacionales (consumidor final /
 * contribuyente) y de exportación. Los campos de catálogo MH se guardan como
 * código controlado (enum) o como FK; nunca texto libre.
 *
 * La obligatoriedad de campos según tipo_cliente se valida en el Form Request
 * (PASO 2), no en la base, porque depende del contexto de captura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->nullable()->comment('Código interno opcional');

            // Catálogos controlados (enums).
            $table->string('tipo_cliente', 20)->comment('consumidor_final | contribuyente | exportacion');
            $table->string('tipo_persona', 20)->nullable()->comment('natural | juridica');
            $table->string('tipo_documento', 2)->nullable()->comment('CAT-022');
            $table->string('num_documento', 25)->nullable();
            $table->string('nrc', 20)->nullable()->comment('Requerido para contribuyente nacional');

            // Identificación / nombre.
            $table->string('nombre')->comment('Nombre o razón social');
            $table->string('nombre_comercial')->nullable();

            // FKs a catálogos.
            $table->foreignId('actividad_economica_id')->nullable()->constrained('actividades_economicas')->nullOnDelete();
            $table->foreignId('pais_id')->nullable()->constrained('paises')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->foreignId('municipio_id')->nullable()->constrained('municipios')->nullOnDelete();

            // Dirección y contacto.
            $table->string('direccion')->nullable();
            $table->string('complemento_direccion')->nullable();
            $table->string('correo')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('contacto_principal')->nullable();
            $table->text('observaciones')->nullable();

            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('codigo');
            $table->index('tipo_cliente');
            $table->index('num_documento');
            $table->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
