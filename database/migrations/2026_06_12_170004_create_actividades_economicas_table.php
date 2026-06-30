<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de actividades económicas (CAT-019 del MH, base CIIU).
 * Lo usarán el emisor y los clientes. Se siembra un subconjunto y queda
 * preparado para agregar más.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actividades_economicas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 10)->unique()->comment('Código CAT-019 del MH');
            $table->string('nombre');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actividades_economicas');
    }
};
