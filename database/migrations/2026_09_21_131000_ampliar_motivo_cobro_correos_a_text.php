<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un correo puede citar varios documentos pendientes: no recortar su evidencia.
        Schema::table('cobro_correos', function (Blueprint $table) {
            $table->text('motivo')->nullable()->change();
        });
    }

    public function down(): void
    {
        $length = DB::connection()->getDriverName() === 'sqlite' ? 'length' : 'CHAR_LENGTH';

        if (DB::table('cobro_correos')->whereRaw($length.'(motivo) > 255')->exists()) {
            throw new RuntimeException('No se puede reducir motivo a 255 caracteres sin perder datos.');
        }

        Schema::table('cobro_correos', function (Blueprint $table) {
            $table->string('motivo', 255)->nullable()->change();
        });
    }
};