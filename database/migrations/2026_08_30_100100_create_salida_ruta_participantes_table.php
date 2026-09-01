<?php

use App\Enums\RolEnSalida;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quiénes van en una salida y CON QUÉ PAPEL. Sustituye a `salida_ruta_user`.
 *
 * ─────────────────────── Qué le faltaba a la tabla anterior ───────────────────────
 *
 * `salida_ruta_user` era un pivote plano contra `users`: decía quiénes iban y nada más. Le
 * faltaban las dos cosas que la operación necesita:
 *
 *  1. RESPONSABLE. Una salida a San Miguel de tres días lleva dos o tres personas y suele
 *     haber una a cargo, que al volver reúne los documentos de sus compañeros. Sin esa
 *     distinción no hay a quién reclamarle el papel que falta.
 *
 *  2. GENTE SIN LOGIN. Apuntaba a `users`, así que solo podía llevar personas con cuenta en
 *     el sistema. Los vendedores no la tienen ni deben tenerla. Ahora apunta a
 *     `rutas_personal`, que es el catálogo de quienes salen.
 *
 * ─────────────────────── El responsable es POR SALIDA ───────────────────────
 *
 * `rol` vive acá y no en la persona. La misma persona es responsable el lunes en San Miguel
 * y acompañante el jueves en Sonsonate; guardarlo en el catálogo obligaría a editarlo en
 * cada viaje y borraría quién respondió por cada uno.
 *
 * El índice único `(salida_ruta_id, responsable_unico)` es el que impide DOS responsables en
 * la misma salida, con el mismo truco de columna redundante que ya usa
 * `salida_ruta_documentos.bloqueo_asignacion`: vale 1 solo en la fila del responsable y NULL
 * en los acompañantes. Como MySQL y SQLite consideran distintos entre sí los NULL de un
 * índice único, quedan «a lo sumo un responsable» y «acompañantes ilimitados» en la misma
 * restricción. Es un candado de BASE a propósito: la comprobación en PHP puede perder una
 * carrera entre dos pestañas.
 *
 * Que NO haya responsable es válido y esperado: una salida de una sola persona no necesita
 * que nadie responda por el grupo.
 *
 * ─────────────────────── La migración de datos ───────────────────────
 *
 * Las filas viejas NO se tiran. Cada `user_id` de `salida_ruta_user` se convierte en una
 * persona de `rutas_personal` —enlazada a su usuario— y en un participante ACOMPAÑANTE: no
 * se inventa un responsable que nadie designó. `down()` rehace el camino inverso, así que la
 * migración es reversible sin pérdida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salida_ruta_participantes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('salida_ruta_id')->constrained('salidas_ruta')->cascadeOnDelete();

            // `restrictOnDelete`: una persona con historial de salidas no se borra. El
            // catálogo se desactiva (`activo = false`), nunca se elimina.
            $table->foreignId('rutas_personal_id')->constrained('rutas_personal')->restrictOnDelete();

            $table->string('rol', 20)->default(RolEnSalida::Acompanante->value)
                ->comment('RolEnSalida: responsable | acompanante');

            // Columna deliberadamente redundante: 1 solo en el responsable, NULL en el resto.
            // Es lo que hace cumplir «un responsable como máximo» en la base y no en PHP.
            $table->unsignedTinyInteger('responsable_unico')->nullable()
                ->comment('1 si esta fila es la del responsable; NULL en los acompañantes');

            $table->timestamps();

            $table->unique(['salida_ruta_id', 'rutas_personal_id'], 'salida_participante_unico');
            $table->unique(['salida_ruta_id', 'responsable_unico'], 'salida_responsable_unico');
        });

        $this->migrarParticipantesAntiguos();

        // Ya vacía y superada: su información vive completa en la tabla nueva.
        Schema::dropIfExists('salida_ruta_user');
    }

    /**
     * Convierte cada fila de `salida_ruta_user` en una persona del catálogo y un
     * participante ACOMPAÑANTE.
     *
     * Se recorre en PHP y no con un `INSERT ... SELECT` porque hace falta crear (o reusar) la
     * fila de `rutas_personal` de cada usuario antes de poder referenciarla, y porque la
     * sintaxis de `INSERT ... SELECT` con subconsulta correlacionada no es la misma en MySQL
     * y en SQLite —la suite corre en SQLite y la operación en MySQL—.
     */
    private function migrarParticipantesAntiguos(): void
    {
        if (! Schema::hasTable('salida_ruta_user')) {
            return;
        }

        $ahora = now();

        DB::table('salida_ruta_user')->orderBy('id')->chunkById(200, function ($filas) use ($ahora) {
            foreach ($filas as $fila) {
                $personalId = DB::table('rutas_personal')->where('user_id', $fila->user_id)->value('id');

                if ($personalId === null) {
                    $nombre = DB::table('users')->where('id', $fila->user_id)->value('name');

                    $personalId = DB::table('rutas_personal')->insertGetId([
                        'nombre' => $nombre ?? 'Persona sin nombre',
                        'user_id' => $fila->user_id,
                        'activo' => true,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ]);
                }

                DB::table('salida_ruta_participantes')->insertOrIgnore([
                    'salida_ruta_id' => $fila->salida_ruta_id,
                    'rutas_personal_id' => $personalId,
                    // Acompañante: nadie designó un responsable en el modelo viejo, así que
                    // no se inventa uno.
                    'rol' => RolEnSalida::Acompanante->value,
                    'responsable_unico' => null,
                    'created_at' => $fila->created_at ?? $ahora,
                    'updated_at' => $fila->updated_at ?? $ahora,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::create('salida_ruta_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salida_ruta_id')->constrained('salidas_ruta')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['salida_ruta_id', 'user_id'], 'salida_ruta_user_unico');
        });

        // Solo vuelven los participantes que tienen usuario: los de campo sin login no
        // caben en la tabla vieja, y ese es justamente el motivo por el que se reemplazó.
        DB::table('salida_ruta_participantes')
            ->join('rutas_personal', 'rutas_personal.id', '=', 'salida_ruta_participantes.rutas_personal_id')
            ->whereNotNull('rutas_personal.user_id')
            ->select('salida_ruta_participantes.salida_ruta_id', 'rutas_personal.user_id')
            ->orderBy('salida_ruta_participantes.id')
            ->chunk(200, function ($filas) {
                foreach ($filas as $fila) {
                    DB::table('salida_ruta_user')->insertOrIgnore([
                        'salida_ruta_id' => $fila->salida_ruta_id,
                        'user_id' => $fila->user_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });

        Schema::dropIfExists('salida_ruta_participantes');
    }
};
