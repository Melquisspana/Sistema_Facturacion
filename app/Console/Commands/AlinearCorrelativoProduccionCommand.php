<?php

namespace App\Console\Commands;

use App\Models\Correlativo;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Alinea el CONTADOR de correlativo de PRODUCCIÓN (CCF 03, ambiente 01, serie
 * M001P001) para que el próximo número real continúe la numeración de Conta Portable.
 *
 * SEGURIDAD:
 *  - Por defecto corre en DRY-RUN: no escribe nada. Aplicar de verdad exige --aplicar
 *    Y la frase exacta "ALINEAR CORRELATIVO {proximo}".
 *  - Solo toca la fila del contador (tabla correlativos). NUNCA toca documentos DTE
 *    (dtes), ni el CCF 1078, ni el ambiente 00 (pruebas), ni emite/transmite/envía nada.
 *  - No abre candados ni cambia .env.
 */
class AlinearCorrelativoProduccionCommand extends Command
{
    protected $signature = 'dte:alinear-correlativo-produccion
        {--tipo=03 : Tipo de DTE (debe ser 03 = CCF)}
        {--ambiente=01 : Ambiente MH (debe ser 01 = producción)}
        {--serie=M001P001 : Serie del correlativo (debe ser M001P001)}
        {--ultimo= : Último CCF real EXTERNO confirmado en Conta Portable (p. ej. 1093)}
        {--aplicar : Aplica el cambio de verdad (además exige la frase exacta)}
        {--frase= : Frase exacta de confirmación: ALINEAR CORRELATIVO {proximo}}
        {--dry-run : Fuerza modo simulación (no escribe, aunque se pase --aplicar)}';

    protected $description = 'Alinea el correlativo de producción CCF M001P001 (dry-run por defecto; aplicar exige frase exacta)';

