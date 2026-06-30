<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos oficiales del Ministerio de Hacienda (CAT-001..CAT-033) importados
 * desde el Excel oficial. Tabla genérica: una fila por (catálogo, código).
 *
 * Es reference data para la futura generación del JSON; NO modifica facturación,
 * enums de la app ni los catálogos propios (países/municipios/etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogos_mh', function (Blueprint $table) {
            $table->id();
            $table->string('cat', 3)->index();          // '001', '014', '031'…
            $table->string('codigo', 60);               // código oficial (string; conserva ceros)
            $table->text('valor');                      // descripción oficial
            $table->string('nombre_catalogo')->nullable(); // nombre de la sección (ej. "Unidad de Medida")
            $table->timestamps();

            // Sin unique(cat,codigo): hay catálogos jerárquicos (municipio/distrito)
            // donde el código se repite por su padre. La importación recarga toda la
            // tabla desde el Excel (idempotente por reemplazo completo).
            $table->index(['cat', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogos_mh');
    }
};
