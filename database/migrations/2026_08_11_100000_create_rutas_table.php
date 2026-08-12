<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rutas comerciales de cobro/visita (San Miguel, Santa Ana, Sonsonate…).
 *
 * Una ruta es una AGRUPACIÓN GEOGRÁFICA estable de salas, no un recorrido con
 * orden ni un calendario. Lo que efectivamente se recorre y cuándo vive en
 * `salidas_ruta`; acá solo está la identidad de la ruta.
 *
 * `frecuencia_objetivo_dias` es una INTENCIÓN («esta ruta debería visitarse cada
 * 15 días»), no una regla que el sistema imponga: nada se agenda ni se bloquea
 * con ella. Sirve para que más adelante se pueda contrastar lo planeado con lo
 * real. Nullable porque hay rutas que se hacen cuando toca.
 *
 * NO toca DTE, correlativos, PPQ ni Planta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rutas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);
            $table->boolean('activa')->default(true);
            $table->unsignedSmallInteger('frecuencia_objetivo_dias')->nullable()
                ->comment('Cada cuántos días se pretende visitar la ruta. Referencia, no regla.');
            $table->timestamps();

            $table->unique('nombre', 'rutas_nombre_unico');
            $table->index('activa', 'rutas_activa_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rutas');
    }
};
