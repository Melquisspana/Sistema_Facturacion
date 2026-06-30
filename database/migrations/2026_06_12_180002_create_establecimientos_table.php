<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Establecimientos de la empresa emisora (casa matriz, sucursales, bodegas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('establecimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('codigo', 4)->comment('Código de establecimiento MH (ej. M001)');
            $table->string('nombre');
            $table->string('tipo_establecimiento', 2)->nullable()->comment('CAT-009');

            $table->foreignId('pais_id')->nullable()->constrained('paises')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->foreignId('municipio_id')->nullable()->constrained('municipios')->nullOnDelete();
            $table->string('direccion')->nullable();

            $table->string('telefono', 30)->nullable();
            $table->string('correo')->nullable();
            $table->boolean('activo')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['empresa_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establecimientos');
    }
};
