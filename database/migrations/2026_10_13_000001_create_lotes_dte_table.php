<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes_dte', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contingencia_evento_id')->nullable()->constrained('contingencia_eventos')->restrictOnDelete();
            $table->char('id_envio', 36)->unique();
            $table->string('codigo_lote', 100)->nullable()->unique();
            $table->string('estado', 20)->index();
            foreach (['cantidad', 'procesados', 'rechazados', 'consultas'] as $campo) {
                $table->unsignedSmallInteger($campo)->default(0);
            }
            foreach (['envio', 'consulta'] as $tipo) {
                $table->json('respuesta_'.$tipo)->nullable();
                $table->string('respuesta_'.$tipo.'_path')->nullable();
            }
            foreach (['enviado_en', 'ultima_consulta_en', 'cerrado_en'] as $campo) {
                $table->dateTime($campo)->nullable();
            }
            $table->timestamps();
        });
        Schema::table('dtes', fn (Blueprint $table) => $table->foreignId('lote_dte_id')->nullable()->index()->constrained('lotes_dte')->restrictOnDelete());
    }

    public function down(): void
    {
        Schema::table('dtes', fn (Blueprint $table) => $table->dropConstrainedForeignId('lote_dte_id'));
        Schema::dropIfExists('lotes_dte');
    }
};
