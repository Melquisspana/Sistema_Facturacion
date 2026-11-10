{{-- PDF del día del vendedor (maqueta del 10/11/2026): lo entregado, lo que no se pudo y lo
     cobrado. Documento aparte para DomPDF, con la forma de «Páginas para imprimir»
     (docs/GUIA_PANTALLAS.md): franja índigo con el nombre de la empresa, cajitas con los
     totales y tablas con encabezado índigo. Se lee igual en blanco y negro: nada se dice
     solo con color. Hoja carta vertical. Sin flexbox: DomPDF arma con tablas.

     Variables: $resumen (DiaVendedor::resumen), $persona, $empresa (NombreEmpresa::actual()),
     $generado (Carbon local). --}}
@php
    $dinero = fn (int $centavos) => '$'.number_format($centavos / 100, 2);
    $fecha = \Illuminate\Support\Carbon::parse($resumen['fecha']);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Resumen del día {{ $fecha->format('d/m/Y') }} · {{ $persona->nombre }}</title>
<style>
    @page { size: letter portrait; margin: 26px 30px; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; margin: 0; }
    .franja { background: #4338ca; color: #fff; padding: 14px 16px; border-radius: 6px; }
    .franja table { width: 100%; border-collapse: collapse; }
    .franja td { border: none; padding: 0; color: #fff; vertical-align: bottom; }
    .empresa { font-size: 10px; letter-spacing: 1px; text-transform: uppercase; }
    .titulo { font-size: 20px; font-weight: bold; margin-top: 2px; }
    .derecha { text-align: right; }
    .datos { width: 100%; border-collapse: separate; border-spacing: 6px; margin: 10px -6px 4px; }
    .datos td { background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 6px; padding: 8px 10px; width: 25%; vertical-align: top; }
    .etiqueta { display: block; font-size: 8px; color: #4b5563; text-transform: uppercase; letter-spacing: .5px; }
    .valor { display: block; font-size: 15px; font-weight: bold; color: #111827; margin-top: 2px; }
    h2 { font-size: 12px; margin: 16px 0 6px; color: #3730a3; }
    table.lista { width: 100%; border-collapse: collapse; }
    table.lista thead { display: table-header-group; }
    table.lista th { background: #4338ca; color: #fff; text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: .4px; padding: 6px 6px; }
    table.lista td { padding: 6px; border-bottom: 1px solid #e5e7eb; }
    table.lista tr.par td { background: #f9fafb; }
    table.lista tr { page-break-inside: avoid; }
    .num { text-align: right; white-space: nowrap; }
    .mono { font-family: DejaVu Sans Mono, monospace; font-size: 8.5px; }
    .total td { font-weight: bold; border-top: 2px solid #4338ca; }
    .marca { display: inline-block; border: 1.5px solid; border-radius: 3px; padding: 0 4px; font-weight: bold; }
    .ok { color: #15803d; background: #dcfce7; border-color: #15803d; }
    .no { color: #b91c1c; background: #fee2e2; border-color: #b91c1c; }
    .aviso { color: #92400e; }
    .vacio { color: #6b7280; padding: 8px 6px; }
    .firmas { width: 100%; margin-top: 50px; border-collapse: collapse; page-break-inside: avoid; }
    .firmas td { width: 50%; text-align: center; padding: 0 20px; border: none; }
    .firmas .linea { padding-top: 4px; font-size: 11px; }
    .pie { margin-top: 18px; font-size: 8px; color: #6b7280; }
</style>
</head>
<body>
    <div class="franja">
        <table><tr>
            <td>
                <div class="empresa">{{ $empresa }}</div>
                <div class="titulo">Resumen del día</div>
            </td>
            <td class="derecha">
                <div class="empresa">{{ ucfirst($fecha->copy()->locale('es')->dayName) }}</div>
                <div class="titulo">{{ $fecha->format('d/m/Y') }}</div>
            </td>
        </tr></table>
    </div>

    <table class="datos"><tr>
        <td><span class="etiqueta">Vendedor</span><span class="valor" style="font-size: 12px;">{{ $persona->nombre }}</span></td>
        <td><span class="etiqueta">Entregas</span><span class="valor">{{ $resumen['entregadas']->count() }}</span></td>
        <td><span class="etiqueta">No se pudo</span><span class="valor">{{ $resumen['no_entregadas']->count() }}</span></td>
        <td><span class="etiqueta">Cobrado</span><span class="valor">{{ $dinero($resumen['cobrado']) }}</span></td>
    </tr></table>

    <h2>Lo entregado</h2>
    <table class="lista">
        <thead><tr><th>Sala</th><th>CCF</th><th>Hora</th><th>Pago</th><th class="num">Monto</th></tr></thead>
        <tbody>
            @forelse ($resumen['entregadas'] as $i => $entrega)
                @php
                    $portal = $resumen['portal_id'] !== null && (int) $entrega->dte?->cliente_id === $resumen['portal_id'];
                @endphp
                <tr class="{{ $i % 2 ? 'par' : '' }}">
                    <td><span class="marca ok" aria-label="Entregado">E</span> {{ $entrega->sala?->nombre ?? 'Sala sin identificar' }}</td>
                    <td class="mono">{{ $entrega->dte?->numero_control }}</td>
                    <td>{{ $entrega->fecha_resultado?->format('H:i') }}</td>
                    <td>
                        {{ $entrega->forma_cobro?->label() ?? ($portal ? 'Por el portal' : 'Quedan') }}
                        @if ($entrega->quedan_numero || $entrega->quedan_fecha_pago)
                            <br><span class="mono">{{ $entrega->quedan_numero ? 'N.º '.$entrega->quedan_numero : '' }}{{ $entrega->quedan_fecha_pago ? ' · paga '.$entrega->quedan_fecha_pago->format('d/m/Y') : '' }}</span>
                        @endif
                        @if ($entrega->nota)
                            <br>Obs.: {{ $entrega->nota }}
                        @endif
                    </td>
                    <td class="num">${{ number_format((float) $entrega->dte?->total_pagar, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="vacio">No entregaste nada este día.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Lo que no se pudo entregar</h2>
    <table class="lista">
        <thead><tr><th>Sala</th><th>CCF</th><th>Hora</th><th>Motivo</th></tr></thead>
        <tbody>
            @forelse ($resumen['no_entregadas'] as $i => $entrega)
                <tr class="{{ $i % 2 ? 'par' : '' }}">
                    <td><span class="marca no" aria-label="No se pudo">N</span> {{ $entrega->sala?->nombre ?? 'Sala sin identificar' }}</td>
                    <td class="mono">{{ $entrega->dte?->numero_control }}</td>
                    <td>{{ $entrega->fecha_resultado?->format('H:i') }}</td>
                    <td>{{ $entrega->motivo_no_entrega?->label() }}{{ $entrega->nota ? ' · '.$entrega->nota : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="vacio">Todo lo que llevaste se entregó.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Lo cobrado</h2>
    <table class="lista">
        <thead><tr><th>Forma de pago</th><th class="num">Facturas</th><th class="num">Monto</th></tr></thead>
        <tbody>
            <tr><td>Efectivo</td><td class="num">{{ $resumen['cuantas']['efectivo'] }}</td><td class="num">{{ $dinero($resumen['efectivo']) }}</td></tr>
            <tr class="par"><td>Cheque</td><td class="num">{{ $resumen['cuantas']['cheque'] }}</td><td class="num">{{ $dinero($resumen['cheque']) }}</td></tr>
            <tr><td>Transferencia</td><td class="num">{{ $resumen['cuantas']['transferencia'] }}</td><td class="num">{{ $dinero($resumen['transferencia']) }}</td></tr>
            <tr class="total"><td>Total cobrado</td><td class="num"></td><td class="num">{{ $dinero($resumen['cobrado']) }}</td></tr>
            <tr><td>Quedan recibidos (no se cobra hoy)</td><td class="num">{{ $resumen['quedan']['cantidad'] }}</td><td class="num">{{ $dinero($resumen['quedan']['monto']) }}</td></tr>
            @if ($resumen['portal']['cantidad'] > 0)
                <tr class="par"><td>Cobro por el portal (supermercado)</td><td class="num">{{ $resumen['portal']['cantidad'] }}</td><td class="num">{{ $dinero($resumen['portal']['monto']) }}</td></tr>
            @endif
        </tbody>
    </table>
    <p class="aviso">Con esta hoja, el vendedor entrega a la oficina el efectivo, los cheques y los quedan. En el sistema no se marca nada más.</p>

    <table class="firmas"><tr>
        <td><div class="linea">Entregó: ______________________</div></td>
        <td><div class="linea">Recibió: ______________________</div></td>
    </tr></table>

    <p class="pie">Generado el {{ $generado->format('d/m/Y H:i') }}.</p>
</body>
</html>
