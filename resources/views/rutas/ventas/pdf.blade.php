<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    {{--
        Resumen de Ventas por zona en PDF (VentasController::pdf, DomPDF, A4 vertical, una hoja).
        Solo lo bajan Administrador y Jefatura (rutas.ventas.ver).

        Forma (12/11/2026, pedido del dueño): la de los documentos impresos (docs/GUIA_PANTALLAS.md,
        «Documentos impresos»), como el PDF del CCF, con el acento GRAFITO y «REPORTE DE VENTAS»
        con «Documento interno · No es factura» en el recuadro, que lleva el reporte, el período y
        la zona. Lo común sale de components/documento. DomPDF: sin flex ni grid, todo con tablas.

        Variables: lo de VentasRutas::consultar(), $empresa (Empresa activa o null) y $logo
        (data-URI de DtePdfService::logoSrc(), puede faltar).
    --}}
    <style>
        <x-documento.estilos acento="grafito" />

        @page { margin: 30px 36px; }
        body { font-size: 9px; }
        /* Una sola hoja A4: el encabezado común, un poco más compacto que en los demás documentos. */
        .topline { margin-bottom: 10px; }
        td.head-logo { width: 4.4em; }
        .logo { width: 3.6em; height: 5.4em; }
        .pie { margin-top: 8px; }
        h2 { font-size: 12px; margin: 12px 0 5px; color: #33373D; }
        p { margin: 5px 0; }
        .muted { color: #646775; font-size: 9px; }
        .total { font-size: 32px; font-weight: bold; margin: 4px 0; }
        .summary { background: #FAFBFC; border: 1px solid #C9CDD4; border-radius: 6px; padding: 8px 14px; margin: 10px 0 6px; }
        .resumen, .columns { table-layout: fixed; }
        .resumen td { padding: 4px 0; border-bottom: 1px solid #EAECEF; vertical-align: top; }
        .resumen td.money { width: 110px; text-align: right; white-space: nowrap; }
        .columns td { width: 50%; border: 0; padding: 0 14px 0 0; vertical-align: top; }
        .columns table td { padding: 4px 0; border-bottom: 1px solid #EAECEF; }
        .columns table td.money { width: 86px; text-align: right; }
        .routes { font-size: {{ count($rutas) > 20 ? 7 : 9 }}px; }
        .routes td { padding: 3px 0; }
        /* Separa las dos columnas de rutas: el monto de la izquierda no se pega al nombre de la derecha. */
        .routes td.money { padding-right: 18px; }
        .footer { margin-top: 15px; font-size: 8px; color: #646775; }
    </style>
</head>
<body>
    @php
        $dinero = fn ($n) => ($n < 0 ? '-' : '').'$'.number_format(abs($n), 2);
        $nombre = fn ($n) => mb_strimwidth($n, 0, 58, '…');
        // La zona elegida sale de las mismas filas del reporte (sin consultas en la vista).
        $zona = ! isset($filtros['ruta']) ? 'Todas' : (collect($rutas)->firstWhere('id', $filtros['ruta'])['nombre'] ?? null);
    @endphp
    <x-documento.encabezado :empresa="$empresa?->razon_social ?? \App\Support\NombreEmpresa::actual()" :logo="$logo ?? null"
        :detalle="$canal === 'exportacion' ? 'FEX menos notas de crédito · USD, sin IVA' : 'CCF + facturas menos notas de crédito, sin IVA'" etiqueta="Documento interno de Rutas" titulo="REPORTE DE VENTAS"
        leyenda="Documento interno · No es factura">
        <tr class="key"><td class="k">Reporte</td><td class="v">{{ $titulo }} · {{ ucfirst($periodo['hasta']->translatedFormat('F Y')) }}</td></tr>
        <tr><td class="k">Período</td><td class="v">{{ ucfirst($periodo['etiqueta']) }}</td></tr>
        @if ($canal === 'nacional' && $zona !== null)
            <tr><td class="k">Zona</td><td class="v">{{ $zona }}</td></tr>
        @endif
    </x-documento.encabezado>
    @if (count(array_intersect_key($filtros, array_flip(['ruta', 'sala', 'cliente', 'producto']))))
        <p class="muted">Resumen con los filtros seleccionados en {{ $titulo }}.</p>
    @endif
    <div class="summary">
        <p class="muted">{{ $canal === 'exportacion' ? 'USD, sin IVA' : 'Vendido sin IVA' }}</p>
        <div class="total">{{ $dinero($totales['actual']) }}</div>
        <p>vs {{ $periodo['etiqueta_anterior'] }}: {{ $totales['porcentaje'] !== null ? ($totales['porcentaje'] >= 0 ? '+' : '').number_format($totales['porcentaje'], 0).' %' : 'Sin datos anteriores' }}</p>
        @if ($totales['porcentaje_anio'] !== null)
            <p>vs {{ $periodo['desde']->copy()->subYearNoOverflow()->translatedFormat('F Y') }}: {{ $totales['porcentaje_anio'] >= 0 ? '+' : '' }}{{ number_format($totales['porcentaje_anio'], 0) }} %</p>
        @endif
    </div>
    <h2>{{ $canal === 'exportacion' ? 'Las 5 presentaciones más vendidas' : 'Los 5 productos más vendidos' }}</h2>
    <table class="resumen">
        @forelse ($resumen['monto'] as $fila)
            <tr><td>{{ $nombre($fila['nombre']) }}</td><td class="money">{{ $dinero($fila['actual']) }}</td></tr>
        @empty <tr><td>Sin ventas en este período.</td></tr> @endforelse
    </table>
    <table class="columns"><tr>
        @foreach (['suben' => 'Subieron', 'bajan' => 'Bajaron'] as $clave => $columna)
            <td><h2>{{ $columna }}</h2><table class="resumen">
                @forelse ($resumen[$clave] as $fila)
                    <tr><td>{{ mb_strimwidth($fila['nombre'], 0, 28, '…') }}</td><td class="money">{{ $fila['diferencia'] > 0 ? '+' : '' }}{{ $dinero($fila['diferencia']) }}</td></tr>
                @empty <tr><td>Ninguno.</td></tr> @endforelse
            </table></td>
        @endforeach
    </tr></table>
    <h2>{{ $canal === 'exportacion' ? 'Clientes' : 'Salas' }} que compraron menos</h2>
    <table class="resumen">
        @forelse (array_slice($alertas, 0, 5) as $fila)
            <tr><td>{{ $nombre($fila['nombre']) }}</td><td class="money">{{ $dinero($fila['anterior']) }} → {{ $dinero($fila['actual']) }}</td></tr>
        @empty <tr><td>Ninguna.</td></tr> @endforelse
    </table>
    @if (count($alertas) > 5)<p class="muted">{{ count($alertas) - 5 }} {{ $canal === 'exportacion' ? 'clientes' : 'salas' }} más en la pantalla de {{ $titulo }}.</p>@endif
    <h2>{{ $canal === 'exportacion' ? 'Totales por cliente' : 'Totales por ruta' }}</h2>
    <table class="resumen routes">
        @forelse (array_chunk($canal === 'exportacion' ? $clientes : $rutas, 2) as $par)
            <tr>@foreach ($par as $fila)<td>{{ $nombre($fila['nombre']) }}</td><td class="money">{{ $dinero($fila['actual']) }}</td>@endforeach</tr>
        @empty <tr><td>Sin ventas en este período.</td></tr> @endforelse
    </table>
    @if ($canal === 'nacional')
    <h2>Devoluciones</h2>
    <p>Devuelto {{ $dinero($devoluciones['total']) }} · {{ $devoluciones['porcentaje'] !== null ? number_format($devoluciones['porcentaje'], 1).' % de lo vendido' : 'Sin venta de referencia' }}</p>
    <p class="footer">Las notas de crédito de pronto pago son descuentos financieros y no cuentan como devolución. Zona actual de la sala.</p>
    @endif
    <p class="pie">Reporte interno de ventas. No es factura ni documento tributario.</p>
</body>
</html>
