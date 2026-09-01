<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERSONAL OPERATIVO de Rutas: quién sale a vender, repartir, cobrar o responder por una
 * salida. Junto con sus FUNCIONES, que van normalizadas y no en un JSON.
 *
 * ═══════════════ Por qué una tabla propia y no `users` ni `asistencia_empleados` ═══════════════
 *
 * NO `users`. El argumento ya está escrito en la migración de `asistencia_empleados` y sigue
 * valiendo: un `User` es alguien que ENTRA AL SISTEMA —correo, contraseña, roles de Spatie—.
 * Un vendedor que anda en San Miguel puede no tener login y no deber tenerlo nunca. Meterlo
 * en `users` obligaría a inventar correos y a que cada permiso nuevo se pregunte si aplica a
 * gente sin acceso.
 *
 * NO `asistencia_empleados`, aunque sean casi las mismas personas. Se evaluó y se descartó
 * por tres razones concretas:
 *
 *  1. `activo` NO significa lo mismo. Allá está documentado como «false = no puede marcar»;
 *     acá significa «no se le puede asignar a una salida ni dejarle documentos». Divergen de
 *     verdad: alguien puede dejar de marcar —pasa a comisión, anda de viaje— y seguir
 *     vendiendo. Compartir la columna haría que un cambio de planilla borrara en silencio a
 *     una persona de la operación de campo.
 *
 *  2. El módulo de Asistencia se APAGA. `ASISTENCIA_ENABLED` es `false` por defecto y su
 *     middleware devuelve 404 con el módulo apagado; en un servidor sin lector de huella no
 *     habría pantalla para dar de alta a nadie. Rutas quedaría sin poder gestionar su propio
 *     personal por depender de hardware que no tiene.
 *
 *  3. Su propia migración declara el aislamiento —«NO ... PPQ, Planta ni Rutas»—. Que Rutas
 *     escriba o dependa de esa tabla invierte esa frontera.
 *
 * ─────────────────────── Y sin embargo, no se duplica la identidad ───────────────────────
 *
 * Hoy las mismas personas YA están dos veces —«Rene Barillas» está en `users` y en
 * `asistencia_empleados`, sin enlazar— y una tercera lista suelta empeoraría eso. Por eso hay
 * DOS punteros opcionales y únicos: `user_id` y `asistencia_empleado_id`. Son REFERENCIAS DE
 * IDENTIDAD, no dependencias: Rutas nunca lee marcaciones, nunca exige el módulo encendido y
 * funciona perfectamente con los dos en NULL. Lo que compran es que nadie tenga que adivinar
 * si el Rene de acá es el de allá, y que unificar todo algún día sea una migración de datos y
 * no una investigación.
 *
 * `nullOnDelete` en los dos: dar de baja un usuario o un empleado no puede borrar a la
 * persona de la operación ni llevarse su historial de custodia por delante.
 *
 * ─────────────────────── Lo que deliberadamente NO tiene ───────────────────────
 *
 * Ruta, cliente ni zona. Nadie tiene ruta fija: cualquiera puede ir a cualquier lado, y una
 * columna `ruta_id` acá se convertiría en una regla que la operación no cumple. Si algún día
 * se quieren destinos habituales, serán SUGERENCIAS en su propia tabla.
 *
 * Tampoco salario, cargo ni horario: eso es planilla, y planilla no es este módulo.
 *
 * No se borra gente: `activo = false`. Alguien con historial de custodia no puede desaparecer
 * sin llevarse la respuesta a «¿quién tenía ese papel?».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rutas_personal', function (Blueprint $table) {
            $table->id();

            $table->string('nombre', 120);

            // Enlace OPCIONAL con el usuario del sistema, si además tiene login. Único: una
            // cuenta no puede ser dos personas de campo.
            $table->foreignId('user_id')->nullable()->unique()
                ->constrained('users')->nullOnDelete()
                ->comment('Enlace OPCIONAL al usuario del sistema. NULL es lo normal: casi nadie de campo tiene login');

            // Puntero de IDENTIDAD hacia la misma persona en Asistencia. No es dependencia:
            // Rutas no lee marcaciones ni necesita el módulo encendido.
            $table->foreignId('asistencia_empleado_id')->nullable()->unique()
                ->constrained('asistencia_empleados')->nullOnDelete()
                ->comment('La MISMA persona en Asistencia, si existe. Solo para no duplicar identidad');

            $table->string('telefono', 30)->nullable();
            $table->string('notas', 300)->nullable();

            $table->boolean('activo')->default(true)
                ->comment('false = no se le asigna a salidas ni se le entregan documentos. Nunca se borra');

            $table->timestamps();

            $table->index('activo', 'rutas_personal_activo_idx');
            $table->index('nombre', 'rutas_personal_nombre_idx');
        });

        /*
         * Funciones combinables. Tabla aparte y no una columna JSON, a propósito: acá se
         * consulta («¿quién puede ser responsable?»), se valida contra el enum y se quiere
         * poder agregar una función nueva sin reescribir filas. Un JSON no deja hacer nada
         * de eso con un índice.
         */
        Schema::create('rutas_personal_funciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rutas_personal_id')->constrained('rutas_personal')->cascadeOnDelete();
            $table->string('funcion', 30)->comment('FuncionPersonalRuta: vendedor | repartidor | responsable_salida | cobrador');
            $table->timestamps();

            // La misma función una sola vez por persona.
            $table->unique(['rutas_personal_id', 'funcion'], 'rutas_personal_funcion_unica');
            $table->index('funcion', 'rutas_personal_funcion_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rutas_personal_funciones');
        Schema::dropIfExists('rutas_personal');
    }
};
