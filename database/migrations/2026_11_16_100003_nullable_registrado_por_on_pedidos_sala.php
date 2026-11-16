<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La importación automática no suplanta a un usuario ni inventa una cuenta para
 * registrar pedidos. Se admite registrado_por vacío conservando la FK a users y
 * su restricción de borrado. El rollback exige que todos tengan usuario para no
 * inventar responsables ni descartar pedidos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_sala', function (Blueprint $table) {
            $table->unsignedBigInteger('registrado_por')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('pedidos_sala')->whereNull('registrado_por')->exists()) {
            throw new RuntimeException('Hay pedidos sin usuario registrador: no se puede revertir sin asignar su responsable.');
        }
        Schema::table('pedidos_sala', function (Blueprint $table) {
            $table->unsignedBigInteger('registrado_por')->nullable(false)->change();
        });
    }
};
