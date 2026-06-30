<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de municipios (CAT-013 del MH), dependiente de departamento.
 *
 * NOTA: el código MH de municipio se deja nullable a propósito. El catálogo
 * CAT-013 cambió con la reforma territorial del MH; los códigos oficiales se
 * completarán al importar el catálogo vigente antes de la Fase 2. Por ahora se
 * siembran nombres y la relación con su departamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('departamento_id')->constrained('departamentos')->cascadeOnDelete();
            $table->string('codigo', 2)->nullable()->comment('Código CAT-013 del MH (pendiente de catálogo oficial)');
            $table->string('nombre');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('departamento_id');
            $table->unique(['departamento_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipios');
    }
};
