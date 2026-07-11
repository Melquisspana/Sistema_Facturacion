<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fecha de emisión del DTE recibido (fecEmi del JSON del proveedor), separada de
 * la fecha del correo. Solo lectura/informativa; no toca DTE emitidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos_recibidos', function (Blueprint $table) {
            $table->date('fecha_dte')->nullable()->after('fecha_correo');
            $table->index('fecha_dte');
        });
    }

    public function down(): void
    {
        Schema::table('documentos_recibidos', function (Blueprint $table) {
            $table->dropIndex(['fecha_dte']);
            $table->dropColumn('fecha_dte');
        });
    }
};
