<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué bolsa y qué viñeta corresponden a una presentación para un mercado y una
 * marca. Configuración DECLARATIVA: en esta fase no consume ni descuenta nada.
 *
 * Tres columnas DERIVADAS, `GENERATED ALWAYS AS (...) STORED`. Existen para que
 * los índices únicos protejan de verdad, y las mantiene el MOTOR: no se pueden
 * escribir a mano y se recalculan venga la escritura de donde venga —Eloquent,
 * query builder, `upsert`, importación o SQL crudo—. Los mutators no bastaban:
 * `query()->update(['marca' => …])` los salta y dejaría `marca_norm` obsoleta,
 * con lo que el unique dejaría de proteger lo que dice proteger.
 *
 *   marca_norm         TRIM(UPPER(COALESCE(marca, '')))
 *                      quita el NULL y las diferencias de caja y espacios.
 *   vinieta_key        COALESCE(planta_insumo_vinieta_id, 0)
 *                      0 = «sin viñeta»; evita que dos NULL se consideren
 *                      distintos y permitan duplicados exactos.
 *   predeterminada_key CASE WHEN es_predeterminada = 1 THEN mercado END
 *                      NULL cuando no es predeterminada. Como MySQL y SQLite
 *                      admiten varios NULL en un unique, esta columna deja UNA
 *                      sola predeterminada por (presentación, mercado) y libres
 *                      todas las demás.
 *
 * Es la primera excepción del repositorio a «nada de columnas generadas»,
 * autorizada expresamente (Q7 del plan). Verificado en MySQL 8.4 y SQLite 3.40:
 * ambos las crean, las indexan con UNIQUE y rechazan escribirlas.
 *
 * Lo que esta migración NO hace: no crea movimientos ni existencias, no consume
 * bolsas ni viñetas, y no referencia clientes de Facturación —`referencia_cliente`
 * es texto libre a propósito, para no acoplar Planta con el área fiscal—.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planta_empaque_configs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('planta_presentacion_id')
                ->constrained('planta_presentaciones')->restrictOnDelete();
            $table->foreignId('planta_insumo_bolsa_id')
                ->constrained('planta_insumos')->restrictOnDelete();
            $table->foreignId('planta_insumo_vinieta_id')->nullable()
                ->constrained('planta_insumos')->restrictOnDelete();

            $table->string('marca', 80)->nullable();
            $table->string('mercado', 20)
                ->comment('App\Enums\Planta\MercadoPlanta: nacional|exportacion|otro');
            $table->string('referencia_cliente', 120)->nullable()
                ->comment('Texto libre; SIN FK a clientes (aislamiento de Facturación)');
            $table->boolean('es_predeterminada')->default(false);
            $table->boolean('activo')->default(true);
            $table->date('vigente_desde')->nullable();
            $table->date('vigente_hasta')->nullable();

            // Derivadas: se declaran DESPUÉS de las columnas de las que dependen,
            // como exige MySQL.
            $table->string('marca_norm', 80)
                ->storedAs("TRIM(UPPER(COALESCE(marca, '')))");
            $table->unsignedBigInteger('vinieta_key')
                ->storedAs('COALESCE(planta_insumo_vinieta_id, 0)');
            $table->string('predeterminada_key', 20)->nullable()
                ->storedAs('CASE WHEN es_predeterminada = 1 THEN mercado ELSE NULL END');

            $table->timestamps();
            $table->softDeletes();

            // Una sola configuración por presentación + mercado + marca + viñeta + bolsa.
            $table->unique(
                ['planta_presentacion_id', 'mercado', 'marca_norm', 'vinieta_key', 'planta_insumo_bolsa_id'],
                'planta_empaque_config_unico'
            );
            // Una sola predeterminada por presentación y mercado.
            $table->unique(['planta_presentacion_id', 'predeterminada_key'], 'planta_empaque_predet_unico');

            $table->index('mercado', 'planta_empaque_mercado_idx');
            $table->index('activo', 'planta_empaque_activo_idx');
            // Declarados antes de las FK para que MySQL los reutilice y no cree
            // índices duplicados.
            $table->index('planta_insumo_bolsa_id', 'planta_empaque_bolsa_idx');
            $table->index('planta_insumo_vinieta_id', 'planta_empaque_vinieta_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planta_empaque_configs');
    }
};
