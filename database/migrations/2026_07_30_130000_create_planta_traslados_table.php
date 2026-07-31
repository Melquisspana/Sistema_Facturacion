<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traslado de inventario entre dos ubicaciones físicas.
 *
 *     origen disponible --enviar--> TRÁNSITO (atado a este traslado) --recibir--> destino disponible
 *
 * DOS ACTOS, NO UNO. Enviar y recibir son hechos físicos distintos, ocurren en
 * momentos distintos y pueden recaer en personas distintas. Entre ambos, la
 * mercancía existe de verdad pero no está en ninguna bodega: vive en la
 * ubicación de tránsito, y ahí es donde importa la quinta dimensión del bucket.
 *
 * POR QUÉ EL TRÁNSITO SE ATA AL TRASLADO. El saldo en tránsito lleva
 * `planta_traslado_id = <id de este documento>`. Sin eso, dos envíos simultáneos
 * del mismo insumo y lote se mezclarían en un único saldo de tránsito y al
 * recibir uno se consumiría indistintamente lo del otro. Con el traslado como
 * dimensión del bucket, cada viaje tiene su propio saldo y recibir consume
 * exactamente lo que ese viaje mandó.
 *
 * SIN RECEPCIÓN PARCIAL. Se recibe exactamente lo enviado. Las diferencias
 * reales —se rompió, faltó, sobró— son un hecho distinto que se registra con un
 * ajuste con motivo, no disfrazándolo de «recibí menos». Mezclarlos haría
 * imposible distinguir un error de conteo de una pérdida real.
 *
 * SIN softDeletes. Un traslado enviado ya escribió en el libro mayor: borrarlo
 * dejaría movimientos apuntando a un documento invisible. Un borrador que no
 * sirve se CANCELA.
 *
 * REVERSIÓN con dos punteros al mismo documento, igual que en recepciones y
 * cambios de disponibilidad. `motivo_reversion` vive en el ORIGINAL porque es
 * ahí donde se consulta «por qué se deshizo esto».
 *
 * Las FK a `users` son `nullable + nullOnDelete`, convención de todo el
 * repositorio: `NOT NULL` sería incompatible con `nullOnDelete`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planta_traslados', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('numero')
                ->comment('Contador propio (Secuencia: planta_traslado). NO es fiscal');
            $table->string('estado', 20)
                ->comment('App\Enums\Planta\EstadoTrasladoPlanta: borrador|enviado|recibido|cancelado|reversado');
            $table->date('fecha')
                ->comment('Fecha OPERATIVA del traslado, no la de captura');

            $table->foreignId('planta_ubicacion_origen_id')
                ->constrained('planta_ubicaciones')->restrictOnDelete()
                ->comment('Física, activa y operable. Nunca TRANSITO ni igual al destino');
            $table->foreignId('planta_ubicacion_destino_id')
                ->constrained('planta_ubicaciones')->restrictOnDelete()
                ->comment('Física, activa y operable. Nunca TRANSITO ni igual al origen');

            $table->foreignId('creado_por')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('enviado_por')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('enviado_en')->nullable();
            $table->foreignId('recibido_por')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('recibido_en')->nullable();

            $table->foreignId('responsable_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('responsable_nombre', 120)->nullable()
                ->comment('Instantánea: sobrevive al borrado del usuario. Quien transporta');

            $table->text('observaciones')->nullable();
            $table->text('motivo_reversion')->nullable()
                ->comment('Se escribe en el ORIGINAL al reversarlo: es ahí donde se consulta');

            $table->foreignId('reversion_de_id')->nullable()
                ->constrained('planta_traslados')->restrictOnDelete()
                ->comment('En el documento que COMPENSA: apunta al original');
            $table->foreignId('revertido_por_id')->nullable()
                ->constrained('planta_traslados')->restrictOnDelete()
                ->comment('En el ORIGINAL: apunta al documento que lo compensó');

            $table->timestamps();

            $table->unique('numero', 'planta_trasl_numero_unico');
            $table->index('estado', 'planta_trasl_estado_idx');
            $table->index('fecha', 'planta_trasl_fecha_idx');
            $table->index('planta_ubicacion_origen_id', 'planta_trasl_origen_idx');
            $table->index('planta_ubicacion_destino_id', 'planta_trasl_destino_idx');
            $table->index('creado_por', 'planta_trasl_creado_por_idx');
            $table->index('enviado_por', 'planta_trasl_enviado_por_idx');
            $table->index('recibido_por', 'planta_trasl_recibido_por_idx');
            $table->index('reversion_de_id', 'planta_trasl_reversion_de_idx');
            $table->index('revertido_por_id', 'planta_trasl_revertido_por_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planta_traslados');
    }
};
