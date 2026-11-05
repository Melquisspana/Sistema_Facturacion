<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos que el vendedor saca en la sala (docs/PLAN_TABLERO_RUTAS.md, etapa 3). Hasta
 * ahora vivían solo en el sugerido de papel y en el grupo de WhatsApp.
 *
 *  - `sacado_por_id` es la ficha de campo de quien lo sacó y `registrado_por` el usuario
 *    que lo guardó, igual que en las entregas.
 *  - `estado` es texto validado por el enum `EstadoPedidoSala`: la pantalla de
 *    producción agrega sus estados sin migrar.
 *  - El enlace con el CCF llega con «facturar desde el pedido».
 *
 * Sin borrado suave: como el resto de Rutas, un pedido no se borra, se anula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos_sala', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_sucursal_id')->constrained('cliente_sucursales')->restrictOnDelete();
            $table->foreignId('ruta_id')->nullable()->constrained('rutas')->nullOnDelete();
            $table->foreignId('salida_ruta_id')->nullable()->constrained('salidas_ruta')->restrictOnDelete();
            $table->foreignId('sacado_por_id')->nullable()->constrained('rutas_personal')->nullOnDelete();
            $table->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $table->date('fecha');
            $table->string('estado', 20)->default('registrado');
            $table->string('numero_orden_compra', 30)->nullable();
            $table->string('notas', 500)->nullable();
            $table->timestamps();

            $table->index(['fecha', 'estado']);
            $table->index(['salida_ruta_id', 'cliente_sucursal_id']);
            $table->index(['cliente_sucursal_id', 'fecha']);
            $table->index(['ruta_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos_sala');
    }
};
