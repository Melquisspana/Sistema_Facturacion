<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Valores por defecto de facturación para no pedirlos manualmente en cada CCF:
 * descuento global y condición de operación, tanto a nivel de cliente como de
 * sucursal/sala (la sala tiene prioridad si los define). Nullable = no definido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->decimal('descuento_global_default', 11, 2)->nullable()->after('observaciones_facturacion');
            $table->unsignedTinyInteger('condicion_operacion_default')->nullable()->after('descuento_global_default');
        });

        Schema::table('cliente_sucursales', function (Blueprint $table) {
            $table->decimal('descuento_global_default', 11, 2)->nullable()->after('requiere_orden_compra');
            $table->unsignedTinyInteger('condicion_operacion_default')->nullable()->after('descuento_global_default');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['descuento_global_default', 'condicion_operacion_default']);
        });
        Schema::table('cliente_sucursales', function (Blueprint $table) {
            $table->dropColumn(['descuento_global_default', 'condicion_operacion_default']);
        });
    }
};
