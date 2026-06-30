<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca si el cliente es agente de retención de IVA (gran contribuyente).
 * Es solo el VALOR POR DEFECTO; el monto final usado se guarda en cada DTE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->boolean('es_agente_retencion')->default(false)->after('nrc');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('es_agente_retencion');
        });
    }
};
