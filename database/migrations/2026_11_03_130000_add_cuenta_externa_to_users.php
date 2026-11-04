<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuenta externa: la de quien usa el sistema sin ser empleado (la contadora externa).
 * No tiene ficha en Personal y no se cuenta entre los usuarios a los que les falta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('cuenta_externa')->default(false)->after('activo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cuenta_externa');
        });
    }
};
