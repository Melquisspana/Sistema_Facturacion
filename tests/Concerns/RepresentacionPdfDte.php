<?php

namespace Tests\Concerns;

use App\Models\Dte;
use App\Services\Dte\DtePdfService;

/**
 * Ayudas para verificar la REPRESENTACIÓN del documento.
 *
 * Desde que «Imprimir» dejó de tener maqueta propia, `facturacion.imprimir` entrega la
 * misma representación PDF que ver, descargar y adjuntar al correo. Eso deja dos cosas
 * distintas por comprobar, y conviene no confundirlas:
 *
 *   · el CONTENIDO del documento → {@see htmlDelPdf}, que rinde la plantilla real con los
 *     datos reales del servicio, sin pasar por Dompdf (un PDF binario no se puede
 *     inspeccionar con assertSee);
 *   · el CONTRATO de la ruta → {@see assertImprimeElPdf}, que comprueba que responde 200
 *     con `application/pdf`.
 *
 * Ambas usan {@see DtePdfService}, así que una prueba no puede pasar en verde con datos
 * que el PDF real nunca habría recibido.
 */
trait RepresentacionPdfDte
{
    /** HTML de la representación PDF: misma plantilla y mismos datos que salen impresos. */
    protected function htmlDelPdf(Dte $dte): string
    {
        return app(DtePdfService::class)->html($dte);
    }

    /**
     * La ruta de impresión responde con la representación PDF.
     *
     * @param  \App\Models\User|null  $como  usuario autenticado; null usa el actual
     */
    protected function assertImprimeElPdf(Dte $dte, $como = null): void
    {
        $peticion = $como ? $this->actingAs($como) : $this;
        $respuesta = $peticion->get(route('facturacion.imprimir', $dte));

        $respuesta->assertOk();

        $this->assertSame(
            'application/pdf',
            strtok((string) $respuesta->headers->get('content-type'), ';'),
            'La ruta de impresión debe entregar la representación PDF, no una vista HTML.'
        );
    }
}
