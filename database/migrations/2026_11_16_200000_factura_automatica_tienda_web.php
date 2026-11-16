<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Factura automática de la tienda web (ADR 0011, aprobada por Melqui el 16/11/2026).
 *
 * - dtes.receptor_nombre / receptor_correo: el consumidor final de la tienda queda
 *   nombrado en su propia factura sin crear un Cliente. Vacíos en todo lo demás. Los
 *   largos son los del esquema de la Factura 01 (nombre 250, correo 100). Fuera de
 *   borrador no cambian: DteObserver solo deja tocar el estado y los datos de Hacienda.
 * - pedidos_web.dte_id: la ÚNICA factura del pedido. Única, porque un documento no
 *   factura dos pedidos, y restrictOnDelete: un documento con pedido no se borra.
 * - factura_correo_en / informado_factura_en: el correo al cliente y el aviso a la
 *   página salen una sola vez; vacíos son pendientes y la pasada siguiente los reintenta.
 *
 * Columnas nuevas al final y vacías: no reescriben datos existentes. Revertir con
 * facturas ya ligadas perdería de qué pedido es cada documento y a quién se le facturó:
 * se aborta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->string('receptor_nombre', 250)->nullable();
            $table->string('receptor_correo', 100)->nullable();
        });

        Schema::table('pedidos_web', function (Blueprint $table) {
            $table->foreignId('dte_id')->nullable()->unique('pw_dte_uq')->constrained('dtes')->restrictOnDelete();
            $table->dateTime('factura_correo_en')->nullable();
            $table->dateTime('informado_factura_en')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('pedidos_web')->whereNotNull('dte_id')->exists()
            || DB::table('dtes')->whereNotNull('receptor_nombre')->orWhereNotNull('receptor_correo')->exists()) {
            throw new RuntimeException('Hay pedidos web facturados o facturas con el receptor nombrado: no se puede revertir sin perder a quién se facturó.');
        }

        Schema::table('pedidos_web', function (Blueprint $table) {
            $table->dropForeign(['dte_id']);
            $table->dropUnique('pw_dte_uq');
            $table->dropColumn(['dte_id', 'factura_correo_en', 'informado_factura_en']);
        });

        Schema::table('dtes', function (Blueprint $table) {
            $table->dropColumn(['receptor_nombre', 'receptor_correo']);
        });
    }
};
