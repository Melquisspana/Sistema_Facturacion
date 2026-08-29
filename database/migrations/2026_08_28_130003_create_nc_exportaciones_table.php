<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOTE de notas de crédito exportadas al cliente: un archivo con una fila por NC.
 *
 * Un lote NO es un corte diario. Las notas se acumulan durante los días o semanas que
 * haga falta y se exportan cuando toca llenar el formato, así que un mismo archivo puede
 * mezclar notas emitidas en fechas distintas. Lo único que agrupa a un lote es la
 * decisión de generarlo.
 *
 * DELIBERADAMENTE SIN COLUMNA `fecha`: el momento en que se generó el archivo ya lo dice
 * `created_at`, y una segunda columna con ese mismo significado solo abre la puerta a que
 * las dos digan cosas distintas. La fecha de las NOTAS vive en cada `dtes.fecha_emision`,
 * que es donde corresponde: no hay una fecha del lote que las represente a todas.
 *
 * Las dos tablas van juntas porque una no significa nada sin la otra. El único de
 * `dte_id` en los items es el que garantiza lo que pidió operación: una NC entra en un
 * lote y en uno solo, así que no puede colarse dos veces en dos archivos distintos.
 *
 * Regenerar un lote NO vuelve a elegir documentos: relee sus items y vuelve a dibujar el
 * archivo. Como las NC exportadas están aceptadas —y por tanto son inmutables—, el
 * archivo regenerado es idéntico al original sin necesidad de congelar una copia de los
 * valores.
 *
 * El lote se registra como GENERADO y, al bajarlo, como DESCARGADO. Nunca como «enviado»:
 * el correo al cliente se manda hoy a mano, fuera del sistema, y llamar «enviado» a un
 * archivo que quizá nadie adjuntó haría indistinguible un lote entregado de uno olvidado
 * en la carpeta de descargas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nc_exportaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('referencia', 60)->unique();
            $table->string('formato', 40);
            $table->string('archivo_nombre', 120);

            // Estado REAL del lote: generado | descargado. No existe «enviado», y no es
            // un olvido: el envío al cliente se hace hoy fuera del sistema y no tenemos
            // evidencia de él. Ver App\Enums\EstadoNcExportacion.
            $table->string('estado', 20)->default('generado');
            $table->timestamp('descargado_en')->nullable();
            $table->unsignedInteger('descargas')->default(0);

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // El historial se lee por cliente y de lo más nuevo a lo más viejo.
            $table->index(['cliente_id', 'created_at']);
        });

        Schema::create('nc_exportacion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nc_exportacion_id')->constrained('nc_exportaciones')->cascadeOnDelete();
            // Único GLOBAL, no por lote: una NC exportada no vuelve a estar disponible.
            $table->foreignId('dte_id')->unique()->constrained('dtes')->cascadeOnDelete();
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nc_exportacion_items');
        Schema::dropIfExists('nc_exportaciones');
    }
};
