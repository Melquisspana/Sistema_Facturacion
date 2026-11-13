{{-- PDF del día del vendedor (maqueta del 10/11/2026): lo entregado, lo que no se pudo, lo
     cobrado y sus gastos, con el efectivo que entrega. Es el control del viaje: lo firman
     quien entrega y quien recibe. Lo baja el vendedor en «Terminar el día» y va adjunto en el
     correo diario.

     Forma (12/11/2026, pedido del dueño): la de los documentos impresos (docs/GUIA_PANTALLAS.md,
     «Documentos impresos»), como el PDF del CCF y la hoja de despacho de la fábrica, con el
     acento AZUL PIZARRA y «RESUMEN DEL DÍA» con «No es factura · Sin valor tributario» en el
     recuadro. Lo común sale de components/documento. Se lee igual en blanco y negro: nada se
     dice solo con color. Hoja carta vertical. DomPDF: sin flex ni grid, todo con tablas.

     Variables: $resumen (DiaVendedor::resumen), $persona, $empresa (NombreEmpresa::actual()),
     $generado (Carbon local) y $logo (data-URI de DtePdfService::logoSrc(), puede faltar). --}}
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
    <x-documento.estilos acento="pizarra" />

    @page { size: letter portrait; margin: 26px 30px; }
    body { font-size: 10px; }
    /* Los totales en cajitas de borde fino, como los del CCF (separadas con padding: DomPDF no corre márgenes negativos). */
    .datos { margin: 10px 0 4px; }
    .datos td { width: 25%; padding: 0 3px; vertical-align: top; }
    .datos td:first-child { padding-left: 0; }
    .datos td:last-child { padding-right: 0; }
    .datos.tres td { width: 33.33%; }
    .caja { background: #FAFBFC; border: 1px solid #C9CDD4; border-radius: 5px; padding: 7px 10px; }
    .caja.clave { background: #2B4A6F; border-color: #2B4A6F; }
    .caja.clave .etiqueta, .caja.clave .valor { color: #fff; }
    .etiqueta { display: block; font-size: 7.5px; color: #6B7280; text-transform: uppercase; letter-spacing: .8px; }
    .valor { display: block; font-size: 14px; font-weight: bold; color: #20242C; margin-top: 2px; }
    .items tr.par td { background: #F8F9FA; }
    .items td.mono { font-size: 8.5px; }
    .num, .items th.num { text-align: right; white-space: nowrap; }
    .total td { font-weight: bold; border-top: 1.5px solid #2B4A6F; }
    .marca { display: inline-block; border: 1px solid; border-radius: 3px; padding: 0 4px; font-weight: bold; }
    .ok { color: #235C42; background: #EEF5F0; border-color: #2F6B4E; }
    .no { color: #8A2440; background: #FBEDF0; border-color: #B0334F; }
    .aviso { color: #7A4A0C; }
    .vacio { color: #6B7280; padding: 8px 6px; }
</style>
</head>
<body>
    <x-documento.encabezado :empresa="$empresa" :logo="$logo ?? null" detalle="Rutas · el día del vendedor"
        etiqueta="Documento interno de Rutas" titulo="RESUMEN DEL DÍA">
        <tr class="key"><td class="k">{{ ucfirst($fecha->copy()->locale('es')->dayName) }}</td><td class="v">{{ $fecha->format('d/m/Y') }}</td></tr>
        <tr><td class="k">Vendedor</td><td class="v">{{ $persona->nombre }}</td></tr>
    </x-documento.encabezado>

    <table class="datos tres"><tr>
        <td><div class="caja"><span class="etiqueta">Entregas</span><span class="valor">{{ $resumen['entregadas']->count() }}</span></div></td>
        <td><div class="caja"><span class="etiqueta">No se pudo</span><span class="valor">{{ $resumen['no_entregadas']->count() }}</span></div></td>
        <td><div class="caja"><span class="etiqueta">Cobrado</span><span class="valor">{{ $dinero($resumen['cobrado']) }}</span></div></td>
    </tr></table>

    <h2>Lo entregado</h2>
    <table class="items">
        <thead><tr><th>Sala</th><th>CCF</th><th>Hora</th><th>Pago</th><th class="num">Monto</th></tr></thead>
        <tbody>
            @forelse ($resumen['entregadas'] as $i => $entrega)
                @php
                    $portal = $resumen['portal_id'] !== null && (int) $entrega->dte?->cliente_id === $resumen['portal_id'];
                @endphp
                <tr class="{{ $i % 2 ? 'par' : '' }}">
                    <td><span class="marca ok" aria-label="Entregado">E</span> {{ $entrega->sala?->nombre ?? 'Sala sin identificar' }}
                        {{-- Lo anotó otro vendedor por él (12/11/2026): la entrega es suya igual. --}}
                        @if ($entrega->registrado_por && (int) $entrega->registrado_por !== (int) $persona->user_id)
                            <br><span class="aviso">Anotó: {{ $entrega->registradoPor?->name }}</span>
                        @endif
                    </td>
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
    <table class="items">
        <thead><tr><th>Sala</th><th>CCF</th><th>Hora</th><th>Motivo</th></tr></thead>
        <tbody>
            @forelse ($resumen['no_entregadas'] as $i => $entrega)
                <tr class="{{ $i % 2 ? 'par' : '' }}">
                    <td><span class="marca no" aria-label="No se pudo">N</span> {{ $entrega->sala?->nombre ?? 'Sala sin identificar' }}
                        @if ($entrega->registrado_por && (int) $entrega->registrado_por !== (int) $persona->user_id)
                            <br><span class="aviso">Anotó: {{ $entrega->registradoPor?->name }}</span>
                        @endif
                    </td>
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
    <table class="items">
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

    <h2>Gastos del día</h2>
    <table class="items">
        <thead><tr><th>Concepto</th><th>Detalle</th><th>Comprobante</th><th class="num">Monto</th></tr></thead>
        <tbody>
            @forelse ($resumen['gastos'] as $i => $gasto)
                <tr class="{{ $i % 2 ? 'par' : '' }}">
                    <td>{{ $gasto->concepto->label() }}</td>
                    <td>{{ $gasto->detalle }}</td>
                    <td>{{ $gasto->archivo_ruta ? 'Con foto' : 'Sin foto' }}</td>
                    <td class="num">${{ number_format((float) $gasto->monto, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="vacio">No anotaste gastos este día.</td></tr>
            @endforelse
            <tr class="total"><td colspan="3">Total de gastos</td><td class="num">{{ $dinero($resumen['gastos_total']) }}</td></tr>
        </tbody>
    </table>

    <table class="datos"><tr>
        <td><div class="caja"><span class="etiqueta">Efectivo cobrado</span><span class="valor">{{ $dinero($resumen['efectivo']) }}</span></div></td>
        <td><div class="caja"><span class="etiqueta">Menos gastos</span><span class="valor">{{ $dinero($resumen['gastos_total']) }}</span></div></td>
        <td><div class="caja clave"><span class="etiqueta">Efectivo a entregar</span><span class="valor">{{ $dinero($resumen['a_entregar']) }}</span></div></td>
        <td><div class="caja"><span class="etiqueta">Cheques y transferencias</span><span class="valor">{{ $dinero($resumen['cheque'] + $resumen['transferencia']) }}</span></div></td>
    </tr></table>
    <p class="aviso">Con esta hoja, el vendedor entrega a la oficina el efectivo, los cheques y los quedan. En el sistema no se marca nada más.</p>

    <x-documento.firmas :quienes="['Entregó', 'Recibió']" />

    <p class="pie">Resumen interno del día de {{ $empresa }}. No es factura ni documento tributario. Generado el {{ $generado->format('d/m/Y H:i') }}.</p>
</body>
</html>
