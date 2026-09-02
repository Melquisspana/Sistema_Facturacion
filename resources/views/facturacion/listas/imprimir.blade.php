<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lista de empaque #{{ $lista->id }}</title>
    {{--
        Documento INDEPENDIENTE, igual que la impresión de documentos fiscales: su
        propio <html>, sus propios estilos y sin el bundle de la aplicación. Por eso
        no recibe la clase `dark` y sale siempre en blanco y negro, que es como se
        imprime y como se manda por correo.

        NO se agregó una segunda tubería de PDF. La que existe está construida
        alrededor del DTE —su plantilla, su QR, sus sellos— y reaprovecharla para un
        documento comercial distinto obligaría a duplicarla a medias. Acá el PDF lo
        hace el navegador con «Imprimir → Guardar como PDF», que es infraestructura
        que ya está en todas las máquinas.
    --}}
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px;
            background: #fff;
            color: #111;
            font-family: "Segoe UI", Arial, Helvetica, sans-serif;
            font-size: 11px;
            line-height: 1.4;
        }
        h1 { margin: 0 0 4px; font-size: 18px; letter-spacing: .02em; text-align: center; }
        .sub { margin: 0 0 18px; text-align: center; font-size: 11px; color: #555; }
        .cabecera { display: flex; flex-wrap: wrap; gap: 24px; margin-bottom: 16px; }
        .bloque { flex: 1 1 240px; min-width: 0; }
        .bloque h2 { margin: 0 0 3px; font-size: 9px; letter-spacing: .1em; text-transform: uppercase; color: #666; }
        .bloque p { margin: 0; }
        .bloque .nombre { font-weight: 600; }
        .datos { display: flex; flex-wrap: wrap; gap: 6px 24px; margin-bottom: 16px; font-size: 11px; }
        .datos b { color: #444; font-weight: 600; }
        .mono { font-family: "Consolas", "Courier New", monospace; }
        .tabla-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 4px 6px; }
        thead th {
            background: #eee;
            font-size: 9px;
            letter-spacing: .04em;
            text-transform: uppercase;
            text-align: center;
            vertical-align: middle;
        }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        tfoot td { background: #f4f4f4; font-weight: 700; }
        .pie { margin-top: 18px; font-size: 10px; color: #555; }
        .firma { margin-top: 42px; display: flex; gap: 48px; }
        .firma div { flex: 1; border-top: 1px solid #333; padding-top: 4px; text-align: center; font-size: 10px; }
        @media print {
            body { padding: 0; }
            thead { display: table-header-group; }
            .no-print { display: none; }
        }
        .no-print { margin-bottom: 16px; text-align: right; }
        .no-print button {
            border: 1px solid #333; background: #fff; padding: 6px 14px;
            font-size: 12px; cursor: pointer; border-radius: 4px;
        }
    </style>
</head>
<body>

<div class="no-print">
    <button type="button" onclick="window.print()">Imprimir o guardar como PDF</button>
</div>

<h1>LISTA DE EMPAQUE / PACKING LIST</h1>
<p class="sub">No. {{ $lista->id }}</p>

<div class="cabecera">
    <div class="bloque">
        <h2>Exportador / Exporter</h2>
        <p class="nombre">{{ $lista->exportador_nombre }}</p>
        <p>{{ $lista->exportador_direccion }}</p>
    </div>
    <div class="bloque">
        <h2>Cliente / Customer</h2>
        <p class="nombre">{{ $lista->cliente_nombre }}</p>
        <p>{{ $lista->cliente_direccion }}</p>
    </div>
</div>

<div class="datos">
    <span><b>Fecha / Date:</b> {{ $lista->fecha?->format('d/m/Y') ?? '—' }}</span>
    <span><b>Factura / Invoice:</b> <span class="mono">{{ $lista->textoFactura() ?: '—' }}</span></span>
    <span><b>FDA Reg. No.:</b> <span class="mono">{{ $lista->fda_reg_number ?: '—' }}</span></span>
    @if ($facturas->count() > 1)
        <span><b>Facturas vinculadas:</b> {{ $facturas->count() }}</span>
    @endif
</div>

<div class="tabla-wrap">
    <table>
        <caption style="caption-side: top; text-align: left; font-size: 9px; color: #666; padding-bottom: 4px;">
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
    <p class="pie"><b>Observaciones:</b> {{ $lista->observaciones }}</p>
@endif

<div class="firma">
    <div>Preparado por / Prepared by</div>
    <div>Recibido por / Received by</div>
</div>

</body>
</html>
