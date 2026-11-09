<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hoja del pedido {{ $pedido->id }} · {{ $pedido->sala?->nombre }}</title>
    {{--
        La hoja de lo que salió: el «sugerido» en limpio que antes copiaba a mano la fábrica.
        Todo lo que pidió el vendedor, lo que llegó en cada envío vigente y una «F» en lo que
        faltó, con su motivo. Sin precios: con esto y la OC la oficina factura.

        Página impresa (docs/GUIA_PANTALLAS.md, «Páginas para imprimir»): documento aparte,
        igual que facturacion/listas/imprimir, con sus propios estilos y sin el bundle de la
        aplicación; sale siempre en blanco y negro, en pantalla y en papel.

        Dice el estado del pedido. La «F» solo con el pedido cerrado (Mandado o No se trae):
        mandado en parte, lo que falta «va en otro envío»; pendiente o anulado, un aviso y
        ninguna F, para que la hoja impresa no parezca un faltante de la fábrica.

        Variables: $pedido con sala, ruta, sacadoPor, lineas.producto,
        lineas.envioLineasVigentes y envios (solo los vigentes) con sus lineas; $volverAl (Y-m-d
        o null), el día al que vuelve «Llegó de la fábrica».
    --}}
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px;
            background: #fff;
            color: #111;
            font-family: "Segoe UI", Arial, Helvetica, sans-serif;
            font-size: 13px;
            line-height: 1.4;
        }
        h1 { margin: 0 0 4px; font-size: 20px; text-align: center; }
        .sub { margin: 0 0 18px; text-align: center; color: #555; }
        .datos { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px 24px; margin-bottom: 16px; }
        .datos div { min-width: 0; }
        .datos dt { font-size: 10px; letter-spacing: .08em; text-transform: uppercase; color: #555; }
        .datos dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
        .mono { font-family: "Consolas", "Courier New", monospace; }
        .tabla-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 6px 8px; vertical-align: top; }
        thead th { background: #eee; font-size: 10px; letter-spacing: .04em; text-transform: uppercase; text-align: center; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        td.falta { text-align: center; white-space: nowrap; }
        .f { display: inline-block; min-width: 1.6em; border: 2px solid #111; border-radius: 4px; font-size: 16px; font-weight: 800; text-align: center; }
        tr.falto td { background: #f2f2f2; }
        .leyenda { margin-top: 8px; font-size: 11px; color: #555; }
        .aviso { margin: 0 0 16px; padding: 8px 12px; border: 2px solid #111; border-radius: 4px; font-weight: 700; text-align: center; }
        .firma { margin-top: 48px; display: flex; flex-wrap: wrap; gap: 32px 48px; }
        .firma div { flex: 1 1 200px; border-top: 1px solid #333; padding-top: 4px; text-align: center; font-size: 11px; }
        .no-print { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; margin-bottom: 16px; }
        .no-print a, .no-print button {
            display: inline-flex; align-items: center; min-height: 44px; padding: 0 16px;
            border: 1px solid #333; background: #fff; color: #111; font: inherit; text-decoration: none;
            border-radius: 6px; cursor: pointer;
        }
        @media print {
            body { padding: 0; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
@php
    $envios = $pedido->envios;
    $porEnvio = $envios->mapWithKeys(fn ($envio) => [
        $envio->id => $envio->lineas->mapWithKeys(fn ($l) => [$l->pedido_sala_linea_id => $l->cantidadLegible()])->all(),
    ]);
    $variosEnvios = $envios->count() > 1;
    $estado = $pedido->estado;
    $cerrado = $estado->cerrado();
    $parcial = $estado === \App\Enums\EstadoPedidoSala::Parcial;
@endphp

<div class="no-print">
    @can('viewAny', App\Models\Dte::class)
        <a href="{{ route('facturacion.llego-de-fabrica', ['fecha' => $volverAl]) }}">Volver a «Llegó de la fábrica»</a>
    @endcan
    <a href="{{ route('rutas.pedidos.show', $pedido) }}">Volver al pedido</a>
    <button type="button" onclick="window.print()">Imprimir</button>
</div>

<h1>Lo que salió de la fábrica</h1>
<p class="sub">Pedido n.º {{ $pedido->id }}</p>

@if ($estado === \App\Enums\EstadoPedidoSala::Anulado)
    <p class="aviso" role="alert">Este pedido está anulado: no se factura.</p>
@elseif ($estado === \App\Enums\EstadoPedidoSala::Registrado)
    <p class="aviso" role="alert">La fábrica todavía no mandó nada de este pedido.</p>
@endif

<dl class="datos">
    <div><dt>Sala</dt><dd>{{ $pedido->sala?->nombre }}{{ $pedido->sala?->codigo ? ' · '.$pedido->sala->codigo : '' }}</dd></div>
    <div><dt>Estado</dt><dd>{{ $estado->label() }}</dd></div>
    <div><dt>Ruta</dt><dd>{{ $pedido->ruta?->nombre ?? 'Sin ruta' }}</dd></div>
    <div><dt>Vendedor</dt><dd>{{ $pedido->sacadoPor?->nombre ?? '—' }}</dd></div>
    <div><dt>Fecha del pedido</dt><dd>{{ $pedido->fecha->format('d/m/Y') }}</dd></div>
    <div><dt>Orden de compra</dt><dd class="mono">{{ $pedido->numero_orden_compra ?? 'Sin OC' }}</dd></div>
    <div><dt>Envíos</dt><dd>
        @forelse ($envios as $envio)
            <span style="display:block">Envío {{ $envio->numero }} · {{ $envio->fecha->format('d/m/Y') }} {{ $envio->created_at->format('H:i') }}</span>
        @empty
            Todavía no salió nada.
        @endforelse
    </dd></div>
</dl>

<div class="tabla-wrap">
    <table>
        <thead>
            <tr>
                <th>Código</th>
                <th>Producto</th>
                <th class="num">Pidió</th>
                @if ($variosEnvios)
                    @foreach ($envios as $envio)
                        <th class="num">Envío {{ $envio->numero }}<br>{{ $envio->fecha->format('d/m') }} {{ $envio->created_at->format('H:i') }}</th>
                    @endforeach
                @endif
                <th class="num">Llegó</th>
                <th>Falta</th>
                <th>Motivo</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($pedido->lineas as $linea)
                @php
                    $pendiente = $linea->tienePendiente();
                    $falto = $pendiente && $cerrado;
                @endphp
                <tr class="{{ $falto ? 'falto' : '' }}">
                    <td class="mono">{{ $linea->producto?->codigo }}</td>
                    <td>{{ $linea->producto?->nombre }}</td>
                    <td class="num">{{ $linea->cantidadLegible() }}</td>
                    @if ($variosEnvios)
                        @foreach ($envios as $envio)
                            <td class="num">{{ $porEnvio[$envio->id][$linea->id] ?? '—' }}</td>
                        @endforeach
                    @endif
                    <td class="num">{{ \App\Models\PedidoSalaLinea::legible($linea->mandado()) }}</td>
                    <td class="falta">
                        @if ($falto)
                            <span class="f" role="img" aria-label="Faltó">F</span> {{ \App\Models\PedidoSalaLinea::legible($linea->pendiente()) }}
                        @elseif ($pendiente && $parcial)
                            Va en otro envío: {{ \App\Models\PedidoSalaLinea::legible($linea->pendiente()) }}
                        @endif
                    </td>
                    <td>{{ $falto ? ($linea->motivo_no_enviado ?? '') : '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@if ($cerrado && $pedido->faltaAlgo())
    <p class="leyenda">F: faltó. Al lado va cuánto faltó.</p>
@elseif ($parcial)
    <p class="leyenda">Mandado en parte: lo que falta todavía va a salir en otro envío.</p>
@endif

<div class="firma">
    <div>Firma de quien entrega</div>
    <div>Firma de quien recibe</div>
</div>

</body>
</html>
