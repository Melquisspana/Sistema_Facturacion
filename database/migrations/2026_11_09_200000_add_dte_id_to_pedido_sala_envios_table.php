<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 2 de pedidos: el CCF que se hizo con lo que llegó en cada envío de la fábrica.
 *
 * Puede quedar vacío (todavía no se facturó) y NO es única: varios envíos del mismo pedido
 * van en un solo CCF. «Ya facturado» no lo decide esta columna sino
 * `Dte::cuentaComoFacturado()` (no borrado, ni invalidado ni rechazado): si el borrador se
 * borra o el CCF se invalida o se rechaza, el envío vuelve solo a «Por facturar».
 *
 * RESTRICT no estorba: los DTE se borran de forma suave. Solo agrega una columna que puede
 * quedar vacía, en una tabla nueva y chica; no mueve datos. La FK ya crea su índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedido_sala_envios', function (Blueprint $table) {
            $table->foreignId('dte_id')->nullable()->after('anulado_por')->constrained('dtes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pedido_sala_envios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dte_id');
        });
    }
};
