<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LO QUE EL SENSOR DICE DE SÍ MISMO.
 *
 * Hasta ahora el servidor no sabía nada del AS608 salvo que existía. Para elegir
 * una ranura donde grabar una huella hace falta saber dos cosas que **solo el
 * sensor conoce**: cuántas ranuras tiene y cuáles están realmente ocupadas.
 *
 * ─────────────── Por qué no se fija la capacidad en el código ───────────────
 *
 * Los AS608 vienen con capacidades distintas (127, 162, 300, 1000…). Escribir
 * «162» como constante funcionaría con el lector de hoy y sería mentira el día
 * que se instale otro — y la mentira se descubriría al fallar un enrolamiento
 * contra una ranura que no existe. La capacidad la REPORTA el lector, y hasta que
 * lo haga el servidor prefiere decir «este lector todavía no ha sincronizado sus
 * ranuras» antes que reservar a ciegas.
 *
 * Las tres columnas son NULLABLE a propósito: `NULL` significa «nunca sincronizó»,
 * que es un estado real y distinto de «sincronizó y está vacío».
 *
 * ──────────────────── Por qué las ocupadas van en JSON ────────────────────
 *
 * Es una FOTO del sensor en un instante, no un dato relacional: no se une con
 * nada, no se consulta por partes y se reemplaza entera en cada sincronización.
 * Una tabla aparte solo añadiría filas que borrar. La verdad relacional —quién es
 * cada ranura— ya vive en `asistencia_huellas`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asistencia_dispositivos', function (Blueprint $table) {
            $table->unsignedSmallInteger('capacidad_sensor')->nullable()->after('activo')
                ->comment('Ranuras que el AS608 dice tener. La REPORTA el lector; NULL = nunca sincronizó');

            $table->json('ranuras_ocupadas')->nullable()->after('capacidad_sensor')
                ->comment('Foto de las ranuras con plantilla FÍSICA, según el sensor. Se reemplaza entera en cada sincronización');

            $table->timestamp('indice_sincronizado_at')->nullable()->after('ranuras_ocupadas')
                ->comment('Cuándo reportó el lector su índice. NULL = sin sincronizar: no se puede reservar automáticamente');
        });
    }

    public function down(): void
    {
        Schema::table('asistencia_dispositivos', function (Blueprint $table) {
            $table->dropColumn(['capacidad_sensor', 'ranuras_ocupadas', 'indice_sincronizado_at']);
        });
    }
};