    public function handle(): int
    {
        $tipo = (string) $this->option('tipo');
        $ambiente = (string) $this->option('ambiente');
        $serie = (string) $this->option('serie');
        $ultimoRaw = $this->option('ultimo');

        // --- Validaciones de identidad (solo producción CCF M001P001) ---
        if ($tipo !== '03') {
            $this->error('El tipo debe ser 03 (CCF). Recibido: '.$tipo.'. Abortado.');

            return self::FAILURE;
        }
        if ($ambiente !== '01') {
            $this->error('El ambiente debe ser 01 (producción). Recibido: '.$ambiente.'. Abortado (no se toca pruebas 00).');

            return self::FAILURE;
        }
        $serie = strtoupper($serie);
        if ($serie !== 'M001P001') {
            $this->error('La serie debe ser M001P001. Recibida: '.$serie.'. Abortado.');

            return self::FAILURE;
        }
        if (! is_numeric($ultimoRaw) || (int) $ultimoRaw <= 0) {
            $this->error('Debés indicar --ultimo con el último CCF real externo (entero positivo, p. ej. 1093). Abortado.');

            return self::FAILURE;
        }
        $externo = (int) $ultimoRaw;

        // La "serie" M001P001 = código de establecimiento (M001) + punto de venta (P001).
        // En este emisor la columna `serie` está en NULL: el correlativo se identifica por
        // establecimiento + punto de venta (4+4 chars del número de control), NO por `serie`.
        $estabCodigo = substr($serie, 0, 4);
        $pvCodigo = substr($serie, 4);
        $estab = Establecimiento::where('codigo', $estabCodigo)->first();
        $pv = PuntoVenta::where('codigo', $pvCodigo)->first();
        if ($estab === null || $pv === null) {
            $this->error("No se encontró establecimiento {$estabCodigo} y/o punto de venta {$pvCodigo}. Abortado.");

            return self::FAILURE;
        }

        // --- Fila del contador de producción (localizada por estab + PV, no por serie) ---
        $corr = Correlativo::where('tipo_dte', $tipo)
            ->where('ambiente', $ambiente)
            ->where('establecimiento_id', $estab->id)
            ->where('punto_venta_id', $pv->id)
            ->where('activo', true)
            ->first();

        if ($corr === null) {
            $this->error("No se encontró el correlativo activo tipo 03, ambiente 01, {$estabCodigo}{$pvCodigo} (establecimiento {$estabCodigo} / punto de venta {$pvCodigo}). Abortado.");

            return self::FAILURE;
        }

        $internoActual = (int) $corr->ultimo_numero;
        $internoProximo = $internoActual + 1;
        $proximoResultante = $externo + 1;

        // El externo debe ir por DELANTE del interno (si no, no hay nada que alinear o se retrocedería).
        if ($externo <= $internoActual) {
            $this->error("El último externo ({$externo}) debe ser MAYOR que el último interno actual ({$internoActual}). Abortado (no se retrocede ni duplica).");

            return self::FAILURE;
        }

        // --- Reporte ANTES ---
        $this->line('Alineación de correlativo de PRODUCCIÓN — CCF M001P001');
        $this->newLine();
        $this->dato('Tipo DTE', $tipo.' (CCF)');
        $this->dato('Ambiente', $ambiente.' (producción)');
        $this->dato('Serie (estab+PV)', $serie.' (establecimiento '.$estabCodigo.' / punto de venta '.$pvCodigo.')');
        $this->dato('Último ACTUAL en tabla (interno)', (string) $internoActual);
        $this->dato('Próximo ACTUAL calculado (interno)', (string) $internoProximo.'  (DESACTUALIZADO)');
        $this->dato('Último EXTERNO solicitado (Conta)', (string) $externo);
        $this->dato('Próximo RESULTANTE esperado', (string) $proximoResultante);
        $this->newLine();

        $fraseEsperada = 'ALINEAR CORRELATIVO '.$proximoResultante;

        // Aplicar de verdad SOLO con --aplicar, sin --dry-run, y con la frase exacta.
        $aplicarReal = (bool) $this->option('aplicar') && ! (bool) $this->option('dry-run');

        if (! $aplicarReal) {
            $this->warn('DRY RUN: no se modificó ningún correlativo.');
            $this->line('Para aplicar de verdad: --aplicar --frase="'.$fraseEsperada.'"');

            return self::SUCCESS;
        }

        // Doble confirmación: --aplicar (ya) + frase exacta.
        $frase = trim((string) $this->option('frase'));
        if ($frase !== $fraseEsperada) {
            $this->error('Frase de confirmación incorrecta. Para aplicar escribí exactamente: --frase="'.$fraseEsperada.'". No se modificó nada.');

            return self::FAILURE;
        }

        // --- Aplicación real (transacción; solo la fila del contador de producción) ---
        // Snapshot de guardas: ambiente 00 y documentos DTE NO deben cambiar.
        $ambiente00Antes = (int) (Correlativo::where('tipo_dte', $tipo)->where('ambiente', '00')->value('ultimo_numero') ?? -1);
        $dtesAntes = (int) DB::table('dtes')->count();

        DB::transaction(function () use ($corr, $externo) {
            // Actualiza SOLO la fila de producción localizada (tipo 03 / ambiente 01 / serie M001P001).
            $corr->ultimo_numero = $externo;
            $corr->save();
        });

        // Auditoría (spatie/activitylog): queda registro de quién/qué/cuándo.
        activity('correlativo_produccion')
            ->withProperties([
                'tipo' => $tipo, 'ambiente' => $ambiente, 'serie' => $serie,
                'interno_anterior' => $internoActual,
                'ultimo_nuevo' => $externo,
                'proximo_resultante' => $proximoResultante,
                'origen' => 'dte:alinear-correlativo-produccion',
            ])
            ->log("Alineó el correlativo de producción CCF {$serie} a último={$externo} (próximo {$proximoResultante})");

        // --- Verificación DESPUÉS ---
        $corr->refresh();
        $ambiente00Despues = (int) (Correlativo::where('tipo_dte', $tipo)->where('ambiente', '00')->value('ultimo_numero') ?? -1);
        $dtesDespues = (int) DB::table('dtes')->count();

        $this->newLine();
        $this->info('APLICADO dentro de transacción.');
        $this->dato('Último AHORA en tabla', (string) $corr->ultimo_numero);
        $this->dato('Próximo AHORA calculado', (string) $corr->siguiente_numero);
        $this->dato('Ambiente 00 (pruebas) intacto', $ambiente00Antes === $ambiente00Despues ? 'sí ('.$ambiente00Despues.')' : 'NO — REVISAR');
        $this->dato('Documentos DTE intactos', $dtesAntes === $dtesDespues ? 'sí ('.$dtesDespues.')' : 'NO — REVISAR');
        $this->newLine();
        $this->warn('*** Solo se cambió el CONTADOR. No se emitió/transmitió/envió nada; no se tocó ningún documento DTE. ***');

        return self::SUCCESS;
    }

    private function dato(string $etiqueta, string $valor): void
    {
        $this->line('  '.str_pad($etiqueta, 36).' : '.$valor);
    }
}
