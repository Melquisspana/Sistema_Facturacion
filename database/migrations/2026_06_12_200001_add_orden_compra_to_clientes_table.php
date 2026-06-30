<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración por cliente para CCF (preparación, sin lógica de DTE todavía):
 * marca si el cliente exige número de orden de compra en sus comprobantes.
 * El número en sí NO se guarda aquí (cambia por factura); solo la regla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->boolean('requiere_orden_compra')->default(false)->after('observaciones');
            $table->string('etiqueta_orden_compra', 100)->nullable()->after('requiere_orden_compra');
            $table->text('observaciones_facturacion')->nullable()->after('etiqueta_orden_compra');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['requiere_orden_compra', 'etiqueta_orden_compra', 'observaciones_facturacion']);
        });
    }
};
