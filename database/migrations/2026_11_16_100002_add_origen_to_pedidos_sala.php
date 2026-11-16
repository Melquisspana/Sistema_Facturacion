<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un pedido web no tiene sala y no es una tienda nueva. El origen conserva esa
 * diferencia en fábrica, las autorizaciones y la selección de pedidos para CCF.
 * Los pedidos anteriores siguen siendo de sala sin cambiar su comportamiento.
 * No se permite revertir si se perdería la distinción de un pedido web.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_sala', function (Blueprint $table) {
            $table->string('origen', 10)->default('sala')->index('ps_origen_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('pedidos_sala')->where('origen', '!=', 'sala')->exists()) {
            throw new RuntimeException('Hay pedidos de otro origen: no se puede perder su procedencia.');
        }
        Schema::table('pedidos_sala', function (Blueprint $table) {
            $table->dropIndex('ps_origen_idx');
            $table->dropColumn('origen');
        });
    }
};
