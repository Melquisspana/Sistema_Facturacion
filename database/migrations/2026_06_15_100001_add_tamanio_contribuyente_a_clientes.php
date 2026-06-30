<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tamaño/clasificación de contribuyente del cliente (pequeno|mediano|grande).
 *
 * Es un dato del cliente, no del documento. Determina automáticamente si el
 * cliente es agente de retención: solo el contribuyente "grande" lo es.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('tamanio_contribuyente', 20)->nullable()->after('es_agente_retencion');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('tamanio_contribuyente');
        });
    }
};
