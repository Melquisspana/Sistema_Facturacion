<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Albarán de crédito que ORIGINA una nota de crédito. Uno por NC como máximo.
 *
 * Hasta ahora el sistema solo conocía albaranes de ENTREGA (`ppq_albaranes`, tipo AC01),
 * emparejados por orden de compra DESPUÉS de emitir, y para el cobro. Nada registraba
 * «esta NC nació de este albarán AC02 número 2270». El Excel que exige Calleja necesita
 * exactamente eso: cuatro de sus diecisiete columnas salen de acá.
 *
 * El número canónico (`AC04/0033/00/3209`) contiene el tipo, la sala y el número; los tres
 * se guardan además desglosados porque el Excel los pide en columnas separadas y porque un
 * albarán capturado a mano puede llegar sin el número completo.
 *
 * UNICIDAD (por qué no hay índice único sobre `numero_canonico`): un borrador de NC se
 * ELIMINA en blando y una NC puede quedar invalidada o rechazada-archivada; en los tres
 * casos el albarán vuelve a estar libre y debe poder usarse otra vez. Un único de base no
 * distingue esos estados y dejaría el albarán bloqueado para siempre tras un borrador
 * descartado. La regla vive en {@see \App\Models\DteAlbaran::scopeDeNotasVigentes()},
 * reutilizando el MISMO criterio de vigencia que el saldo acreditable
 * ({@see \App\Models\Dte::scopeConsumeSaldoAcreditable()}), para no inventar un segundo
 * concepto de «NC que todavía cuenta». Lo único que sí es estructural —una NC no puede
 * tener dos albaranes— sí va como único de base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dte_albaranes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dte_id')->unique()->constrained('dtes')->cascadeOnDelete();

            // "AC04/0033/00/3209" normalizado en mayúsculas y sin espacios.
            $table->string('numero_canonico', 40);
            $table->string('tipo_codigo', 10);          // AC02 | AC04 | ...
            $table->string('sala_codigo', 10)->nullable();
            $table->string('numero', 20);               // 3209
            $table->date('fecha')->nullable();

            // Total impreso en el albarán, SIEMPRE positivo. El PDF de Calleja lo imprime
            // en negativo porque es un abono; el Excel lo pide positivo, y guardarlo en
            // positivo evita tener dos convenios de signo en la misma tabla.
            $table->decimal('total', 11, 2)->nullable();

            // Enlace opcional al albarán ya ingresado por el módulo PPQ, cuando exista.
            $table->foreignId('ppq_albaran_id')->nullable()
                ->constrained('ppq_albaranes')->nullOnDelete();

            $table->timestamps();

            $table->index('numero_canonico');
            $table->index(['tipo_codigo', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dte_albaranes');
    }
};
