<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `numero_control` es el número OFICIAL de Hacienda (formato rígido
 * DTE-{tipo}-{estab}{pv}-{correlativo}, normado por el MH: no se puede
 * insertar el ambiente en el formato). Pero el correlativo de cada AMBIENTE
 * (00 pruebas / 01 producción) cuenta desde cero de forma INDEPENDIENTE (son
 * filas distintas de `correlativos`), mientras que `numero_control` era único
 * a nivel GLOBAL en la tabla `dtes` (sin importar el ambiente). Eso hace que
 * el primer documento REAL de un tipo pueda coincidir exactamente con un
 * documento de PRUEBA ya generado en esa misma posición (visto realmente con
 * la FEX #131: DTE #100 en ambiente 00 ya tiene "DTE-11-M001P001-...001", el
 * mismo valor que produciría la primera FEX de producción).
 *
 * Ambiente 00 (apitest) y ambiente 01 (producción) son sistemas de Hacienda
 * completamente separados: no existe ningún requisito oficial de que
 * numero_control sea distinto entre ambos. Se acota la unicidad a
 * (ambiente, numero_control) en vez de global.
 *
 * NO cambia el formato del número (sigue siendo válido contra el esquema del
 * MH), NO toca documentos existentes, NO reasigna numero_control a ningún DTE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropUnique(['numero_control']);
            $table->unique(['ambiente', 'numero_control']);
        });
    }

    public function down(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropUnique(['ambiente', 'numero_control']);
            $table->unique(['numero_control']);
        });
    }
};
