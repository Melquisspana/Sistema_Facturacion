<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El descuento del cliente/sucursal es un PORCENTAJE (0–100), no un monto fijo.
 *
 * El DTE sigue guardando el MONTO del descuento en descuento_global y en los
 * buckets (descuento_gravado/exento/no_sujeto/total_descuento), pero ese monto
 * se calcula a partir de este porcentaje sobre el subtotal bruto y se congela
 * al generar. Guardar el porcentaje permite mostrar "Descuento aplicado: 5%" y
 * dejar trazabilidad de qué porcentaje usó cada documento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->decimal('descuento_porcentaje_aplicado', 5, 2)->nullable()->default(0)->after('descuento_global');
        });
    }

    public function down(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropColumn('descuento_porcentaje_aplicado');
        });
    }
};
