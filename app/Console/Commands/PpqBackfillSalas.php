<?php

namespace App\Console\Commands;

use App\Models\PpqItem;
use App\Models\PpqSala;
use App\Services\Ppq\DteCorreoParser;
use App\Services\Ppq\JsonAdjuntoDecoder;
use App\Support\OrdenCompra;
use App\Support\Sala;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Siembra el mapa auxiliar `ppq_salas` (código de sala -> nombre comercial) con lo que
 * PPQ ya vio: los nombres snapshoteados en `ppq_items` y los JSON de CCF descargados de
 * Gmail (receptor.nombreComercial + sala derivada de la OC). SOLO LECTURA de esas fuentes;
 * NO toca DTE, correlativos ni datos fiscales. Dry-run por defecto.
 */
class PpqBackfillSalas extends Command
{
    protected $signature = 'ppq:backfill-salas {--aplicar : Escribe en ppq_salas (por defecto solo muestra)}';

    protected $description = 'Rellena el mapa auxiliar ppq_salas (codigo -> nombre) desde items y JSON de CCF';

    public function handle(DteCorreoParser $parser, JsonAdjuntoDecoder $decoder): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $mapa = []; // codigo => ['nombre' => ..., 'fuente' => ...]

        // 1) Nombres ya snapshoteados en ppq_items.
        foreach (PpqItem::whereNotNull('sala_nombre')->get(['numero_orden_compra', 'sala_nombre']) as $it) {
            $cod = OrdenCompra::salaDesde($it->numero_orden_compra);
            if ($cod && blank($mapa[$cod] ?? null)) {
                $mapa[$cod] = ['nombre' => $it->sala_nombre, 'fuente' => 'ppq_item'];
            }
        }

        // 2) JSON de CCF guardados (inspect): receptor.nombreComercial + sala desde la OC.
        $dir = trim((string) config('ppq.gmail.storage_dir', 'ppq/gmail'), '/').'/inspect';
        $disk = Storage::disk((string) config('dte.storage.disk', 'local'));
        $jsonLeidos = 0;
        foreach ($disk->files($dir) as $ruta) {
            if (! preg_match('/\.json$/i', $ruta)) {
                continue;
            }
            $dec = $decoder->decodificar($disk->get($ruta), 'application/json', basename($ruta));
            if (! ($dec['ok'] ?? false)) {
                continue;
            }
            $jsonLeidos++;
            $r = $parser->desdeJson($dec['data']);
            $cod = OrdenCompra::salaDesde($r['ordenCompra'] ?? null);
            $nombre = $r['salaNombre'] ?? null;
            // El JSON es autoritativo (nombre real del receptor): prioriza sobre el item.
            if ($cod && filled($nombre)) {
                $mapa[$cod] = ['nombre' => $nombre, 'fuente' => 'ccf_json'];
            }
        }

        ksort($mapa);
        $this->info(count($mapa).' sala(s) resueltas ('.$jsonLeidos.' JSON de CCF leídos).');
        $this->table(
            ['Código', 'Nombre', 'Fuente'],
            collect($mapa)->map(fn ($v, $k) => [$k, $v['nombre'], $v['fuente']])->values()->all(),
        );

        if (! $aplicar) {
            $this->warn('DRY-RUN: no se escribió nada. Corré con --aplicar para guardar en ppq_salas.');

            return self::SUCCESS;
        }

        $n = 0;
        foreach ($mapa as $cod => $v) {
            PpqSala::recordar($cod, $v['nombre'], $v['fuente']);
            $n++;
        }
        $this->info("Guardadas/actualizadas {$n} salas en ppq_salas.");
        Sala::olvidarCache();

        return self::SUCCESS;
    }
}
