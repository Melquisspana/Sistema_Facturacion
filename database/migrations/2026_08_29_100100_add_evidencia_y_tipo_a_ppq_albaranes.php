<?php

use App\Support\NumeroAlbaran;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dos cosas que le faltaban al albarán para poder ser la FUENTE ÚNICA de la entrega: su
 * TIPO y su EVIDENCIA.
 *
 * ─────────────────────────────── El tipo ───────────────────────────────
 *
 * El cliente manda por correo albaranes de ENTREGA (AC01) y albaranes de CRÉDITO (AC02
 * avería, AC04 devolución), y hasta ahora la tabla no los distinguía. Como el vínculo con
 * el documento se hace por ORDEN DE COMPRA —y una misma OC ampara el AC01 de la entrega y
 * el AC02/AC04 de la nota de crédito que vino después—, el localizador podía contestar
 * «entregado» apoyándose en un albarán de abono, y tomar de él el monto contra el que se
 * calcula la diferencia.
 *
 * El tipo YA ESTABA en el propio número (`AC01/0236/00/6359`); lo que faltaba era leerlo y
 * guardarlo. Se rellena con {@see NumeroAlbaran::desde()}, que es el único lugar del
 * sistema que sabe desarmar ese número, para no escribir una segunda versión de la regla.
 *
 * Los albaranes cuyo número NO trae el prefijo —los capturados a mano con el número suelto
 * («3211», «3474»)— quedan con `tipo_codigo` en NULL, y eso es DELIBERADO: significa «tipo
 * desconocido», no «AC01». Suponerles entrega sería exactamente el error que esta columna
 * existe para impedir; van a excepción y alguien los mira.
 *
 * ────────────────────────────── La evidencia ──────────────────────────────
 *
 * La sincronización bajaba el PDF del correo, le sacaba número, fecha, OC y monto con
 * expresiones regulares, y lo tiraba. Lo único que quedaba era el identificador del
 * mensaje de Gmail: si el correo se borra, la etiqueta se reorganiza o la cuenta cambia,
 * la prueba de la entrega desaparece y queda solo un número que alguien parseó.
 *
 * `archivo_path` ya existía y estaba vacío en todas las filas. Ahora se acompaña del
 * nombre original, del SHA-256 del contenido y de la fecha de descarga:
 *
 *  - el HASH es la identidad real del archivo. Permite guardar la copia direccionada por
 *    su contenido —así releer el mismo correo escribe el mismo archivo y no duplica nada—
 *    y, sobre todo, distingue un REENVÍO idéntico de una CORRECCIÓN: mismo número y misma
 *    OC con hash distinto es una versión nueva, y eso hay que avisarlo en vez de
 *    descartarlo en silencio;
 *  - el NOMBRE original se conserva porque el cliente lo usa para referirse al documento;
 *  - la FECHA DE DESCARGA es cuándo lo vimos nosotros, que no es la fecha impresa en el
 *    albarán ni la del correo.
 *
 * En esa copia solo van los BYTES DEL ADJUNTO. Nunca tokens, credenciales ni la traza de
 * depuración de la búsqueda, que puede llevar dentro los parámetros de la consulta.
 *
 * Esta migración solo agrega columnas y rellena una a partir de un dato que ya estaba en
 * la misma fila. No borra, no toca montos, no toca `ppq_items` ni los lotes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppq_albaranes', function (Blueprint $table) {
            $table->string('tipo_codigo', 10)->nullable()->after('numero_albaran')
                ->comment('AC01 entrega | AC02/AC04 crédito. NULL = no se pudo determinar: va a excepción, NUNCA se supone AC01');

            $table->string('archivo_nombre', 255)->nullable()->after('archivo_path')
                ->comment('Nombre del adjunto tal como llegó');
            $table->char('archivo_hash', 64)->nullable()->after('archivo_nombre')
                ->comment('SHA-256 del PDF: identidad del archivo y detección de correcciones');
            $table->timestamp('archivo_descargado_en')->nullable()->after('archivo_hash')
                ->comment('Cuándo lo bajamos nosotros (no es la fecha del albarán ni la del correo)');

            $table->index('tipo_codigo', 'ppq_albaranes_tipo_idx');
            $table->index('archivo_hash', 'ppq_albaranes_archivo_hash_idx');
        });

        $this->rellenarTipo();
    }

    /**
     * Deriva `tipo_codigo` del número que la fila YA tiene. No consulta Gmail, no vuelve a
     * parsear ningún PDF y no inventa nada: lo que el número no diga, queda en NULL.
     *
     * Se recorre en PHP —y no con un UPDATE con expresiones— por dos razones: el desarme
     * del número vive en una sola clase y hay que usar esa, y la sintaxis de expresiones
     * regulares de MySQL y la de SQLite no son la misma (la suite corre en SQLite y la
     * operación en MySQL), así que un UPDATE portable no existiría.
     */
    private function rellenarTipo(): void
    {
        DB::table('ppq_albaranes')
            ->select('id', 'numero_albaran')
            ->orderBy('id')
            ->chunkById(500, function ($filas) {
                foreach ($filas as $fila) {
                    $tipo = NumeroAlbaran::desde($fila->numero_albaran)?->tipo;

                    if ($tipo === null) {
                        continue; // número sin prefijo reconocible: se queda en NULL a propósito
                    }

                    DB::table('ppq_albaranes')->where('id', $fila->id)->update(['tipo_codigo' => $tipo]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('ppq_albaranes', function (Blueprint $table) {
            $table->dropIndex('ppq_albaranes_tipo_idx');
            $table->dropIndex('ppq_albaranes_archivo_hash_idx');
            $table->dropColumn(['tipo_codigo', 'archivo_nombre', 'archivo_hash', 'archivo_descargado_en']);
        });
    }
};
