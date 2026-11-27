<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marcar con un número de 4 dígitos, además de la huella (aprobado por Melqui el 26/11/2026).
 *
 * `asistencia_empleados`:
 *  - `numero_marcar`: el número, CIFRADO con APP_KEY (cast `encrypted` del modelo). Hace
 *    falta en claro solo para armar la lista del lector; ninguna pantalla lo muestra.
 *  - `numero_marcar_indice`: HMAC-SHA256 del número con una clave derivada de APP_KEY.
 *    Sirve para buscar a la persona sin descifrar a todos y, con el ÚNICO, para que dos
 *    personas nunca tengan el mismo número aunque dos pedidos lleguen a la vez.
 *    Ver App\Services\Asistencia\NumeroParaMarcar.
 *
 * `asistencia_marcaciones.metodo`: 'huella' | 'numero'. NULL en las correcciones manuales,
 * que no las hizo nadie en el lector. Las marcas del lector que ya existen fueron todas
 * con huella: era la única forma de marcar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asistencia_empleados', function (Blueprint $table) {
            $table->text('numero_marcar')->nullable()
                ->comment('Número para marcar, cifrado con APP_KEY. Nunca se muestra');
            $table->char('numero_marcar_indice', 64)->nullable()
                ->comment('HMAC-SHA256 del número con clave derivada de APP_KEY: búsqueda y unicidad');
            $table->unique('numero_marcar_indice', 'asistencia_empleados_numero_marcar_indice_unique');
        });

        Schema::table('asistencia_marcaciones', function (Blueprint $table) {
            $table->string('metodo', 10)->nullable()
                ->comment('huella | numero. NULL en las correcciones manuales');
        });

        DB::table('asistencia_marcaciones')
            ->whereIn('origen', ['dispositivo', 'diferida'])
            ->update(['metodo' => 'huella']);
    }

    public function down(): void
    {
        Schema::table('asistencia_marcaciones', function (Blueprint $table) {
            $table->dropColumn('metodo');
        });

        Schema::table('asistencia_empleados', function (Blueprint $table) {
            $table->dropUnique('asistencia_empleados_numero_marcar_indice_unique');
        });

        Schema::table('asistencia_empleados', function (Blueprint $table) {
            $table->dropColumn(['numero_marcar', 'numero_marcar_indice']);
        });
    }
};
