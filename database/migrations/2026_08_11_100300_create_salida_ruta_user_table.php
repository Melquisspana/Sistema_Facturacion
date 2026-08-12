<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quiénes van en una salida. N:M contra `users`: una salida puede llevar uno,
 * dos, tres o más participantes, y una persona hace muchas salidas.
 *
 * SIN tabla de vendedores propia a propósito. Quien sale a ruta ya existe como
 * usuario del sistema; duplicarlo en un catálogo aparte obligaría a mantener dos
 * listas de las mismas personas y a decidir cuál manda cuando difieran.
 *
 * Tampoco hay vendedor fijo en `rutas`: la misma ruta la hace gente distinta cada
 * semana, y amarrarla al catálogo obligaría a editar la ruta para algo que es de
 * la salida.
 *
 * `cascadeOnDelete` sobre la salida (si la salida desaparece, su lista de
 * participantes no tiene sentido) y sobre el usuario (la fila del pivote no es
 * historial: el historial de la salida vive en `salidas_ruta` y en activity_log).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salida_ruta_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salida_ruta_id')->constrained('salidas_ruta')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // Una persona no puede ir dos veces en la misma salida.
            $table->unique(['salida_ruta_id', 'user_id'], 'salida_ruta_user_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salida_ruta_user');
    }
};
