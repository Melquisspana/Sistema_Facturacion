<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empresa emisora (Dulces La Negrita). Datos fiscales del emisor.
 * No guarda credenciales ni certificados de Hacienda (eso va en .env/storage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('razon_social');
            $table->string('nombre_comercial')->nullable();
            $table->string('nit', 20)->nullable();
            $table->string('nrc', 20)->nullable();

            $table->foreignId('actividad_economica_id')->nullable()
                ->constrained('actividades_economicas')->nullOnDelete();

            $table->foreignId('pais_id')->nullable()->constrained('paises')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->foreignId('municipio_id')->nullable()->constrained('municipios')->nullOnDelete();
            $table->string('direccion')->nullable();

            $table->string('telefono', 30)->nullable();
            $table->string('correo')->nullable();

            $table->string('ambiente', 2)->default('00')->comment('00=pruebas, 01=produccion (CAT-001)');
            $table->boolean('activo')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
