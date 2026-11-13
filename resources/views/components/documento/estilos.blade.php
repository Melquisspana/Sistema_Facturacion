@props(['acento'])
{{--
    Estilos comunes de los documentos impresos (docs/GUIA_PANTALLAS.md, «Documentos impresos»):
    la forma del PDF del CCF (facturacion/pdf) con el acento propio de cada documento, para que
    se parezcan pero ninguno se confunda con una factura. Va DENTRO del <style> de cada documento,
    que después agrega lo suyo.

    Sirve igual en el navegador y en DomPDF: todo arma con tablas (sin flex ni grid) y los tamaños
    van en em, así cada documento fija su tamaño de letra en body (la hoja de la fábrica 12px en
    el navegador, el PDF del día 10px en DomPDF) y todo escala junto.

    Acentos (oscuro para textos, medio para la línea de arriba, fondo y borde del recuadro). El
    vino del CCF no está acá: el CCF tiene su propia vista y es el único que lo usa.
--}}
@php
    $paleta = [
        'verde' => ['fuerte' => '#235C42', 'linea' => '#2F6B4E', 'fondo' => '#EEF5F0', 'borde' => '#BFE0CD'],
        'pizarra' => ['fuerte' => '#2B4A6F', 'linea' => '#3B5F8A', 'fondo' => '#EEF2F7', 'borde' => '#C5D1E0'],
        'ocre' => ['fuerte' => '#7A5212', 'linea' => '#9A6B1F', 'fondo' => '#F8F2E6', 'borde' => '#E3D0A8'],
        'grafito' => ['fuerte' => '#33373D', 'linea' => '#4A4F58', 'fondo' => '#F2F3F5', 'borde' => '#D4D8DE'],
    ][$acento];
@endphp
* { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { margin: 0; background: #fff; color: #20242C; font-family: "DejaVu Sans", "Segoe UI", Arial, sans-serif; line-height: 1.3; }
table { width: 100%; border-collapse: collapse; }
.mono { font-family: "DejaVu Sans Mono", Consolas, "Courier New", monospace; }

.topline { border-top: 2px solid {{ $paleta['linea'] }}; margin-bottom: 1.2em; }
td.head-emisor, td.head-logo, td.head-razon, td.head-dbox { vertical-align: top; padding: 0; }
td.head-emisor { padding-right: 1.5em; }
td.head-logo { width: 5.6em; padding-right: .9em; }
td.head-dbox { width: 40%; }
.logo { width: 4.7em; height: 7em; }
.logo-fb { width: 4.7em; height: 4.7em; border-radius: 6px; background: #20242C; color: #fff; text-align: center; font-size: 1em; font-weight: bold; padding-top: 1.4em; }
.razon { font-size: 1.65em; font-weight: bold; line-height: 1.12; }
.emi { font-size: .92em; color: #4A4F58; margin-top: .35em; }

{{-- El recuadro del documento, como el del CCF, con la cabecera del acento y la leyenda. --}}
.dbox { border: 1px solid #C9CDD4; border-radius: 6px; overflow: hidden; }
.dbox-h { background: {{ $paleta['fondo'] }}; border-bottom: 1px solid {{ $paleta['borde'] }}; padding: .6em .85em; }
.dbox-h .lbl { display: block; font-size: .72em; letter-spacing: 1.2px; text-transform: uppercase; color: #6B7280; }
.dbox-h .ttl { display: block; font-size: 1.6em; font-weight: bold; letter-spacing: .5px; color: {{ $paleta['fuerte'] }}; line-height: 1.15; }
.dbox-h .nof { display: block; margin-top: .25em; font-size: .96em; font-weight: bold; color: #20242C; }
.dbox-t td { padding: .35em .85em; font-size: .96em; border-bottom: 1px solid #EEF0F2; }
.dbox-t tr:last-child td { border-bottom: 0; }
.dbox-t .k { color: #6B7280; white-space: nowrap; }
.dbox-t .v { text-align: right; font-weight: bold; }
.dbox-t tr.key td { background: #FAFBFC; }

{{-- Aviso de una línea, como las cintas de estado del CCF. --}}
.st { margin: 1em 0 0; padding: .4em .85em; border: 1px solid #E7C98D; border-left-width: 3px; border-left-color: #C8881E; border-radius: 3px; background: #FBF6EC; color: #7A4A0C; }

{{-- Bloque de datos: borde fino + cabecera gris + cuerpo. --}}
.sec { margin-top: 1.2em; border: 1px solid #C9CDD4; border-radius: 6px; }
.sec-h { background: #F2F3F5; border-bottom: 1px solid #D4D8DE; padding: .4em .85em; font-size: .75em; letter-spacing: 1px; text-transform: uppercase; color: #6B7280; font-weight: bold; }
.sec-b { padding: .75em .85em; }

{{-- Tablas: encabezado oscuro como el CCF, filas con línea fina; el encabezado se repite en cada hoja. --}}
h2 { margin: 1.35em 0 .4em; font-size: 1.08em; }
.items thead { display: table-header-group; }
.items thead th { background: #20242C; color: #F3F4F6; font-size: .8em; text-transform: uppercase; letter-spacing: .3px; padding: .5em .6em; text-align: left; }
.items tbody td { padding: .35em .6em; border-bottom: 1px solid #EAECEF; vertical-align: top; }
.items tr { page-break-inside: avoid; }
.items .r { text-align: right; white-space: nowrap; }

{{-- Firmas con línea fina. --}}
.firmas { margin-top: 3.6em; page-break-inside: avoid; }
.firmas td { width: 50%; vertical-align: bottom; padding: 0 1.6em; }
.firmas td:first-child { padding-left: 0; }
.firmas td:last-child { padding-right: 0; }
.firmas .flk { font-size: .75em; text-transform: uppercase; letter-spacing: .4px; color: #8A9099; }
.firmas .fline { display: block; height: 2.5em; border-bottom: 1px solid #B6BBC2; }

.pie { margin-top: 1.2em; font-size: .75em; color: #9AA0A8; text-align: center; }
