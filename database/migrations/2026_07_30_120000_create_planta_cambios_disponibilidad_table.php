<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documento que LIBERA o RECHAZA saldo retenido.
 *
 *     retenido --liberar--> disponible
 *     retenido --rechazar-> rechazado
 *
 * NO altera la cantidad física: el par de movimientos que emite suma cero. Lo
 * único que cambia es QUÉ PARTE del saldo puede usarse. Por eso es un documento
 * distinto de un ajuste, que sí altera la cantidad; mezclarlos haría imposible
 * distinguir «apareció o desapareció mercancía» de «la misma mercancía cambió
 * de situación».
 *
 * UNA SOLA FILA, SIN LÍNEAS. A diferencia de una recepción, este documento
 * afecta a exactamente un par de buckets que comparten insumo, lote, ubicación y
 * `planta_traslado_id = 0`, y se distinguen solo por el estado. Una tabla de
 * detalles añadiría una indirección sin nada que guardar en ella.
 *
 * `estado_origen` se persiste aunque hoy valga SIEMPRE `retenido`. No es
 * redundante: es el dato que hace legible el histórico sin tener que saber qué
 * transiciones estaban permitidas el día que se firmó. Si mañana se admite
 * `disponible -> retenido`, los documentos viejos siguen diciendo de dónde
 * salieron.
 *
 * SIN softDeletes. Un documento confirmado ya escribió en el libro mayor:
 * borrarlo —aunque fuera de forma lógica— dejaría movimientos apuntando a un
 * documento invisible. Un borrador que no sirve se ANULA.
 *
 * REVERSIÓN con dos punteros al mismo documento, igual que en las recepciones:
 * `reversion_de_id` en el que compensa y `revertido_por_id` en el original. Así
 * una reversión es un cambio de disponibilidad más y se lista, filtra y audita
 * con el mismo código.
 *
 * Las FK a `users` son `nullable + nullOnDelete`, convención de todo el
 * repositorio: `NOT NULL` sería incompatible con `nullOnDelete`, porque el motor
 * no puede escribir NULL en una columna que no lo admite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planta_cambios_disponibilidad', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('numero')
                ->comment('Contador propio (Secuencia: planta_cambio_disponibilidad). NO es fiscal');
            $table->string('estado', 20)
                ->comment('App\Enums\Planta\EstadoCambioDisponibilidad: borrador|confirmado|anulado|reversado');

            // --- El bucket afectado: las cuatro dimensiones que NO cambian ---
            $table->foreignId('planta_insumo_id')
                ->constrained('planta_insumos')->restrictOnDelete();
            $table->foreignId('planta_lote_id')
                ->constrained('planta_lotes')->restrictOnDelete();
            $table->foreignId('planta_ubicacion_id')
                ->constrained('planta_ubicaciones')->restrictOnDelete()
                ->comment('La MISMA para origen y destino. Nunca TRANSITO');

            // --- La quinta dimensión, que es lo único que cambia ---
            $table->string('estado_origen', 20)
                ->comment('App\Enums\Planta\EstadoDisponibilidad. Hoy siempre `retenido`');
            $table->string('estado_destino', 20)
                ->comment('App\Enums\Planta\EstadoDisponibilidad: disponible (liberar) | rechazado');

            $table->decimal('cantidad', 14, 4)
                ->comment('En unidad base del insumo. Siempre > 0. La MISMA en ambos movimientos');
            $table->date('fecha')
                ->comment('Fecha OPERATIVA de la decisión, no la de captura');
            $table->text('motivo')
                ->comment('NOT NULL: sin motivo no queda constancia de por qué dejó de ser utilizable');

            $table->foreignId('creado_por')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('confirmado_por')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('confirmado_en')->nullable();

            $table->foreignId('responsable_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('responsable_nombre', 120)->nullable()
                ->comment('Instantánea: sobrevive al borrado del usuario');

            $table->foreignId('reversion_de_id')->nullable()
                ->constrained('planta_cambios_disponibilidad')->restrictOnDelete()
                ->comment('En el documento que COMPENSA: apunta al original');
            $table->foreignId('revertido_por_id')->nullable()
                ->constrained('planta_cambios_disponibilidad')->restrictOnDelete()
                ->comment('En el ORIGINAL: apunta al documento que lo compensó');

            $table->timestamps();

            $table->unique('numero', 'planta_camdisp_numero_unico');
            $table->index('estado', 'planta_camdisp_estado_idx');
            $table->index('fecha', 'planta_camdisp_fecha_idx');
            $table->index('planta_insumo_id', 'planta_camdisp_insumo_idx');
            $table->index('planta_lote_id', 'planta_camdisp_lote_idx');
            $table->index('planta_ubicacion_id', 'planta_camdisp_ubicacion_idx');
            $table->index('estado_origen', 'planta_camdisp_origen_idx');
            $table->index('estado_destino', 'planta_camdisp_destino_idx');
            $table->index('creado_por', 'planta_camdisp_creado_por_idx');
            $table->index('reversion_de_id', 'planta_camdisp_reversion_de_idx');
            $table->index('revertido_por_id', 'planta_camdisp_revertido_por_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planta_cambios_disponibilidad');
    }
};
