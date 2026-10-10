<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contingencias', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('tipo');
            $table->string('motivo', 500)->nullable();
            $table->string('origen', 12);
            $table->dateTime('inicio');
            $table->dateTime('cese')->nullable();
            $table->string('estado', 20)->index();
            $table->foreignId('activada_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cerrada_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('contingencia_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contingencia_id')->constrained()->restrictOnDelete();
            $table->smallInteger('parte');
            $table->char('codigo_generacion', 36)->unique();
            $table->string('estado', 20);
            foreach (['json_path', 'jws_path', 'respuesta_mh_path', 'sello_recibido'] as $campo) {
                $table->string($campo)->nullable();
            }
            $table->json('respuesta_mh')->nullable();
            foreach (['fecha_transmision', 'fecha_procesamiento', 'rechazado_en'] as $campo) {
                $table->dateTime($campo)->nullable();
            }
            $table->timestamps();
        });
        Schema::table('dtes', function (Blueprint $table) {
            $table->foreignId('contingencia_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('contingencia_evento_id')->nullable()->index()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contingencia_evento_id');
            $table->dropConstrainedForeignId('contingencia_id');
        });
        Schema::dropIfExists('contingencia_eventos');
        Schema::dropIfExists('contingencias');
    }
};
