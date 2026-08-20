<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de comprobaciones de configuración: "¿esto funciona de verdad?".
 *
 * POR QUÉ UNA TABLA APARTE Y NO COLUMNAS EN `ajustes_sistema`
 * ------------------------------------------------------------------
 * Son dos cosas distintas con vidas distintas:
 *
 *  - `ajustes_sistema` guarda lo que una PERSONA decidió. Se edita, es una fila
 *    por clave, y su `updated_at` significa "cuándo se cambió el valor".
 *  - esto guarda lo que el SISTEMA observó. Se añade, nunca se edita, y hay
 *    muchas filas por clave (el historial es justamente lo útil: "falla desde
 *    ayer" es información que una sola columna no puede dar).
 *
 * Mezclarlas obligaría a tocar la fila del ajuste cada vez que se prueba una
 * conexión —moviendo su `updated_at` sin que nadie haya cambiado nada, y
 * rompiendo la comprobación optimista de concurrencia— y a añadir columnas que
 * para la mayoría de las claves estarían siempre vacías.
 *
 * `clave` NO es una clave del registry: es el nombre del SERVICIO comprobado
 * ('smtp', 'hacienda', 'firmador', 'gmail', 'imap'), porque una comprobación
 * valida un conjunto de ajustes a la vez, no uno solo.
 *
 * `mensaje` guarda un texto YA SANEADO por quien registra. Nunca una excepción
 * completa: el mensaje de un fallo de autenticación SMTP puede incluir el
 * usuario y, según el servidor, parte del intercambio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verificaciones_configuracion', function (Blueprint $table) {
            $table->id();

            // Servicio comprobado. 64 basta y mantiene el índice pequeño.
            $table->string('clave', 64);

            // 'exito' | 'fallo'. Ver App\Ajustes\Verificaciones\ResultadoVerificacion.
            $table->string('resultado', 16);

            // Texto saneado y acotado. No es un log de excepciones.
            $table->string('mensaje', 500)->nullable();

            // Quién la disparó. Null = comprobación automática/programada.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Solo created_at: la fila nunca se modifica.
            $table->timestamp('created_at')->nullable();

            // "La última comprobación de smtp": el índice compuesto la resuelve
            // sin escanear el historial completo.
            $table->index(['clave', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verificaciones_configuracion');
    }
};
