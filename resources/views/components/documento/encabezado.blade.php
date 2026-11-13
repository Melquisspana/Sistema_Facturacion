@props(['empresa', 'logo' => null, 'detalle' => null, 'etiqueta', 'titulo'])
{{--
    Encabezado común de los documentos impresos (con <x-documento.estilos>): la línea de color,
    el logo y la empresa a la izquierda y, a la derecha, el recuadro con el nombre del documento
    y la leyenda obligatoria «No es factura · Sin valor tributario». Las filas del recuadro van en
    el slot, como <tr><td class="k">…</td><td class="v">…</td></tr>.

    $logo: la dirección de la imagen (asset() en el navegador; data-URI en DomPDF, como el CCF con
    DtePdfService::logoSrc()). Sin logo, la inicial de la empresa en un cuadro, como el CCF.
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
                <span class="nof">No es factura · Sin valor tributario</span>
            </div>
            <table class="dbox-t">{{ $slot }}</table>
        </div>
    </td>
</tr></table>
