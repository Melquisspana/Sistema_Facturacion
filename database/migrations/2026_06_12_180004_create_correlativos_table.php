<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correlativos internos por tipo de DTE / establecimiento / punto de venta /
 * ambiente. Aquí SOLO se define la estructura: la asignación transaccional del
 * número real (con bloqueo de fila) llega en la fase del motor DTE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correlativos', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_dte', 2)->comment('CAT-002');
            $table->foreignId('establecimiento_id')->constrained('establecimientos')->cascadeOnDelete();
            $table->foreignId('punto_venta_id')->nullable()->constrained('puntos_venta')->cascadeOnDelete();
            $table->string('ambiente', 2)->default('00')->comment('00=pruebas, 01=produccion');
            $table->string('serie', 10)->nullable()->comment('Serie o prefijo, si aplica');
            $table->unsignedBigInteger('ultimo_numero')->default(0);
            $table->boolean('activo')->default(true);

            $table->timestamps();

            // Evita correlativos duplicados para la misma combinación.
            $table->unique(
                ['tipo_dte', 'establecimiento_id', 'punto_venta_id', 'ambiente'],
                'correlativos_unico'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correlativos');
    }
};
