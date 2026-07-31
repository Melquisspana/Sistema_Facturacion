<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de un traslado: qué insumo y qué lote viajan, y cuánto.
 *
 * A diferencia de una recepción, aquí NO hay conversión: lo que viaja ya está en
 * inventario y por tanto ya está en unidad base. `unidad_base` se guarda como
 * INSTANTÁNEA del insumo para que el histórico no cambie si mañana cambia el
 * catálogo, pero no hay factor ni unidad de compra que aplicar.
 *
 * `planta_lote_id` es NOT NULL, al contrario que en las recepciones: un traslado
 * mueve saldo que YA EXISTE, y ese saldo siempre está en un lote concreto —real
 * o genérico—. No hay ningún momento en que un traslado no sepa qué lote mueve.
 *
 * UNIQUE (traslado, insumo, lote). Dos líneas del mismo lote en el mismo
 * traslado no son dos cosas distintas: son la misma cantidad escrita dos veces.
 * El servicio las FUSIONA de forma determinista antes de escribir, y el unique
 * es la garantía dura de que ninguna otra vía las duplique. Sin él, enviar
 * produciría dos movimientos sobre el mismo bucket con el mismo documento y
 * detalle distinto, que es correcto pero ilegible.
 *
 * `cascadeOnDelete` sobre el traslado es coherente con el ciclo de vida: el
 * único documento que puede desaparecer de la base es uno que nunca llegó a
 * existir. Un enviado no se borra —se reversa— y la aplicación lo impide antes
 * de llegar al motor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planta_traslado_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planta_traslado_id')
                ->constrained('planta_traslados')->cascadeOnDelete();
            $table->foreignId('planta_insumo_id')
                ->constrained('planta_insumos')->restrictOnDelete();
            $table->foreignId('planta_lote_id')
                ->constrained('planta_lotes')->restrictOnDelete()
                ->comment('NOT NULL: un traslado mueve saldo que ya existe, y ese saldo tiene lote');

            $table->decimal('cantidad', 14, 4)
                ->comment('En unidad base. Siempre > 0. La misma en salida, tránsito y llegada');
            $table->string('unidad_base', 10)
                ->comment('Instantánea de planta_insumos.unidad_base. Aquí no hay conversión');

            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->unique(
                ['planta_traslado_id', 'planta_insumo_id', 'planta_lote_id'],
                'planta_trasl_det_linea_unica'
            );
            $table->index('planta_traslado_id', 'planta_trasl_det_traslado_idx');
            $table->index('planta_insumo_id', 'planta_trasl_det_insumo_idx');
            $table->index('planta_lote_id', 'planta_trasl_det_lote_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planta_traslado_detalles');
    }
};
