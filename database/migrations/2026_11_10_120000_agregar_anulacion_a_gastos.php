<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anular un gasto: quién, cuándo y por qué.
 *
 * Anular NO borra. La obligación sigue existiendo, con su historial y sus pagos
 * revertidos, pero deja de ser deuda desde ese instante. Por eso basta con marcarla:
 * `anulado_at` NULL —lo normal— es «vigente».
 *
 * Mismo nombre que ya usan los pagos (`revertido_at`, `revertido_por`,
 * `motivo_reversion`) y las planillas (`anulada_at`, `anulada_por`,
 * `motivo_anulacion`), para que el módulo hable de una sola manera.
 *
 * Aditiva y reversible. Revertirla se NIEGA si ya hay gastos anulados: borrar las
 * columnas los volvería deuda vigente en silencio, con su saldo de nuevo en el panel
 * y en los avisos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gastos', function (Blueprint $t) {
            $t->timestamp('anulado_at')->nullable();
            $t->foreignId('anulado_por')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('motivo_anulacion', 500)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('gastos')->whereNotNull('anulado_at')->exists()) {
            throw new RuntimeException(
                'Hay gastos anulados. Quitar estas columnas los volvería deuda vigente sin aviso; '
                .'no se revierte sin perder datos.'
            );
        }

        Schema::table('gastos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('anulado_por');
            $t->dropColumn(['anulado_at', 'motivo_anulacion']);
        });
    }
};
