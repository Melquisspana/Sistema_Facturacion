<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los CCF que lleva una salida y qué pasó con cada uno (docs/DISENO_MODULO_RUTAS_20260926.md).
 *
 * Una fila = un INTENTO: el CCF viajó en esta salida. Si sobró, en la próxima salida es
 * otra fila; la historia de intentos queda completa.
 *
 *   · resultado NULL           → todavía sin registrar (pendiente en esta salida).
 *   · resultado 'entregado'    → quién lo entregó, cuándo y si trajo nota de avería.
 *   · resultado 'no_entregado' → con motivo (sin tiempo, fuera de horario, sala cerrada,
 *                                no aceptado, otro + nota).
 *
 * La entrega confirmada por el ALBARÁN de Calleja no se guarda acá: se deriva al leer, de
 * `ppq_albaranes` (AlbaranLocalizador), para que haya una sola fuente de verdad.
 *
 * Que un CCF no esté en dos salidas abiertas a la vez lo controla el servicio que carga
 * los CCF; el índice único solo impide repetirlo dentro de la misma salida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salida_ruta_entregas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salida_ruta_id')->constrained('salidas_ruta')->cascadeOnDelete();
            $table->foreignId('dte_id')->constrained('dtes');
            // Copia de la sala del CCF al cargarlo: agrupa la salida por sala sin depender
            // de que alguien la cambie después en el DTE.
            $table->foreignId('cliente_sucursal_id')->nullable()->constrained('cliente_sucursales')->nullOnDelete();

            $table->string('resultado', 20)->nullable()->comment('ResultadoEntrega; NULL = sin registrar');
            $table->string('motivo_no_entrega', 30)->nullable()->comment('MotivoNoEntrega');
            $table->string('nota', 300)->nullable();
            $table->boolean('trae_nota_averia')->default(false)->comment('La sala dio nota de avería (AC02)');

            $table->foreignId('entregado_por_id')->nullable()->constrained('rutas_personal')->nullOnDelete();
            $table->dateTime('fecha_resultado')->nullable();
            $table->string('origen_registro', 20)->nullable()->comment('OrigenRegistroEntrega: vendedor | oficina');
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['salida_ruta_id', 'dte_id']);
            $table->index(['dte_id', 'resultado']);
            $table->index(['cliente_sucursal_id', 'resultado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salida_ruta_entregas');
    }
};
