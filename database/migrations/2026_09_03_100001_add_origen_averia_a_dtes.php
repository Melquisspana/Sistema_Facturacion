<?php

use App\Enums\OrigenAveria;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Origen operativo de una nota de crédito por AVERÍA ({@see OrigenAveria}):
 * si el producto dañado se detectó durante una entrega o revisando inventario en sala.
 *
 * Nullable a propósito: solo lo llevan las NC por avería. Las notas por avería que ya
 * existen quedan en null —no se inventa un origen que nadie declaró— y siguen
 * funcionando igual; el formulario solo exige el dato en las nuevas.
 *
 * Es trazabilidad, no un valor fiscal: no entra al JSON del MH ni participa en el
 * descuento, la retención o los totales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->string('origen_averia', 30)->nullable()->after('tipo_nota_credito');
        });
    }

    public function down(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropColumn('origen_averia');
        });
    }
};
