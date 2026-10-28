<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><title>Liquidación {{ $fecha }}</title>
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
    .entregar { border: 2px solid #111; padding: 13px; margin-top: 14px; page-break-inside: avoid; }
    .entregar strong { font-size: 21px; }
    .firmas { margin-top: 55px; page-break-inside: avoid; }
    .firmas td { width: 50%; text-align: center; border: none; }
    .nota { border: 1px solid #777; padding: 7px; margin-top: 12px; }
</style></head>
<body>
    <h1>Liquidación de ruta</h1>
    <p><strong>{{ $empresa?->razon_social }}</strong></p>
    <p><strong>Ruta:</strong> {{ $salida->ruta->nombre }} · <strong>Fecha:</strong> {{ \Illuminate\Support\Carbon::parse($fecha)->format('d/m/Y') }}</p>
    <p><strong>Vendedores:</strong> {{ $salida->personal->pluck('nombre')->join(', ') }}</p>
    <p><strong>Entrega el dinero:</strong> {{ $cierre->entregaDinero?->nombre ?? 'Sin cobros' }}</p>
    @foreach ($entregas->groupBy('cliente_sucursal_id') as $filas)
        <h2>{{ $filas->first()->sala?->nombre ?? 'Sala sin identificar' }}</h2>
        <table><thead><tr><th>CCF</th><th class="dinero">Monto</th><th>Forma</th><th class="dinero">Esperado</th><th class="dinero">Cobrado</th></tr></thead><tbody>
        @foreach ($filas as $entrega)
            <tr><td class="numero">{{ $entrega->dte->numero_control }}</td><td class="dinero">${{ number_format((float) $entrega->dte->total_pagar, 2) }}</td><td>{{ $entrega->forma_cobro?->label() ?? 'Sin cobro (quedan)' }}</td><td class="dinero">{{ $entrega->monto_esperado !== null ? '$'.number_format((float) $entrega->monto_esperado, 2) : '—' }}</td><td class="dinero">{{ $entrega->monto_cobrado !== null ? '$'.number_format((float) $entrega->monto_cobrado, 2) : '—' }}</td></tr>
        @endforeach
        </tbody></table>
    @endforeach
    <h2>Totales por forma de cobro</h2>
    <table><thead><tr><th>Forma</th><th class="dinero">Esperado</th><th class="dinero">Cobrado</th></tr></thead><tbody>
    @foreach (['efectivo' => 'Efectivo', 'cheques' => 'Cheques', 'transferencias' => 'Transferencias'] as $campo => $label)
        <tr><td>{{ $label }}</td><td class="dinero">${{ number_format((float) $cierre->{$campo.'_esperado'}, 2) }}</td><td class="dinero">${{ number_format((float) $cierre->{$campo.'_cobrado'}, 2) }}</td></tr>
    @endforeach
    <tr><td>Gastos</td><td></td><td class="dinero">${{ number_format((float) $cierre->gastos_total, 2) }}</td></tr>
    </tbody></table>
    <div class="entregar"><p>Efectivo cobrado ${{ number_format((float) $cierre->efectivo_cobrado, 2) }} − gastos ${{ number_format((float) $cierre->gastos_total, 2) }}</p><strong>Efectivo a entregar ${{ number_format((float) $cierre->a_entregar, 2) }}</strong></div>
    @if ($cierre->reabierto_en)<p class="nota">Este día se reabrió el {{ $cierre->reabierto_en->format('d/m/Y H:i') }}: {{ $cierre->reabierto_motivo }}. {{ $cierre->estaCerrado() ? 'Liquidación recalculada al volver a cerrar.' : 'Día reabierto: los importes del cierre están pendientes de recalcular.' }}</p>@endif
    <table class="firmas"><tr><td>____________________________<br>Entrega: {{ $cierre->entregaDinero?->nombre }}</td><td>____________________________<br>Recibe</td></tr></table>
</body></html>
