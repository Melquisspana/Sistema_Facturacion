<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CCF y NC de contabilidad (Conta) que entran al seguimiento desde los correos ENVIADOS
 * de la cuenta Gmail conectada, con el JSON del DTE adjunto.
 *
 * Un documento externo no tiene fila en `dtes`, así que tampoco tiene
 * `dtes.dte_relacionado_id`: sin algo propio, una NC de Conta nunca podría decir a qué
 * CCF descuenta. Estas dos tablas guardan solo lo necesario para poder demostrarlo:
 *
 *   · PROCEDENCIA: de qué mensaje y de qué adjunto salió cada documento. Ni el JSON ni
 *     el cuerpo del correo: la huella del adjunto basta para comprobar que es el mismo.
 *     Un reenvío es otra fila del mismo documento, no otro documento.
 *   · RELACIÓN NC→CCF: la que DECLARA el `documentoRelacionado` del JSON de la nota, por
 *     código de generación. Todas las que declare, sin elegir una. El CCF destino puede
 *     no estar todavía en el seguimiento; entonces queda sin resolver, visible.
 *
 * No se infiere ninguna relación por importe, OC ni fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobro_documento_procedencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cobro_documento_id')->constrained('cobro_documentos')->cascadeOnDelete();

            // gmail_enviados. Otra fuente futura sería otro valor, no otra tabla.
            $table->string('fuente', 30);
            $table->string('gmail_message_id', 120);
            $table->string('adjunto_nombre', 160)->nullable();
            // sha256 del adjunto tal como llegó: evidencia sin guardar su contenido.
            $table->string('adjunto_hash', 64);
            $table->string('codigo_generacion', 40)->nullable();

            $table->timestamps();

            // Volver a barrer el mismo mensaje no agrega filas.
            $table->unique(['cobro_documento_id', 'gmail_message_id', 'adjunto_hash'], 'cobro_doc_proc_unica');
            $table->index('gmail_message_id');
        });

        Schema::create('cobro_documento_relaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nc_cobro_documento_id')->constrained('cobro_documentos')->cascadeOnDelete();

            // Lo declarado en el JSON de la NC, tal cual (en mayúsculas).
            $table->string('codigo_generacion_relacionado', 40);
            $table->string('tipo_documento_relacionado', 2)->nullable();
            $table->date('fecha_emision_relacionado')->nullable();

            // El CCF del seguimiento con ese código, cuando existe. Null = aún sin resolver.
            $table->foreignId('ccf_cobro_documento_id')->nullable()->constrained('cobro_documentos')->nullOnDelete();

            $table->timestamps();

            $table->unique(['nc_cobro_documento_id', 'codigo_generacion_relacionado'], 'cobro_doc_rel_unica');
            $table->index('codigo_generacion_relacionado');
            $table->index('ccf_cobro_documento_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobro_documento_relaciones');
        Schema::dropIfExists('cobro_documento_procedencias');
    }
};
