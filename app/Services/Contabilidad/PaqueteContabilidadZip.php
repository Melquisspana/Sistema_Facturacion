<?php

namespace App\Services\Contabilidad;

use App\Services\DocumentosRecibidos\DocumentosRecibidosExcel;
use App\Services\Reportes\ReporteContadoraExcel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Arma el ZIP mensual para contabilidad (herramienta INTERNA; la contadora no entra
 * al sistema). Junta COMPRAS (documentos recibidos: Excel + PDF/JSON ya guardados
 * localmente) y VENTAS (reporte contadora: Excel). SOLO LECTURA: no vuelve a
 * descargar correos, no envía nada, no toca DTE emitidos ni correlativos.
 *
 * Los PDF/JSON de VENTAS (emitidos) NO se incluyen en esta fase (no hay forma segura
 * de ubicarlos); se deja constancia en el LEEME del ZIP.
 */
class PaqueteContabilidadZip
{
    public function __construct(
        private readonly DocumentosRecibidosExcel $comprasExcel,
        private readonly ReporteContadoraExcel $ventasExcel,
    ) {}

    /**
     * @param  Collection<int, \App\Models\DocumentoRecibido>  $compras
     * @param  Collection<int, \App\Models\Dte>  $ventas
     * @return array{ruta: string, compras_pdf: int, compras_json: int}
     */
    public function generar(string $etiqueta, Collection $compras, Collection $ventas, bool $incluirCompras, bool $incluirVentas): array
    {
        $rutaZip = tempnam(sys_get_temp_dir(), 'paq_contab_').'.zip';
        $zip = new ZipArchive();
        $zip->open($rutaZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $pdf = 0;
        $json = 0;

        if ($incluirCompras) {
            $zip->addFromString("compras/documentos_recibidos_{$etiqueta}.xlsx", $this->xlsx($this->comprasExcel->generar($compras)));

            // Adjuntos ya guardados localmente (no se re-descarga nada de Yahoo).
            foreach ($compras as $doc) {
                foreach ((array) data_get($doc->metadata_json, 'archivos', []) as $ruta) {
                    if (! is_string($ruta) || ! Storage::disk('local')->exists($ruta)) {
                        continue;
                    }
                    $ext = strtolower((string) pathinfo($ruta, PATHINFO_EXTENSION));
                    $sub = $ext === 'pdf' ? 'pdf' : ($ext === 'json' ? 'json' : null);
                    if ($sub === null) {
                        continue;
                    }
                    $zip->addFromString("compras/{$sub}/{$doc->id}_".basename($ruta), (string) Storage::disk('local')->get($ruta));
                    $sub === 'pdf' ? $pdf++ : $json++;
                }
            }
        }

        if ($incluirVentas) {
            $zip->addFromString("ventas/reporte_contadora_{$etiqueta}.xlsx", $this->xlsx($this->ventasExcel->generar($ventas)));
        }

        $zip->addFromString('LEEME.txt', $this->leeme($etiqueta, $incluirCompras, $incluirVentas, $pdf, $json));
        $zip->close();

        return ['ruta' => $rutaZip, 'compras_pdf' => $pdf, 'compras_json' => $json];
    }

    public function nombreArchivo(string $etiqueta): string
    {
        return 'documentos_contabilidad_'.$etiqueta.'.zip';
    }

    /** Lee el .xlsx generado a un string y borra el temporal. */
    private function xlsx(string $rutaTemporal): string
    {
        $contenido = (string) file_get_contents($rutaTemporal);
        @unlink($rutaTemporal);

        return $contenido;
    }

    private function leeme(string $etiqueta, bool $compras, bool $ventas, int $pdf, int $json): string
    {
        $lineas = [
            'Paquete de contabilidad '.$etiqueta,
            'Herramienta interna: la contadora no entra al sistema. Este paquete se le envía por fuera.',
            '',
        ];
        if ($compras) {
            $lineas[] = 'compras/documentos_recibidos_'.$etiqueta.'.xlsx — CCF/facturas de proveedores recibidas.';
            $lineas[] = "compras/pdf/ — {$pdf} PDF de compras (adjuntos recibidos).";
            $lineas[] = "compras/json/ — {$json} JSON de compras (adjuntos recibidos).";
        }
        if ($ventas) {
            $lineas[] = 'ventas/reporte_contadora_'.$etiqueta.'.xlsx — documentos emitidos (ventas).';
            $lineas[] = 'ventas/pdf y ventas/json: los adjuntos de documentos EMITIDOS se agregarán en una fase posterior.';
        }

        return implode("\r\n", $lineas)."\r\n";
    }
}
