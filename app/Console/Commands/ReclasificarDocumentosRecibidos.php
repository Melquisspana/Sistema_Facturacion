<?php

namespace App\Console\Commands;

use App\Models\DocumentoRecibido;
use App\Services\DocumentosRecibidos\ClasificadorDocumentoRecibido;
use App\Services\DocumentosRecibidos\ParserDocumentoRecibido;
use App\Services\Ppq\JsonAdjuntoDecoder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Reclasifica documentos_recibidos EXISTENTES (dte_valido/no_es_dte/json_invalido/
 * tipo_no_soportado/falta_adjunto) usando lo que YA está guardado en disco y en
 * `metadata_json`, y completa `total` SOLO para el Comprobante de Retención (07)
 * cuando está NULL y el JSON oficial ya guardado trae `resumen.totalSujetoRetencion`
 * (nunca `totalIVAretenido`, nunca el PDF). SOLO LECTURA de disco y BD: NO se
 * conecta a Yahoo/IMAP, NO vuelve a descargar nada, NO toca adjuntos, NO cambia
 * `estado` (pendiente/enviado/ignorado) ni ningún otro campo — solo
 * `clasificacion`, `clasificacion_diagnostico` y, en el caso descrito, `total`.
 *
 * Dry-run por defecto (no escribe nada). Con --apply, actualiza solo esas
 * columnas, y solo en los registros donde el valor calculado realmente cambia
 * (idempotente: correrlo dos veces con --apply da el mismo resultado, porque un
 * `total` ya poblado nunca se vuelve a tocar).
 */
class ReclasificarDocumentosRecibidos extends Command
{
    protected $signature = 'documentos-recibidos:reclasificar {--apply : Aplica los cambios (por defecto es dry-run: solo reporta)}';

    protected $description = 'Reclasifica documentos recibidos existentes y completa el total del tipo 07 (resumen.totalSujetoRetencion) cuando falta. Dry-run por defecto.';

    /** @var list<array{id: int, anterior: ?float, propuesto: ?float, accion: string}> */
    private array $detalleTipo07 = [];

