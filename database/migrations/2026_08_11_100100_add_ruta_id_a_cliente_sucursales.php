<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ruta HABITUAL de una sala. Puramente aditivo: una columna nullable.
 *
 * Qué significa: «cuando se recorre esta ruta, normalmente se pasa por esta
 * sala». Qué NO significa:
 *
 *  - NO es participación en una salida concreta. Que una sala tenga ruta no la
 *    mete en ninguna salida, y una salida podrá visitar salas de otra ruta.
 *  - NO es un dato fiscal. No interviene en DTE, correlativos, retención ni
 *    conciliación; el módulo de Rutas es comercial.
 *  - NO es obligatorio. `null` es un estado válido y esperado: hay salas que no
 *    entran en ninguna ruta.
 *
 * `nullOnDelete`: borrar una ruta deja las salas sin ruta habitual, nunca borra
 * salas. Las sucursales dadas de baja (soft delete) no se tocan: esta migración
 * solo agrega la columna y no escribe ni un valor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cliente_sucursales', function (Blueprint $table) {
            $table->foreignId('ruta_id')->nullable()->after('cliente_id')
                ->constrained('rutas')->nullOnDelete()
                ->comment('Ruta habitual de visita/cobro. No implica participación en una salida.');
        });
    }

    public function down(): void
    {
        Schema::table('cliente_sucursales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ruta_id');
        });
    }
};
