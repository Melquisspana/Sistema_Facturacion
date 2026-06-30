<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tercer nivel territorial (distrito, división 2024) también en el RECEPTOR
 * fiscal (clientes) y en el EMISOR (empresas), además de salas y establecimientos.
 * Nullable + FK con nullOnDelete (no destructivo). El código MH del distrito se
 * mantiene en la tabla `distritos`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->foreignId('distrito_id')->nullable()->after('municipio_id')
                ->constrained('distritos')->nullOnDelete();
        });

        Schema::table('empresas', function (Blueprint $table) {
            $table->foreignId('distrito_id')->nullable()->after('municipio_id')
                ->constrained('distritos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropForeign(['distrito_id']);
            $table->dropColumn('distrito_id');
        });
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropForeign(['distrito_id']);
            $table->dropColumn('distrito_id');
        });
    }
};
