<?php

namespace App\Services\Dte;

use App\Models\Dte;
use App\Models\Empresa;
use App\Support\Dte\DatosExportacionPresentacion;
use App\Support\Dte\ReceptorExportacionPresentacion;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

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
        // Subconjunto de fuentes: sin esto Dompdf incrusta las DejaVu completas (~1,5 MB)
        // en CADA PDF. El paquete mensual de contabilidad llegó a 240 MB por eso.
        return Pdf::loadView('facturacion.pdf', $this->datosVista($dte))
            ->setPaper('letter')
            ->setOption('enable_font_subsetting', true);
    }

    /**
     * La MISMA representación, renderizada a HTML sin pasar por Dompdf.
     *
     * Existe para poder inspeccionar el contenido del documento —en pruebas o al
     * diagnosticar— sobre exactamente los mismos datos que salen impresos. Antes cada
     * prueba armaba su propio `view('facturacion.pdf', compact(...))` con un subconjunto
     * distinto de variables, así que podía pasar en verde con un emisor o un receptor que
     * el PDF real nunca habría recibido.
     */
    public function html(Dte $dte): string
    {
        return view('facturacion.pdf', $this->datosVista($dte))->render();
    }

    /**
     * FUENTE ÚNICA de las variables de la plantilla. Todo lo que se ve, se descarga, se
     * imprime o se adjunta al correo sale de acá.
     *
     * @return array<string, mixed>
     */
    private function datosVista(Dte $dte): array
    {
        $dte->loadMissing([
            'cliente.departamento', 'cliente.municipio', 'cliente.distrito', 'cliente.actividadEconomica', 'cliente.pais',
            'clienteSucursal.departamento', 'clienteSucursal.municipio', 'clienteSucursal.distrito.departamento',
            'lineas',
            'establecimiento.empresa.departamento', 'establecimiento.empresa.municipio',
            'puntoVenta', 'dteRelacionado',
        ]);

        $emisor = $this->emisor($dte);
        // Relaciones del emisor para el encabezado (solo presentación): actividad económica
        // y ubicación en 3 niveles. El emisor puede resolverse a una empresa distinta a la
        // del establecimiento, por eso se cargan sobre la instancia ya resuelta.
        $emisor?->loadMissing(['actividadEconomica', 'departamento', 'municipio', 'distrito']);

        return [
            'dte' => $dte,
            'emisor' => $emisor,
            'logoSrc' => $this->logoSrc(),
            'qrDataUri' => $this->qrOficial($dte), // solo si hay sello (datos oficiales)
            'datosExportacion' => DatosExportacionPresentacion::resolver($dte),
            'datosReceptor' => ReceptorExportacionPresentacion::resolver($dte),
        ];
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
     * URL de consulta oficial SOLO si el documento ya tiene sello de recepción y los
     * datos oficiales necesarios. Si falta cualquiera, devuelve null (no se inventa).
     */
    public function urlConsultaQr(Dte $dte): ?string
    {
        if (blank($dte->sello_recepcion) || blank($dte->codigo_generacion) || ! $dte->fecha_emision) {
            return null;
        }

        return rtrim((string) config('dte.pdf.consulta_qr_url', ''), '/')
            .'?ambiente='.$dte->ambiente->value
            .'&codGen='.$dte->codigo_generacion
            .'&fechaEmi='.$dte->fecha_emision->format('Y-m-d');
    }

    private function qrOficial(Dte $dte): ?string
    {
        $url = $this->urlConsultaQr($dte);
        if ($url === null) {
            return null;
        }

        try {
            return (new Builder)
                ->build(writer: new PngWriter, data: $url, size: 130, margin: 2)
                ->getDataUri();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
