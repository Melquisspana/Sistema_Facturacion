<?php

use App\Services\Planilla\PrepararPlanilla;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pagos de vacaciones de UNA persona: varios en el mismo mes.
 *
 * La clave única `(tipo_periodo, periodo, clase)` dejaba un solo pago de vacaciones por
 * mes. Se le suma `individual_de`: el empleado del pago individual, o 0 en las planillas
 * de varios.
 *
 * Es 0 y no NULL a propósito: en un índice único dos NULL no chocan, y la quincena
 * regular perdería la garantía de ser una sola por período. Por ese 0 no lleva llave
 * foránea; quien la escribe es {@see PrepararPlanilla::abrir()}, que
 * valida que la persona exista.
 *
 * down() falla si ya hay dos pagos de vacaciones del mismo mes: hay que anular y borrar
 * uno antes, porque la clave vieja no los admite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planillas', function (Blueprint $t) {
            $t->unsignedBigInteger('individual_de')->default(0)->after('clase');
        });

        Schema::table('planillas', function (Blueprint $t) {
            $t->dropUnique(['tipo_periodo', 'periodo', 'clase']);
            $t->unique(['tipo_periodo', 'periodo', 'clase', 'individual_de']);
        });
    }

    public function down(): void
    {
        Schema::table('planillas', function (Blueprint $t) {
            $t->dropUnique(['tipo_periodo', 'periodo', 'clase', 'individual_de']);
            $t->unique(['tipo_periodo', 'periodo', 'clase']);
        });

        Schema::table('planillas', function (Blueprint $t) {
            $t->dropColumn('individual_de');
        });
    }
};
