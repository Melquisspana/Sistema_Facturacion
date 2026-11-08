<?php

namespace App\Services\Dte;

use App\Models\Dte;
use App\Models\OrdenCompraRecibida;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Un solo PDF para imprimir: primero el CCF (la misma representación de
 * {@see DtePdfService}) y después todas las páginas de la orden de compra recibida.
 *
 * Solo para el botón «PDF + orden» de la ficha. El PDF normal y los correos al
 * cliente y a contabilidad siguen siendo únicamente el CCF.
 */
class PdfConOrdenCompra
{
    public function __construct(private readonly DtePdfService $pdfs) {}

    /**
     * @throws RuntimeException si el PDF de la orden falta o FPDI no lo puede leer (por
     *                          ejemplo, una compresión que la versión libre no abre)
     */
    public function unir(Dte $dte, OrdenCompraRecibida $orden): string
    {
        $disco = Storage::disk((string) config('dte.storage.disk', 'local'));
        if (! $disco->exists($orden->pdf_path)) {
            throw new RuntimeException('No se encontró el PDF guardado de la orden de compra.');
        }

        $ccf = $this->pdfs->pdf($dte)->output();
        $pdfOrden = (string) $disco->get($orden->pdf_path);

        try {
            $union = new Fpdi;
            foreach ([$ccf, $pdfOrden] as $documento) {
                $paginas = $union->setSourceFile(StreamReader::createByString($documento));
                for ($i = 1; $i <= $paginas; $i++) {
                    $plantilla = $union->importPage($i);
                    $tamano = $union->getTemplateSize($plantilla);
                    $union->AddPage($tamano['orientation'], [$tamano['width'], $tamano['height']]);
                    $union->useTemplate($plantilla);
                }
            }

            return $union->Output('S');
        } catch (\Throwable $e) {
            throw new RuntimeException('No se pudo unir el PDF de la orden de compra: '.$e->getMessage(), previous: $e);
        }
    }
}
