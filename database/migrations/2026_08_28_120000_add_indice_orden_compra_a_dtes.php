<?php

use App\Http\Controllers\Ppq\PpqBusquedaController;
use App\Services\Ppq\SalaResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índice sobre `dtes.numero_orden_compra`.
 *
 * NO es un índice por intuición: cubre consultas de IGUALDAD que ya existen y que hoy
 * recorren la tabla entera de documentos.
 *
 *  1. {@see SalaResolver::nombre()}
 *     `where('numero_orden_compra', $oc)` — resuelve el nombre comercial de la sala de
 *     un documento que vino de Gmail. Se ejecuta por cada ficha sin nombre de sala.
 *  2. {@see PpqBusquedaController::ccfRelacionadosPorOc()}
 *     `where('tipo_dte','03')->whereIn('numero_orden_compra', $ocs)` — el CCF original
 *     que comparte OC con una nota de crédito.
 *
 * Lo que este índice NO acelera, y conviene dejarlo dicho para que nadie espere de él
 * lo que no puede dar: las búsquedas por texto de PPQ usan `LIKE '%…%'`, y un comodín
 * al principio no puede aprovechar ningún índice B-tree. La OC como FILTRO de texto
 * seguirá costando lo mismo; lo que baja es la resolución por OC exacta.
 *
 * Solo agrega un índice: no toca datos, ni columnas, ni la emisión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->index('numero_orden_compra', 'dtes_numero_orden_compra_index');
        });
    }

    public function down(): void
    {
        Schema::table('dtes', function (Blueprint $table) {
            $table->dropIndex('dtes_numero_orden_compra_index');
        });
    }
};
