<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calleja recibe primero las NC y después el quedan de sus CCF. Se registra cada carga
 * declarada al portal por separado y se congela qué notas respaldaron cada solicitud.
 * Descargar el formato de NC no demuestra su presentación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nc_exportaciones', function (Blueprint $table) {
            $table->timestamp('presentada_en')->nullable();
            $table->foreignId('presentada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('presentada_nota', 255)->nullable();
            $table->string('referencia_portal', 60)->nullable();
        });

        Schema::create('cobro_solicitud_notas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cobro_solicitud_id')->constrained('cobro_solicitudes')->cascadeOnDelete();
            $table->foreignId('cobro_documento_id')->constrained('cobro_documentos')->cascadeOnDelete();
            $table->foreignId('dte_id')->constrained('dtes')->cascadeOnDelete();
            $table->foreignId('nc_exportacion_id')->constrained('nc_exportaciones')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['cobro_solicitud_id', 'dte_id'], 'cobro_sol_nc_unica');
            $table->index(['cobro_documento_id', 'dte_id'], 'cobro_sol_nc_por_ccf');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobro_solicitud_notas');

        Schema::table('nc_exportaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('presentada_por');
            $table->dropColumn(['presentada_en', 'presentada_nota', 'referencia_portal']);
        });
    }
};
