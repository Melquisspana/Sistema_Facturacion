<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progreso de la sincronización de compras, UN DÍA POR FILA.
 *
 * POR QUÉ UNA TABLA Y NO UNA MARCA SUELTA. La marca de progreso de albaranes
 * (`ppq.albaranes.ultimo_dia_completo` en `configuraciones`) alcanza para avanzar hacia
 * adelante, pero no responde la pregunta que dejó al paquete de contabilidad saliendo
 * incompleto sin avisar: *¿qué días de agosto se leyeron de verdad?* Sin esto, un día
 * sin documentos y un día sin leer se ven idénticos —ambos son cero filas— y no hay
 * forma honesta de decir si un período está cubierto.
 *
 * Con una fila por día, la cobertura es una consulta y no una inferencia, y una
 * recuperación de dos meses que se corta en el día 34 sabe exactamente dónde retomar.
 *
 * Estados:
 *   - `pendiente` el día se conoce pero todavía no se recorrió;
 *   - `parcial`   se leyó una parte (`ultimo_uid` es el cursor para continuar);
 *   - `completo`  se recorrió ENTERO, sin truncar y sin error. Solo este cuenta como cubierto;
 *   - `error`     el buzón falló en ese día; queda el motivo y NO se da por cubierto.
 *
 * No guarda contenido de correos ni credenciales: solo fechas, conteos y un motivo de
 * error ya saneado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_recibidos_progreso', function (Blueprint $table) {
            $table->id();

            $table->date('dia')->comment('Día cubierto, por fecha del correo');
            $table->string('carpeta', 64)->default('INBOX');
            $table->unsignedBigInteger('uid_validity')->nullable()
                ->comment('UIDVALIDITY de la carpeta al recorrer el día: si cambia, el progreso por UID deja de ser válido');

            $table->string('estado', 12)->default('pendiente')
                ->comment('pendiente | parcial | completo | error');
            $table->unsignedBigInteger('ultimo_uid')->nullable()
                ->comment('Cursor dentro del día: último UID leído, para reanudar sin repetir');

            // Conteos de lo que pasó ese día. Alimentan la verificación de cobertura del
            // paquete mensual: procesados, repetidos y rechazados, sin adivinar.
            $table->unsignedInteger('correos')->default(0);
            $table->unsignedInteger('nuevos')->default(0);
            $table->unsignedInteger('duplicados')->default(0);
            $table->unsignedInteger('descartados')->default(0)->comment('No-DTE excluidos por regla');
            $table->unsignedInteger('rechazados')->default(0)->comment('Con adjuntos pero sin DTE legible, o que fallaron al guardarse');

            $table->text('error')->nullable()->comment('Motivo saneado del último fallo (sin credenciales)');
            $table->timestamp('completado_en')->nullable();

            $table->timestamps();

            // Un día se cubre una sola vez por carpeta: es lo que hace idempotente
            // repetir un rango.
            $table->unique(['dia', 'carpeta']);
            $table->index(['estado', 'dia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_recibidos_progreso');
    }
};
