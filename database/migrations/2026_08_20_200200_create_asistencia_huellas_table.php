<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La asociación «esta ranura de ESTE sensor es esta persona». Es la traducción
 * que hace posible que el ESP32 no sepa de quién es la huella que acaba de leer.
 *
 * LA UNICIDAD CORRECTA es `(dispositivo, fingerprint_id)`, NO `fingerprint_id` a
 * secas. El número que manda el lector es el índice de una plantilla guardada
 * DENTRO de ese sensor: el AS608 numera sus ranuras desde cero y cada sensor
 * numera las suyas. Dos lectores tienen, los dos, una ranura 1. Un único global
 * sobre `fingerprint_id` funcionaría hoy —hay un solo lector— y se rompería el
 * día que se instale el segundo, obligando a migrar el histórico.
 *
 * Un empleado PUEDE tener varias filas: dos dedos en el mismo lector (el índice
 * se corta, el pulgar sigue funcionando) o el mismo dedo dado de alta en varios
 * lectores. Por eso no hay único por empleado.
 *
 * `activo = false` da de baja una plantilla sin borrar la fila: la marcación que
 * ya ocurrió sigue apuntando a la huella con la que se hizo. Una huella inactiva
 * se trata como DESCONOCIDA en el endpoint de marcación.
 *
 * IMPORTANTE: acá NO hay datos biométricos. La plantilla de la huella vive y
 * muere dentro del sensor AS608; por la red y en esta tabla solo viaja un número
 * de ranura, con el que no se reconstruye ninguna huella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencia_huellas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asistencia_empleado_id')
                ->constrained('asistencia_empleados')->restrictOnDelete();
            $table->foreignId('asistencia_dispositivo_id')
                ->constrained('asistencia_dispositivos')->restrictOnDelete();
            $table->unsignedSmallInteger('fingerprint_id')
                ->comment('Número de ranura DENTRO del sensor. Solo tiene sentido junto al dispositivo');
            $table->boolean('activo')->default(true)
                ->comment('false = plantilla dada de baja; se trata como huella desconocida');
            $table->timestamps();

            // El único que impide asociaciones ambiguas: una ranura de un sensor
            // no puede apuntar a dos personas.
            $table->unique(['asistencia_dispositivo_id', 'fingerprint_id'], 'asistencia_huellas_disp_finger_uq');
            $table->index('asistencia_empleado_id', 'asistencia_huellas_empleado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_huellas');
    }
};
