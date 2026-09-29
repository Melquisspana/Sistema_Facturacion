<?php

use App\Support\IdentidadPpq;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COBROS CALLEJA: el seguimiento de cada factura desde que Hacienda la acepta hasta que
 * el cliente la paga, incluidas las que nunca llegaron a presentarse.
 *
 * ─────────────────────── Por qué no alcanzaba con `ppq_items` ───────────────────────
 *
 * Un `ppq_item` existe porque alguien lo AGREGÓ a un lote. Eso deja fuera justo lo que hay
 * que controlar: la factura que nadie metió en ninguna solicitud. Mientras el seguimiento
 * viva dentro del lote, «no aparece» y «no existe» son lo mismo, y una factura olvidada es
 * indistinguible de una que no se emitió.
 *
 * Por eso el sujeto del seguimiento pasa a ser el DOCUMENTO, no el renglón de un lote: se
 * da de alta en cuanto el CCF está aceptado por Hacienda y vive aunque nunca entre en una
 * solicitud. El lote PPQ sigue existiendo y no se toca.
 *
 * ────────────────────────── Las dos fuentes y el duplicado ──────────────────────────
 *
 * Los documentos entran por dos caminos: los CCF/NC ACEPTADOS de nuestro sistema (la
 * fuente principal) y los INCORPORADOS a mano, que es como siguen llegando los de
 * contabilidad y los del correo. El mismo documento no puede entrar por los dos: por eso
 * `numero_control_norm` es único GLOBAL y no por cliente ni por fuente. Un duplicado acá
 * no es un registro de más: es una factura reclamada dos veces.
 *
 * La normalización es la MISMA que ya usa PPQ para cruzar contra el TXT de pagos
 * ({@see IdentidadPpq}); no se inventa una segunda.
 *
 * ──────────────── Estado de presentación y estado de pago son dos ejes ────────────────
 *
 * Una factura puede estar presentada y sin pagar, pagada sin haberse presentado nunca por
 * este circuito, o recibida y con diferencia. Meter todo en una sola columna «estado»
 * obliga a elegir cuál de las dos verdades se pierde. Y las OBSERVACIONES son un tercer
 * eje: son notas, no un estado, y por eso viven en su propia columna (las nuestras) y en
 * `cobro_eventos` (las que manda el cliente). Actualizar un estado no las borra.
 *
 * ─────────────────────────── `cobro_eventos` es la bitácora ───────────────────────────
 *
 * Todo hecho comprobable —se presentó, Calleja lo recibió, entró un pago, llegó una
 * observación, se revirtió algo— es un EVENTO con su evidencia. Los acumulados de la
 * tabla de documentos (`monto_pagado`, `pago_estado`) se DERIVAN de esos eventos, así que
 * volver a cargar el mismo archivo no los infla: el evento ya existe y el único
 * `(cobro_documento_id, tipo, evidencia_hash, referencia_linea)` lo impide.
 *
 * Esa es también la costura por la que entrará el día de mañana otro archivo del portal:
 * el seguimiento no sabe leer archivos, solo recibe hechos ya normalizados.
 *
 * No emite, no firma, no transmite y no toca ningún valor fiscal.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        | SOLICITUD: una presentación de documentos al cliente. Guarda la selección, el
        | archivo EXACTO que se generó y —por separado— si se declaró presentada y con qué
        | referencia. Descargar no es presentar, así que son dos hechos y dos columnas.
        */
        Schema::create('cobro_solicitudes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('referencia', 60)->unique();      // interna: SOL-000123-20260917-01
            $table->string('formato', 40);                   // slug del exportador usado

            // El archivo generado, con su huella: volver a bajarlo tiene que dar esto.
            $table->string('archivo_nombre', 120);
            $table->string('archivo_hash', 64)->nullable();
            $table->string('archivo_path', 255)->nullable();

            // generada → presentada → recibida. Nunca salta sola: cada paso se registra.
            $table->string('estado', 20)->default('generada');
            $table->timestamp('descargada_en')->nullable();
            $table->unsignedInteger('descargas')->default(0);

            // Presentación DECLARADA por una persona: el sistema no sube nada al portal.
            $table->timestamp('presentada_en')->nullable();
            $table->foreignId('presentada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('presentada_nota', 255)->nullable();

            // Respuesta del cliente (llega por correo o se captura a mano).
            $table->string('referencia_calleja', 40)->nullable();   // «31001»
            $table->timestamp('recibida_en')->nullable();
            $table->date('fecha_programada_pago')->nullable();

            // Reenvío/corrección: apunta a la solicitud que reemplaza. La deuda no se
            // duplica porque los documentos se mueven, no se copian.
            $table->foreignId('corrige_a_id')->nullable()->constrained('cobro_solicitudes')->nullOnDelete();
            $table->text('motivo_correccion')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['cliente_id', 'created_at']);
            $table->index('referencia_calleja');
        });

        /*
        | DOCUMENTO: el sujeto del seguimiento. Uno por CCF/NC de este cliente.
        */
        Schema::create('cobro_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();

            // 'dte' = aceptado en nuestro sistema · 'externo' = incorporado a mano.
            $table->string('origen', 20)->default('dte');
            // Único: un DTE no puede tener dos seguimientos.
            $table->foreignId('dte_id')->nullable()->unique()->constrained('dtes')->nullOnDelete();

            // Identidad fiscal. Para un externo esto es TODO lo que hay, así que se guarda
            // aunque el documento también exista en `dtes`.
            $table->string('tipo_dte', 2);                         // 03 | 05
            $table->string('numero_control', 40);
            // ANTI-DUPLICADO entre fuentes. Ver la nota de la migración.
            $table->string('numero_control_norm', 40)->unique();
            $table->string('codigo_generacion', 40)->nullable();
            $table->string('sello_recepcion', 60)->nullable();
            $table->date('fecha_emision')->nullable();
            $table->decimal('monto', 12, 2)->nullable();

            // Establecimiento y punto de venta, EXTRAÍDOS del número de control y guardados
            // aparte. Normalizar el número para comparar los borra; acá siguen estando, que
            // es lo que permite distinguir un P001 de un P002 con el mismo correlativo.
            $table->string('establecimiento_codigo', 10)->nullable();
            $table->string('punto_venta_codigo', 10)->nullable();

            // ─── Albarán ───
            $table->foreignId('ppq_albaran_id')->nullable()->constrained('ppq_albaranes')->nullOnDelete();
            // sin_albaran | vinculado | revisar. «revisar» NUNCA es un vínculo a medias:
            // significa que hay candidatos y ninguno gana solo.
            $table->string('vinculacion_estado', 20)->default('sin_albaran');
            $table->string('vinculacion_motivo', 255)->nullable();
            $table->json('vinculacion_candidatos')->nullable();
            $table->timestamp('vinculado_en')->nullable();
            $table->foreignId('vinculado_por')->nullable()->constrained('users')->nullOnDelete();

            // ─── Presentación ───
            // sin_presentar | preparada | presentada | recibida
            $table->string('presentacion_estado', 20)->default('sin_presentar');
            $table->foreignId('cobro_solicitud_id')->nullable()->constrained('cobro_solicitudes')->nullOnDelete();

            // ─── Pago (DERIVADO de los eventos, nunca escrito a mano) ───
            // pendiente | parcial | pagado | diferencia
            $table->string('pago_estado', 20)->default('pendiente');
            $table->decimal('monto_pagado', 12, 2)->default(0);
            $table->date('fecha_pago')->nullable();

            // Nota del operador. Coexiste con los estados y no se pisa al actualizarlos.
            $table->text('observaciones')->nullable();

            // Para el arranque: un documento viejo del que no sabemos si se presentó o se
            // cobró no se declara «sin presentar» ni «sin pagar». Se marca para revisión.
            $table->boolean('revisar_historico')->default(false);
            $table->string('revisar_historico_motivo', 255)->nullable();

            $table->timestamps();

            $table->index(['cliente_id', 'pago_estado']);
            $table->index(['cliente_id', 'presentacion_estado']);
            $table->index(['cliente_id', 'fecha_emision']);
            $table->index('vinculacion_estado');
        });

        /*
        | Renglón de una solicitud: qué documento viajó y con qué datos EXACTOS se escribió
        | en el archivo. El snapshot no es redundancia: si mañana se corrige el albarán del
        | documento, lo que se presentó sigue siendo lo que dice esta fila.
        */
        Schema::create('cobro_solicitud_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cobro_solicitud_id')->constrained('cobro_solicitudes')->cascadeOnDelete();
            $table->foreignId('cobro_documento_id')->constrained('cobro_documentos')->cascadeOnDelete();
            $table->unsignedInteger('orden')->default(0);

            // Lo escrito en el archivo, tal cual.
            $table->string('sala_codigo', 10)->nullable();
            $table->string('albaran_numero', 30)->nullable();
            $table->unsignedSmallInteger('albaran_anio')->nullable();
            $table->unsignedTinyInteger('albaran_mes')->nullable();
            $table->string('albaran_tipo', 10)->nullable();
            $table->decimal('monto', 12, 2)->nullable();

            $table->timestamps();

            // Un documento no puede ir dos veces en la MISMA solicitud. Entre solicitudes
            // distintas sí: eso es un reenvío, y lo gobierna `corrige_a_id`.
            $table->unique(['cobro_solicitud_id', 'cobro_documento_id'], 'cobro_sol_item_unico');
        });

        /*
        | EVENTO: cada hecho comprobable sobre un documento, con su evidencia.
        |
        | `evidencia_hash` es la huella del archivo o el id del mensaje que lo produjo, y
        | junto con `referencia_linea` forma el único que hace la carga IDEMPOTENTE: subir
        | dos veces el mismo TXT no suma dos pagos, y dos archivos solapados que traen la
        | misma línea tampoco.
        */
        Schema::create('cobro_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cobro_documento_id')->constrained('cobro_documentos')->cascadeOnDelete();

            // presentacion | recibido | observacion | pago | ajuste | reversion | nota
            $table->string('tipo', 20);
            // txt | correo | manual | solicitud
            $table->string('origen', 20);

            /*
            | ¿Este evento CUENTA para los acumulados? aplicado | en_revision | descartado.
            |
            | La llave de evidencia de más abajo impide que la misma línea del MISMO archivo
            | entre dos veces, pero no que un archivo POSTERIOR —otra huella— vuelva a traer
            | un pago ya informado. Desde el archivo, «lo repitió» y «pagó en dos abonos» son
            | indistinguibles, así que el segundo pago entra `en_revision`: se registra
            | entero, no suma, y una persona decide. Ver App\Enums\Cobros\EstadoEventoCobro.
            */
            $table->string('estado', 20)->default('aplicado');
            $table->string('estado_motivo', 255)->nullable();
            $table->timestamp('resuelto_en')->nullable();
            $table->foreignId('resuelto_por')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('monto', 12, 2)->nullable();
            $table->date('fecha')->nullable();               // fecha del HECHO, si consta
            $table->text('detalle')->nullable();

            // Evidencia: huella del archivo o id del mensaje. Null solo para lo capturado
            // a mano, que por definición no tiene archivo detrás.
            $table->string('evidencia_hash', 64)->nullable();
            $table->string('evidencia_nombre', 160)->nullable();
            $table->string('referencia_linea', 40)->nullable();
            $table->json('datos')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['cobro_documento_id', 'tipo']);
            $table->index(['estado', 'tipo']);
            $table->unique(
                ['cobro_documento_id', 'tipo', 'evidencia_hash', 'referencia_linea'],
                'cobro_evento_evidencia_unico'
            );
        });

        /*
        | AJUSTE informado por el cliente: las filas QD del archivo de pagos
        | («QD;PPQ/31001;;-107.21»).
        |
        | NO se imputa a ninguna factura, y eso es deliberado. El importe es un descuento
        | global asociado a una REFERENCIA de solicitud, no a un documento: repartirlo entre
        | las facturas de esa referencia —a prorrata, por orden, como fuera— inventaría un
        | dato que el cliente no mandó y dejaría cada factura cobrada por una cifra que
        | nadie informó.
        |
        | Tampoco se emite nada solo,  ni se deduce su desglose fiscal: un importe neto no
        | dice cuánto es gravado ni cuánto IVA. Queda como PENDIENTE DE NC y se resuelve por
        | el circuito de notas de crédito que ya existe.
        */
        Schema::create('cobro_ajustes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();

            $table->string('referencia', 40);                      // «PPQ/31001», tal cual
            $table->string('referencia_calleja', 40)->nullable();   // «31001», para cruzar
            $table->foreignId('cobro_solicitud_id')->nullable()->constrained('cobro_solicitudes')->nullOnDelete();

            $table->decimal('monto', 12, 2);                       // con su signo del archivo
            $table->date('fecha')->nullable();

            // pendiente_nc | resuelto | descartado
            $table->string('estado', 20)->default('pendiente_nc');
            $table->foreignId('nc_dte_id')->nullable()->constrained('dtes')->nullOnDelete();
            $table->string('motivo', 255)->nullable();

            $table->string('evidencia_hash', 64)->nullable();
            $table->string('evidencia_nombre', 160)->nullable();
            $table->string('referencia_linea', 40)->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // La misma línea del mismo archivo no entra dos veces.
            $table->unique(['evidencia_hash', 'referencia_linea'], 'cobro_ajuste_evidencia_unico');
            $table->index(['cliente_id', 'estado']);
            $table->index('referencia_calleja');
        });

        /*
        | CORREO leído del buzón: el mensaje de RECIBIDO o de OBSERVACIONES, guardado
        | entero. El sistema solo LEE: no manda, no responde y no modifica nada en Gmail.
        |
        | Se guarda aunque no se pueda asociar a nada: un correo que no casó es trabajo
        | pendiente de una persona, no basura que se descarta en silencio.
        */
        Schema::create('cobro_correos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();

            // El id de Gmail es el anti-duplicado: releer el buzón no crea filas nuevas.
            $table->string('gmail_message_id', 120)->unique();
            $table->string('gmail_thread_id', 120)->nullable();

            // recibido | observaciones | desconocido
            $table->string('tipo', 20)->default('desconocido');
            $table->string('asunto', 255)->nullable();
            $table->string('remitente', 190)->nullable();
            $table->timestamp('fecha_mensaje')->nullable();
            $table->longText('cuerpo')->nullable();

            // Lo interpretado, si se pudo.
            $table->string('archivo_referido', 160)->nullable();     // «000123202609040951»
            $table->string('referencia_calleja', 40)->nullable();    // «31001»
            $table->date('fecha_programada_pago')->nullable();

            // pendiente | asociado | sin_asociar. «sin_asociar» es visible a propósito.
            $table->string('estado', 20)->default('pendiente');
            $table->string('motivo', 255)->nullable();
            $table->foreignId('cobro_solicitud_id')->nullable()->constrained('cobro_solicitudes')->nullOnDelete();
            $table->timestamp('procesado_en')->nullable();

            $table->timestamps();

            $table->index(['estado', 'tipo']);
            $table->index('referencia_calleja');
        });

        /*
        | HASTA DÓNDE se ha barrido el buzón. Una fila por cliente y consulta.
        |
        | ─────────────── El problema que resuelve: la corrida que no avanza ───────────────
        |
        | Una búsqueda de Gmail devuelve los mensajes del más nuevo al más viejo. Si cada
        | corrida empieza por la primera página y se corta al llegar a su tope, la tarea de
        | cada media hora lee SIEMPRE los mismos primeros N y los más viejos no se leen
        | jamás. Paginar dentro de una corrida no lo arregla: el problema es que todas las
        | corridas empiezan en el mismo sitio. Subir el tope tampoco: solo mueve la frontera.
        |
        | ─────────────────────────── Las dos ventanas ───────────────────────────
        |
        | Por eso cada corrida mira DOS sitios distintos:
        |
        |   · la CABEZA, `after:{ultimo_mensaje_en}` — lo que llegó desde la última vez, que
        |     es poco y no puede esperar;
        |   · la COLA, `before:{barrido_hasta}` — el backlog, que avanza hacia atrás una
        |     tanda por corrida hasta llegar al principio.
        |
        | Así lo nuevo nunca espera detrás de lo viejo, y lo viejo se alcanza igual. Cuando
        | la cola llega al final, `barrido_completo` queda en true y las corridas siguientes
        | solo miran la cabeza.
        |
        | La marca es la FECHA del mensaje y no un token de página de Gmail: un token
        | caduca, y además se invalida en cuanto entra un correo nuevo que corre todas las
        | páginas. Una fecha sigue significando lo mismo mañana.
        |
        | `consulta_hash` forma parte del único porque cambiar la consulta cambia el
        | universo barrido: heredar el progreso de otra búsqueda daría por cubierto lo que
        | nunca se miró.
        |
        | Igual que en Compras: solo `barrido_completo` significa cubierto. Su ausencia
        | significa «todavía no lo sé», no «no hay nada».
        */
        Schema::create('cobro_correo_progresos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();

            $table->string('consulta', 500);
            $table->string('consulta_hash', 64);

            // El mensaje MÁS NUEVO ya visto: desde acá sigue la cabeza.
            $table->timestamp('ultimo_mensaje_en')->nullable();
            // El mensaje MÁS VIEJO ya visto en el barrido: hasta acá llegó la cola.
            $table->timestamp('barrido_hasta')->nullable();
            // ¿La cola llegó al principio del buzón? Solo esto significa «cubierto».
            $table->boolean('barrido_completo')->default(false);

            $table->timestamp('ultima_corrida_en')->nullable();
            $table->unsignedInteger('mensajes_leidos')->default(0);

            $table->timestamps();

            $table->unique(['cliente_id', 'consulta_hash'], 'cobro_correo_progreso_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobro_correo_progresos');
        Schema::dropIfExists('cobro_correos');
        Schema::dropIfExists('cobro_ajustes');
        Schema::dropIfExists('cobro_eventos');
        Schema::dropIfExists('cobro_solicitud_items');
        Schema::dropIfExists('cobro_documentos');
        Schema::dropIfExists('cobro_solicitudes');
    }
};
