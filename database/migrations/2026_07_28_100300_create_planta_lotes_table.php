<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identidad trazable de una entrada física de un insumo.
 *
 * El lote NO guarda cantidad: eso son las existencias (paso 5). Aquí solo vive
 * la identidad y las fechas de lo que entró.
 *
 * SIN softDeletes, a diferencia del resto de catálogos: un lote con movimientos
 * en el libro mayor no puede desaparecer del historial ni siquiera de forma
 * lógica. Para retirarlo de la operación se usa `activo = false`, que impide
 * nuevas entradas y conserva saldo e historial. Las claves foráneas que
 * apuntarán a esta tabla desde el mayor serán `restrictOnDelete`.
 *
 * `fecha_recepcion` es DATE NOT NULL a propósito, para lotes reales y
 * genéricos: un lote sin fecha de entrada no es trazable, y permitir NULL
 * abriría la puerta a lotes creados «de paso» sin saber cuándo llegó lo que
 * contienen. El valor es la fecha OPERATIVA del documento que lo creó, no
 * `now()`.
 *
 * Esta migración NO crea lotes genéricos `GEN-<insumo_id>` ni el LoteService
 * que los resuelve: eso llega con las recepciones. Aquí solo queda la
 * estructura preparada, incluida la bandera `es_generico`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planta_lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planta_insumo_id')
                ->constrained('planta_insumos')->restrictOnDelete();
            $table->foreignId('planta_proveedor_id')->nullable()
                ->constrained('planta_proveedores')->nullOnDelete();
            $table->string('codigo_interno', 30)
                ->comment('INT-AAAAMMDD-#### para lotes reales; GEN-<insumo_id> para el genérico');
            $table->string('codigo_proveedor', 60)->nullable()
                ->comment('Texto del proveedor; NO unique: puede repetirse entre insumos');
            $table->boolean('es_generico')->default(false)
                ->comment('true = lote único de un insumo sin control de lotes');
            $table->date('fecha_recepcion')
                ->comment('NOT NULL siempre: fecha operativa del documento que creó el lote');
            $table->date('fecha_elaboracion')->nullable()
                ->comment('Dato del proveedor, no siempre disponible');
            $table->date('fecha_vencimiento')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique('codigo_interno', 'planta_lote_interno_unico');
            $table->index(['planta_insumo_id', 'codigo_proveedor'], 'planta_lote_insumo_prov_idx');
            $table->index('fecha_vencimiento', 'planta_lote_vencimiento_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planta_lotes');
    }
};
