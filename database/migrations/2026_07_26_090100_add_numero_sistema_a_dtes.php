<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numeración GLOBAL VISIBLE del sistema ("N.º de sistema"): un número comercial único y
 * compartido por TODOS los tipos de documento (CCF 03, NC 05, factura 01, FEX 11) para
 * mostrar en pantalla en lugar de `dtes.id`, que es solo la primary key técnica.
 *
 * Aditiva y reversible. NO toca `id`, `numero_control`, `numero_interno`,
 * `codigo_generacion` ni los correlativos fiscales del MH.
 *
 * Reglas (implementadas en NumeroSistemaService y verificadas en DteNumeroSistemaTest):
 *  - Se asigna SOLO en ambiente 01 (producción). Pruebas/APITEST nunca consume número.
 *  - Se asigna en el punto IRREVERSIBLE de la generación, junto con el correlativo fiscal.
 *    Un borrador no tiene número (queda NULL: de ahí el nullable).
 *  - Una vez asignado NUNCA se reutiliza ni se libera, aunque el documento termine
 *    rechazado, invalidado o archivado. El unique lo garantiza a nivel de BD y el
 *    DteObserver lo bloquea a nivel de modelo (no está en la whitelist de campos
 *    modificables fuera de borrador).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->unsignedBigInteger('numero_sistema')->nullable()->unique()->after('numero_interno')
                ->comment('Numeración global visible del sistema (solo producción); NULL en borrador y en pruebas');
        });
    }

    public function down(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropUnique(['numero_sistema']);
            $table->dropColumn('numero_sistema');
        });
    }
};
