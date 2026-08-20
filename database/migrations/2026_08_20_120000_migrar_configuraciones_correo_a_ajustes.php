<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mueve las cinco claves de correo/contabilidad de `configuraciones` a
 * `ajustes_sistema`.
 *
 * MUEVE, NO COPIA. Cada clave se inserta en la tabla nueva y se BORRA de la
 * anterior en la misma transacción. Dejar el valor en los dos sitios es la forma
 * de acabar con dos verdades que se desincronizan en silencio; el sistema de
 * ajustes existe precisamente para que cada clave tenga una sola ubicación de
 * escritura.
 *
 * QUÉ SE MUEVE Y QUÉ NO
 * ------------------------------------------------------------------
 * Solo estas cinco, que son las que el catálogo declara y las únicas cuyos
 * consumidores pasan ya por `Ajustes`. La tabla `configuraciones` NO se borra y
 * las demás claves NO se tocan:
 *
 *   produccion.auth_prod_validada, .._en, .._fuente   → no son configuración: son
 *   produccion.ultimo_ccf_externo                       ESTADO que escribe un
 *   ppq.albaranes.ultimo_dia_completo                   comando y lee un preflight
 *
 * Mudarlas obligaría a registrarlas como ajustes editables, y no lo son: nadie
 * debería poder declarar desde una pantalla que las credenciales de producción
 * están validadas.
 *
 * IDEMPOTENTE Y SEGURA ANTE UNA MEDIA MUDANZA. Si una clave ya existe en la tabla
 * nueva (porque alguien guardó desde la pantalla antes de correr esto), gana la
 * nueva y la vieja se borra igual: se converge a una sola fila, nunca a dos.
 *
 * REVERSIBLE. `down()` devuelve los valores a `configuraciones` y los quita de
 * `ajustes_sistema`, dejando el sistema exactamente como estaba.
 */
return new class extends Migration
{
    /**
     * Clave en `configuraciones` ⇒ clave en `ajustes_sistema`. Hoy son idénticas;
     * el mapa existe para que dejen de tener que serlo.
     */
    private const CLAVES = [
        'contabilidad.correo' => 'contabilidad.correo',
        'contabilidad.enviar_copia' => 'contabilidad.enviar_copia',
        'correo.auto_envio' => 'correo.auto_envio',
        'correo.adjuntar_jws' => 'correo.adjuntar_jws',
        'correo.plantilla' => 'correo.plantilla',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('configuraciones') || ! Schema::hasTable('ajustes_sistema')) {
            return;
        }

        DB::transaction(function () {
            $ahora = now();

            foreach (self::CLAVES as $vieja => $nueva) {
                $fila = DB::table('configuraciones')->where('clave', $vieja)->first();

                if ($fila === null) {
                    continue;
                }

                $yaEstaba = DB::table('ajustes_sistema')->where('clave', $nueva)->exists();

                // Un valor NULL o vacío en la tabla vieja significaba "sin
                // configurar"; trasladarlo como fila vacía convertiría una ausencia
                // en un override que tapa el fallback. Se descarta y se borra.
                if (! $yaEstaba && filled($fila->valor)) {
                    DB::table('ajustes_sistema')->insert([
                        'clave' => $nueva,
                        'valor' => $fila->valor,
                        // Ninguna de estas cinco es un secreto: se mudan en claro,
                        // igual que estaban.
                        'cifrado' => false,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ]);
                }

                DB::table('configuraciones')->where('clave', $vieja)->delete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('configuraciones') || ! Schema::hasTable('ajustes_sistema')) {
            return;
        }

        DB::transaction(function () {
            $ahora = now();

            foreach (self::CLAVES as $vieja => $nueva) {
                $fila = DB::table('ajustes_sistema')->where('clave', $nueva)->first();

                if ($fila === null) {
                    continue;
                }

                // updateOrInsert y no insert: si alguien volvió a escribir la clave
                // en la tabla vieja entre medias, se respeta una sola fila.
                DB::table('configuraciones')->updateOrInsert(
                    ['clave' => $vieja],
                    ['valor' => $fila->valor, 'updated_at' => $ahora, 'created_at' => $ahora],
                );

                DB::table('ajustes_sistema')->where('clave', $nueva)->delete();
            }
        });
    }
};
