<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LIBRO MAYOR del inventario de Planta. Es la única fuente de verdad de los
 * saldos: `planta_existencias` es una proyección materializada de esta tabla y
 * puede reconstruirse entera desde aquí.
 *
 * APPEND-ONLY. La tabla se diseña para que una fila, una vez escrita, no vuelva
 * a tocarse nunca:
 *
 *   - SIN `updated_at`: no hay ningún momento legítimo en que una fila cambie,
 *     así que la columna solo serviría para disimular que alguien la cambió.
 *   - SIN softDeletes: borrar un hecho del mayor descuadra el saldo. Deshacer
 *     algo se registra con un movimiento de compensación de tipo `reversion_*`
 *     que apunta al original con `movimiento_revertido_id`.
 *
 * BUCKET DE CINCO DIMENSIONES. El saldo no vive «en un lote» ni «en una
 * ubicación»: vive en la intersección exacta de
 *
 *     insumo + lote + ubicación + estado de disponibilidad + traslado
 *
 * Las cinco columnas viajan juntas SIEMPRE, en el mismo orden, en esta tabla y
 * en `planta_existencias`. El índice `planta_mov_bucket_idx` cubre esa tupla
 * completa porque la reconciliación agrupa exactamente por ella.
 *
 * POR QUÉ `planta_traslado_id` NO TIENE CLAVE FORÁNEA
 * ---------------------------------------------------
 * La quinta dimensión distingue el saldo que ya salió del origen y todavía no
 * llegó al destino: vive en la ubicación TRANSITO y se identifica por el
 * traslado que lo mueve. Pero la columna es `NOT NULL DEFAULT 0` porque el
 * saldo NORMAL —el 99 % de las filas— no pertenece a ningún traslado, y una
 * dimensión de bucket no puede ser NULL: en MySQL y en SQLite dos NULL no son
 * iguales, así que un `UNIQUE` con NULL dejaría de impedir duplicados
 * justamente en el caso común. El centinela 0 mantiene el único operativo.
 *
 * Ese centinela es incompatible con una FK: 0 no es un traslado. Y la tabla
 * `planta_traslados` ni siquiera existe todavía (llega en el paso 6), así que
 * en este paso no hay a qué apuntar.
 *
 * COSTE REAL DE NO TENER FK, explícito para que nadie lo descubra tarde:
 *   a) el motor NO impide escribir un `planta_traslado_id` que no corresponda a
 *      ningún traslado;
 *   b) el motor NO impide borrar un traslado dejando movimientos huérfanos;
 *   c) un `traslado_id > 0` en una ubicación física —o un 0 en TRANSITO— sería
 *      aceptado por la base sin protestar.
 *
 * VALIDACIÓN COMPENSATORIA, en tres capas:
 *   1. PlantaInventarioService valida las invariantes de bucket ANTES de
 *      escribir y es el único escritor lógico: ningún flujo de dominio puede
 *      insertar aquí sin pasar por él.
 *   2. El comando `planta:reconciliar-existencias` detecta las violaciones
 *      (b) y (c) sobre datos ya escritos, incluidas las que entraran por SQL
 *      crudo, y las reporta con exit code distinto de cero.
 *   3. Cuando el paso 6 cree `planta_traslados`, la comprobación (a) podrá
 *      endurecerse con una FK parcial o un CHECK, pero el servicio y la
 *      reconciliación seguirán siendo la defensa principal: la regla real
 *      —«0 fuera de tránsito, >0 solo en TRANSITO»— es condicional al TIPO de
 *      la ubicación y ninguna FK puede expresarla.
 *
 * `efecto_uid` es la idempotencia DURA: SHA-256 determinista del efecto exacto
 * (documento + detalle + transición + tipo + bucket + secuencia). El unique
 * impide que el mismo efecto se aplique dos veces aunque el llamador reintente.
 * `grupo_uuid` NO sirve para eso: solo agrupa los movimientos de una misma
 * operación para poder leerlos juntos.
 *
 * `documento_type`/`documento_id` son una relación polimórfica DELIBERADAMENTE
 * suelta (sin morphTo obligatorio ni FK): el mayor debe sobrevivir a cualquier
 * documento que lo origine, presente o futuro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planta_movimientos', function (Blueprint $table) {
            $table->id();

            // --- Bucket: las cinco dimensiones, siempre en este orden ---
            $table->foreignId('planta_insumo_id')
                ->constrained('planta_insumos')->restrictOnDelete();
            $table->foreignId('planta_lote_id')
                ->constrained('planta_lotes')->restrictOnDelete();
            $table->foreignId('planta_ubicacion_id')
                ->constrained('planta_ubicaciones')->restrictOnDelete();
            $table->string('estado', 20)
                ->comment('App\Enums\Planta\EstadoDisponibilidad: disponible|retenido|rechazado');
            $table->unsignedBigInteger('planta_traslado_id')->default(0)
                ->comment('0 = fuera de tránsito. >0 solo en ubicación TRANSITO. SIN FK: ver cabecera');

            // --- Efecto sobre el saldo ---
            $table->decimal('cantidad', 14, 4)
                ->comment('FIRMADA: positiva suma al bucket, negativa resta. Nunca 0');
            $table->string('unidad_base', 10)
                ->comment('Copia de planta_insumos.unidad_base congelada al escribir');

            // --- Por qué y de dónde viene ---
            $table->string('tipo', 40)
                ->comment('App\Enums\Planta\TipoMovimientoPlanta');
            $table->string('documento_type', 120)
                ->comment('Clase del documento origen; polimórfico suelto, sin FK');
            $table->unsignedBigInteger('documento_id');
            $table->unsignedBigInteger('documento_detalle_id')->nullable()
                ->comment('Línea del documento cuando el efecto nace de un detalle');
            $table->string('transicion', 30)
                ->comment('Transición del documento que provocó el efecto: confirmar, enviar, recibir, reversar...');

            // --- Idempotencia y agrupación ---
            $table->char('efecto_uid', 64)
                ->comment('SHA-256 determinista del efecto exacto. Idempotencia DURA');
            $table->uuid('grupo_uuid')
                ->comment('Agrupa los movimientos de una misma operación. NO es idempotencia');
            $table->foreignId('movimiento_revertido_id')->nullable()
                ->constrained('planta_movimientos')->restrictOnDelete()
                ->comment('Movimiento original que este compensa. NOT NULL solo en tipos reversion_*');

            // --- Quién y cuándo ---
            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('responsable_nombre', 120)->nullable()
                ->comment('Nombre congelado: sobrevive al borrado del usuario');
            $table->date('fecha_efectiva')
                ->comment('Fecha OPERATIVA del documento, no la de escritura');

            $table->json('metadata')->nullable()
                ->comment('saldo_antes/saldo_despues del bucket y contexto del documento origen');

            // Solo created_at: una fila del mayor no se actualiza jamás.
            $table->timestamp('created_at')->nullable();

            // Tupla exacta del bucket: es la agrupación de la reconciliación.
            $table->index([
                'planta_insumo_id',
                'planta_lote_id',
                'planta_ubicacion_id',
                'estado',
                'planta_traslado_id',
            ], 'planta_mov_bucket_idx');

            $table->index(['documento_type', 'documento_id'], 'planta_mov_documento_idx');
            $table->index('grupo_uuid', 'planta_mov_grupo_idx');
            $table->index('fecha_efectiva', 'planta_mov_fecha_idx');
            $table->index('user_id', 'planta_mov_user_idx');
            $table->index('tipo', 'planta_mov_tipo_idx');

            $table->unique('efecto_uid', 'planta_mov_efecto_uid_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planta_movimientos');
    }
};
