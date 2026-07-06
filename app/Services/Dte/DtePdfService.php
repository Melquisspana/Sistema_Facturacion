<?php

namespace App\Services\Dte;

use App\Models\Dte;
use App\Models\Empresa;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Construye la representación gráfica (PDF) de un DTE con la plantilla
 * `facturacion.pdf`. Centraliza el render para reutilizarlo desde el controlador
 * (ver/descargar) y desde el Job de correo (fuera del request). Solo presentación:
 * NO transmite, NO cambia estado, NO usa credenciales.
 */
class DtePdfService
{
    /** Objeto PDF listo para stream()/download()/output(). */
    public function pdf(Dte $dte): \Barryvdh\DomPDF\PDF
    {
        $dte->loadMissing([
            'cliente.departamento', 'cliente.municipio', 'cliente.distrito',
            'clienteSucursal.departamento', 'clienteSucursal.municipio', 'clienteSucursal.distrito.departamento',
            'lineas',
            'establecimiento.empresa.departamento', 'establecimiento.empresa.municipio',
            'puntoVenta', 'dteRelacionado',
        ]);

        $emisor = $this->emisor($dte);
        $logoSrc = $this->logoSrc();
        $qrDataUri = $this->qrOficial($dte); // solo si hay sello (datos oficiales)

        return Pdf::loadView('facturacion.pdf', compact('dte', 'emisor', 'logoSrc', 'qrDataUri'))->setPaper('letter');
    }

    /** Bytes del PDF (para adjuntar en correo). */
    public function bytes(Dte $dte): string
    {
        return (string) $this->pdf($dte)->output();
    }

    /** Nombre del archivo PDF: "preliminar-..." mientras no haya sello de recepción. */
    public function nombre(Dte $dte): string
    {
        $prefijo = filled($dte->sello_recepcion) ? 'dte' : 'preliminar';

        return $prefijo.'-'.$dte->tipo_dte->value.'-'.$dte->id.'.pdf';
    }

    /**
     * Emisor a mostrar en el PDF. Si el emisor enlazado al DTE tiene NIT placeholder
     * (vacío o solo ceros), usa la empresa REAL del sistema. Solo presentación.
     */
    public function emisor(Dte $dte): ?Empresa
    {
        $enlazada = $dte->establecimiento?->empresa;
        if ($enlazada && ! $this->nitEsPlaceholder($enlazada->nit)) {
            return $enlazada;
        }

        $real = Empresa::query()->orderByDesc('activo')->get()
            ->reject(fn (Empresa $e) => $this->nitEsPlaceholder($e->nit))
            ->first();

        return $real ?? $enlazada;
    }

    /** Logo del emisor como data-URI (o null si no existe el archivo). Solo estética. */
    public function logoSrc(): ?string
    {
        $ruta = (string) config('dte.pdf.logo_path', '');
        if ($ruta === '' || ! is_file($ruta)) {
            return null;
        }
        $ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($ruta));
    }

    /** ¿El NIT es un placeholder (vacío o solo ceros)? */
    private function nitEsPlaceholder(?string $nit): bool
    {
        $digitos = preg_replace('/\D/', '', (string) $nit);

        return $digitos === '' || trim($digitos, '0') === '';
    }

    /**
     * QR OFICIAL como data-URI SOLO si el documento ya tiene sello de recepción y los
     * datos oficiales necesarios. Si falta cualquiera, devuelve null (no se inventa).
     */
    private function qrOficial(Dte $dte): ?string
    {
        if (blank($dte->sello_recepcion) || blank($dte->codigo_generacion) || ! $dte->fecha_emision) {
            return null;
        }

        $url = rtrim((string) config('dte.pdf.consulta_qr_url', ''), '/')
            .'?ambiente='.$dte->ambiente->value
            .'&codGen='.$dte->codigo_generacion
            .'&fechaEmi='.$dte->fecha_emision->format('Y-m-d');

        try {
            return (new \Endroid\QrCode\Builder\Builder())
                ->build(writer: new \Endroid\QrCode\Writer\PngWriter(), data: $url, size: 130, margin: 2)
                ->getDataUri();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
