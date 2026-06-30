<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos que la CalculadoraDte ya produce y faltaban para persistir con
 * coherencia el borrador:
 *  - dtes.total_exportacion: venta exportada (FEX, 0% IVA), separada del gravado.
 *  - dtes.total_antes_retencion: total a pagar ANTES de restar la retención de IVA.
 *  - dte_lineas.venta_exportacion: venta exportada por línea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->decimal('total_exportacion', 11, 2)->default(0)->after('total_gravado');
            $table->decimal('total_antes_retencion', 11, 2)->default(0)->after('monto_total_operacion');
        });

        Schema::table('dte_lineas', function (Blueprint $table) {
            $table->decimal('venta_exportacion', 11, 2)->default(0)->after('venta_gravada');
        });
    }

    public function down(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropColumn(['total_exportacion', 'total_antes_retencion']);
        });

        Schema::table('dte_lineas', function (Blueprint $table) {
            $table->dropColumn('venta_exportacion');
        });
    }
};
