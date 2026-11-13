@props(['empresa', 'logo' => null, 'detalle' => null, 'etiqueta', 'titulo', 'leyenda' => 'No es factura · Sin valor tributario'])
{{--
    Encabezado común de los documentos impresos (con <x-documento.estilos>): la línea de color,
    el logo y la empresa a la izquierda y, a la derecha, el recuadro con el nombre del documento
    y la leyenda obligatoria «No es factura · Sin valor tributario». Las filas del recuadro van en
    el slot, como <tr><td class="k">…</td><td class="v">…</td></tr>.

    $logo: la dirección de la imagen (asset() en el navegador; data-URI en DomPDF, como el CCF con
    DtePdfService::logoSrc()). Sin logo, la inicial de la empresa en un cuadro, como el CCF.

    $leyenda: solo la cambia un reporte interno que no sale con mercadería (el de Ventas por zona,
    «Documento interno · No es factura»), un documento que va a la aduana o al cliente (la lista de
    empaque) o uno laboral (planilla, asistencia), donde «no es factura» no viene al caso. Vacía
    (leyenda=""; null vuelve a la de siempre), sin leyenda: los que se firman con validez legal (el contrato, los recibos de vacaciones y de
    indemnización) no llevan nada que los pueda restar.
--}}
<div class="topline"></div>

<table class="head"><tr>
    <td class="head-emisor">
        <table><tr>
            <td class="head-logo">
                @if ($logo)<img class="logo" src="{{ $logo }}" alt="">@else<div class="logo-fb">{{ mb_substr($empresa, 0, 1) }}</div>@endif
            </td>
            <td class="head-razon">
                <div class="razon">{{ $empresa }}</div>
                @if ($detalle)<div class="emi">{{ $detalle }}</div>@endif
            </td>
        </tr></table>
    </td>
    <td class="head-dbox">
        <div class="dbox">
            <div class="dbox-h">
                <span class="lbl">{{ $etiqueta }}</span>
                <span class="ttl">{{ $titulo }}</span>
                @if (filled($leyenda))<span class="nof">{{ $leyenda }}</span>@endif
            </div>
            <table class="dbox-t">{{ $slot }}</table>
        </div>
    </td>
</tr></table>
