<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completa los campos de Factura de exportación (FEX, tipo 11) que exige el schema
 * real del MH (fe-fex-v3.json) y que hoy se enviaban como null: recinto fiscal
 * (CAT-027), tipo de régimen (CAT-033), régimen (CAT-028) y tipo de ítem de
 * exportación (bienes/servicios). cod_incoterms/desc_incoterms (CAT-031) YA
 * existen desde 2026_06_13_100009_add_campos_json_base_a_dtes.php; no se tocan.
 *
 * Por-DTE, no por-emisor: pueden cambiar de un envío a otro (distinto recinto
 * aduanero, régimen, incoterm), así que no se fijan en la empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->unsignedTinyInteger('tipo_item_expor')->default(1)->after('desc_incoterms');
            $table->string('recinto_fiscal', 2)->nullable()->after('tipo_item_expor');
            $table->string('tipo_regimen', 10)->nullable()->after('recinto_fiscal');
            $table->string('regimen', 13)->nullable()->after('tipo_regimen');
        });
    }

    public function down(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropColumn(['tipo_item_expor', 'recinto_fiscal', 'tipo_regimen', 'regimen']);
        });
    }
};
