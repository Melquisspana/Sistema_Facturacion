<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soporte de múltiples destinatarios y estado "pendiente" (encolado) en el historial
 * de correos. `destinatarios` guarda la lista completa; `destinatario` queda como el
 * principal (compatibilidad / orden). estado: pendiente | enviado | error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dte_envios', function (Blueprint $table) {
            $table->json('destinatarios')->nullable()->after('destinatario');
            $table->string('destinatario', 120)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('dte_envios', function (Blueprint $table) {
            $table->dropColumn('destinatarios');
            $table->string('destinatario', 120)->nullable(false)->change();
        });
    }
};
