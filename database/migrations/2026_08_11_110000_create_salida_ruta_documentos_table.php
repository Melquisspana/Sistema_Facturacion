<?php

use App\Models\SalidaRuta;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué documentos viajaron en una salida de ruta.
 *
 * Esta tabla NO es un documento fiscal ni una copia de uno: es la BITÁCORA
 * OPERATIVA de una salida. Anota a qué recorrido fue cada CCF y dos hechos que
 * solo existen en el mundo físico —si volvió el papel firmado y si el documento
 * quedó marcado para revisar por una NC—. No toca `dtes`, ni correlativos, ni
 * firma, ni transmisión, ni invalidaciones.
 *
 * ───────────────────────────── P002 vs P001 ─────────────────────────────
 *
 * El sistema factura en dos series y solo una vive acá dentro:
 *
 *  - P002 (camino principal): el documento EXISTE en `dtes`. `dte_id` apunta a
 *    él y es la fuente de verdad de cliente, sala, monto y fecha. `numero_control`
 *    se guarda igual, como identidad universal: es lo único que ambos caminos
 *    comparten y por lo tanto lo único sobre lo que se puede exigir unicidad.
 *
 *  - P001 (compatibilidad histórica): el documento lo emitió Conta Portable y NO
 *    está en `dtes` — y no se va a importar. `dte_id` queda NULL y `numero_control`
 *    lo identifica. Como no hay de dónde leerlo, lo poco que se sabe se guarda
 *    como SNAPSHOT (cliente, sala, monto, fecha), igual que hace `ppq_items`. No
 *    se inventa una FK ni se crea una tabla paralela de documentos históricos.
 *
 * ──────────────────── La regla de unicidad (la parte delicada) ────────────────────
 *
 * Un documento no puede estar en dos salidas ABIERTAS a la vez, pero sí puede
 * aparecer en varias salidas a lo largo del tiempo: un CCF que salió, no se pudo
 * entregar y volvió a salir la semana siguiente es historia legítima, y una
 * unicidad global sobre `numero_control` la haría imposible de registrar.
 *
 * Por eso el candado es una columna deliberadamente redundante:
 *
 *   `bloqueo_asignacion` = 1   mientras la salida dueña está PLANIFICADA o EN CURSO
 *   `bloqueo_asignacion` = NULL cuando esa salida terminó (finalizada/cancelada)
 *
 * y el índice `UNIQUE (numero_control, bloqueo_asignacion)`. Como MySQL —y SQLite—
 * consideran distintos entre sí los NULL de un índice único, el efecto es exacto:
 *
 *   · a lo sumo UNA fila viva por número de control  -> imposible duplicar en silencio;
 *   · filas de salidas ya terminadas, ilimitadas     -> la historia no se bloquea.
 *
 * La columna la mantiene un solo lugar del código ({@see SalidaRuta::sincronizarBloqueoDocumentos()},
 * invocado por las transiciones de estado) y nunca se edita a mano desde la UI.
 * Es un candado de BASE DE DATOS a propósito: la comprobación en PHP puede perder
 * una carrera entre dos pestañas abiertas, el índice no.
 *
 * El segundo índice, `UNIQUE (salida_ruta_id, numero_control)`, cubre el caso
 * trivial y absoluto —el mismo documento dos veces en la MISMA salida— que ni
 * siquiera para una salida terminada tiene sentido permitir.
 *
 * ─────────────────────────── Lo que NO tiene esta tabla ───────────────────────────
 *
 *  - NO hay columna `entregado`. La entrega se DERIVA del albarán (`ppq_albaranes`),
 *    que es la única fuente de verdad; duplicarla acá crearía dos respuestas
 *    posibles a la misma pregunta y tarde o temprano se contradicen.
 *  - NO hay columna con el estado de la nota de crédito. `requiere_nc` es una marca
 *    OPERATIVA («esto hay que revisarlo»), no el reflejo de un documento fiscal: si
 *    la NC existe, su estado se lee de `dtes` en el momento de mostrarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salida_ruta_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salida_ruta_id')->constrained('salidas_ruta')->cascadeOnDelete();

            // P002: apunta al DTE real. P001 histórico: NULL.
            // restrictOnDelete: `dtes` no se borra en la operación normal (hay soft
            // deletes), y si alguna vez se intentara, la bitácora de ruta no es
            // motivo para permitirlo en silencio.
            $table->foreignId('dte_id')->nullable()->constrained('dtes')->restrictOnDelete()
                ->comment('P002: DTE real. P001 histórico: NULL');

            // Identidad universal: el único dato que comparten los dos caminos.
            $table->string('numero_control', 40);

            // Llave con la que se localiza el albarán (y, en P001, la NC por OC).
            $table->string('numero_orden_compra')->nullable()->index();

            // Sala resuelta. En P002 se copia del DTE; en P001 se resuelve desde la
            // OC si se puede. NULL es válido: hay históricos sin sala resoluble.
            $table->foreignId('cliente_sucursal_id')->nullable()->constrained('cliente_sucursales')->nullOnDelete();

            $table->string('origen', 10)->default('p002')->comment('p002 | p001');

            // Snapshot: SOLO se usa cuando no hay `dte_id` de dónde leer (P001).
            $table->string('cliente_nombre')->nullable();
            $table->string('sala_nombre')->nullable();
            $table->decimal('monto', 12, 2)->nullable();
            $table->date('fecha_documento')->nullable();

            // Cómo llegó el documento a esta salida.
            $table->timestamp('asignado_at');
            $table->foreignId('asignado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('asignacion_automatica')->default(false)
                ->comment('true = lo asoció el barrido automático, no una persona');

            // Documentación física de regreso: esto SÍ es manual, no hay de dónde derivarlo.
            $table->timestamp('documentacion_fisica_recibida_at')->nullable();
            $table->foreignId('documentacion_fisica_recibida_por')->nullable()->constrained('users')->nullOnDelete();

            // Marca operativa. NO crea la NC ni refleja su estado.
            $table->boolean('requiere_nc')->default(false);
            $table->string('motivo_revision', 20)->nullable()->comment('averia | devolucion | faltante | otro');
            $table->string('motivo_revision_nota', 300)->nullable()->comment('Detalle libre, sobre todo para "otro"');

            // Candado de unicidad: ver el bloque de arriba.
            $table->unsignedTinyInteger('bloqueo_asignacion')->nullable()
                ->comment('1 mientras la salida dueña está abierta; NULL cuando terminó');

            $table->timestamps();

            $table->unique(['numero_control', 'bloqueo_asignacion'], 'srd_documento_unico_vigente');
            $table->unique(['salida_ruta_id', 'numero_control'], 'srd_documento_unico_en_salida');
            // `dte_id` y `salida_ruta_id` ya quedan indexados por sus claves foráneas.
            $table->index(['salida_ruta_id', 'requiere_nc'], 'srd_salida_nc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salida_ruta_documentos');
    }
};
