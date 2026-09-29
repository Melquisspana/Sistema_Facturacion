<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segunda tanda de fase 1: ajustes de deuda y vínculo con Compras.
 *
 * AJUSTES. Una nota de crédito, una nota de débito o una corrección NO son un
 * pago: no hay salida de dinero. Van en su propia tabla para que los informes
 * puedan decir «saldada por ajuste» y no «pagada», y para que el dinero pagado
 * del período no se contamine con créditos.
 *
 * Se aplican a la CUOTA y no a la obligación entera, a propósito: el pendiente y
 * el vencido ya se calculan cuota por cuota, y un ajuste colgado del gasto
 * obligaría a inventar a qué vencimiento pertenece. Con una sola cuota —el caso
 * normal— es exactamente lo mismo.
 *
 * FUENTES. De dónde salió la obligación o qué la respalda. Un documento de
 * Compras puede originar UNA sola deuda; ese es el candado que evita registrar
 * dos veces la misma factura. Como respaldo, en cambio, puede colgarse de una
 * obligación que ya existía sin generar deuda nueva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gastos_ajustes', function (Blueprint $t) {
            $t->id();
            $t->uuid('clave')->unique();
            $t->foreignId('cuota_id')->constrained('gastos_cuotas')->restrictOnDelete();
            // Importe SIEMPRE positivo con la dirección explícita al lado. Un signo
            // metido dentro del número se lee mal en cuanto alguien lo suma sin mirar.
            $t->string('direccion', 10); // credito | debito
            $t->string('tipo', 30);      // nota_credito | nota_debito | correccion
            $t->decimal('importe', 14, 2);
            $t->string('motivo', 500);
            $t->foreignId('documento_recibido_id')->nullable()->constrained('documentos_recibidos')->nullOnDelete();
            $t->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $t->timestamp('revertido_at')->nullable();
            $t->foreignId('revertido_por')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('motivo_reversion', 500)->nullable();
            $t->timestamps();

            $t->index(['cuota_id', 'revertido_at']);
        });

        Schema::create('gastos_fuentes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('gasto_id')->constrained('gastos')->cascadeOnDelete();
            $t->foreignId('documento_recibido_id')->constrained('documentos_recibidos')->restrictOnDelete();
            $t->string('papel', 20); // deuda | respaldo

            // Un documento origina UNA deuda y nada más. La columna vale el id del
            // documento cuando el papel es «deuda» y NULL en cualquier otro caso; como
            // los NULL no chocan en un índice único (ni en MySQL ni en SQLite), esto da
            // «único solo para deuda» sin índices parciales, que no son portables.
            $t->unsignedBigInteger('deuda_unica')->nullable()->unique();

            // Fotografía de lo que se reutilizó al vincular: emisor, número, fecha y
            // total tal como estaban ese día. Si Compras se resincroniza, el gasto
            // conserva lo que su operador vio.
            $t->json('snapshot');
            $t->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $t->timestamps();

            $t->unique(['gasto_id', 'documento_recibido_id']);
            $t->index('documento_recibido_id');
        });

        Schema::table('gastos_eventos', function (Blueprint $t) {
            $t->foreignId('ajuste_id')->nullable()->after('pago_id')->constrained('gastos_ajustes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gastos_eventos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('ajuste_id');
        });

        Schema::dropIfExists('gastos_fuentes');
        Schema::dropIfExists('gastos_ajustes');
    }
};
