<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula la Lista de Empaque (exportaciones) con la Factura de Exportación (FEX,
 * dtes tipo 11) creada a partir de ella. Nullable: las listas existentes no tienen
 * FEX todavía. Unique: una fila de "dte_id" por Lista impide que la MISMA Lista
 * apunte a dos DTE (una sola columna, un solo valor) y que dos Listas distintas
 * apunten al MISMO DTE (unique lo impide entre filas). MySQL permite múltiples
 * NULL en una columna unique, así que no afecta a las listas sin FEX.
 *
 * Esta migración SOLO agrega la columna: la creación real de la FEX y la copia de
 * líneas se implementan en una fase posterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exportaciones', function (Blueprint $table) {
            $table->foreignId('dte_id')->nullable()->unique()->after('exportacion_cliente_id')
                ->constrained('dtes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exportaciones', function (Blueprint $table) {
            $table->dropForeign(['dte_id']);
            $table->dropUnique(['dte_id']);
            $table->dropColumn('dte_id');
        });
    }
};
