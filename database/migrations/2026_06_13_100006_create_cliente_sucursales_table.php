<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sucursales / salas comerciales de un cliente fiscal.
 *
 * El cliente fiscal (razón social, NIT, NRC) vive en `clientes`. Un mismo cliente
 * (ej. Calleja S.A. de C.V.) puede tener muchas salas (Selectos Santa Rosa,
 * Merliot, Cojutepeque…) SIN duplicar el cliente fiscal ni su documento.
 *
 * La sucursal es referencia COMERCIAL (a dónde se entregó/facturó); el receptor
 * fiscal del DTE sigue siendo el cliente principal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliente_sucursales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();

            $table->string('codigo')->nullable()->comment('Código interno de la sala (opcional)');
            $table->string('nombre')->comment('Nombre comercial / sala (ej. Selectos Santa Rosa)');
            $table->string('direccion')->nullable();
            $table->foreignId('pais_id')->nullable()->constrained('paises')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->foreignId('municipio_id')->nullable()->constrained('municipios')->nullOnDelete();
            $table->string('telefono', 30)->nullable();
            $table->string('correo')->nullable();

            // null = hereda del cliente; true/false = decide la propia sucursal.
            $table->boolean('requiere_orden_compra')->nullable();

            $table->boolean('activo')->default(true);
            $table->text('observaciones')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('cliente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliente_sucursales');
    }
};
