<?php

namespace App\Services\DocumentosRecibidos;

use App\Models\DocumentoRecibido;
use Illuminate\Support\Facades\Storage;

/**
 * Elige QUÉ archivos ya guardados de un documento recibido viajan en el correo a
 * contabilidad. SOLO LECTURA de disco: no descarga nada del buzón, no crea, no
 * convierte y no inventa adjuntos que no existan.
 *
 * Reglas (deterministas, mismo resultado para la validación previa y para el job):
 *  - solo archivos de `metadata_json.archivos` que EXISTAN en el disco local;
 *  - prioridad PDF → JSON → cualquier otro adjunto fiscal guardado;
 *  - se van agregando mientras no se supere el límite total (15 MB por correo);
 *  - lo que no cabe se reporta como omitido: NUNCA hace fallar el envío completo;
 *  - no parte ni comprime nada.
 */
class AdjuntosDocumentoRecibido
{
    /** Límite total del correo (bytes). Un adjunto que no quepa se omite, no rompe el envío. */
    public function maxBytes(): int
    {
        return (int) config('documentos_recibidos.adjuntos_max_bytes', 15 * 1024 * 1024);
    }

    /**
     * Selección final para este documento.
     *
     * @return array{
     *     enviados: array<int, array{ruta: string, nombre: string, mime: string, size: int}>,
     *     omitidos: array<int, array{nombre: string, size: int}>,
     *     bytes: int
     * }
     */
    public function seleccionar(DocumentoRecibido $documento): array
    {
        $candidatos = $this->candidatos($documento);

        $enviados = [];
        $omitidos = [];
        $bytes = 0;
        $max = $this->maxBytes();

        foreach ($candidatos as $c) {
            if ($bytes + $c['size'] > $max) {
                $omitidos[] = ['nombre' => $c['nombre'], 'size' => $c['size']];

                continue;
            }
            $enviados[] = $c;
            $bytes += $c['size'];
        }

        return ['enviados' => $enviados, 'omitidos' => $omitidos, 'bytes' => $bytes];
    }

    /** Nombres en texto para el cuerpo del correo y el historial ("a.pdf, b.json"). */
    public function nombres(array $archivos): string
    {
        return implode(', ', array_map(fn (array $a) => $a['nombre'], $archivos));
    }

    /**
     * Archivos existentes en disco, ordenados por prioridad: PDF, JSON, el resto.
     * Dentro de cada grupo se conserva el orden en que se guardaron.
     *
     * @return array<int, array{ruta: string, nombre: string, mime: string, size: int}>
     */
    private function candidatos(DocumentoRecibido $documento): array
    {
        $grupos = ['pdf' => [], 'json' => [], 'otros' => []];

        foreach ((array) data_get($documento->metadata_json, 'archivos', []) as $ruta) {
            if (! is_string($ruta) || $ruta === '' || ! Storage::disk('local')->exists($ruta)) {
                continue; // solo lo que realmente está guardado
            }

            $ext = strtolower((string) pathinfo($ruta, PATHINFO_EXTENSION));
            $grupo = match ($ext) {
                'pdf' => 'pdf',
                'json' => 'json',
                default => 'otros',
            };

            $grupos[$grupo][] = [
                'ruta' => $ruta,
                'nombre' => basename($ruta),
                'mime' => $this->mime($ext),
                'size' => (int) Storage::disk('local')->size($ruta),
            ];
        }

        return array_merge($grupos['pdf'], $grupos['json'], $grupos['otros']);
    }

    private function mime(string $ext): string
    {
        return match ($ext) {
            'pdf' => 'application/pdf',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'zip' => 'application/zip',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }
}
