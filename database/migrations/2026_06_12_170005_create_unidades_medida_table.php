<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de unidades de medida (CAT-014 del MH).
 * Lo usarán los productos.
 *
 * NOTA: el código MH se llena para las unidades de las que se tiene certeza
 * (59 = Unidad, 99 = Otra) y se deja nullable para las demás hasta importar el
 * catálogo CAT-014 vigente. Los nombres y abreviaturas ya quedan utilizables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades_medida', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 3)->nullable()->comment('Código CAT-014 del MH');
            $table->string('nombre');
            $table->string('abreviatura', 10)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades_medida');
    }
};
