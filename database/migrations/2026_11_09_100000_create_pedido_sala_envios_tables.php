<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que la fábrica manda de cada pedido de sala (etapa 1, PR 11). Todo es aditivo: las
 * migraciones de pedidos ya están desplegadas y no se editan.
 *
 *  - `pedido_sala_envios`: cada salida de la fábrica, numerada 1, 2, 3… por pedido. Un
 *    envío no se borra: se anula (`anulado_en`, `anulado_por`) y conserva su número. La
 *    `clave` (uuid) evita que un doble toque cree dos envíos. La etapa 2 lo enlaza al CCF.
 *  - `pedido_sala_envio_lineas`: qué productos llevó y cuánto. Apunta a la LÍNEA del
 *    pedido, así la base garantiza que el producto es de ese pedido, y es RESTRICT: la base
 *    no deja borrar un envío con sus líneas ni la línea de un pedido que ya salió.
 *  - `pedido_sala_lineas.va_borrador` / `motivo_borrador`: lo que la fábrica va marcando
 *    en la hoja «Qué va» antes de mandar; se guarda solo y se limpia al mandar o cerrar. El
 *    motivo del borrador va aparte de `motivo_no_enviado` (que ya existe y es el motivo
 *    OFICIAL del faltante vigente): si compartieran columna, marcar o borrar lo marcado
 *    pisaría el motivo de un envío anterior.
 *  - `pedidos_sala.armando_desde` / `armando_por`: desde cuándo y quién empezó a armarlo.
 *    También frena la corrección del vendedor: la fábrica ya empezó. `armando_sigue`: la
 *    casilla «Lo que falta va en otro envío» de lo marcado.
 *
 * `pedido_sala_lineas.cantidad_enviada` queda sin uso (en NULL): lo mandado sale solo de
 * la suma de los envíos vigentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_sala_envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_sala_id')->constrained('pedidos_sala')->restrictOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->uuid('clave')->unique();
            // Fecha de negocio (HoraNegocio::fechaHoy()); la pone el servidor.
            $table->date('fecha');
            $table->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $table->timestamp('anulado_en')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // También sirve de índice de la FK del pedido.
            $table->unique(['pedido_sala_id', 'numero']);
            $table->index('fecha');
        });

        Schema::create('pedido_sala_envio_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_sala_envio_id')->constrained('pedido_sala_envios')->restrictOnDelete();
            $table->foreignId('pedido_sala_linea_id')->constrained('pedido_sala_lineas')->restrictOnDelete();
            // Siempre mayor que 0: un producto que no fue no tiene fila.
            $table->decimal('cantidad', 11, 4);

            // Nombre corto a propósito: el automático tiene 73 caracteres y MySQL acepta 64.
            $table->unique(['pedido_sala_envio_id', 'pedido_sala_linea_id'], 'pse_lineas_envio_linea_unique');
        });

        Schema::table('pedido_sala_lineas', function (Blueprint $table) {
            $table->decimal('va_borrador', 11, 4)->nullable()->after('motivo_no_enviado');
            $table->string('motivo_borrador', 200)->nullable()->after('va_borrador');
        });

        Schema::table('pedidos_sala', function (Blueprint $table) {
            $table->timestamp('armando_desde')->nullable()->after('estado');
            $table->foreignId('armando_por')->nullable()->after('armando_desde')->constrained('users')->restrictOnDelete();
            $table->boolean('armando_sigue')->default(false)->after('armando_por');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos_sala', function (Blueprint $table) {
            $table->dropConstrainedForeignId('armando_por');
            $table->dropColumn(['armando_desde', 'armando_sigue']);
        });

        Schema::table('pedido_sala_lineas', function (Blueprint $table) {
            $table->dropColumn(['va_borrador', 'motivo_borrador']);
        });

        // Primero las líneas y después la cabecera.
        Schema::dropIfExists('pedido_sala_envio_lineas');
        Schema::dropIfExists('pedido_sala_envios');
    }
};
