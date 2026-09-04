<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sala a la que CORRESPONDE una nota de crédito por avería.
 *
 * No es la sala receptora del documento. Cuando la avería se acredita contra un CCF de
 * otra sala del mismo cliente —algo permitido, avisado y con motivo obligatorio—, el
 * documento se emite a la sala del CCF, pero el producto dañado sigue perteneciendo a la
 * sala donde estaba. Guardar solo `cliente_sucursal_id` perdería ese dato y haría parecer
 * que la avería fue de la sala del CCF.
 *
 * Nullable: solo la lleva la avería. Es trazabilidad, no fiscalidad: no entra al JSON del
 * MH ni toca descuento, retención o totales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->foreignId('sucursal_averia_id')
                ->nullable()
                ->after('tipo_nota_credito')
                ->constrained('cliente_sucursales')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sucursal_averia_id');
        });
    }
};
