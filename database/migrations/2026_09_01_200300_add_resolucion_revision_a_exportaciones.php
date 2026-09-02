<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Clasificación de las listas de empaque HEREDADAS, y sitio donde anotar qué se
 * decidió con cada una.
 *
 * ═══ POR QUÉ SE REESCRIBIÓ ═══
 *
 * El backfill original vivía en `add_finalizacion_a_exportaciones` y clasificaba
 * por el `estado` heredado: 'aprobada' + factura → finalizada; cualquier otro
 * estado desconocido → marcar. La auditoría de PRODUCCIÓN devolvió esto:
 *
 *   lista  estado     archivada  DTE      estado fiscal  ambiente
 *      9   borrador   sí         tipo 11  invalidado     01
 *     14   borrador   no         tipo 11  aceptado       01
 *     15   borrador   no         tipo 11  aceptado       01
 *
 * Las tres en 'borrador' y las tres con `dte_id`. Ninguna entraba en la primera
 * regla (no son 'aprobada') ni en la segunda (sí son 'borrador'): el backfill no
 * tocaba nada y las tres sobrevivían como borradores de trabajo corrientes, con su
 * Factura de Exportación ya emitida detrás y sin ninguna marca. Editable,
 * borrable y re-facturable la #14 y la #15; y la #9, con su FEX INVALIDADA, igual.
 *
 * La conclusión es que el `estado` viejo no dice nada útil —vale 'borrador' en las
 * tres— y que lo que sí lo dice es el ESTADO FISCAL de la factura vinculada.
 *
 * ═══ LAS CUATRO REGLAS ═══
 *
 *  1. Lista NO archivada cuya `dte_id` es una FEX (tipo 11) ACEPTADA, viva, del
 *     mismo cliente y en ambiente de PRODUCCIÓN → `finalizada`. Es el único caso
 *     sin ambigüedad: el embarque se facturó y Hacienda aceptó la factura, que es
 *     exactamente la definición nueva de finalizada. `finalizada_en` se rellena con
 *     `updated_at` —el rastro más cercano que existe, no se inventa la fecha de
 *     hoy— y `finalizada_por_user_id` queda NULL porque el sistema anterior nunca
 *     guardó quién cerró. El vínculo se conserva en `dte_id` y en el pivote (lo
 *     crea `create_exportacion_dte_table`), y NADA de esto mira el campo textual
 *     `factura`, que en las tres filas reales es NULL.
 *
 *  2. Lista ARCHIVADA con factura → se queda como está. No se finaliza (archivada
 *     es «fuera del flujo», no «cerrada») y tampoco se marca para revisión: ya es
 *     ineditable por estar archivada, y pedir que alguien la clasifique sería pedir
 *     una decisión sobre algo que nadie va a retomar. Conserva su vínculo con la
 *     FEX —incluso invalidada, que es el caso de la #9— y no puede reutilizarse
 *     para otra factura porque vincular exige {@see Exportacion::puedeEditarse()}.
 *     Que su FEX está invalidada se ve en la ficha, en la tabla de facturas, con su
 *     estado fiscal al lado; acá solo queda anotado en la auditoría.
 *
 *  3. Lista NO archivada cuya factura no cumple TODO lo de la regla 1 —generada,
 *     firmada, enviada, rechazada, invalidada, de otro tipo, de otro cliente, de
 *     otro ambiente, archivada o eliminada— → se CONGELA: `requiere_revision`, el
 *     `estado` literal intacto y el motivo escrito. No se edita, no se factura, no
 *     se finaliza y no se borra hasta que un administrador la clasifique.
 *
 *  4. Lista realmente SIN factura y en un estado que el flujo nuevo entiende → no
 *     se toca. Sigue siendo un borrador de trabajo, que es lo que es.
 *
 * ═══ GARANTÍAS ═══
 *
 * CONSERVA EL ORIGINAL. Toda fila que el backfill toca guarda su `estado` literal
 * en `revision_estado_original` ANTES de cambiarlo, incluidas las que se finalizan.
 * Es lo que permite auditar la decisión y lo que hace que `down()` pueda devolver
 * el valor exacto en vez de inventar uno.
 *
 * DEJA AUDITORÍA. `revision_motivo` explica en prosa por qué se decidió eso,
 * nombrando el estado fiscal de la factura. `requiere_revision` es la marca de
 * CONGELADO, no la de «esto se tocó»: la regla 2 anota sin congelar.
 *
 * ES IDEMPOTENTE. Solo mira filas sin `revision_estado_original`, y estampa esa
 * columna en toda fila que decide. Volver a aplicarla no cambia nada. (Un ciclo
 * `up → down → up` también es estable: `down()` restituye el estado original y
 * borra la marca, así que la segunda pasada parte de los mismos datos.)
 *
 * NO INVENTA VÍNCULOS. No crea, mueve ni borra ninguna fila de `exportacion_dte`
 * ni toca `dte_id`: el vínculo es siempre el que ya estaba.
 */
