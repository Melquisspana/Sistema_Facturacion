<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lectores biométricos dados de alta. HOY hay uno (el ESP32 + AS608), pero la
 * tabla existe desde el primer día por dos razones que no son especulativas:
 *
 *  1. ES LA IDENTIDAD QUE AUTENTICA. Cada lector tiene su propio token; el
 *     servidor guarda solo su HASH. Un token por dispositivo se revoca solo
 *     (basta poner `activo = false` o rotarlo) sin dejar fuera a los demás.
 *  2. UN «fingerprint_id» NO ES UNA PERSONA. Es el número de ranura DENTRO de un
 *     sensor concreto: la ranura 1 del lector de la entrada y la ranura 1 del
 *     lector de la bodega son dos huellas distintas de dos personas distintas.
 *     Sin esta tabla, la unicidad de `fingerprint_id` tendría que ser global y el
 *     día que se agregue el segundo lector habría que rehacer el esquema y migrar
 *     el histórico. Ver `asistencia_huellas`.
 *
 * El TOKEN EN CLARO no se guarda ni se puede recuperar: solo su SHA-256. Es un
 * secreto generado por la máquina, con entropía alta, así que no necesita el
 * coste de bcrypt (el mismo criterio que usa Sanctum para sus tokens); lo que sí
 * necesita es compararse con `hash_equals`. Si se pierde, se rota con
 * `php artisan asistencia:dispositivo` y se recarga en el firmware.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencia_dispositivos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique()
                ->comment('Identificador que el lector manda en la cabecera X-Dispositivo');
            $table->string('nombre', 100)
                ->comment('Dónde está físicamente: «Entrada principal», «Bodega»');
            $table->string('token_hash', 64)
                ->comment('SHA-256 hex del token. El token en claro NUNCA se guarda');
            $table->boolean('activo')->default(true)
                ->comment('false = el lector deja de autenticar sin borrar su historial');
            $table->timestamp('ultima_conexion_at')->nullable()
                ->comment('Última petición autenticada. Sirve para ver si el lector sigue vivo');
            $table->string('ultima_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_dispositivos');
    }
};
