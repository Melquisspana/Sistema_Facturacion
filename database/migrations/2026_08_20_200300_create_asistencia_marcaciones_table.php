<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las marcaciones reales. Es el LIBRO de la asistencia y se trata como el mayor
 * de Planta: APPEND-ONLY.
 *
 *  - SIN `updated_at`: no existe un momento legítimo en que una marcación ya
 *    escrita cambie. La columna solo serviría para disimular que alguien la
 *    cambió. Una corrección futura será una fila NUEVA que anula a la anterior,
 *    no una edición encima del hecho.
 *  - SIN softDeletes: borrar una marcación borra horas trabajadas de una persona.
 *  - `restrictOnDelete` hacia empleado: no se puede borrar a alguien y llevarse
 *    su historial laboral por delante.
 *
 * LAS DOS COLUMNAS DE TIEMPO, y por qué son dos:
 *
 *  - `marcado_at` es el instante exacto, en UTC, puesto por el SERVIDOR. Es la
 *    hora oficial. El reloj del ESP32 no participa: no tiene batería, se reinicia
 *    y se desfasa, así que su hora no es evidencia de nada.
 *  - `fecha_local` es el DÍA al que pertenece esa marcación en la zona oficial
 *    del módulo (config/asistencia.php). No es redundante: «la primera marcación
 *    del día» y «las horas del martes» son preguntas sobre el día LOCAL, y
 *    derivarlo de un timestamp UTC en cada consulta exige funciones de zona
 *    horaria que difieren entre MySQL y SQLite y que ningún índice puede
 *    aprovechar. Guardarlo lo vuelve exacto, portable e indexable.
 *
 * `tipo` es entrada|salida (App\Enums\Asistencia\TipoMarcacion). Es `string` y no
 * un enum de base a propósito: los eventos futuros (salida a almuerzo, regreso,
 * permiso) se agregan en PHP sin una migración que reescriba la columna.
 *
 * `origen` distingue lo que puso el lector de lo que ponga una persona cuando
 * exista la corrección manual. Hoy solo se escribe 'dispositivo', pero el día que
 * exista un ajuste hecho a mano tiene que poder demostrarse cuál fue cuál, y esa
 * distinción no se puede reconstruir hacia atrás.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencia_marcaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asistencia_empleado_id')
                ->constrained('asistencia_empleados')->restrictOnDelete();
            $table->foreignId('asistencia_dispositivo_id')->nullable()
                ->constrained('asistencia_dispositivos')->nullOnDelete()
                ->comment('NULL para una corrección manual futura: no la hizo ningún lector');
            $table->foreignId('asistencia_huella_id')->nullable()
                ->constrained('asistencia_huellas')->nullOnDelete()
                ->comment('Con qué plantilla se identificó. NULL si no vino de una huella');

            $table->string('tipo', 20)
                ->comment('App\Enums\Asistencia\TipoMarcacion: entrada|salida');
            $table->timestamp('marcado_at')
                ->comment('Instante EXACTO en UTC, puesto por el servidor. Hora oficial');
            $table->date('fecha_local')
                ->comment('Día al que pertenece en la zona oficial del módulo. Indexable');
            $table->string('origen', 20)->default('dispositivo')
                ->comment('dispositivo | manual (corrección futura)');
            $table->string('ip', 45)->nullable()
                ->comment('Desde dónde llegó la petición del lector');

            // Append-only: created_at sin updated_at (mismo criterio que el mayor
            // de Planta). Una fila escrita no vuelve a tocarse.
            $table->timestamp('created_at')->nullable();

            // «Qué marcó esta persona hoy» —la consulta del propio endpoint, en
            // cada marcación— y «qué pasó tal día» —los reportes futuros—.
            $table->index(['asistencia_empleado_id', 'fecha_local'], 'asistencia_marc_empleado_fecha_idx');
            $table->index('fecha_local', 'asistencia_marc_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_marcaciones');
    }
};
