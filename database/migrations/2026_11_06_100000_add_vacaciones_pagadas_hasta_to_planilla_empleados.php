<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hasta qué año ya se le pagaron las vacaciones a la persona, aunque haya sido fuera del
 * sistema: el aniversario de ese año y los anteriores no se vuelven a proponer. Null es
 * «no se sabe», y entonces solo cuentan las planillas de vacaciones del sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planilla_empleados', function (Blueprint $table) {
            $table->unsignedSmallInteger('vacaciones_pagadas_hasta')->nullable()->after('alta_el');
        });
    }

    public function down(): void
    {
        Schema::table('planilla_empleados', function (Blueprint $table) {
            $table->dropColumn('vacaciones_pagadas_hasta');
        });
    }
};
