<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relación EXPLÍCITA lista de empaque ↔ facturas de exportación (FEX).
 *
 * Hasta hoy el vínculo era `exportaciones.dte_id`: una sola columna, y por tanto
 * como máximo UNA factura por lista. Un embarque real puede facturarse en varias
 * FEX (por contenedor, por orden de compra, por límite de monto), así que la
 * relación pasa a ser uno-a-muchos con su propia tabla.
 *
 * ESTRICTAMENTE ADITIVA:
 *  - NO se toca ni se borra `exportaciones.dte_id`. Sigue existiendo, sigue con su
 *    índice único y sigue escribiéndose para que cualquier consumidor antiguo
 *    (incluido `Dte::exportacionOrigen()`) funcione exactamente igual.
 *  - `principal` marca cuál de las FEX es la que heredó `dte_id`. Con eso el
 *    camino de vuelta —reconstruir `dte_id` desde la tabla nueva— es exacto y no
 *    depende del orden de inserción.
 *
 * `down()` solo tira la tabla nueva: `exportaciones.dte_id` nunca se vació, así
 * que revertir no pierde ningún vínculo.
 *
 * ═══ EL BACKFILL NO ESTÁ ACÁ, Y ES A PROPÓSITO ═══
 *
 * Esta migración solo CREA la tabla. Las filas —una por cada lista con `dte_id`—
 * las escribe `add_resolucion_revision_a_exportaciones`, la última del lote, junto
 * con la clasificación de las listas heredadas.
 *
 * El motivo es concreto y se comprobó a mano: `add_finalizacion_a_exportaciones`
 * corre DESPUÉS que esta y agrega a `exportaciones` una columna con clave foránea.
 * SQLite —el motor de la suite— no sabe añadir una FK con `ALTER TABLE`, así que
 * su única estrategia es RECREAR la tabla; y como `exportacion_dte.exportacion_id`
 * es `cascadeOnDelete`, el borrado de la tabla vieja se llevaba por delante, en
 * silencio, todas las filas del pivote recién rellenadas. En MySQL no pasa (ahí el
 * ALTER es in situ), y por eso era invisible: el backfill quedaba vacío
 * exactamente en el único motor donde las pruebas podían haberlo detectado.
 *
 * Escribir TODOS los datos después de TODOS los cambios de esquema quita esa
 * dependencia del motor. El orden de las migraciones deja de importar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exportacion_dte', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exportacion_id')->constrained('exportaciones')->cascadeOnDelete();
            // restrictOnDelete a propósito: un DTE es evidencia fiscal. Si alguien
            // intentara borrarlo teniendo una lista vinculada, la base lo impide en
            // vez de dejar la lista sin rastro de su factura (que es lo que hace hoy
            // el nullOnDelete de `exportaciones.dte_id`).
            $table->foreignId('dte_id')->constrained('dtes')->restrictOnDelete();
            $table->boolean('principal')->default(false)
                ->comment('La FEX que ocupa exportaciones.dte_id; hay como máximo una por lista.');
            $table->timestamps();

            // Un DTE no puede vincularse dos veces a la misma lista.
            $table->unique(['exportacion_id', 'dte_id'], 'exportacion_dte_unico');
            // Un DTE pertenece como mucho a UNA lista: mismo invariante que el unique
            // de exportaciones.dte_id, ahora también válido para las FEX adicionales.
            $table->unique('dte_id', 'exportacion_dte_dte_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exportacion_dte');
    }
};
