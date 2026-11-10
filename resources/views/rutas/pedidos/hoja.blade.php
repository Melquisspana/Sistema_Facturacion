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
        igual que facturacion/listas/imprimir, con sus propios estilos, sin el bundle de la
        aplicación y sin modo oscuro. Lleva color de acento (índigo, verde, ámbar, rojo) que se
        imprime tal cual (print-color-adjust: exact), pero se lee igual en una impresora en
        blanco y negro: la «F» sigue siendo una letra en un recuadro y los textos de color son
        tonos oscuros. Entra en hoja carta vertical.

        Dice el estado del pedido. La «F» solo con el pedido cerrado (Mandado o No se trae):
        mandado en parte, lo que falta «va en otro envío»; pendiente o anulado, un aviso y
        ninguna F, para que la hoja impresa no parezca un faltante de la fábrica.

        Ya facturado, lleva el n.º de CCF en los datos y la columna «Regresa»: lo que llegó menos
        lo que quedó en el CCF (lo que la OC no pidió y se bajó en el editor).

        Variables: $pedido con sala, ruta, sacadoPor, lineas.producto,
        lineas.envioLineasVigentes y envios (solo los vigentes) con sus lineas y su dte; $regresa
        [producto_id => llego, facturado, regresa] (vacío si no hay nada facturado); $volverAl
        (Y-m-d o null), el día al que vuelve «Llegó de la fábrica».
    --}}
    <style>
        :root {
            --tinta: #111827;
            --suave: #4b5563;
            --indigo: #3730a3;
            --indigo-borde: #c7d2fe;
            --indigo-claro: #eef2ff;
            --verde: #166534;
            --verde-claro: #dcfce7;
            --ambar: #92400e;
            --ambar-claro: #fef3c7;
            --rojo: #991b1b;
            --rojo-claro: #fee2e2;
            --gris: #374151;
            --gris-claro: #f3f4f6;
        }
        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body {
            margin: 0;
            padding: 24px 16px;
            background: #fff;
            color: var(--tinta);
            font-family: "Segoe UI", Arial, Helvetica, sans-serif;
            font-size: 13px;
            line-height: 1.4;
        }
        .hoja { max-width: 820px; margin: 0 auto; }
        .mono { font-family: "Consolas", "Courier New", monospace; }

        .franja {
            display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 8px 24px;
            margin-bottom: 16px; padding: 14px 20px;
            background: var(--indigo); color: #fff; border-radius: 10px;
            border-bottom: 5px solid #818cf8;
        }
        .empresa { margin: 0 0 2px; font-size: 11px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: #e0e7ff; }
        h1 { margin: 0; font-size: 22px; font-weight: 800; line-height: 1.2; }
        .numero { margin: 0; text-align: right; }
        .numero span { display: block; font-size: 10px; letter-spacing: .12em; text-transform: uppercase; color: #e0e7ff; }
        .numero strong { font-size: 28px; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }

        .aviso { margin: 0 0 16px; padding: 10px 14px; border: 2px solid; border-radius: 8px; font-weight: 700; text-align: center; }
        .aviso-rojo { background: var(--rojo-claro); border-color: var(--rojo); color: var(--rojo); }
        .aviso-ambar { background: var(--ambar-claro); border-color: var(--ambar); color: var(--ambar); }

        .datos { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin: 0 0 16px; }
        .datos > div { min-width: 0; padding: 8px 12px; background: var(--indigo-claro); border: 1px solid var(--indigo-borde); border-radius: 8px; }
        .datos .ancho { grid-column: span 2; }
        .datos dt { font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--indigo); }
        .datos dd { margin: 2px 0 0; font-size: 14px; font-weight: 600; overflow-wrap: anywhere; }
        .datos dd .envio { display: block; }

        .insignia { display: inline-block; padding: 1px 10px; border: 1.5px solid; border-radius: 999px; font-size: 13px; font-weight: 700; }
        .insignia-green { background: var(--verde-claro); border-color: var(--verde); color: var(--verde); }
        .insignia-amber { background: var(--ambar-claro); border-color: var(--ambar); color: var(--ambar); }
        .insignia-red { background: var(--rojo-claro); border-color: var(--rojo); color: var(--rojo); }
        .insignia-gray { background: var(--gris-claro); border-color: var(--gris); color: var(--gris); }

        .tabla-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; border: 1px solid var(--indigo); }
        th, td { border: 1px solid #a5b4fc; padding: 6px 8px; vertical-align: middle; }
        thead th {
            background: var(--indigo); color: #fff; border-color: #6366f1;
            font-size: 10px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; text-align: left;
        }
        tbody tr:nth-child(even) td { background: #f5f7ff; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        td.num { font-size: 16px; font-weight: 600; }
        td.llego { font-weight: 800; color: var(--verde); }
        td.falta { white-space: nowrap; font-size: 15px; }
        .f {
            display: inline-block; min-width: 1.7em; margin-right: 4px; padding: 0 2px;
            border: 2px solid var(--rojo); border-radius: 4px; background: var(--rojo-claro); color: var(--rojo);
            font-size: 17px; font-weight: 900; line-height: 1.3; text-align: center;
        }
        .cuanto-falto { font-weight: 800; color: var(--rojo); }
        .otro-envio {
            display: inline-block; padding: 1px 8px; border: 1.5px solid var(--ambar); border-radius: 6px;
            background: var(--ambar-claro); color: var(--ambar); font-size: 12px; font-weight: 700;
        }
        td.motivo { font-style: italic; color: var(--gris); }
        td.regresa { font-weight: 800; color: var(--ambar); }
        tbody tr.falto td { background: #fff1f1; }

        .leyenda { margin: 8px 0 0; padding: 6px 10px; border-left: 4px solid; border-radius: 4px; font-size: 12px; font-weight: 600; }
        .leyenda-rojo { border-color: var(--rojo); background: var(--rojo-claro); color: var(--rojo); }
        .leyenda-ambar { border-color: var(--ambar); background: var(--ambar-claro); color: var(--ambar); }

        .firma { margin-top: 56px; display: flex; flex-wrap: wrap; gap: 32px 48px; }
        .firma div { flex: 1 1 200px; border-top: 2px solid var(--indigo); padding-top: 4px; text-align: center; font-size: 11px; color: var(--suave); }

        .no-print { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; max-width: 820px; margin: 0 auto 16px; }
        .no-print a, .no-print button {
            display: inline-flex; align-items: center; min-height: 44px; padding: 0 16px;
            border: 1px solid #d1d5db; background: #fff; color: #374151; font: inherit; font-weight: 500;
            text-decoration: none; border-radius: 6px; cursor: pointer;
        }
        .no-print a:hover, .no-print button:hover { background: #f9fafb; }
        .no-print a:focus-visible, .no-print button:focus-visible { outline: 2px solid #4f46e5; outline-offset: 2px; }

        @media (max-width: 640px) {
            .datos { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 400px) {
            .datos { grid-template-columns: minmax(0, 1fr); }
            .datos .ancho { grid-column: auto; }
            .numero { text-align: left; }
        }
        @page { size: letter portrait; margin: 12mm; }
        @media print {
            body { padding: 0; }
            .hoja { max-width: none; }
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
    // Sin consultas: los envíos ya traen su CCF.
    $facturacion = \App\Services\Rutas\FacturarPedido::estado($pedido);
    $conCcf = $facturacion['facturados']->isNotEmpty() || $facturacion['borrador'] !== null;
    $enviosTexto = fn ($lista) => ($lista->count() === 1 ? 'envío ' : 'envíos ').$lista->pluck('numero')->join(', ', ' y ');
    $enviosDe = fn ($dte) => $enviosTexto($envios->where('dte_id', $dte->id));
    $conRegresa = $regresa !== [];
@endphp

<div class="no-print">
    @can('viewAny', App\Models\Dte::class)
        <a href="{{ route('facturacion.llego-de-fabrica', ['fecha' => $volverAl]) }}">Volver a «Llegó de la fábrica»</a>
    @endcan
    <a href="{{ route('rutas.pedidos.show', $pedido) }}">Volver al pedido</a>
    <button type="button" onclick="window.print()">Imprimir</button>
</div>

<main class="hoja">
    <header class="franja">
        <div>
            <p class="empresa">{{ \App\Support\NombreEmpresa::actual() }}</p>
            <h1>Lo que salió de la fábrica</h1>
        </div>
        <p class="numero"><span>Pedido n.º</span> <strong>{{ $pedido->id }}</strong></p>
    </header>

    @if ($estado === \App\Enums\EstadoPedidoSala::Anulado)
        <p class="aviso aviso-rojo" role="alert">Este pedido está anulado: no se factura.</p>
    @elseif ($estado === \App\Enums\EstadoPedidoSala::Registrado)
        <p class="aviso aviso-ambar" role="alert">La fábrica todavía no mandó nada de este pedido.</p>
    @endif

    <dl class="datos">
        <div class="ancho"><dt>Sala</dt><dd>{{ $pedido->sala?->nombre }}{{ $pedido->sala?->codigo ? ' · '.$pedido->sala->codigo : '' }}</dd></div>
        <div><dt>Estado</dt><dd><span class="insignia insignia-{{ $estado->color() }}">{{ $estado->label() }}</span></dd></div>
        <div><dt>Orden de compra</dt><dd class="mono">{{ $pedido->numero_orden_compra ?? 'Sin OC' }}</dd></div>
        <div><dt>Ruta</dt><dd>{{ $pedido->ruta?->nombre ?? 'Sin ruta' }}</dd></div>
        <div><dt>Vendedor</dt><dd>{{ $pedido->sacadoPor?->nombre ?? '—' }}</dd></div>
        <div><dt>Fecha del pedido</dt><dd>{{ $pedido->fecha->format('d/m/Y') }}</dd></div>
        <div><dt>Envíos</dt><dd>
            @forelse ($envios as $envio)
                <span class="envio">Envío {{ $envio->numero }} · {{ $envio->fecha->format('d/m/Y') }} {{ $envio->created_at->format('H:i') }}</span>
            @empty
                Todavía no salió nada.
            @endforelse
        </dd></div>
        @if ($conCcf)
            <div class="ancho"><dt>Facturación</dt><dd>
                @foreach ($facturacion['facturados'] as $dte)
                    <span class="envio">CCF n.º {{ $dte->numero_control ? '…'.\App\Support\OrdenCompra::ultimosDigitos($dte->numero_control) : $dte->etiquetaNumeroSistema() }} · {{ $enviosDe($dte) }}</span>
                @endforeach
                @if ($facturacion['borrador'])
                    <span class="envio">CCF en borrador · {{ $enviosDe($facturacion['borrador']) }}</span>
                @endif
                @if ($facturacion['por_facturar']->isNotEmpty())
                    <span class="envio">Sin facturar · {{ $enviosTexto($facturacion['por_facturar']) }}</span>
                @endif
            </dd></div>
        @endif
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
                    @if ($conRegresa)
                        <th class="num">Regresa</th>
                    @endif
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
                        <td class="num llego">{{ \App\Models\PedidoSalaLinea::legible($linea->mandado()) }}</td>
                        @if ($conRegresa)
                            <td class="num regresa">{{ isset($regresa[$linea->producto_id]) ? \App\Models\PedidoSalaLinea::legible($regresa[$linea->producto_id]['regresa']) : '—' }}</td>
                        @endif
                        <td class="falta">
                            @if ($falto)
                                <span class="f" role="img" aria-label="Faltó">F</span> <span class="cuanto-falto">{{ \App\Models\PedidoSalaLinea::legible($linea->pendiente()) }}</span>
                            @elseif ($pendiente && $parcial)
                                <span class="otro-envio">Va en otro envío: {{ \App\Models\PedidoSalaLinea::legible($linea->pendiente()) }}</span>
                            @endif
                        </td>
                        <td class="motivo">{{ $falto ? ($linea->motivo_no_enviado ?? '') : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if ($conRegresa)
        <p class="leyenda leyenda-ambar">Regresa: lo que llegó y no se facturó porque la OC no lo pidió.</p>
    @endif
    @if ($cerrado && $pedido->faltaAlgo())
        <p class="leyenda leyenda-rojo">F: faltó. Al lado va cuánto faltó.</p>
    @elseif ($parcial)
        <p class="leyenda leyenda-ambar">Mandado en parte: lo que falta todavía va a salir en otro envío.</p>
    @endif

    <div class="firma">
        <div>Firma de quien entrega</div>
        <div>Firma de quien recibe</div>
    </div>
</main>

</body>
</html>
