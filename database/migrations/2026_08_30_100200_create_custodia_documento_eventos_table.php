<?php

use App\Enums\EstadoCustodia;
use App\Services\Rutas\AsignadorDocumentos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BITÁCORA de la custodia del CCF FÍSICO: cada vez que el papel cambió de manos.
 *
 * ═══════════════ Por qué eventos y no una columna «quién lo tiene» ═══════════════
 *
 * Una columna con el responsable actual contesta el presente sobrescribiendo el pasado. En
 * cuanto el papel pasa de un vendedor al responsable de la salida, se pierde que el vendedor
 * lo tuvo — y esa es exactamente la información que hace falta el día que el documento no
 * aparece y hay que preguntarle a alguien concreto.
 *
 * Acá se guardan los HECHOS y el estado actual se DERIVA del último evento vigente
 * ({@see EstadoCustodia}). Solo se inserta: un evento mal registrado se ANULA con
 * otro evento que lo compensa y exige motivo, nunca se edita ni se borra.
 *
 * ─────────────── Por qué `salida_ruta_id` se guarda además del documento ───────────────
 *
 * Parece redundante —el documento ya sabe a qué salida pertenece— y no lo es: un documento
 * puede MOVERSE de salida ({@see AsignadorDocumentos::mover()}). Si el
 * evento no congelara la salida en que ocurrió, mover el documento reescribiría el pasado y
 * la línea de tiempo diría que el papel se entregó en un viaje al que todavía no pertenecía.
 *
 * ═══════════════ El candado contra la doble recepción ═══════════════
 *
 * Dos personas confirmando a la vez que el mismo papel llegó no pueden producir dos
 * recepciones válidas. Se resuelve con el mismo truco que ya usa
 * `salida_ruta_documentos.bloqueo_asignacion`, y por el mismo motivo —la comprobación en PHP
 * pierde carreras, el índice no—:
 *
 *   `recepcion_vigente` = 1     en un evento de recepción que sigue en pie
 *   `recepcion_vigente` = NULL  en todo lo demás (otros tipos, y recepciones ya anuladas)
 *
 * con `UNIQUE (salida_ruta_documento_id, recepcion_vigente)`. Como MySQL y SQLite consideran
 * distintos entre sí los NULL de un índice único, el efecto es exacto: a lo sumo UNA
 * recepción viva por documento, y todas las recepciones anuladas que hagan falta en el
 * historial. Anular una libera el documento para volver a recibirlo.
 *
 * ─────────────── Relación con las columnas que ya existían ───────────────
 *
 * `salida_ruta_documentos.documentacion_fisica_recibida_at` y `_por` NO se eliminan ni
 * cambian de significado: pasan a ser una PROYECCIÓN del evento de recepción vigente, que el
 * servicio de custodia mantiene sincronizada dentro de la misma transacción. Se conservan
 * porque media aplicación ya las lee —la bandeja filtra por ellas en SQL, el resumen las
 * cuenta, tres vistas las muestran— y porque un filtro por columna real es lo que permite
 * acotar en la base en vez de hidratar todo. La bitácora es la verdad; esas dos columnas son
 * la respuesta rápida a la pregunta más frecuente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custodia_documento_eventos', function (Blueprint $table) {
            $table->id();

            // El documento de la salida, que es lo que de verdad viaja. `cascadeOnDelete`
            // acompaña a la fila que lo justifica: sin el documento, su custodia no significa
            // nada. (Quitar un documento de una salida ya es un acto auditado aparte.)
            $table->foreignId('salida_ruta_documento_id')
                ->constrained('salida_ruta_documentos')->cascadeOnDelete();

            // Snapshot: en qué salida estaba el documento cuando pasó esto.
            $table->foreignId('salida_ruta_id')->nullable()
                ->constrained('salidas_ruta')->nullOnDelete();

            $table->string('tipo', 30)->comment('TipoEventoCustodia');

            // Quién lo tenía y a quién pasa. Ambos opcionales: una entrega desde bodega no
            // tiene origen, y una recepción no tiene destino (el destino es la empresa).
            $table->foreignId('origen_personal_id')->nullable()
                ->constrained('rutas_personal')->nullOnDelete();
            $table->foreignId('destino_personal_id')->nullable()
                ->constrained('rutas_personal')->nullOnDelete();

            // Quién lo REGISTRÓ en el sistema. Puede no ser ninguno de los dos anteriores:
            // en la recepción es quien recibe en oficina.
            $table->foreignId('registrado_por')->nullable()
                ->constrained('users')->nullOnDelete();

            // Cuándo ocurrió DE VERDAD. Separado de `created_at` a propósito: el papel puede
            // haber vuelto el viernes y registrarse el lunes, y las dos fechas importan.
            $table->timestamp('ocurrido_en');

            $table->string('observacion', 500)->nullable();

            // Obligatorio en las anulaciones. Lo exige el servicio, no la base: los demás
            // eventos no llevan motivo y la columna es la misma.
            $table->string('motivo', 500)->nullable();

            // Evento que este anula. Solo lo usan los de tipo `anulacion`.
            $table->foreignId('anula_evento_id')->nullable()
                ->constrained('custodia_documento_eventos')->nullOnDelete();

            // Marca de «este evento ya no cuenta». La escribe únicamente el servicio al
            // anularlo; nunca se edita a mano desde la interfaz.
            $table->boolean('anulado')->default(false);

            // Ver el bloque de arriba: el candado real contra la doble recepción.
            $table->unsignedTinyInteger('recepcion_vigente')->nullable()
                ->comment('1 en una recepción que sigue en pie; NULL en todo lo demás');

            $table->timestamp('created_at')->nullable();

            $table->unique(['salida_ruta_documento_id', 'recepcion_vigente'], 'custodia_recepcion_unica');

            // La consulta de siempre: la línea de tiempo de un documento, y su último evento.
            $table->index(['salida_ruta_documento_id', 'id'], 'custodia_documento_idx');
            // «¿Qué papeles tiene esta persona?» — la pregunta del día a día.
            $table->index(['destino_personal_id', 'anulado'], 'custodia_destino_idx');
            $table->index('tipo', 'custodia_tipo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custodia_documento_eventos');
    }
};
