<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liquidación de la salida completa («Terminar ruta»). En una salida de varios días el
 * dinero se entrega una sola vez, al volver: quién lo entrega y quién y cuándo cerró la
 * ruta quedan en la salida. Los importes no se copian: salen de los cierres de cada día.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salidas_ruta', function (Blueprint $table) {
            $table->foreignId('entrega_dinero_personal_id')->nullable()->after('observaciones')->constrained('rutas_personal')->nullOnDelete();
            $table->foreignId('liquidada_por')->nullable()->after('entrega_dinero_personal_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('liquidada_en')->nullable()->after('liquidada_por');
        });
    }

    public function down(): void
    {
        Schema::table('salidas_ruta', function (Blueprint $table) {
            $table->dropConstrainedForeignId('liquidada_por');
            $table->dropConstrainedForeignId('entrega_dinero_personal_id');
            $table->dropColumn('liquidada_en');
        });
    }
};
