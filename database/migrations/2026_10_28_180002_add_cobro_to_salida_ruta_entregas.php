<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salida_ruta_entregas', function (Blueprint $table) {
            $table->string('forma_cobro', 20)->nullable();
            $table->decimal('monto_esperado', 12, 2)->nullable();
            $table->decimal('monto_cobrado', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('salida_ruta_entregas', function (Blueprint $table) {
            $table->dropColumn(['forma_cobro', 'monto_esperado', 'monto_cobrado']);
        });
    }
};
