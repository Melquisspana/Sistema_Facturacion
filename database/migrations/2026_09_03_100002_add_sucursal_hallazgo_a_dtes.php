<?php

use App\Enums\OrigenAveria;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sala donde se ENCONTRÓ el producto averiado, cuando la avería no salió de una entrega
 * sino de una revisión de inventario ({@see OrigenAveria::InventarioSala}).
 *
 * No es la sala receptora de la nota —esa sigue siendo `cliente_sucursal_id`, heredada
 * del CCF— sino el lugar del hallazgo. Las dos pueden diferir: se revisa el estante de
 * una sala y el producto se factura en un CCF de otra sala del mismo cliente. Cuando
 * difieren, el formulario avisa y exige motivo; el cliente nunca puede cambiar.
 *
 * Nullable porque solo lo lleva ese caso: la avería durante una entrega y las otras tres
 * modalidades lo dejan en null, igual que las notas ya emitidas. Es trazabilidad, no un
 * valor fiscal: no entra al JSON del MH ni toca descuento, retención o totales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->foreignId('sucursal_hallazgo_id')
                ->nullable()
                ->after('origen_averia')
                ->constrained('cliente_sucursales')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sucursal_hallazgo_id');
        });
    }
};
