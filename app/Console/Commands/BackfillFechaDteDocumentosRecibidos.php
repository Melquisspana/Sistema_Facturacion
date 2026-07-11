<?php

namespace App\Console\Commands;

use App\Models\DocumentoRecibido;
use App\Services\DocumentosRecibidos\ParserDocumentoRecibido;
use App\Services\Ppq\JsonAdjuntoDecoder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Rellena documentos_recibidos.fecha_dte de registros VIEJOS usando el JSON ya
 * guardado localmente (identificacion.fecEmi). SOLO LECTURA de disco: NO se conecta
 * a Yahoo/IMAP, NO cambia fecha_correo ni estado, NO toca adjuntos, NO crea
 * duplicados. Idempotente: solo mira registros con fecha_dte NULL.
 */
class BackfillFechaDteDocumentosRecibidos extends Command
{
    protected $signature = 'documentos-recibidos:backfill-fecha-dte {--dry-run : Solo reporta, no guarda}';

    protected $description = 'Rellena fecha_dte de documentos recibidos viejos desde el JSON ya guardado localmente (sin tocar Yahoo).';

    public function handle(JsonAdjuntoDecoder $decoder, ParserDocumentoRecibido $parser): int
    {
        $seco = (bool) $this->option('dry-run');
        $revisados = $actualizados = $sinJson = $sinFecha = $errores = 0;

        DocumentoRecibido::whereNull('fecha_dte')->orderBy('id')->chunkById(200, function ($docs) use (
            $decoder, $parser, $seco, &$revisados, &$actualizados, &$sinJson, &$sinFecha, &$errores
        ) {
            foreach ($docs as $doc) {
                $revisados++;
                try {
                    $fecha = $this->fechaDeMetadata($doc) ?? $this->fechaDeJson($doc, $decoder, $parser);

                    if ($fecha === 'SIN_JSON') {
                        $sinJson++;

                        continue;
                    }
                    $carbon = $fecha ? rescue(fn () => Carbon::parse($fecha), null, false) : null;
                    if ($carbon === null) {
                        $sinFecha++;

                        continue;
                    }

                    if (! $seco) {
                        // Actualiza SOLO fecha_dte; no toca fecha_correo, estado ni adjuntos.
                        $doc->fecha_dte = $carbon->toDateString();
                        $doc->save();
                    }
                    $actualizados++;
                } catch (Throwable $e) {
                    $errores++;
                    $this->warn("  #{$doc->id}: {$e->getMessage()}");
                }
            }
        });

        $this->info(($seco ? '[DRY-RUN] ' : '').'Backfill fecha_dte de documentos recibidos:');
        $this->table(['Métrica', 'Cantidad'], [
            ['Revisados (fecha_dte NULL)', $revisados],
            ['Actualizados', $actualizados],
            ['Sin JSON guardado', $sinJson],
            ['Sin fecha en el JSON', $sinFecha],
            ['Errores', $errores],
        ]);

        return self::SUCCESS;
    }

    /** Si metadata_json ya trae una fecha del DTE parseada, usarla. */
    private function fechaDeMetadata(DocumentoRecibido $doc): ?string
    {
        foreach (['fecha_dte', 'fecEmi', 'fecha'] as $clave) {
            $v = data_get($doc->metadata_json, $clave);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }

    /**
     * Lee el JSON guardado en disco y extrae la fecha (fecEmi). Devuelve la fecha,
     * null si el JSON existe pero no trae fecha, o 'SIN_JSON' si no hay archivo.
     */
    private function fechaDeJson(DocumentoRecibido $doc, JsonAdjuntoDecoder $decoder, ParserDocumentoRecibido $parser): ?string
    {
        $rutaJson = null;
        foreach ((array) data_get($doc->metadata_json, 'archivos', []) as $ruta) {
            if (is_string($ruta) && str_ends_with(strtolower($ruta), '.json') && Storage::disk('local')->exists($ruta)) {
                $rutaJson = $ruta;
                break;
            }
        }
        if ($rutaJson === null) {
            return 'SIN_JSON';
        }

        $dec = $decoder->decodificar((string) Storage::disk('local')->get($rutaJson));
        if (empty($dec['ok']) || ! is_array($dec['data'])) {
            return null;
        }

        return $parser->extraer($dec['data'])['fecha'] ?? null;
    }
}
