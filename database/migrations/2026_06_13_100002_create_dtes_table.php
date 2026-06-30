<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cabecera del Documento Tributario Electrónico (borrador en esta fase).
 *
 * Las columnas de identificación MH (numero_control, codigo_generacion, sello)
 * y de archivos (json/pdf) quedan NULL hasta las fases de generación y envío.
 * No se genera JSON, firma ni numeración final aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dtes', function (Blueprint $table) {
            $table->id();

            $table->string('tipo_dte', 2)->comment('TipoDte: 01 FC, 03 CCF, 05 NC, 11 FEX');
            $table->string('estado', 20)->default('borrador')->comment('EstadoDte');
            $table->string('ambiente', 2)->default('00')->comment('00 pruebas, 01 produccion');

            // Punto de emisión.
            $table->foreignId('establecimiento_id')->constrained('establecimientos');
            $table->foreignId('punto_venta_id')->nullable()->constrained('puntos_venta')->nullOnDelete();
            $table->foreignId('correlativo_id')->nullable()->constrained('correlativos')->nullOnDelete();

            // Receptor (nullable: factura a consumidor final puede ir sin cliente).
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();

            // Relación: NC/ND apuntan a su documento original.
            $table->foreignId('dte_relacionado_id')->nullable()->constrained('dtes')->nullOnDelete();

            // Identificadores MH — reservados (NULL hasta generación/envío).
            $table->string('numero_control', 31)->nullable()->unique();
            $table->char('codigo_generacion', 36)->nullable()->unique();
            $table->string('sello_recepcion')->nullable();

            // Operación.
            $table->unsignedTinyInteger('condicion_operacion')->default(1)->comment('CondicionPago: 1 contado, 2 credito, 3 otro');
            $table->string('numero_orden_compra')->nullable()->comment('Solo en el documento, nunca en el cliente');
            $table->date('fecha_emision');
            $table->time('hora_emision');
            $table->text('observaciones')->nullable();
            $table->text('motivo')->nullable()->comment('Usado por nota de crédito');
            $table->char('moneda', 3)->default('USD');

            // Totales.
            $table->decimal('total_no_sujeto', 11, 2)->default(0);
            $table->decimal('total_exento', 11, 2)->default(0);
            $table->decimal('total_gravado', 11, 2)->default(0);
            $table->decimal('descuento_no_sujeto', 11, 2)->default(0);
            $table->decimal('descuento_exento', 11, 2)->default(0);
            $table->decimal('descuento_gravado', 11, 2)->default(0);
            $table->decimal('descuento_global', 11, 2)->default(0);
            $table->decimal('total_descuento', 11, 2)->default(0);
            $table->decimal('subtotal', 11, 2)->default(0);
            $table->decimal('iva', 11, 2)->default(0);

            // Retención de IVA: el flag aplicado y el monto final usado quedan en el DTE.
            $table->boolean('aplica_retencion_iva')->default(false);
            $table->decimal('iva_retenido', 11, 2)->default(0);
            $table->decimal('retencion_renta', 11, 2)->default(0);

            $table->decimal('monto_total_operacion', 11, 2)->default(0);
            $table->decimal('total_pagar', 11, 2)->default(0);
            $table->string('total_letras')->nullable();

            // Exportación.
            $table->decimal('flete', 11, 2)->nullable();
            $table->decimal('seguro', 11, 2)->nullable();

            // Archivos — reservados para fases posteriores.
            $table->string('json_generado_path')->nullable();
            $table->string('json_firmado_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->dateTime('fecha_procesamiento_mh')->nullable();

            // Trazabilidad.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('generado_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('enviado_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('invalidado_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('estado');
            $table->index(['tipo_dte', 'fecha_emision']);
            $table->index('cliente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dtes');
    }
};