    public function handle(JsonAdjuntoDecoder $decoder, ParserDocumentoRecibido $parser, ClasificadorDocumentoRecibido $clasificador): int
    {
        $aplicar = (bool) $this->option('apply');

        $conteos = array_fill_keys(DocumentoRecibido::CLASIFICACIONES, 0);
        $afectadosPorClasificacion = [];
        $totalCompletados = [];
        $omitidosTotalYaPoblado = [];
        $omitidosTotalSinDato = [];
        $revisados = 0;
        $actualizados = 0;
        $this->detalleTipo07 = [];

        DocumentoRecibido::orderBy('id')->chunkById(200, function ($docs) use (
            $decoder, $parser, $clasificador, $aplicar,
            &$conteos, &$afectadosPorClasificacion, &$totalCompletados,
            &$omitidosTotalYaPoblado, &$omitidosTotalSinDato, &$revisados, &$actualizados
        ) {
            foreach ($docs as $doc) {
                $revisados++;

                $analisis = $this->analizarDocumento($doc, $decoder, $parser, $clasificador);
                $clasificacion = $analisis['clasificacion'];
                $diagnostico = $analisis['diagnostico'];

                $conteos[$clasificacion] = ($conteos[$clasificacion] ?? 0) + 1;

                $cambiaClasificacion = $doc->clasificacion !== $clasificacion
                    || $doc->clasificacion_diagnostico !== $diagnostico;
                if ($cambiaClasificacion) {
                    $afectadosPorClasificacion[$clasificacion][] = $doc->id;
                }

                // ---- Propuesta de total, SOLO para tipo 07 (resumen.totalSujetoRetencion) ----
                // Se considera "tipo 07" tanto por lo ya guardado en BD como por lo que
                // acaba de decodificar el JSON (para no perder de vista, en el reporte, un
                // documento que YA sabíamos que era 07 aunque su JSON ahora no decodifique).
                $totalPropuesto = null;
                $esTipo07 = $doc->tipo_documento === '07' || $analisis['tipo'] === '07';
                if ($esTipo07) {
                    $anterior = $doc->total !== null ? (float) $doc->total : null;

                    if ($anterior !== null) {
                        $omitidosTotalYaPoblado[] = $doc->id;
                        $this->detalleTipo07[] = ['id' => $doc->id, 'anterior' => $anterior, 'propuesto' => $anterior, 'accion' => 'omitido (ya tiene total)'];
                    } elseif (! $analisis['json_decodifico'] || $analisis['total'] === null) {
                        $omitidosTotalSinDato[] = $doc->id;
                        $motivo = ! $analisis['json_decodifico'] ? 'omitido (JSON inválido)' : 'omitido (sin totalSujetoRetencion en el JSON)';
                        $this->detalleTipo07[] = ['id' => $doc->id, 'anterior' => null, 'propuesto' => null, 'accion' => $motivo];
                    } else {
                        $totalPropuesto = $analisis['total'];
                        $totalCompletados[] = $doc->id;
                        $this->detalleTipo07[] = ['id' => $doc->id, 'anterior' => null, 'propuesto' => $totalPropuesto, 'accion' => 'completar'];
                    }
                }

                $cambia = $cambiaClasificacion || $totalPropuesto !== null;

                if ($aplicar && $cambia) {
                    // Actualiza SOLO clasificación/diagnóstico y, si aplica, total.
                    // No toca estado, adjuntos ni ningún otro campo.
                    $update = ['clasificacion' => $clasificacion, 'clasificacion_diagnostico' => $diagnostico];
                    if ($totalPropuesto !== null) {
                        $update['total'] = $totalPropuesto;
                    }
                    $doc->forceFill($update)->save();
                    $actualizados++;
                }
            }
        });

        $this->reportar($aplicar, $conteos, $afectadosPorClasificacion, $totalCompletados, $omitidosTotalYaPoblado, $omitidosTotalSinDato, $revisados, $actualizados);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $conteos
     * @param  array<string, list<int>>  $afectadosPorClasificacion
     * @param  list<int>  $totalCompletados
     * @param  list<int>  $omitidosTotalYaPoblado
     * @param  list<int>  $omitidosTotalSinDato
     */
    private function reportar(
        bool $aplicar,
        array $conteos,
        array $afectadosPorClasificacion,
        array $totalCompletados,
        array $omitidosTotalYaPoblado,
        array $omitidosTotalSinDato,
        int $revisados,
        int $actualizados,
    ): void {
        $this->info(($aplicar ? '[APLICADO] ' : '[DRY-RUN] ').'Reclasificación de documentos recibidos:');

        // 1) Clasificaciones propuestas.
        $this->line('');
        $this->line('Clasificaciones propuestas:');
        $this->table(['Clasificación', 'Cantidad'], collect($conteos)->map(fn ($c, $k) => [$k, $c])->values()->all());

        // 2) Diagnósticos propuestos (solo donde hay uno: json_invalido / tipo_no_soportado).
        $this->line('');
        $this->line('Diagnósticos propuestos (json_invalido / tipo_no_soportado):');
        $idsConDiagnostico = array_merge($afectadosPorClasificacion['json_invalido'] ?? [], $afectadosPorClasificacion['tipo_no_soportado'] ?? []);
        $this->line($idsConDiagnostico === [] ? '  (ninguno)' : '  #'.implode(', #', $idsConDiagnostico));

        // 3-4) Tipo 07: detalle anterior/propuesto y acción, para los 5 registros.
        $this->line('');
        $this->line('Tipo 07 — total (resumen.totalSujetoRetencion, nunca totalIVAretenido ni el PDF):');
        if ($this->detalleTipo07 === []) {
            $this->line('  (no hay documentos tipo 07)');
        } else {
            $this->table(['ID', 'Total anterior', 'Total propuesto', 'Acción'], array_map(fn ($d) => [
                $d['id'],
                $d['anterior'] !== null ? number_format($d['anterior'], 2) : '—',
                $d['propuesto'] !== null ? number_format($d['propuesto'], 2) : '—',
                $d['accion'],
            ], $this->detalleTipo07));
        }

        $this->line('');
        $this->line('IDs cuyo total sería completado: '.($totalCompletados === [] ? '(ninguno)' : '#'.implode(', #', $totalCompletados)));
        $this->line('Omitidos por ya tener total: '.($omitidosTotalYaPoblado === [] ? '(ninguno)' : '#'.implode(', #', $omitidosTotalYaPoblado)));
        $this->line('Omitidos por JSON inválido o campo ausente: '.($omitidosTotalSinDato === [] ? '(ninguno)' : '#'.implode(', #', $omitidosTotalSinDato)));

        // Resumen general (clasificación + total).
        $totalAfectados = collect($afectadosPorClasificacion)->flatten()->concat($totalCompletados)->unique()->count();
        $this->line('');
        $this->line("Revisados: {$revisados}");
        $this->line($aplicar ? "Actualizados: {$actualizados}" : "Se actualizarían con --apply: {$totalAfectados}");
        foreach ($afectadosPorClasificacion as $clasificacionAfectada => $ids) {
            $this->line("  → clasificación {$clasificacionAfectada}: #".implode(', #', $ids));
        }

        if (! $aplicar) {
            $this->comment('Dry-run: no se guardó nada. Repetir con --apply para aplicar.');
        }
    }

    /**
     * @return array{clasificacion: string, diagnostico: ?array<string, mixed>, tipo: ?string, total: ?float, json_decodifico: bool}
     */
    private function analizarDocumento(
        DocumentoRecibido $doc,
        JsonAdjuntoDecoder $decoder,
        ParserDocumentoRecibido $parser,
        ClasificadorDocumentoRecibido $clasificador,
    ): array {
        $tieneJson = (bool) $doc->tiene_json;
        $datos = [];
        $decodeFallido = null;

        if ($tieneJson) {
            $rutaJson = $this->rutaJson($doc);
            if ($rutaJson === null) {
                // tiene_json=true pero no se encuentra el .json guardado en disco
                // (no debería pasar en uso normal): se trata como no decodificable.
                $decodeFallido = [
                    'archivo' => null,
                    'error' => 'El .json no se encontró en disco (no estaba entre los adjuntos guardados).',
                    'size' => null, 'mime' => null, 'bom' => null, 'gzip' => null,
                    'parece_base64' => null, 'encoding_detectado' => null, 'intentos' => null,
                ];
            } else {
                $dec = $decoder->decodificar((string) Storage::disk('local')->get($rutaJson), 'application/json', basename($rutaJson));
                if (! empty($dec['ok']) && is_array($dec['data'])) {
                    $datos = $parser->extraer($dec['data']);
                } else {
                    $decodeFallido = $clasificador->diagnosticoDecode($dec, basename($rutaJson));
                }
            }
        }

        [$clasificacion, $diagnostico] = $clasificador->clasificar($tieneJson, $datos, $decodeFallido, (string) $doc->asunto, $this->nombrePdf($doc));

        return [
            'clasificacion' => $clasificacion,
            'diagnostico' => $diagnostico,
            'tipo' => $datos['tipo_documento'] ?? null,
            'total' => $datos['total'] ?? null,
            'json_decodifico' => $datos !== [],
        ];
    }

    /**
     * Ruta del primer .json guardado para este documento (mismo criterio de orden
     * que usó el sincronizador al procesarlo). Solo lectura de disco.
     */
    private function rutaJson(DocumentoRecibido $doc): ?string
    {
        foreach ((array) data_get($doc->metadata_json, 'archivos', []) as $ruta) {
            if (is_string($ruta) && str_ends_with(strtolower($ruta), '.json') && Storage::disk('local')->exists($ruta)) {
                return $ruta;
            }
        }

        return null;
    }

    /** Nombre del primer adjunto PDF registrado (para la heurística "parece DTE"). */
    private function nombrePdf(DocumentoRecibido $doc): string
    {
        foreach ((array) data_get($doc->metadata_json, 'adjuntos', []) as $a) {
            $nombre = (string) ($a['filename'] ?? '');
            if (str_ends_with(strtolower($nombre), '.pdf')) {
                return $nombre;
            }
        }

        return '';
    }
}
