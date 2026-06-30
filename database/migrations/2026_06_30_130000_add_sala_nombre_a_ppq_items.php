<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot del nombre comercial de la sala en cada item del lote. Se resuelve al agregar
 * (vía la sucursal relacionada al CCF o por el código), para que el detalle del lote y el
 * Excel muestren el nombre aunque el item venga de Gmail (sin DTE local) o cambie luego.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppq_items', function (Blueprint $table) {
            $table->string('sala_nombre')->nullable()->after('numero_orden_compra');
        });
    }

    public function down(): void
    {
        Schema::table('ppq_items', function (Blueprint $table) {
            $table->dropColumn('sala_nombre');
        });
    }
};
