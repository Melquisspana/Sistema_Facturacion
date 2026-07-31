<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes de inventario: el documento que altera la CANTIDAD FÍSICA sin
 * contrapartida documental.
 *
 * Es la diferencia esencial con todo lo anterior. Una recepción tiene un
 * proveedor detrás, un traslado tiene un camión, un cambio de disponibilidad
 * suma cero. Un ajuste dice «aquí hay más» o «aquí hay menos» porque alguien lo
 * constató, y no hay nada más que lo respalde. Por eso el `motivo` es NOT NULL,
 * por eso es admin-only, y por eso cada tipo se guarda por separado en vez de
 * agruparlos todos en «ajuste»: dentro de un año, «cuánto se mermó» y «cuánto se
 * corrigió por conteo» son preguntas distintas con respuestas distintas.
 *
 * `tipo` guarda App\Enums\Planta\TipoAjuste, que ya existía desde el
 * paso 2. La carga inicial NO tiene tabla propia: comparte el 100 % de la
 * mecánica del ajuste positivo y se distingue por el tipo, que es indexado y
 * filtrable. Duplicar el flujo transaccional para ella solo añadiría superficie
 * de error.
 *
 * SIN softDeletes. Un ajuste confirmado ya escribió en el libro mayor: borrarlo
 * dejaría movimientos apuntando a un documento invisible. Un borrador que no
 * sirve se ANULA.
 *
 * REVERSIÓN con dos punteros al mismo documento, igual que en el resto del
 * módulo: `reversion_de_id` en el que compensa, `revertido_por_id` en el
 * original.
 *
 * Las FK a `users` son `nullable + nullOnDelete`, convención de todo el
 * repositorio: `NOT NULL` sería incompatible con `nullOnDelete`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planta_ajustes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('numero')
                ->comment('Contador propio (Secuencia: planta_ajuste). NO es fiscal');
            $table->string('estado', 20)
                ->comment('App\Enums\Planta\EstadoAjustePlanta: borrador|confirmado|anulado|reversado');
            $table->string('tipo', 30)
                ->comment('App\Enums\Planta\TipoAjuste: carga_inicial|positivo|negativo|merma|dano|vencimiento|correccion_conteo');
            $table->date('fecha')
                ->comment('Fecha OPERATIVA del ajuste, no la de captura');
            $table->text('motivo')
                ->comment('NOT NULL sin excepción: un ajuste no tiene documento externo que lo respalde');

            $table->foreignId('creado_por')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('confirmado_por')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('confirmado_en')->nullable();

            $table->foreignId('responsable_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('responsable_nombre', 120)->nullable()
                ->comment('Instantánea: quien constató el hecho, sobreviva o no su cuenta');

            $table->text('observaciones')->nullable();

            $table->foreignId('reversion_de_id')->nullable()
                ->constrained('planta_ajustes')->restrictOnDelete()
                ->comment('En el documento que COMPENSA: apunta al original');
            $table->foreignId('revertido_por_id')->nullable()
                ->constrained('planta_ajustes')->restrictOnDelete()
                ->comment('En el ORIGINAL: apunta al documento que lo compensó');

            $table->timestamps();

            $table->unique('numero', 'planta_ajuste_numero_unico');
            $table->index('estado', 'planta_ajuste_estado_idx');
            $table->index('tipo', 'planta_ajuste_tipo_idx');
            $table->index('fecha', 'planta_ajuste_fecha_idx');
            $table->index('creado_por', 'planta_ajuste_creado_por_idx');
            $table->index('confirmado_por', 'planta_ajuste_confirmado_por_idx');
            $table->index('reversion_de_id', 'planta_ajuste_reversion_de_idx');
            $table->index('revertido_por_id', 'planta_ajuste_revertido_por_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planta_ajustes');
    }
};
