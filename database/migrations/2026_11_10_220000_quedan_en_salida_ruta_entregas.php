<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quedan al entregar (Melqui, 10/11/2026): si la sala no paga y da un quedan, el vendedor
 * puede anotar su número y la fecha de pago que le dieron. La factura queda por cobrar con
 * esa fecha.
 *
 * Aditiva: dos columnas vacías. Las entregas que ya existen quedan como estaban.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salida_ruta_entregas', function (Blueprint $table) {
            $table->string('quedan_numero', 30)->nullable()->after('monto_cobrado');
            $table->date('quedan_fecha_pago')->nullable()->after('quedan_numero');
        });
    }

    public function down(): void
    {
        Schema::table('salida_ruta_entregas', function (Blueprint $table) {
            $table->dropColumn(['quedan_numero', 'quedan_fecha_pago']);
        });
    }
};
