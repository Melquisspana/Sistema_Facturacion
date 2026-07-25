<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canal del envío por correo: a quién va dirigido el documento ('cliente' o
 * 'contabilidad'). Nullable y SIN backfill: los envíos históricos quedan en NULL y
 * se siguen leyendo/procesando igual (se interpretan como 'cliente' donde hace falta).
 * El índice permite filtrar el historial por canal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dte_envios', function (Blueprint $table) {
            $table->string('canal', 20)->nullable()->after('estado')
                ->comment('cliente | contabilidad; NULL = envío histórico (previo al canal)');
            $table->index('canal');
        });
    }

    public function down(): void
    {
        Schema::table('dte_envios', function (Blueprint $table) {
            $table->dropIndex(['canal']);
            $table->dropColumn('canal');
        });
    }
};
