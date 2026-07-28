<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dónde se guarda el inventario de Planta: Casa, Fábrica de Olocuilta y la
 * ubicación de sistema «en tránsito».
 *
 * Módulo AISLADO: no toca DTE, correlativos, firma, transmisión ni correo.
 *
 * Esta migración solo crea la estructura. NO siembra CASA, FABRICA ni TRANSITO
 * (eso es del seeder de Planta), no crea saldos ni movimientos, y no establece
 * jerarquía entre ubicaciones: en Fase 2 son una lista plana.
 *
 * `tipo` guarda los valores de App\Enums\Planta\TipoUbicacion como string(20),
 * no como ENUM de SQL, siguiendo la convención del repositorio (ningún ENUM
 * nativo en las 60+ migraciones existentes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planta_ubicaciones', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->comment('CASA, FABRICA, TRANSITO');
            $table->string('nombre', 100);
            $table->string('tipo', 20)->default('fisica')
                ->comment('App\Enums\Planta\TipoUbicacion: fisica|transito');
            $table->boolean('es_sistema')->default(false)
                ->comment('true = la crea el sistema; no se edita ni se elimina');
            $table->boolean('permite_operacion_manual')->default(true)
                ->comment('false en TRANSITO: solo los traslados mueven su saldo');
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('codigo', 'planta_ubic_codigo_unico');
            $table->index('activo', 'planta_ubic_activo_idx');
            $table->index('tipo', 'planta_ubic_tipo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planta_ubicaciones');
    }
};
