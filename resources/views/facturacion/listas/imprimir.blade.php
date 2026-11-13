<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lista de empaque #{{ $lista->id }}</title>
    {{--
        Documento INDEPENDIENTE, igual que la impresión de documentos fiscales: su
        propio <html>, sus propios estilos y sin el bundle de la aplicación. Por eso
        no recibe la clase `dark` y sale siempre sobre fondo blanco, que es como se
        imprime y como se manda por correo.

        NO se agregó una segunda tubería de PDF. La que existe está construida
        alrededor del DTE —su plantilla, su QR, sus sellos— y reaprovecharla para un
        documento comercial distinto obligaría a duplicarla a medias. Acá el PDF lo
        hace el navegador con «Imprimir → Guardar como PDF», que es infraestructura
        que ya está en todas las máquinas.

        Forma (13/11/2026, pedido del dueño): la de los documentos impresos
        (docs/GUIA_PANTALLAS.md, «Documentos impresos»), como el PDF del CCF, con el acento
        PETRÓLEO y «LISTA DE EMPAQUE» en el recuadro. El emisor es el exportador guardado en la
        lista. La leyenda NO dice «no es factura» ni «documento interno»: la lista viaja con la
        mercadería a la aduana y al cliente, y eso le restaría validez. Dice que acompaña a la
        factura, que es lo que es.
    --}}
    <style>
        <x-documento.estilos acento="petroleo" />

        body { padding: 24px; font-size: 11px; line-height: 1.4; }
        .tabla-wrap { overflow-x: auto; margin-top: 1.2em; }
        .items caption { caption-side: top; text-align: left; font-size: .82em; color: #6B7280; padding-bottom: 4px; }
        /* Trece columnas en carta vertical: celdas angostas para que entre la última (antes se cortaba «Bruto lb»). */
        .items thead th { padding: 5px 4px; font-size: 8px; letter-spacing: 0; text-align: center; vertical-align: middle; border-left: 1px solid #3A3F48; }
        .items thead th:first-child { border-left: 0; }
        .items tbody td { padding: 4px; font-size: 10px; }
        .items td.num, .items th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .items tbody td { border-bottom: 1px solid #EAECEF; }
        .items tfoot td { padding: 5px 4px; font-size: 10px; background: #F2F3F5; border-top: 1px solid #20242C; font-weight: 700; }
        .obs { margin-top: 1.2em; }
        .rec-name { font-weight: bold; }
        @media print {
            body { padding: 0; }
            .tabla-wrap { overflow: visible; }
            .head, .sec { page-break-inside: avoid; }
            .no-print { display: none; }
        }
        .no-print { margin-bottom: 16px; text-align: right; }
        .no-print button {
            display: inline-flex; align-items: center; min-height: 44px; padding: 0 16px;
            border: 1px solid #C9CDD4; background: #fff; color: #20242C; font: inherit; font-weight: 600;
            border-radius: 6px; cursor: pointer;
        }
        .no-print button:hover { background: #F8F9FA; }
        .no-print button:focus-visible { outline: 2px solid #2A6F7C; outline-offset: 2px; }
        /* En el celular el recuadro baja debajo del exportador (solo pantalla; al imprimir, al lado). */
        @media screen and (max-width: 600px) {
            td.head-emisor, td.head-dbox { display: block; width: auto; padding: 0 0 14px; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button type="button" onclick="window.print()">Imprimir o guardar como PDF</button>
</div>

<x-documento.encabezado :empresa="$lista->exportador_nombre" :logo="asset('images/dte/logo-pdf.png')"
    :detalle="collect(['Exportador / Exporter', $lista->exportador_direccion])->filter()->implode(' · ')" etiqueta="Packing list" titulo="LISTA DE EMPAQUE"
    leyenda="Acompaña a la factura · Accompanies the invoice">
    <tr class="key"><td class="k">No.</td><td class="v mono">{{ $lista->id }}</td></tr>
    <tr><td class="k">Fecha / Date</td><td class="v">{{ $lista->fecha?->format('d/m/Y') ?? '—' }}</td></tr>
    <tr><td class="k">Factura / Invoice</td><td class="v mono">{{ $lista->textoFactura() ?: '—' }}</td></tr>
    <tr><td class="k">FDA Reg. No.</td><td class="v mono">{{ $lista->fda_reg_number ?: '—' }}</td></tr>
    @if ($facturas->count() > 1)
        <tr><td class="k">Facturas vinculadas</td><td class="v">{{ $facturas->count() }}</td></tr>
    @endif
</x-documento.encabezado>

<section class="sec">
    <div class="sec-h">Cliente / Customer</div>
    <div class="sec-b">
        <div class="rec-name">{{ $lista->cliente_nombre }}</div>
        <div>{{ $lista->cliente_direccion }}</div>
    </div>
</section>

<div class="tabla-wrap">
    <table class="items">
        <caption>
            Detalle de productos / Product detail
        </caption>
        <thead>
            <tr>
                <th class="num">Cajas<br>Boxes</th>
                <th>Descripción / Description</th>
                <th>Empaque<br>Packing</th>
                <th class="num">Unid./caja<br>Units/box</th>
                <th class="num">Gramos<br>Grams</th>
                <th class="num">Onzas<br>Ounces</th>
                <th class="num">Total unid.<br>Total units</th>
                <th class="num">Precio caja<br>Box price</th>
                <th class="num">Valor total<br>Total value</th>
                <th class="num">Neto kg<br>Net kg</th>
                <th class="num">Bruto kg<br>Gross kg</th>
                <th class="num">Neto lb<br>Net lb</th>
                <th class="num">Bruto lb<br>Gross lb</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lista->items as $item)
                <tr>
                    <td class="num">{{ $item->cantidad_cajas }}</td>
                    <td>{{ $item->descripcionCombinada() }}</td>
                    <td>{{ $item->unidad }}</td>
                    <td class="num">{{ $item->unidades_por_caja }}</td>
                    <td class="num">{{ number_format((float) $item->gramos_por_unidad, 2) }}</td>
                    <td class="num">{{ number_format((float) $item->onzas_por_unidad, 2) }}</td>
                    <td class="num">{{ number_format($item->totalUnidades()) }}</td>
                    <td class="num">{{ number_format((float) $item->precio_caja, 2) }}</td>
                    <td class="num">{{ number_format($item->valorTotal(), 2) }}</td>
                    <td class="num">{{ number_format($item->pesoNetoTotalKg(), 2) }}</td>
                    <td class="num">{{ number_format($item->pesoBrutoTotalKg(), 2) }}</td>
                    <td class="num">{{ number_format($item->pesoNetoTotalLb(), 2) }}</td>
                    <td class="num">{{ number_format($item->pesoBrutoTotalLb(), 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td class="num">{{ $lista->totalCajas() }}</td>
                <td colspan="5">TOTALES / TOTALS</td>
                <td class="num">{{ number_format($lista->totalUnidades()) }}</td>
                <td></td>
                <td class="num">{{ number_format($lista->valorTotal(), 2) }}</td>
                <td class="num">{{ number_format($lista->pesoNetoTotalKg(), 2) }}</td>
                <td class="num">{{ number_format($lista->pesoBrutoTotalKg(), 2) }}</td>
                <td class="num">{{ number_format($lista->pesoNetoTotalLb(), 2) }}</td>
                <td class="num">{{ number_format($lista->pesoBrutoTotalLb(), 2) }}</td>
            </tr>
        </tfoot>
    </table>
</div>

@if ($lista->observaciones)
    <p class="obs"><b>Observaciones:</b> {{ $lista->observaciones }}</p>
@endif

<x-documento.firmas :quienes="['Preparado por / Prepared by', 'Recibido por / Received by']" />

</body>
</html>
