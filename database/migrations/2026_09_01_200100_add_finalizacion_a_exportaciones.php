<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre del flujo corto de la lista de empaque: borrador → finalizada.
 *
 * ADITIVA Y SIN REINTERPRETACIONES. No se borra ni se toca `estado`, `archivada`
 * ni `archivada_en`. Se agregan las columnas que faltaban para poder decir CUÁNDO
 * y POR QUIÉN se finalizó una lista.
 *
 * ═══ POR QUÉ ACÁ NO HAY BACKFILL ═══
 *
 * La versión anterior de este archivo traducía estados aquí mismo, con dos reglas
 * ciegas: «`estado = 'aprobada'` + `dte_id` → finalizada» y «cualquier otro estado
 * desconocido → marcar». La auditoría de PRODUCCIÓN demostró que esas dos reglas
 * no tocaban NI UNA de las tres listas reales: las tres están en
 * `estado = 'borrador'` CON `dte_id` puesto, así que ninguna entraba en la primera
 * (no son 'aprobada') ni en la segunda (sí son 'borrador'). El resultado habría
 * sido el peor posible: tres listas con su Factura de Exportación ya emitida
 * sobreviviendo a la migración como borradores de trabajo corrientes —editables,
 * borrables y re-facturables— sin ninguna marca que lo advirtiera.
 *
 * La clasificación correcta no depende del `estado` heredado sino del ESTADO
 * FISCAL de la factura vinculada, y para dejar constancia de lo que había antes
 * necesita `revision_estado_original`, que agrega la migración
 * `add_resolucion_revision_a_exportaciones`. Por eso el backfill entero vive allá,
 * después de que existan TODAS las columnas que necesita: partirlo en dos mitades
 * —una que traduce sin poder anotar el original y otra que anota— es justamente lo
 * que hacía imposible auditar la decisión.
 *
 * Esta migración, por tanto, solo agrega columnas. Ninguna fila cambia de estado
 * al aplicarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exportaciones', function (Blueprint $table) {
            $table->timestamp('finalizada_en')->nullable()->after('archivada_en');
            $table->foreignId('finalizada_por_user_id')->nullable()->after('finalizada_en')
                ->constrained('users')->nullOnDelete();
            $table->boolean('requiere_revision')->default(false)->after('finalizada_por_user_id')
                ->comment('Registro histórico cuyo estado no se pudo traducir con certeza al flujo nuevo.');
            $table->string('revision_motivo', 255)->nullable()->after('requiere_revision');
        });
    }

    /**
     * Solo quita columnas.
     *
     * La versión anterior, antes de quitarlas, hacía `estado = 'aprobada'` en toda
     * lista finalizada con factura. Sobre los datos reales eso era una PÉRDIDA: las
     * tres listas productivas valen 'borrador', así que un despliegue seguido de una
     * vuelta atrás las habría dejado en un estado que nunca tuvieron —y sin forma de
     * saberlo, porque el original ya no estaba en ningún sitio—. Devolver el `estado`
     * a su valor exacto es responsabilidad de `down()` en la migración de resolución,
     * que corre ANTES que esta y sí tiene guardado el valor literal.
     */
    public function down(): void
    {
        Schema::table('exportaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finalizada_por_user_id');
            $table->dropColumn(['finalizada_en', 'requiere_revision', 'revision_motivo']);
        });
    }
};
