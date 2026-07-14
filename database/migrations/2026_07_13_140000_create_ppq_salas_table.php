<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapa auxiliar de PPQ: código de sala (4 dígitos, ej. 0260) -> nombre comercial.
 *
 * NO es una tabla fiscal ni reemplaza `cliente_sucursales`: es solo un caché de
 * nombres que PPQ ya vio (JSON de CCF, alta manual) para mostrar "Súper Selectos X"
 * en vez de "Sala 0260 sin nombre registrado". No crea ni toca sucursales fiscales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppq_salas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 8)->unique();        // sala normalizada a 4 dígitos (0260)
            $table->string('nombre');                      // nombre comercial detectado
            $table->string('fuente', 40)->nullable();      // ccf_json | ppq_item | manual | gmail
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppq_salas');
    }
};
