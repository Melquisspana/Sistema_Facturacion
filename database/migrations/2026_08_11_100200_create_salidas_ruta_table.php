<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salidas de ruta: el recorrido REAL, con sus fechas, su estado y su gente.
 *
 * Una salida dura lo que dura. NO se asume salir y regresar el mismo día, por eso
 * hay tres fechas distintas y cada una responde algo diferente:
 *
 *  - `fecha_inicio`        cuándo sale (o salió) — siempre presente;
 *  - `fecha_fin_estimada`  cuándo se espera el regreso — una previsión, nullable;
 *  - `fecha_fin_real`      cuándo regresó de verdad — se llena al FINALIZAR.
 *
 * Guardar la estimada aparte de la real es lo que después permite ver si las
 * rutas se están alargando; pisar una con la otra perdería justamente ese dato.
 *
 * `created_by` es quien registró la salida en el sistema, NO quien viaja: los que
 * viajan están en `salida_ruta_user`. `nullOnDelete` para que dar de baja a un
 * usuario no borre historial de salidas.
 *
 * Todavía SIN documentos: la relación con CCF/albaranes llega en el bloque
 * siguiente, y por eso acá no hay ninguna columna que la anticipe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salidas_ruta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ruta_id')->constrained('rutas')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('fecha_inicio');
            $table->date('fecha_fin_estimada')->nullable();
            $table->date('fecha_fin_real')->nullable()->comment('Se llena al finalizar la salida');
            $table->string('estado', 20)->default('planificada')->comment('planificada | en_curso | finalizada | cancelada');
            $table->text('observaciones')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Quién registró la salida, NO quién viaja');
            $table->timestamps();

            // Las dos preguntas frecuentes del listado: «qué hay abierto» y «qué
            // se hizo en esta ruta».
            $table->index(['estado', 'fecha_inicio'], 'salidas_ruta_estado_fecha_idx');
            $table->index(['ruta_id', 'fecha_inicio'], 'salidas_ruta_ruta_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salidas_ruta');
    }
};
