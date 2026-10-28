<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ventas nacionales</title>
    <style>
        @page { margin: 30px 36px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #202227; }
        h1 { font-size: 19px; margin: 0 0 5px; }
        h2 { font-size: 12px; margin: 12px 0 5px; color: #4338ca; }
        p { margin: 5px 0; }
        .muted { color: #646775; font-size: 9px; }
        .total { font-size: 32px; font-weight: bold; margin: 4px 0; }
        .summary { background: #f3f4ff; padding: 10px 16px; margin: 12px 0 6px; border-radius: 12px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        td { padding: 4px 0; border-bottom: 1px solid #e9eaee; vertical-align: top; }
        td.money { width: 110px; text-align: right; white-space: nowrap; }
        .columns td { width: 50%; border: 0; padding: 0 14px 0 0; }
        .columns table td { padding: 4px 0; border-bottom: 1px solid #e9eaee; }
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
    @endphp
    <h1>Ventas nacionales · {{ ucfirst($periodo['hasta']->translatedFormat('F Y')) }}</h1>
    <p>{{ $empresa?->razon_social }}</p>
    <p class="muted">{{ ucfirst($periodo['etiqueta']) }} · CCF menos notas de crédito, sin IVA</p>
    @if (count(array_intersect_key($filtros, array_flip(['ruta', 'sala', 'cliente', 'producto']))))
        <p class="muted">Resumen con los filtros seleccionados en Ventas nacionales.</p>
    @endif
    <div class="summary">
        <p class="muted">Vendido sin IVA</p>
        <div class="total">{{ $dinero($totales['actual']) }}</div>
        <p>vs {{ $periodo['etiqueta_anterior'] }}: {{ $totales['porcentaje'] !== null ? ($totales['porcentaje'] >= 0 ? '+' : '').number_format($totales['porcentaje'], 0).' %' : 'Sin datos anteriores' }}</p>
        @if ($totales['porcentaje_anio'] !== null)
            <p>vs {{ $periodo['desde']->copy()->subYearNoOverflow()->translatedFormat('F Y') }}: {{ $totales['porcentaje_anio'] >= 0 ? '+' : '' }}{{ number_format($totales['porcentaje_anio'], 0) }} %</p>
        @endif
    </div>
    <h2>Los 5 productos más vendidos</h2>
    <table>
        @forelse ($resumen['monto'] as $fila)
            <tr><td>{{ $nombre($fila['nombre']) }}</td><td class="money">{{ $dinero($fila['actual']) }}</td></tr>
        @empty <tr><td>Sin ventas en este período.</td></tr> @endforelse
    </table>
    <table class="columns"><tr>
        @foreach (['suben' => 'Subieron', 'bajan' => 'Bajaron'] as $clave => $titulo)
            <td><h2>{{ $titulo }}</h2><table>
                @forelse ($resumen[$clave] as $fila)
                    <tr><td>{{ mb_strimwidth($fila['nombre'], 0, 28, '…') }}</td><td class="money">{{ $fila['diferencia'] > 0 ? '+' : '' }}{{ $dinero($fila['diferencia']) }}</td></tr>
                @empty <tr><td>Ninguno.</td></tr> @endforelse
            </table></td>
        @endforeach
    </tr></table>
    <h2>Salas que compraron menos</h2>
    <table>
        @forelse (array_slice($alertas, 0, 5) as $fila)
            <tr><td>{{ $nombre($fila['nombre']) }}</td><td class="money">{{ $dinero($fila['anterior']) }} → {{ $dinero($fila['actual']) }}</td></tr>
        @empty <tr><td>Ninguna.</td></tr> @endforelse
    </table>
    @if (count($alertas) > 5)<p class="muted">{{ count($alertas) - 5 }} salas más en la pantalla de Ventas nacionales.</p>@endif
    <h2>Totales por ruta</h2>
    <table class="routes">
        @forelse (array_chunk($rutas, 2) as $par)
            <tr>@foreach ($par as $fila)<td>{{ $nombre($fila['nombre']) }}</td><td class="money">{{ $dinero($fila['actual']) }}</td>@endforeach</tr>
        @empty <tr><td>Sin ventas en este período.</td></tr> @endforelse
    </table>
    <h2>Devoluciones</h2>
    <p>Devuelto {{ $dinero($devoluciones['total']) }} · {{ $devoluciones['porcentaje'] !== null ? number_format($devoluciones['porcentaje'], 1).' % de lo vendido' : 'Sin venta de referencia' }}</p>
    <p class="footer">Las notas de crédito de pronto pago son descuentos financieros y no cuentan como devolución. Ruta actual de la sala.</p>
</body>
</html>
