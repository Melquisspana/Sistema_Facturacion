<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modalidad interna de la nota de crédito (TipoNotaCredito). Nullable: solo aplica
 * a documentos tipo 05. No es catálogo oficial del MH.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->string('tipo_nota_credito', 30)->nullable()->after('motivo');
        });
    }

    public function down(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropColumn('tipo_nota_credito');
        });
    }
};
