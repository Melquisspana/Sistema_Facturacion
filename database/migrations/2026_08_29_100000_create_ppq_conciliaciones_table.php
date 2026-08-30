<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BITÁCORA INMUTABLE de los cambios de estado de cobro de un lote PPQ.
 *
 * ─────────────────────────── El problema que resuelve ───────────────────────────
 *
 * Hasta ahora conciliar un lote era una operación sin memoria: se subía un TXT, se
 * escribía el resultado sobre `ppq_items` y el archivo se descartaba. Eso dejaba tres
 * agujeros, y el tercero es el grave:
 *
 *   1. no se sabía QUIÉN concilió ni CON QUÉ archivo;
 *   2. el TXT —la única prueba de que el cliente reportó ese pago— no se guardaba;
 *   3. una segunda corrida con un archivo parcial BORRABA los pagos que la primera había
 *      registrado, porque el renglón que no aparecía en el TXT nuevo se limpiaba. Un pago
 *      borrado es irrecuperable y hace que el documento vuelva a figurar como deuda.
 *
 * Estas dos tablas son la memoria que faltaba. `ppq_conciliaciones` es la CORRIDA —quién,
 * cuándo, con qué archivo y con qué resultado— y `ppq_conciliacion_movimientos` es el
 * detalle: una fila por cada renglón que efectivamente cambió, con el valor ANTERIOR y el
 * NUEVO. Con eso, cualquier estado de cobro se puede reconstruir y cualquier corrección
 * se puede explicar.
 *
 * SOLO SE INSERTA. Ninguna de las dos se actualiza ni se borra: por eso llevan únicamente
 * `created_at`. Una bitácora que se puede editar no prueba nada.
 *
 * ──────────────────────── Las dos formas de que un pago cambie ────────────────────────
 *
 * `origen` distingue las únicas dos legítimas (ver App\Enums\OrigenConciliacionPpq):
 *
 *   · `txt`       — lo dijo el archivo de pagos del cliente. Evidencia externa.
 *   · `reversion` — lo decidió una persona autorizada, con motivo obligatorio.
 *
 * Sin esa distinción una corrección hecha a mano se vería igual que un abono reportado
 * por el cliente, y el día que un pago no cuadre nadie podría distinguirlas.
 *
 * ──────────────────── Por qué el hash y por qué es único por lote ────────────────────
 *
 * `archivo_hash` (SHA-256 del contenido) hace dos cosas a la vez. Identifica el archivo
 * con independencia de cómo lo hayan nombrado —el mismo TXT renombrado sigue siendo el
 * mismo TXT— y, con el índice único (ppq_lote_id, archivo_hash), convierte el reproceso
 * en una operación IDEMPOTENTE de base de datos: subir dos veces el mismo archivo al
 * mismo lote no puede registrar dos corridas ni volver a tocar los renglones.
 *
 * El único no estorba a las reversiones: su hash va NULL, y tanto MySQL como SQLite
 * consideran distintos entre sí los NULL de un índice único, así que un lote admite todas
 * las correcciones que haga falta.
 *
 * ──────────────────────── Por qué `restrictOnDelete` sobre el lote ────────────────────────
 *
 * `ppq_lotes` se borra en BLANDO, que es el camino normal y no toca nada de acá. Pero un
 * borrado FÍSICO se llevaría por delante la prueba de pagos ya cobrados, así que se
 * prohíbe mientras exista historial. Es deliberadamente más estricto que `ppq_items`, que
 * sí cascadea: los renglones son el estado actual y se pueden reconstruir; esto es la
 * evidencia y no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppq_conciliaciones', function (Blueprint $table) {
            $table->id();

            // El historial impide el borrado FÍSICO del lote. El borrado lógico —el que
            // usa la aplicación— no dispara la clave foránea y sigue funcionando igual.
            $table->foreignId('ppq_lote_id')->constrained('ppq_lotes')->restrictOnDelete();

            // Quién lo hizo. Nullable solo para no perder el historial si el usuario se
            // da de baja: la corrida no desaparece porque la persona ya no esté.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('origen', 20)->default('txt')->comment('OrigenConciliacionPpq: txt | reversion');

            // Evidencia del archivo. Todo nullable porque una reversión no tiene archivo.
            $table->string('archivo_nombre', 255)->nullable()->comment('Nombre original tal como lo subió el usuario');
            $table->char('archivo_hash', 64)->nullable()->comment('SHA-256 del contenido: identidad real del archivo');
            $table->string('archivo_path', 255)->nullable()->comment('Copia guardada del TXT, direccionada por su hash');

            // Qué traía el archivo. Se guarda el recuento y no el contenido interpretado:
            // el contenido ya está en la copia, y duplicarlo abriría la puerta a que las
            // dos versiones digan cosas distintas.
            $table->unsignedInteger('total_filas')->default(0);
            $table->unsignedInteger('filas_cf')->default(0)->comment('CCF pagados que traía el archivo');
            $table->unsignedInteger('filas_nc')->default(0)->comment('Notas de crédito aplicadas');
            $table->unsignedInteger('filas_qd')->default(0)->comment('Ajustes/descuentos QD');

            // Qué hizo la corrida. `sin_cambio` es tan informativo como `cambiados`: es lo
            // que prueba que un reproceso no tocó nada.
            $table->unsignedInteger('items_cambiados')->default(0);
            $table->unsignedInteger('items_sin_cambio')->default(0);

            // Obligatorio en las reversiones (lo exige el servicio, no la base: una
            // corrida de TXT no lleva motivo y la columna es la misma).
            $table->text('motivo')->nullable();

            $table->timestamp('created_at')->nullable();

            // Idempotencia del reproceso: el mismo archivo, en el mismo lote, una vez.
            $table->unique(['ppq_lote_id', 'archivo_hash'], 'ppq_conciliacion_lote_archivo_unico');
            $table->index(['ppq_lote_id', 'id'], 'ppq_conciliacion_lote_idx');
        });

        Schema::create('ppq_conciliacion_movimientos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ppq_conciliacion_id')->constrained('ppq_conciliaciones')->cascadeOnDelete();
            $table->foreignId('ppq_item_id')->constrained('ppq_items')->cascadeOnDelete();

            // El par anterior/nuevo es lo que hace reconstruible cualquier estado y lo que
            // permite demostrar que una corrida NO borró un pago previo.
            $table->string('estado_anterior', 20)->nullable();
            $table->string('estado_nuevo', 20)->nullable();
            $table->date('fecha_pago_anterior')->nullable();
            $table->date('fecha_pago_nueva')->nullable();
            $table->decimal('monto_pagado_anterior', 10, 2)->nullable();
            $table->decimal('monto_pagado_nuevo', 10, 2)->nullable();

            // Línea del TXT que lo produjo, para poder ir al archivo y verlo.
            $table->unsignedInteger('linea_txt')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index('ppq_item_id', 'ppq_conciliacion_mov_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppq_conciliacion_movimientos');
        Schema::dropIfExists('ppq_conciliaciones');
    }
};
