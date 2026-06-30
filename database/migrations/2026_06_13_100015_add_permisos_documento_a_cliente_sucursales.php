<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uso permitido por sucursal/sala: a qué documentos se puede facturar.
 * - permite_ccf: se le pueden emitir CCF (salas normales de venta).
 * - permite_nota_credito: se le pueden emitir notas de crédito (ajustes/PPQ).
 * Ej.: Oficina Central → permite_ccf=false, permite_nota_credito=true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cliente_sucursales', function (Blueprint $table) {
            $table->boolean('permite_ccf')->default(true)->after('es_agente_retencion');
            $table->boolean('permite_nota_credito')->default(true)->after('permite_ccf');
        });
    }

    public function down(): void
    {
        Schema::table('cliente_sucursales', function (Blueprint $table) {
            $table->dropColumn(['permite_ccf', 'permite_nota_credito']);
        });
    }
};
