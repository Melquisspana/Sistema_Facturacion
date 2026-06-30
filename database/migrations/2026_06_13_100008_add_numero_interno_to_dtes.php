<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Número INTERNO / provisional asignado al generar el documento (consumiendo el
 * correlativo interno). NO es el número de control oficial del MH (ese sigue en
 * numero_control, reservado para la fase de generación/firma real).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->string('numero_interno', 40)->nullable()->unique()->after('numero_control');
        });
    }

    public function down(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropColumn('numero_interno');
        });
    }
};
