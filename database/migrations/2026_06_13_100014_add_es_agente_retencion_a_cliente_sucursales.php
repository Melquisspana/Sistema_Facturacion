<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Override de "agente de retención" por sucursal/sala:
 *  null  = hereda del cliente
 *  true  = la sala es agente de retención
 *  false = la sala NO es agente de retención
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cliente_sucursales', function (Blueprint $table) {
            $table->boolean('es_agente_retencion')->nullable()->after('requiere_orden_compra');
        });
    }

    public function down(): void
    {
        Schema::table('cliente_sucursales', function (Blueprint $table) {
            $table->dropColumn('es_agente_retencion');
        });
    }
};
