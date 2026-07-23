<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro de ejecuciones del backup diario de base de datos
     * ({@see \App\Console\Commands\BackupMysqlDiarioCommand}). Es la fuente de verdad
     * del readiness ("Backup del día listo"), en vez de escanear archivos por fecha de
     * modificación (con el bug de timezone que eso arrastraba). NO reemplaza a
     * spatie/laravel-backup (zip completo app+BD): es un mecanismo aparte, específico
     * para el dump `.sql` verificado (single-transaction, rutinas/triggers/eventos,
     * marca "Dump completed", SHA-256).
     */
    public function up(): void
    {
        Schema::create('respaldo_ejecuciones', function (Blueprint $table) {
            $table->id();
            $table->timestamp('iniciado_en');
            $table->timestamp('terminado_en')->nullable();
            $table->boolean('exitoso')->default(false);
            $table->string('archivo_ruta')->nullable();
            $table->unsignedBigInteger('archivo_tamano_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->text('mensaje')->nullable();
            $table->string('origen', 20)->default('automatico'); // 'automatico' | 'manual'
            $table->timestamps();

            $table->index(['exitoso', 'terminado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('respaldo_ejecuciones');
    }
};
