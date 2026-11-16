<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Primera etapa de la tienda (aprobada el 16/11/2026): conservar el pedido pagado y
 * su enlace con fábrica antes de informar «preparando». web_id es la identidad de
 * importación; ni una respuesta perdida ni una nueva pasada deben duplicarlo.
 * Los campos personales cifrados necesitan TEXT, no el tamaño del texto original.
 * El estado es texto validado por EstadoPedidoWeb: facturación llega en otra etapa.
 * Revertir con pedidos guardados perdería el pago y la identidad: se aborta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos_web', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('web_id')->unique('pw_web_uq');
            $table->string('codigo', 30)->unique('pw_codigo_uq');
            $table->string('idioma', 2);
            $table->dateTime('pagado_en');
            $table->string('alerta', 30)->default('');
            $table->unsignedBigInteger('total_centavos');
            $table->string('moneda', 3);
            $table->string('cliente_nombre', 100);
            $table->string('cliente_correo');
            $table->text('cliente_telefono');
            $table->string('entrega_tipo', 10);
            $table->string('departamento_codigo', 2)->nullable();
            $table->string('municipio', 100)->nullable();
            $table->text('direccion')->nullable();
            $table->text('notas')->nullable();
            $table->text('regalo')->nullable();
            $table->json('cajitas_detalle');
            $table->json('lineas');
            $table->string('pasarela', 30);
            $table->string('referencia')->nullable();
            $table->string('estado', 20);
            $table->text('motivo_revision')->nullable();
            $table->foreignId('pedido_sala_id')->nullable();
            $table->foreign('pedido_sala_id', 'pw_pedido_fk')->references('id')->on('pedidos_sala')->restrictOnDelete();
            $table->dateTime('informado_preparando_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('pedidos_web')->exists()) {
            throw new RuntimeException('Hay pedidos web: no se puede perder su historial al revertir.');
        }
        Schema::dropIfExists('pedidos_web');
    }
};
