<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REUTILIZAR UNA RANURA SIN PERDER EL HISTORIAL.
 *
 * ─────────────────────────────── El problema ───────────────────────────────
 *
 * La tabla nació con `UNIQUE (asistencia_dispositivo_id, fingerprint_id)`. Es la
 * unicidad correcta —una ranura de un sensor no puede apuntar a dos personas—
 * pero es DEMASIADO fuerte en el eje del tiempo: no distingue «a la vez» de
 * «alguna vez». Cuando alguien deja la empresa y se libera su plantilla en el
 * AS608, esa ranura queda inutilizable para siempre:
 *
 *   - dar de baja la huella (`activo = false`) NO libera nada: la fila sigue ahí
 *     y el único la sigue bloqueando;
 *   - borrar la fila sí libera, pero `asistencia_marcaciones.asistencia_huella_id`
 *     es `nullOnDelete`: se pierde CON QUÉ asignación se marcó cada vez;
 *   - reutilizar la fila (cambiarle el empleado) es peor: reescribe el pasado.
 *     Las marcaciones de quien se fue pasarían a apuntar a quien llegó.
 *
 * ──────────────────────────────── La regla ────────────────────────────────
 *
 * La unicidad es sobre la asignación ACTIVA, no sobre la histórica:
 *
 *   · a lo sumo UNA huella activa por (dispositivo, ranura);
 *   · cualquier cantidad de huellas históricas para esa misma ranura;
 *   · una huella liberada NO se toca nunca más: se queda como estaba, con su
 *     empleado y sus marcaciones colgando de ella.
 *
 * ────────────────────── Cómo se impone en la BD, de verdad ──────────────────────
 *
 * MySQL no tiene índices únicos parciales (`WHERE activo = 1`). Y
 * `UNIQUE (dispositivo, ranura, activo)` NO sirve: permitiría una activa y UNA
 * SOLA inactiva, que es justo lo contrario de lo que hace falta —el histórico
 * tiene que poder crecer—.
 *
 * La solución que sí funciona en MySQL usa una COLUMNA GENERADA nullable:
 *
 *     fingerprint_id_activo = CASE WHEN activo = 1 THEN fingerprint_id ELSE NULL END
 *     UNIQUE (asistencia_dispositivo_id, fingerprint_id_activo)
 *
 * y se apoya en que, en un índice único, **los NULL no colisionan entre sí**
 * (SQL estándar, y así se comportan MySQL y SQLite):
 *
 *   · fila ACTIVA   → la columna vale la ranura  → el único la compara y bloquea
 *                     una segunda activa de la misma ranura;
 *   · fila LIBERADA → la columna vale NULL       → el único la ignora, y caben
 *                     todas las que haga falta.
 *
 * Es GENERADA (no la escribe nadie) a propósito: si fuera una columna normal que
 * la aplicación mantiene, la garantía dependería de que ningún código la olvide
 * —y una garantía que depende de que nadie se olvide no es una garantía—. Se
 * deriva de `activo` en el motor, así que liberar una huella libera la ranura en
 * el mismo UPDATE, sin un segundo paso que pueda fallar.
 *
 * VIRTUAL y no STORED: no ocupa espacio, se calcula al leer el índice, y es la
 * única forma que SQLite admite en un `ALTER TABLE ADD COLUMN` —la suite de
 * pruebas corre sobre SQLite y producción sobre MySQL; la garantía tiene que ser
 * la misma en los dos, no una comprobación de PHP que en pruebas no se ejerce.
 *
 * Comprobado contra MySQL 8.4 y SQLite 3.40.
 *
 * ──────────────────── Por qué una migración nueva y no editar ────────────────────
 *
 * Las cuatro migraciones del módulo YA están aplicadas en desarrollo. Editar la
 * original dejaría esa base sin el cambio y sin forma de enterarse: Laravel no
 * vuelve a ejecutar una migración ya registrada. Una migración nueva llega igual
 * a quien ya migró y a quien monte el módulo desde cero.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ORDEN DELIBERADO. En MySQL, `asistencia_dispositivo_id` tiene una clave
        // foránea y toda columna con FK necesita un índice que la encabece. Hoy
        // ese papel lo cumple el único que se va a quitar. Se crea PRIMERO el
        // nuevo —que también empieza por esa columna, así que la releva— y solo
        // después se elimina el viejo. Al revés, MySQL rechaza el DROP (errno 150).
        Schema::table('asistencia_huellas', function (Blueprint $table) {
            $table->unsignedSmallInteger('fingerprint_id_activo')
                ->nullable()
                ->virtualAs('CASE WHEN activo = 1 THEN fingerprint_id ELSE NULL END')
                ->comment('GENERADA: la ranura mientras la asignación está activa, NULL cuando se liberó. Solo existe para el único de abajo; no la escribe nadie');
        });

        Schema::table('asistencia_huellas', function (Blueprint $table) {
            $table->unique(
                ['asistencia_dispositivo_id', 'fingerprint_id_activo'],
                'asistencia_huellas_ranura_activa_uq'
            );
        });

        Schema::table('asistencia_huellas', function (Blueprint $table) {
            $table->dropUnique('asistencia_huellas_disp_finger_uq');
        });

        // CUÁNDO se liberó. La auditoría (activitylog) guarda el hecho y quién lo
        // hizo; esta columna existe para poder RESPONDER CON UNA CONSULTA «de
        // quién era la ranura 1 en marzo», que es la pregunta que va a hacer el
        // módulo de Formatos. Reconstruirlo desde el log de auditoría obligaría a
        // leer JSON fila por fila y no se puede indexar ni unir.
        Schema::table('asistencia_huellas', function (Blueprint $table) {
            $table->timestamp('liberada_at')->nullable()->after('activo')
                ->comment('Momento en que la asignación se liberó. NULL mientras está activa');
        });
    }

    /**
     * Revertir NO siempre es posible, y eso no es un defecto de esta migración:
     * es el punto. El esquema anterior no puede REPRESENTAR el historial que este
     * permite: en cuanto una ranura se reutilizó una vez, existen dos filas con la
     * misma (dispositivo, ranura) y el único viejo las rechaza.
     *
     * Sin la comprobación de abajo, `migrate:rollback` moriría con una violación
     * de restricción a media faena —con el índice nuevo ya borrado— y habría que
     * reparar el esquema a mano. Con ella, no se toca nada y el mensaje dice
     * exactamente qué hay que resolver antes.
     */
    public function down(): void
    {
        $duplicadas = DB::table('asistencia_huellas')
            ->select('asistencia_dispositivo_id', 'fingerprint_id')
            ->groupBy('asistencia_dispositivo_id', 'fingerprint_id')
            ->havingRaw('count(*) > 1')
            ->count();

        if ($duplicadas > 0) {
            throw new RuntimeException(
                'No se puede revertir: hay '.$duplicadas.' ranura(s) con historial de reutilización. '
                .'El esquema anterior solo admite una fila por (dispositivo, ranura), así que revertir '
                .'exigiría borrar asignaciones históricas y dejar marcaciones sin su asignación original. '
                .'Resolvé a mano qué hacer con ese historial antes de revertir.'
            );
        }

        // Simétrico e igual de cuidadoso con el índice de la clave foránea:
        // primero se repone el viejo, después se quita el nuevo.
        Schema::table('asistencia_huellas', function (Blueprint $table) {
            $table->dropColumn('liberada_at');
        });

        Schema::table('asistencia_huellas', function (Blueprint $table) {
            $table->unique(['asistencia_dispositivo_id', 'fingerprint_id'], 'asistencia_huellas_disp_finger_uq');
        });

        Schema::table('asistencia_huellas', function (Blueprint $table) {
            $table->dropUnique('asistencia_huellas_ranura_activa_uq');
        });

        Schema::table('asistencia_huellas', function (Blueprint $table) {
            $table->dropColumn('fingerprint_id_activo');
        });
    }
};