return new class extends Migration
{
    /** Estados que el flujo nuevo entiende sin traducción. */
    private const ESTADOS_CONOCIDOS = ['borrador', 'finalizada'];

    /** Ambiente de PRODUCCIÓN del Ministerio de Hacienda (CAT-001). */
    private const AMBIENTE_PRODUCCION = '01';

    /** Código CAT-002 de la Factura de Exportación. */
    private const TIPO_FEX = '11';

    public function up(): void
    {
        Schema::table('exportaciones', function (Blueprint $table) {
            $table->string('revision_estado_original', 20)->nullable()->after('revision_motivo')
                ->comment('Estado literal heredado del sistema anterior, capturado antes de tocarlo.');
            $table->timestamp('revision_resuelta_en')->nullable()->after('revision_estado_original');
            $table->foreignId('revision_resuelta_por_user_id')->nullable()->after('revision_resuelta_en')
                ->constrained('users')->nullOnDelete();
            $table->string('revision_resolucion', 20)->nullable()->after('revision_resuelta_por_user_id')
                ->comment('borrador | finalizada | archivada');
        });

        // El trabajo sobre DATOS del lote entero se concentra acá, cuando ya no queda
        // ningún cambio de esquema por delante. El porqué, en `create_exportacion_dte_table`.
        $this->sincronizarPivote();
        $this->clasificarListasHeredadas();
    }

    /**
     * Restituye el `estado` literal de toda fila que el backfill decidió, ANTES de
     * tirar la columna que lo guarda. Nunca inventa un valor: escribe exactamente
     * el que la fila traía del sistema anterior.
     *
     * Alcanza también a las listas que un administrador clasificó después del
     * despliegue, y es a propósito: revertir la migración es deshacer el flujo
     * entero, y dejar un 'finalizada' escrito sería dejar en la base un valor que
     * el sistema anterior no sabe leer.
     */
    public function down(): void
    {
        DB::table('exportaciones')
            ->whereNotNull('revision_estado_original')
            ->update(['estado' => DB::raw('revision_estado_original')]);

        Schema::table('exportaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revision_resuelta_por_user_id');
            $table->dropColumn(['revision_estado_original', 'revision_resuelta_en', 'revision_resolucion']);
        });
    }

    // ------------------------------------------------------------------ backfill

    /**
     * Una fila de `exportacion_dte` por cada lista con `dte_id`, marcada como
     * `principal` porque es la factura que ocupa la columna histórica.
     *
     * Determinista y sin pérdida: no inventa vínculos, no borra ninguno y no toca
     * `dte_id`. Idempotente por comprobación previa, así que volver a aplicarla no
     * duplica nada — y tampoco pisa el `principal` de una lista que ya lo tenga.
     */
    private function sincronizarPivote(): void
    {
        DB::table('exportaciones')
            ->whereNotNull('dte_id')
            ->orderBy('id')
            ->select('id', 'dte_id')
            ->chunkById(200, function ($listas) {
                $ahora = now();

                foreach ($listas as $lista) {
                    $yaExiste = DB::table('exportacion_dte')
                        ->where('exportacion_id', $lista->id)
                        ->where('dte_id', $lista->dte_id)
                        ->exists();

                    if ($yaExiste) {
                        continue;
                    }

                    DB::table('exportacion_dte')->insert([
                        'exportacion_id' => $lista->id,
                        'dte_id' => $lista->dte_id,
                        'principal' => ! DB::table('exportacion_dte')->where('exportacion_id', $lista->id)->exists(),
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ]);
                }
            });
    }

    private function clasificarListasHeredadas(): void
    {
        // Los ids se resuelven de una vez y ANTES de escribir nada: el propio
        // backfill modifica la columna por la que filtra, así que recorrer el
        // resultado mientras se actualiza haría depender el barrido de su efecto.
        $ids = DB::table('exportaciones')
            ->whereNull('revision_estado_original')
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids->chunk(200) as $bloque) {
            foreach ($bloque as $id) {
                $lista = DB::table('exportaciones')->find($id);

                if ($lista !== null) {
                    $this->clasificar($lista);
                }
            }
        }
    }

    private function clasificar(object $lista): void
    {
        $original = (string) $lista->estado;
        $factura = $lista->dte_id !== null ? DB::table('dtes')->find($lista->dte_id) : null;

        // Regla 4: sin factura. Si además el estado es de los que el flujo nuevo
        // entiende, no hay absolutamente nada que traducir.
        if ($factura === null) {
            if (in_array($original, self::ESTADOS_CONOCIDOS, true)) {
                return;
            }

            $this->congelar($lista, $original, 'Estado heredado «'.$original.'» sin ninguna factura vinculada: '
                .'se conservó su valor original y la lista queda congelada hasta que un administrador la clasifique.');

            return;
        }

        $estadoFiscal = (string) $factura->estado;
        $problemas = $this->incoherencias($lista, $factura);

        // Regla 2: archivada. Fuera del flujo: ni se cierra ni se libera ni se pide
        // decidir sobre ella. Solo se deja constancia de con qué factura quedó.
        if ((bool) $lista->archivada) {
            $this->anotar($lista, $original, 'Lista archivada con su factura de exportación en estado «'.$estadoFiscal
                .'»: se conservó archivada, con su vínculo intacto y fuera del flujo de trabajo.');

            return;
        }

        // Regla 1: el único caso sin ambigüedad.
        if ($problemas === [] && $estadoFiscal === 'aceptado') {
            $this->finalizar($lista, $original, 'Cerrada por el backfill: su factura de exportación estaba '
                .'«aceptado» por Hacienda en producción. Estado heredado: «'.$original.'».');

            return;
        }

        // Regla 3: todo lo demás se congela, con el porqué escrito.
        $this->congelar($lista, $original, 'Factura de exportación en estado «'.$estadoFiscal.'»'
            .($problemas === [] ? '' : ' — '.implode('; ', $problemas))
            .'. La lista queda congelada hasta que un administrador la clasifique.');
    }

    /**
     * Todo lo que impide dar por buena la factura vinculada, en prosa. Vacío
     * significa que el vínculo es coherente y solo falta mirar el estado fiscal.
     *
     * @return list<string>
     */
    private function incoherencias(object $lista, object $factura): array
    {
        $problemas = [];

        if ((string) $factura->tipo_dte !== self::TIPO_FEX) {
            $problemas[] = 'el documento vinculado es del tipo '.$factura->tipo_dte.', no una factura de exportación';
        }

        if (($factura->deleted_at ?? null) !== null) {
            $problemas[] = 'el documento vinculado está eliminado';
        }

        if ((bool) ($factura->archivado ?? false)) {
            $problemas[] = 'el documento vinculado está archivado';
        }

        if ((string) $factura->ambiente !== self::AMBIENTE_PRODUCCION) {
            $problemas[] = 'la factura es del ambiente '.$factura->ambiente.', no de producción';
        }

        $problemas = array_merge($problemas, $this->incoherenciaDeCliente($lista, $factura));

        return array_values($problemas);
    }

    /**
     * El receptor de la factura tiene que ser el cliente de la lista. Si la lista no
     * tiene perfil de exportación no hay con qué contrastarlo, y eso NO se resuelve
     * a favor: una lista que no se puede comprobar es exactamente el caso que la
     * marca de revisión existe para atrapar.
     *
     * @return list<string>
     */
    private function incoherenciaDeCliente(object $lista, object $factura): array
    {
        if ($lista->exportacion_cliente_id === null) {
            return ['la lista no tiene perfil de cliente con el que contrastar el receptor de la factura'];
        }

        $clienteDeLaLista = DB::table('exportacion_clientes')
            ->where('id', $lista->exportacion_cliente_id)
            ->value('cliente_id');

        if ($clienteDeLaLista === null || (int) $clienteDeLaLista !== (int) $factura->cliente_id) {
            return ['el receptor de la factura no es el cliente de la lista'];
        }

        return [];
    }

    // ------------------------------------------------------------- las decisiones

    /** Regla 1: se cierra, conservando el estado original y la fecha real. */
    private function finalizar(object $lista, string $original, string $motivo): void
    {
        DB::table('exportaciones')->where('id', $lista->id)->update([
            'estado' => 'finalizada',
            'finalizada_en' => $lista->updated_at,
            'finalizada_por_user_id' => null,
            'requiere_revision' => false,
            'revision_motivo' => Str::limit($motivo, 255, ''),
            'revision_estado_original' => $original,
        ]);
    }

    /** Regla 3 (y regla 4 con estado desconocido): se congela sin traducir nada. */
    private function congelar(object $lista, string $original, string $motivo): void
    {
        DB::table('exportaciones')->where('id', $lista->id)->update([
            'requiere_revision' => true,
            'revision_motivo' => Str::limit($motivo, 255, ''),
            'revision_estado_original' => $original,
        ]);
    }

    /** Regla 2: no cambia nada; solo deja escrito qué se encontró. */
    private function anotar(object $lista, string $original, string $motivo): void
    {
        DB::table('exportaciones')->where('id', $lista->id)->update([
            'requiere_revision' => false,
            'revision_motivo' => Str::limit($motivo, 255, ''),
            'revision_estado_original' => $original,
        ]);
    }
};
