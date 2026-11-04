<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><title>Liquidación de la ruta</title>
<style>
    @page { margin: 28px 30px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
    h1 { font-size: 19px; margin: 0 0 8px; }
    h2 { font-size: 11px; margin: 14px 0 5px; }
    p { margin: 4px 0; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    th, td { border-bottom: 1px solid #bbb; padding: 5px 3px; text-align: left; }
    th { background: #eee; font-weight: bold; }
    .numero { font-size: 8px; }
    .dinero { text-align: right; white-space: nowrap; }
    tr { page-break-inside: avoid; }
    thead { display: table-header-group; }
    .total td { font-weight: bold; }
    .entregar { border: 2px solid #111; padding: 13px; margin-top: 14px; page-break-inside: avoid; }
    .entregar strong { font-size: 21px; }
    .firmas { margin-top: 55px; page-break-inside: avoid; }
    .firmas td { width: 50%; text-align: center; border: none; }
</style></head>
<body>
    @php($plata = fn (int $centavos) => '$'.number_format($centavos / 100, 2))
    <h1>Liquidación de la ruta</h1>
    <p><strong>{{ $empresa?->razon_social }}</strong></p>
    <p><strong>Ruta:</strong> {{ $salida->ruta->nombre }} · <strong>Fechas:</strong> {{ $salida->fecha_inicio->format('d/m/Y') }}{{ $salida->fecha_fin_real && ! $salida->fecha_fin_real->isSameDay($salida->fecha_inicio) ? ' al '.$salida->fecha_fin_real->format('d/m/Y') : '' }}</p>
    <p><strong>Vendedores:</strong> {{ $salida->quienesVan() }}</p>
    <p><strong>Entrega el dinero:</strong> {{ $salida->entregaDinero?->nombre ?? 'Sin cobros' }}</p>

    @php($montoDe = fn ($lista) => $lista->sum(fn ($e) => \App\Services\Rutas\CierreDia::centavos($e->dte->total_pagar)))
    <h2>Documentos</h2>
    <p><strong>Entregados:</strong> {{ $entregadas->count() }} · {{ $plata($montoDe($entregadas)) }}</p>
    <p><strong>No entregados:</strong> {{ $noEntregadas->count() }} · {{ $plata($montoDe($noEntregadas)) }}</p>

    <h2>Cobrado y gastos por día</h2>
    <table><thead><tr><th>Día</th><th class="dinero">Efectivo</th><th class="dinero">Cheques</th><th class="dinero">Transferencias</th><th class="dinero">Gastos</th></tr></thead><tbody>
    @foreach ($totales['dias'] as $dia)
        <tr><td>{{ $dia->fecha->format('d/m/Y') }}</td><td class="dinero">${{ number_format((float) $dia->efectivo_cobrado, 2) }}</td><td class="dinero">${{ number_format((float) $dia->cheques_cobrado, 2) }}</td><td class="dinero">${{ number_format((float) $dia->transferencias_cobrado, 2) }}</td><td class="dinero">${{ number_format((float) $dia->gastos_total, 2) }}</td></tr>
    @endforeach
        <tr class="total"><td>Total</td><td class="dinero">{{ $plata($totales['efectivo']) }}</td><td class="dinero">{{ $plata($totales['cheques']) }}</td><td class="dinero">{{ $plata($totales['transferencias']) }}</td><td class="dinero">{{ $plata($totales['gastos']) }}</td></tr>
    </tbody></table>

    <h2>Gastos</h2>
    <table><thead><tr><th>Día</th><th>Concepto</th><th>Detalle</th><th class="dinero">Monto</th><th>Comprobante</th></tr></thead><tbody>
    @forelse ($gastos as $gasto)
        <tr><td>{{ $gasto->fecha->format('d/m/Y') }}</td><td>{{ $gasto->concepto->label() }}</td><td>{{ $gasto->detalle }}</td><td class="dinero">${{ number_format((float) $gasto->monto, 2) }}</td><td>{{ $gasto->archivo_ruta ? 'Con foto' : 'Sin comprobante' }}</td></tr>
    @empty
        <tr><td colspan="5">Sin gastos</td></tr>
    @endforelse
    </tbody></table>

    <h2>Entregados</h2>
    <table><thead><tr><th>Sala</th><th>CCF</th><th class="dinero">Monto</th><th>Forma</th><th class="dinero">Cobrado</th><th>Entregó</th></tr></thead><tbody>
    @forelse ($entregadas as $entrega)
        <tr><td>{{ $entrega->sala?->nombre ?? 'Sala sin identificar' }}</td><td class="numero">{{ $entrega->dte->numero_control }}</td><td class="dinero">${{ number_format((float) $entrega->dte->total_pagar, 2) }}</td><td>{{ $entrega->forma_cobro?->label() ?? 'Sin cobro (quedan)' }}</td><td class="dinero">{{ $entrega->monto_cobrado !== null ? '$'.number_format((float) $entrega->monto_cobrado, 2) : '—' }}</td><td>{{ $entrega->entregadoPor?->nombre }}</td></tr>
    @empty
        <tr><td colspan="6">No se entregó ninguno</td></tr>
    @endforelse
    </tbody></table>

    <h2>No entregados</h2>
    <table><thead><tr><th>Sala</th><th>CCF</th><th class="dinero">Monto</th><th>Motivo</th></tr></thead><tbody>
    @forelse ($noEntregadas as $entrega)
        <tr><td>{{ $entrega->sala?->nombre ?? 'Sala sin identificar' }}</td><td class="numero">{{ $entrega->dte->numero_control }}</td><td class="dinero">${{ number_format((float) $entrega->dte->total_pagar, 2) }}</td><td>{{ $entrega->motivo_no_entrega?->label() }}{{ $entrega->nota ? ': '.$entrega->nota : '' }}</td></tr>
    @empty
        <tr><td colspan="4">Se entregó todo</td></tr>
    @endforelse
    </tbody></table>

    <div class="entregar">
        <p>Efectivo cobrado {{ $plata($totales['efectivo']) }} − gastos {{ $plata($totales['gastos']) }}</p>
        <strong>{{ $totales['a_entregar'] < 0 ? 'A reintegrar al vendedor' : 'Efectivo a entregar' }} {{ $plata(abs($totales['a_entregar'])) }}</strong>
        @if ($totales['cheques'] > 0)<p>Más los cheques por {{ $plata($totales['cheques']) }}.</p>@endif
    </div>
    <table class="firmas"><tr><td>____________________________<br>Entrega: {{ $salida->entregaDinero?->nombre }}</td><td>____________________________<br>Recibe: Oficina</td></tr></table>
</body></html>
