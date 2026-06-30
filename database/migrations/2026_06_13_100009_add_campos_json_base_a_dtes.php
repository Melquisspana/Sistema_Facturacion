<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos preparatorios para la futura generación del JSON oficial del MH.
 * No se usan todavía en ningún flujo: quedan con defaults seguros (modelo y
 * operación = normal) para no romper documentos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            // identificacion (MH): 1 = normal, 2 = contingencia.
            $table->unsignedTinyInteger('tipo_modelo')->default(1)->after('ambiente');
            $table->unsignedTinyInteger('tipo_operacion')->default(1)->after('tipo_modelo');
            $table->string('tipo_contingencia', 2)->nullable()->after('tipo_operacion');
            $table->text('motivo_contingencia')->nullable()->after('tipo_contingencia');

            // Exportación (FEX): incoterms (CAT-018). Nullable; solo aplica a tipo 11.
            $table->string('cod_incoterms', 5)->nullable()->after('seguro');
            $table->string('desc_incoterms')->nullable()->after('cod_incoterms');

            // Forma de pago (CAT-017) — preparado, sin UI compleja todavía.
            $table->string('forma_pago', 2)->nullable()->after('condicion_operacion');
        });
    }

    public function down(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropColumn([
                'tipo_modelo', 'tipo_operacion', 'tipo_contingencia', 'motivo_contingencia',
                'cod_incoterms', 'desc_incoterms', 'forma_pago',
            ]);
        });
    }
};
