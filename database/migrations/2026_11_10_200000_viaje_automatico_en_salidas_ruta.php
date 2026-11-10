<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El viaje que se arma solo (Melqui, 10/11/2026): la salida se abre con el primer pedido del
 * vendedor y suma los días seguidos en la misma zona o en una vecina.
 *
 *  - `ruta_id` admite vacío: el viaje no sale de una ruta fija. Lleva la zona de su primera
 *    sala (la ruta de la sala o la que propone la cobertura) y queda vacía si la sala no
 *    tiene ninguna. La FK se quita y se vuelve a poner igual para cambiar la columna sin
 *    depender de cómo lo tome cada versión de MySQL.
 *  - `automatica`: la salida que nadie armó (el viaje y la de «Por entregar»). Hasta hoy se
 *    reconocía por el texto de `observaciones`; las de antes siguen así (no se mueven datos).
 *  - `suma_pedidos`: el viaje todavía recibe pedidos. Se apaga cuando el vendedor dice que no
 *    sigue, cuando abre un viaje en otra zona o cuando pasa un día sin pedidos; después el
 *    viaje queda abierto solo para entregar.
 *
 * Aditiva: dos columnas con valor por defecto y un índice. Las salidas que ya existen quedan
 * como estaban.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salidas_ruta', function (Blueprint $table) {
            $table->dropForeign(['ruta_id']);
        });
        Schema::table('salidas_ruta', function (Blueprint $table) {
            $table->unsignedBigInteger('ruta_id')->nullable()->change();
            $table->foreign('ruta_id')->references('id')->on('rutas')->cascadeOnUpdate()->restrictOnDelete();
            $table->boolean('automatica')->default(false)->after('observaciones');
            $table->boolean('suma_pedidos')->default(false)->after('automatica');
            $table->index(['automatica', 'estado'], 'salidas_ruta_automatica_estado_idx');
        });
    }

    public function down(): void
    {
        // Un viaje sin zona no tiene a qué ruta volver: se avisa en vez de borrar o inventar.
        if (DB::table('salidas_ruta')->whereNull('ruta_id')->exists()) {
            throw new RuntimeException('Hay viajes sin ruta en salidas_ruta: asignales una ruta antes de revertir esta migración.');
        }

        Schema::table('salidas_ruta', function (Blueprint $table) {
            $table->dropIndex('salidas_ruta_automatica_estado_idx');
            $table->dropColumn(['automatica', 'suma_pedidos']);
            $table->dropForeign(['ruta_id']);
        });
        Schema::table('salidas_ruta', function (Blueprint $table) {
            $table->unsignedBigInteger('ruta_id')->nullable(false)->change();
            $table->foreign('ruta_id')->references('id')->on('rutas')->cascadeOnUpdate()->restrictOnDelete();
        });
    }
};
