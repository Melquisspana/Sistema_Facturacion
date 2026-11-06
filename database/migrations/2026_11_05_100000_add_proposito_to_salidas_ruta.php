<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Para qué sale una salida: entregar, sacar pedido o las dos
 * (el enum `PropositoSalida`). Por defecto «entregar», que es lo que fueron
 * todas las salidas hasta hoy: las que ya existen no cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salidas_ruta', function (Blueprint $table) {
            $table->string('proposito', 20)->default('entregar')->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('salidas_ruta', function (Blueprint $table) {
            $table->dropColumn('proposito');
        });
    }
};
