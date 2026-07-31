<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de una recepción: qué insumo entró, cuánto, en qué unidad de compra y
 * a qué disponibilidad.
 *
 * LA CONVERSIÓN SE CONGELA AQUÍ. El insumo lleva valores «sugeridos»
 * (`factor_conversion_sugerido`, `unidad_recepcion_sugerida`,
 * `contenido_sugerido`) que son solo ayudas de captura: lo que de verdad ocurrió
 * queda escrito en esta línea y no cambia aunque el catálogo cambie mañana. Sin
 * esa copia, editar el factor de un insumo reescribiría el histórico de todas
 * sus recepciones.
 *
 *     cantidad_base = round(cantidad_recibida × contenido_por_unidad × factor_conversion, 4)
 *
 * `cantidad_base` se guarda calculada, pero NUNCA se confía en la que llegue del
 * formulario: se recalcula en el servidor al guardar y OTRA VEZ al confirmar.
 * Está en la tabla para poder leerla sin recalcular, no como fuente de verdad.
 *
 * `unidad_base` se copia de `planta_insumos.unidad_base`. El usuario no la
 * elige: elige la unidad de COMPRA (`unidad_recibida`, texto libre: saco, caja,
 * kg, paquete) y el factor que la convierte.
 *
 * `planta_lote_id` es NULLABLE porque un borrador normalmente todavía no tiene
 * lote: el lote REAL nace al CONFIRMAR, con la fecha de la recepción. Crearlo
 * antes llenaría el catálogo de lotes de borradores que quizá se anulen.
 *
 * La excepción es la REUTILIZACIÓN EXPLÍCITA: si el usuario elige un lote real
 * existente, el borrador guarda esa intención aquí y el servicio la valida al
 * confirmar (mismo insumo, activo, no genérico). Al confirmar la columna deja de
 * ser nula en todos los casos, y la aplicación no permite volver a borrador.
 *
 * `cascadeOnDelete` sobre la recepción es coherente con el ciclo de vida: el
 * único documento que puede desaparecer de la base es uno que nunca se creó
 * (rollback). Un confirmado no se borra —se reversa— y la aplicación lo impide
 * antes de llegar al motor; el cascade solo evita dejar líneas huérfanas si
 * alguna vez se elimina un borrador desde consola.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planta_recepcion_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planta_recepcion_id')
                ->constrained('planta_recepciones')->cascadeOnDelete();
            $table->foreignId('planta_insumo_id')
                ->constrained('planta_insumos')->restrictOnDelete();
            $table->foreignId('planta_lote_id')->nullable()
                ->constrained('planta_lotes')->restrictOnDelete()
                ->comment('NULL en borrador salvo reutilización explícita; se resuelve o se crea al confirmar');

            // --- Lo que se recibió, tal como lo entregó el proveedor ---
            $table->decimal('cantidad_recibida', 14, 4)
                ->comment('En unidad de compra. Siempre > 0');
            $table->string('unidad_recibida', 30)
                ->comment('Unidad de COMPRA: saco, caja, paquete, kg. Texto libre');
            $table->decimal('contenido_por_unidad', 14, 4)
                ->comment('Cuánto trae cada unidad de compra. Siempre > 0');
            $table->decimal('factor_conversion', 18, 8)
                ->comment('Del contenido a la unidad base del insumo. Siempre > 0');

            // --- Lo que entra al inventario ---
            $table->decimal('cantidad_base', 14, 4)
                ->comment('Recalculada en backend al guardar y al confirmar. Nunca se confía en la del formulario');
            $table->string('unidad_base', 10)
                ->comment('Copiada de planta_insumos.unidad_base. El usuario no la elige');
            $table->string('estado_destino', 20)
                ->comment('App\Enums\Planta\EstadoDisponibilidad: disponible|retenido. Nunca rechazado');

            // --- Trazabilidad del lote ---
            $table->string('lote_codigo_proveedor', 60)->nullable()
                ->comment('Texto impreso por el proveedor. NO implica reutilizar lote');
            $table->date('fecha_elaboracion')->nullable();
            $table->date('fecha_vencimiento')->nullable();

            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index('planta_recepcion_id', 'planta_recep_det_recepcion_idx');
            $table->index('planta_insumo_id', 'planta_recep_det_insumo_idx');
            $table->index('planta_lote_id', 'planta_recep_det_lote_idx');
            $table->index('estado_destino', 'planta_recep_det_destino_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planta_recepcion_detalles');
    }
};
