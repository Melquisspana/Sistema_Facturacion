<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_web', function (Blueprint $table) {
            $table->dateTime('lista_en')->nullable();
            $table->dateTime('entregado_en')->nullable();
            $table->string('estado_por_informar', 20)->nullable();
            $table->dateTime('estado_informado_en')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('pedidos_web')->whereNotNull('lista_en')->orWhereNotNull('entregado_en')
            ->orWhereNotNull('estado_por_informar')->orWhereNotNull('estado_informado_en')->exists()) {
            throw new RuntimeException('Hay seguimiento de pedidos web: no se puede revertir sin perder su historial.');
        }

        Schema::table('pedidos_web', function (Blueprint $table) {
            $table->dropColumn(['lista_en', 'entregado_en', 'estado_por_informar', 'estado_informado_en']);
        });
    }
};
