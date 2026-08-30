<?php

use App\Support\VigenciaFiscalDte;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMBIENTE del documento en la bitácora de ruta. Es la mitad que le faltaba a la identidad
 * fiscal.
 *
 * ─────────────────────────── Por qué hace falta ───────────────────────────
 *
 * `numero_control` dejó de ser único en `dtes` cuando la unicidad pasó a ser
 * `(ambiente, numero_control)` —migración 2026_07_17_150000—, y por un motivo correcto:
 * los correlativos de PRUEBAS (00) y de PRODUCCIÓN (01) cuentan desde cero de forma
 * independiente, así que el primer documento real de una serie coincide exactamente con
 * uno de prueba ya emitido en esa misma posición. Rutas siguió tratando el número de
 * control como identidad completa, y eso deja al documento de pruebas capaz de ocultar,
 * bloquear o prestarle datos al de producción.
 *
 * Guardar el ambiente junto al número cierra esa ambigüedad allí donde el documento se
 * MUESTRA y se BUSCA. Es un snapshot, igual que el resto de columnas de esta tabla: se
 * copia del DTE al asignarlo y no se vuelve a tocar.
 *
 * NULL es un valor válido y esperado: los documentos HISTÓRICOS P001, que no están en
 * `dtes`, no tienen ambiente que copiar. No se les inventa uno; «no consta» se dice
 * diciendo NULL.
 *
 * ──────────── Por qué el índice único NO cambia (y no es un olvido) ────────────
 *
 * Sería tentador ampliar `srd_documento_unico_vigente` a (numero_control, ambiente,
 * bloqueo_asignacion). Sería un error, y conviene dejarlo escrito para que nadie lo
 * «arregle» más adelante:
 *
 *  - MySQL y SQLite consideran distintos entre sí los NULL de un índice único, así que
 *    en cuanto `ambiente` entrara al índice, los históricos P001 —todos con ambiente
 *    NULL— dejarían de excluirse entre sí. Dos filas del mismo documento histórico
 *    podrían convivir en dos salidas abiertas. Se perdería una garantía real para cubrir
 *    un caso que ya no puede ocurrir.
 *
 *  - Ya no puede ocurrir porque el riesgo se cierra antes, en la puerta: desde esta misma
 *    fase, a una salida de ruta solo entra un CCF FISCALMENTE VIGENTE
 *    ({@see VigenciaFiscalDte}), y eso excluye por completo el ambiente de
 *    pruebas. Un documento de pruebas no llega a tener fila acá.
 *
 *  - Y el índice actual falla del lado SEGURO: puede sobrar bloqueo, nunca faltar. Un
 *    candado de más obliga a mover un documento a mano; uno de menos pinta «entregado» o
 *    «pagado» sobre algo que nadie entregó ni pagó.
 *
 * La columna sirve entonces para IDENTIFICAR y MOSTRAR —y para que las consultas que
 * resuelven un DTE desde un número de control puedan hacerlo sin ambigüedad—, no para
 * bloquear.
 *
 * Aditiva y sin pérdida: agrega una columna nullable y rellena lo que se puede leer del
 * propio DTE ya vinculado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salida_ruta_documentos', function (Blueprint $table) {
            $table->char('ambiente', 2)->nullable()->after('origen')
                ->comment('Ambiente MH del DTE (00 pruebas / 01 producción). NULL en históricos P001: no consta');
        });

        // Relleno desde el DTE ya vinculado. Sin subconsulta correlacionada en el UPDATE
        // —MySQL y SQLite no la escriben igual—: se recorre por lotes y se actualiza por
        // valor, que es portable y, a estos volúmenes, indistinguible en costo.
        DB::table('salida_ruta_documentos')
            ->select('id', 'dte_id')
            ->whereNotNull('dte_id')
            ->orderBy('id')
            ->chunkById(500, function ($filas) {
                $ambientes = DB::table('dtes')
                    ->whereIn('id', $filas->pluck('dte_id')->all())
                    ->pluck('ambiente', 'id');

                foreach ($filas as $fila) {
                    $ambiente = $ambientes[$fila->dte_id] ?? null;

                    if ($ambiente !== null) {
                        DB::table('salida_ruta_documentos')->where('id', $fila->id)->update(['ambiente' => $ambiente]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('salida_ruta_documentos', function (Blueprint $table) {
            $table->dropColumn('ambiente');
        });
    }
};
