<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapeo de cada modalidad INTERNA de nota de crédito ({@see \App\Enums\TipoNotaCredito})
 * al código que usa el cliente, más la regla de descuento de esa modalidad.
 *
 * Es la tabla que sustituye al `if` que trataba avería y devolución por igual. Un
 * cliente puede declarar, por ejemplo:
 *
 *   averia              -> AC02 · descuento_origen = ccf      (hereda el 5 % del CCF)
 *   devolucion_producto -> AC04 · descuento_origen = ninguno  (0 %, aunque el CCF tenga 5 %)
 *
 * Una modalidad SIN fila acá no cambia de comportamiento: cae al criterio histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliente_perfil_tipos_nc', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_perfil_documento_id')
                ->constrained('cliente_perfiles_documento')
                ->cascadeOnDelete();

            // Valor de App\Enums\TipoNotaCredito (averia, devolucion_producto, ...).
            $table->string('tipo_nota_credito', 40);

            // Código del cliente para esa modalidad (AC02, AC04, ...). Se guarda canónico
            // en MAYÚSCULAS; cada formato decide cómo escribirlo (el Excel de Calleja lo
            // quiere en minúsculas).
            $table->string('codigo_externo', 10);
            $table->string('etiqueta_externa', 60)->nullable();

            // Valor de App\Enums\OrigenDescuentoNc.
            $table->string('descuento_origen', 20)->default('ccf');

            // Solo se usa cuando descuento_origen = tasa_propia.
            $table->decimal('descuento_tasa', 5, 2)->nullable();

            $table->timestamps();

            // Los dos índices llevan nombre EXPLÍCITO: el que Laravel genera solo
            // (tabla + columnas + sufijo) da 72 caracteres para el segundo, y MySQL corta
            // en 64. SQLite lo acepta sin chistar, así que la suite pasa y la migración
            // real revienta; nombrarlos a mano es lo que evita esa trampa.
            $table->unique(['cliente_perfil_documento_id', 'tipo_nota_credito'], 'perfil_tipo_nc_unico');
            $table->index(['cliente_perfil_documento_id', 'codigo_externo'], 'perfil_tipo_nc_codigo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliente_perfil_tipos_nc');
    }
};
