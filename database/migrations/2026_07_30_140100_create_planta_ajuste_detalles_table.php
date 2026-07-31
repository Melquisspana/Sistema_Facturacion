<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de un ajuste: qué bucket se altera y en cuánto.
 *
 * EL BUCKET COMPLETO VIVE EN LA LÍNEA. A diferencia de un traslado —donde la
 * ubicación es del documento— aquí cada línea lleva su propia ubicación y su
 * propio estado de disponibilidad: un mismo ajuste puede corregir Casa y Fábrica,
 * y saldo disponible y rechazado, en una sola firma. La quinta dimensión,
 * `planta_traslado_id`, no se guarda porque siempre vale 0: un ajuste nunca toca
 * saldo en tránsito, que solo lo mueven los traslados.
 *
 * LAS TRES COLUMNAS DE CONTEO son exclusivas del tipo `correccion_conteo` y por
 * eso son nullable:
 *
 *   - `cantidad_conteo`   lo que se contó físicamente. Lo aporta la persona.
 *   - `cantidad_sistema`  lo que decía el sistema, leído BAJO BLOQUEO al
 *                         confirmar. Nunca se acepta del formulario: entre que
 *                         se escribe el borrador y se confirma, el saldo puede
 *                         haber cambiado, y usar el valor viejo escribiría una
 *                         diferencia que ya no existe.
 *   - `diferencia`        conteo − sistema, recalculada al confirmar.
 *
 * `cantidad` es SIEMPRE la magnitud del movimiento, en positivo; el signo lo
 * decide el tipo del documento y lo pone el servicio. En una corrección de
 * conteo se deriva de la diferencia, así que en el borrador vale 0 hasta que se
 * confirma.
 *
 * UNIQUE sobre las cinco dimensiones del bucket dentro del ajuste. Dos líneas
 * del mismo bucket en el mismo documento no son dos hechos: son el mismo hecho
 * escrito dos veces, y sumarían dos movimientos sobre el mismo saldo sin que
 * nadie pueda explicar por qué.
 *
 * `cascadeOnDelete` sobre el ajuste es coherente con el ciclo de vida: el único
 * documento que puede desaparecer es uno que nunca llegó a existir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planta_ajuste_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planta_ajuste_id')
                ->constrained('planta_ajustes')->cascadeOnDelete();

            // --- El bucket, salvo el traslado, que aquí es siempre 0 ---
            $table->foreignId('planta_insumo_id')
                ->constrained('planta_insumos')->restrictOnDelete();
            $table->foreignId('planta_lote_id')
                ->constrained('planta_lotes')->restrictOnDelete()
                ->comment('NOT NULL: real si el insumo controla lotes, genérico si no');
            $table->foreignId('planta_ubicacion_id')
                ->constrained('planta_ubicaciones')->restrictOnDelete()
                ->comment('Física, activa y operable. Nunca TRANSITO');
            $table->string('estado_disponibilidad', 20)
                ->comment('App\Enums\Planta\EstadoDisponibilidad: disponible|retenido|rechazado');

            $table->decimal('cantidad', 14, 4)
                ->comment('MAGNITUD del movimiento, siempre positiva. El signo lo pone el tipo');

            // --- Solo en correccion_conteo ---
            $table->decimal('cantidad_conteo', 14, 4)->nullable()
                ->comment('Lo contado físicamente. Lo aporta la persona');
            $table->decimal('cantidad_sistema', 14, 4)->nullable()
                ->comment('Lo que decía el sistema, leído BAJO BLOQUEO al confirmar. Nunca del formulario');
            $table->decimal('diferencia', 14, 4)->nullable()
                ->comment('conteo - sistema, recalculada al confirmar');

            $table->string('unidad_base', 10)
                ->comment('Instantánea de planta_insumos.unidad_base');
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->unique([
                'planta_ajuste_id',
                'planta_insumo_id',
                'planta_lote_id',
                'planta_ubicacion_id',
                'estado_disponibilidad',
            ], 'planta_ajuste_det_bucket_unico');

            $table->index('planta_ajuste_id', 'planta_ajuste_det_ajuste_idx');
            $table->index('planta_insumo_id', 'planta_ajuste_det_insumo_idx');
            $table->index('planta_lote_id', 'planta_ajuste_det_lote_idx');
            $table->index('planta_ubicacion_id', 'planta_ajuste_det_ubicacion_idx');
            $table->index('estado_disponibilidad', 'planta_ajuste_det_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planta_ajuste_detalles');
    }
};
