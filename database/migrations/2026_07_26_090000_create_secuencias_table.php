<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contadores GLOBALES del sistema, independientes de los correlativos fiscales del MH
 * (tabla `correlativos`, que es por tipo/establecimiento/punto de venta/ambiente y NO se
 * toca acá).
 *
 * Existe para poder tomar el siguiente número con un bloqueo de fila real
 * (SELECT … FOR UPDATE) en vez de MAX()+1, que bajo concurrencia entrega el mismo número
 * a dos procesos.
 *
 * Nace con la clave `numero_sistema`: la numeración COMERCIAL única y compartida por
 * todos los tipos de documento (CCF 03, NC 05, factura 01 y FEX 11) de PRODUCCIÓN.
 * Arranca en 0 → el primer documento tomaría el 1. El backfill de los documentos de
 * producción ya emitidos (comando `dte:numero-sistema-backfill`) es el que deja este
 * contador en el número que corresponda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secuencias', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 60)->unique()
                ->comment('Identificador del contador, p. ej. numero_sistema');
            $table->unsignedBigInteger('ultimo_numero')->default(0)
                ->comment('Último número ENTREGADO; el siguiente es este + 1');
            $table->timestamps();
        });

        // El contador nace explícitamente en 0 para que el primer `siguiente()` no
        // dependa de crear la fila al vuelo (y de paso deja el registro auditable).
        DB::table('secuencias')->insert([
            'clave' => 'numero_sistema',
            'ultimo_numero' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('secuencias');
    }
};
