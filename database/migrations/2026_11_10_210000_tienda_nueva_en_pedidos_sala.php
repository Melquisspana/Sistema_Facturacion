<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tienda nueva (Melqui, 10/11/2026): el vendedor saca el pedido de una tienda que todavía no
 * está en Clientes escribiendo solo su nombre. La oficina, al facturar, da de alta el cliente
 * y la sala y la enlaza al pedido; sin sala enlazada no se factura.
 *
 *  - `cliente_sucursal_id` admite vacío: la tienda nueva no tiene sala hasta que la oficina la
 *    da de alta. La FK se quita y se vuelve a poner igual para cambiar la columna sin depender
 *    de cómo lo tome cada versión de MySQL.
 *  - `sala_nueva_nombre`: el nombre que escribió el vendedor. Queda después del enlace, para la
 *    historia del pedido.
 *
 * Aditiva: los pedidos que ya existen quedan como estaban.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_sala', function (Blueprint $table) {
            $table->dropForeign(['cliente_sucursal_id']);
        });
        Schema::table('pedidos_sala', function (Blueprint $table) {
            $table->unsignedBigInteger('cliente_sucursal_id')->nullable()->change();
            $table->foreign('cliente_sucursal_id')->references('id')->on('cliente_sucursales')->restrictOnDelete();
            $table->string('sala_nueva_nombre', 100)->nullable()->after('cliente_sucursal_id');
        });
    }

    public function down(): void
    {
        // Una tienda nueva sin enlazar no tiene a qué sala volver: se avisa en vez de borrarla.
        if (DB::table('pedidos_sala')->whereNull('cliente_sucursal_id')->exists()) {
            throw new RuntimeException('Hay pedidos de tiendas nuevas sin sala en pedidos_sala: enlazalos a su sala antes de revertir esta migración.');
        }

        Schema::table('pedidos_sala', function (Blueprint $table) {
            $table->dropColumn('sala_nueva_nombre');
            $table->dropForeign(['cliente_sucursal_id']);
        });
        Schema::table('pedidos_sala', function (Blueprint $table) {
            $table->unsignedBigInteger('cliente_sucursal_id')->nullable(false)->change();
            $table->foreign('cliente_sucursal_id')->references('id')->on('cliente_sucursales')->restrictOnDelete();
        });
    }
};
