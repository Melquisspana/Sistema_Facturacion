<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ÓRDENES DE ENROLAMIENTO: «este lector tiene que grabar la huella de esta
 * persona en esta ranura».
 *
 * Es el buzón que hace posible que Laravel le pida algo al ESP32 sin poder
 * llamarlo: el lector sondea, encuentra su orden y la ejecuta. Una orden PERTENECE
 * a un lector concreto —hoy hay uno, mañana tres— y ningún lector puede tomar la
 * de otro.
 *
 * ─────────────────── LA RESERVA NO ES UNA ASIGNACIÓN ───────────────────
 *
 * `ranura_reservada` aparta un número MIENTRAS dura la orden, sin crear todavía
 * una fila en `asistencia_huellas`. Es la distinción que pidió el encargo: la
 * asignación solo nace cuando el AS608 confirma que grabó la plantilla de verdad.
 * Si el enrolamiento falla, la reserva desaparece con la orden y no queda ninguna
 * huella fantasma apuntando a nadie.
 *
 * ──────────────── Dos unicidades PARCIALES, la misma técnica ────────────────
 *
 * Mismo problema que las ranuras de la Fase 1 y misma solución: columnas
 * generadas que valen NULL cuando la orden ya no está viva, y `NULL` no colisiona
 * consigo mismo en un índice único.
 *
 *   orden_activa_uq      -> un lector no puede tener dos órdenes vivas a la vez
 *   ranura_reservada_uq  -> dos órdenes vivas no pueden apartar la misma ranura
 *
 * El histórico de órdenes crece sin límite; lo vivo está acotado. Y la garantía la
 * impone la BASE, no una comprobación de PHP que dos sondeos simultáneos se
 * saltarían.
 *
 * ────────────────────────── El token de la orden ──────────────────────────
 *
 * Se guarda HASHEADO, igual que el del lector y por el mismo motivo. El valor en
 * claro viaja al ESP32 en la respuesta del sondeo y no se puede recuperar después.
 * No es el token del lector: es de esta orden, muere con ella y jamás pasa por el
 * navegador.
 *
 * ─────────────────────────── Append-only, casi ───────────────────────────
 *
 * Las órdenes SÍ se actualizan —cambian de estado, eso es su función— pero nunca
 * se borran: son la bitácora de qué se intentó grabar, cuándo y con qué desenlace.
 * `intento` encadena los reintentos automáticos cuando el sensor resulta tener una
 * plantilla heredada donde el servidor creía que había hueco.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencia_ordenes_enrolamiento', function (Blueprint $table) {
            $table->id();

            // Una orden es de UN lector. Sin esto, con dos lectores, cualquiera
            // podría ejecutar el enrolamiento que le tocaba al otro.
            $table->foreignId('asistencia_dispositivo_id')
                ->constrained('asistencia_dispositivos')->restrictOnDelete();

            $table->foreignId('asistencia_empleado_id')
                ->constrained('asistencia_empleados')->restrictOnDelete();

            $table->string('estado', 20)
                ->comment('App\Enums\Asistencia\EstadoOrdenEnrolamiento');

            $table->unsignedSmallInteger('ranura_reservada')
                ->comment('Ranura APARTADA. No es una asignación: eso solo nace al confirmar el AS608');

            $table->boolean('ranura_manual')->default(false)
                ->comment('true = la escribió una persona (recuperación de sensores con plantillas heredadas)');

            $table->string('token_hash', 64)->nullable()
                ->comment('SHA-256 del token de la orden. El valor en claro solo viaja al lector, una vez');

            // La huella que se creó al confirmar. NULL mientras no haya confirmación
            // real: es la garantía de «no crear la asignación antes de tiempo».
            $table->foreignId('asistencia_huella_id')->nullable()
                ->constrained('asistencia_huellas')->nullOnDelete();

            $table->string('motivo_fallo', 40)->nullable()
                ->comment('Código ESTABLE sobre el que ramifica el firmware');
            $table->string('detalle', 255)->nullable()
                ->comment('Texto legible para la pantalla y la auditoría');

            $table->unsignedTinyInteger('intento')->default(1)
                ->comment('1 = original. Sube cuando el sensor resulta tener la ranura ocupada y se reserva otra');
            $table->foreignId('orden_origen_id')->nullable()
                ->constrained('asistencia_ordenes_enrolamiento')->nullOnDelete()
                ->comment('De qué orden nació este reintento');

            $table->timestamp('expira_at')
                ->comment('Una orden vencida NO revive: se materializa como expirada al leerla');
            $table->timestamp('tomada_at')->nullable();
            $table->timestamp('finalizada_at')->nullable();

            $table->foreignId('solicitada_por_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Solo mientras la orden está VIVA. Ver el comentario de arriba.
            $table->unsignedTinyInteger('orden_activa_uq')->nullable()
                ->virtualAs("CASE WHEN estado IN ('pendiente','tomada','en_curso') THEN 1 ELSE NULL END")
                ->comment('GENERADA: 1 mientras la orden vive, NULL después. Solo existe para el único');

            $table->unsignedSmallInteger('ranura_reservada_uq')->nullable()
                ->virtualAs("CASE WHEN estado IN ('pendiente','tomada','en_curso') THEN ranura_reservada ELSE NULL END")
                ->comment('GENERADA: la ranura mientras la orden vive, NULL después. Solo existe para el único');

            $table->unique(['asistencia_dispositivo_id', 'orden_activa_uq'], 'asistencia_ordenes_activa_uq');
            $table->unique(['asistencia_dispositivo_id', 'ranura_reservada_uq'], 'asistencia_ordenes_ranura_uq');

            $table->index('asistencia_empleado_id', 'asistencia_ordenes_empleado_idx');
            // «Qué órdenes hay que vencer» y «qué sondea el lector»: las dos miran
            // estado + expiración del mismo lector.
            $table->index(['asistencia_dispositivo_id', 'estado', 'expira_at'], 'asistencia_ordenes_sondeo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_ordenes_enrolamiento');
    }
};
