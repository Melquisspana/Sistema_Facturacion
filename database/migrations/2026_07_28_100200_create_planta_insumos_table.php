<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué se almacena en Planta: materias primas, bolsas, viñetas y empaques.
 *
 * El insumo define CÓMO se comporta en el inventario (unidad base, si controla
 * lotes, si admite fracción) pero NO conoce saldos: las existencias viven en su
 * propia tabla y se derivan del libro mayor (paso 5).
 *
 * Aislamiento: no referencia `productos` ni `unidades_medida` de Facturación.
 * `unidades_medida` es el catálogo CAT-014 del Ministerio de Hacienda y usarlo
 * arrastraría a Planta al dominio fiscal; Planta define su unidad base propia.
 *
 * `tipo` y `unidad_base` guardan los valores de App\Enums\Planta\TipoInsumo y
 * App\Enums\Planta\UnidadBase como string, no como ENUM de SQL.
 *
 * Los campos «sugerido» son ayudas de captura para la recepción: el valor real
 * de cada entrada se congela en el detalle de la recepción, así que cambiarlos
 * aquí NO altera el histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planta_insumos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30);
            $table->string('nombre', 150);
            $table->string('tipo', 20)
                ->comment('App\Enums\Planta\TipoInsumo: materia_prima|bolsa|vinieta|empaque|otro');
            $table->string('unidad_base', 10)
                ->comment('App\Enums\Planta\UnidadBase: libra|unidad');
            $table->boolean('controla_lotes')->default(true);
            $table->boolean('permite_fraccion')->default(true)
                ->comment('false en bolsas y viñetas: se cuentan enteras');
            $table->decimal('factor_conversion_sugerido', 18, 8)->nullable()
                ->comment('Informativo; el factor real se congela en la recepción');
            $table->string('unidad_recepcion_sugerida', 30)->nullable()
                ->comment('saco, caja, paquete, kg');
            $table->decimal('contenido_sugerido', 14, 4)->nullable();
            $table->decimal('stock_minimo', 14, 4)->nullable()
                ->comment('Solo alerta visual; no bloquea ninguna operación');
            $table->boolean('activo')->default(true);
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('codigo', 'planta_insumo_codigo_unico');
            $table->index(['tipo', 'activo'], 'planta_insumo_tipo_activo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planta_insumos');
    }
};
