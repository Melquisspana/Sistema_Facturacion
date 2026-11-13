<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hoja del pedido {{ $pedido->id }} · {{ $pedido->nombreSala() }}</title>
    {{--
        La hoja de lo que salió: el «sugerido» en limpio que antes copiaba a mano la fábrica.
        Todo lo que pidió el vendedor, lo que llegó en cada envío vigente y una «F» en lo que
        faltó, con su motivo. Sin precios: con esto y la OC la oficina factura.

        Forma (12/11/2026, pedido del dueño): la de los documentos impresos (docs/GUIA_PANTALLAS.md,
        «Documentos impresos»), como el PDF del CCF, la hoja de despacho de la fábrica y el PDF
        del día, con el acento OCRE y «HOJA DE PEDIDO» con «No es factura · Sin valor tributario»
        en el recuadro. Lo común sale de components/documento. Documento aparte, sin el bundle de
        la aplicación ni modo oscuro, carta vertical. Se lee igual en una impresora en blanco y
        negro: la «F» sigue siendo una letra en un recuadro y los textos de color son tonos
        oscuros. La sirven la oficina (rutas.pedidos.hoja) y «Por entregar» (rutas.por-entregar.hoja).

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
        <x-documento.estilos acento="ocre" />

        @page { size: letter portrait; margin: 12mm; }
        body { padding: 24px 16px; font-size: 12px; }
        .hoja { max-width: 820px; margin: 0 auto; }

        /* Aviso del estado: el ámbar común (.st) y una variante roja para el anulado. */
        .st.rojo { border-color: #E3B5BF; border-left-color: #B0334F; background: #FBEDF0; color: #8A2440; }

        /* Sala y envíos en dos bloques lado a lado (en el celular, uno debajo del otro). */
        .bloques > tbody > tr > td { width: 50%; vertical-align: top; padding: 0 .6em; }
        .bloques > tbody > tr > td:first-child { padding-left: 0; }
        .bloques > tbody > tr > td:last-child { padding-right: 0; }
        .rec-name { font-size: 1.25em; font-weight: bold; }
        .f-dato { margin-top: .3em; }
        .f-dato .k { color: #8A9099; }
        .envio { display: block; }

        .insignia { display: inline-block; padding: 0 .7em; border: 1px solid; border-radius: 999px; font-weight: bold; }
        .insignia-green { background: #EEF5F0; border-color: #2F6B4E; color: #235C42; }
        .insignia-amber { background: #FBF6EC; border-color: #C8881E; color: #7A4A0C; }
        .insignia-red { background: #FBEDF0; border-color: #B0334F; color: #8A2440; }
        .insignia-gray { background: #F2F3F5; border-color: #8A9099; color: #374151; }

        .tabla-wrap { overflow-x: auto; }
        .items tbody td { vertical-align: middle; }
        .items tbody tr:nth-child(even) td { background: #F8F9FA; }
        .items th.num, .items td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .items td.num { font-size: 1.3em; font-weight: bold; }
        .items td.cod { font-weight: bold; font-size: .92em; }
        .items td.llego { color: #235C42; }
        .items td.falta { white-space: nowrap; font-size: 1.2em; }
        .f {
            display: inline-block; min-width: 1.7em; margin-right: 4px; padding: 0 2px;
            border: 2px solid #8A2440; border-radius: 4px; background: #FBEDF0; color: #8A2440;
            font-size: 1.15em; font-weight: 900; line-height: 1.3; text-align: center;
        }
        .cuanto-falto { font-weight: bold; color: #8A2440; }
        .otro-envio {
            display: inline-block; padding: 1px 8px; border: 1px solid #C8881E; border-radius: 4px;
            background: #FBF6EC; color: #7A4A0C; font-size: .8em; font-weight: bold;
        }
        .items td.motivo { font-style: italic; color: #4A4F58; }
        .items td.regresa { color: #7A4A0C; }
        .items tbody tr.falto td { background: #FDF3F5; }

        .leyenda { margin: .6em 0 0; padding: .4em .85em; border: 1px solid; border-left-width: 3px; border-radius: 3px; font-size: .92em; font-weight: bold; }
        .leyenda-rojo { border-color: #E3B5BF; border-left-color: #B0334F; background: #FBEDF0; color: #8A2440; }
        .leyenda-ambar { border-color: #E7C98D; border-left-color: #C8881E; background: #FBF6EC; color: #7A4A0C; }

        .observaciones { page-break-inside: avoid; }
        .observaciones h2.sec-h { margin: 0; }
        .observaciones ul { margin: 0; padding-left: 2.2em; }
        .observaciones li { margin: .2em 0; }

        .no-print { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; max-width: 820px; margin: 0 auto 16px; }
        .no-print a, .no-print button { display: inline-flex; align-items: center; min-height: 44px; padding: 0 16px; border: 1px solid #C9CDD4; background: #fff; color: #20242C; font: inherit; font-weight: 600; text-decoration: none; border-radius: 6px; cursor: pointer; }
        .no-print a:hover, .no-print button:hover { background: #F8F9FA; }
        .no-print a:focus-visible, .no-print button:focus-visible { outline: 2px solid #9A6B1F; outline-offset: 2px; }

        /* En el celular el recuadro y los bloques bajan uno debajo del otro (solo pantalla; al imprimir, al lado). */
        @media screen and (max-width: 600px) {
            td.head-emisor, td.head-dbox { display: block; width: auto; padding: 0 0 14px; }
            .bloques > tbody > tr > td { display: block; width: auto; padding: 0; }
        }

        @media print {
            body { padding: 0; }
            .hoja { max-width: none; }
            .head, .sec { page-break-inside: avoid; }
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
    $empresa = \App\Support\NombreEmpresa::actual();
@endphp

<div class="no-print">
    @can('viewAny', App\Models\Dte::class)
        <a href="{{ route('facturacion.llego-de-fabrica', ['fecha' => $volverAl]) }}">Volver a «Llegó de pedidos»</a>
    @endcan
    @if ($volverPorEntregar ?? false)
        <a href="{{ route('rutas.por-entregar.index') }}">Volver a «Por entregar»</a>
    @else
        <a href="{{ route('rutas.pedidos.show', $pedido) }}">Volver al pedido</a>
    @endif
    <button type="button" onclick="window.print()">Imprimir</button>
</div>

<main class="hoja">
    <x-documento.encabezado :empresa="$empresa" :logo="asset('images/dte/logo-pdf.png')" detalle="Lo que se despachó"
        etiqueta="Documento interno de Rutas" titulo="HOJA DE PEDIDO">
        <tr class="key"><td class="k">Pedido n.º</td><td class="v">{{ $pedido->id }}</td></tr>
        <tr><td class="k">Fecha del pedido</td><td class="v">{{ $pedido->fecha->format('d/m/Y') }}</td></tr>
        <tr><td class="k">Vendedor</td><td class="v">{{ $pedido->sacadoPor?->nombre ?? '—' }}</td></tr>
        <tr><td class="k">Orden de compra</td><td class="v mono">{{ $pedido->numero_orden_compra ?? 'Sin OC' }}</td></tr>
    </x-documento.encabezado>

    @if ($estado === \App\Enums\EstadoPedidoSala::Anulado)
        <p class="st rojo" role="alert"><strong>Este pedido está anulado: no se factura.</strong></p>
    @elseif ($estado === \App\Enums\EstadoPedidoSala::Registrado)
        <p class="st" role="alert"><strong>Todavía no se despachó nada de este pedido.</strong></p>
    @endif

    <table class="bloques"><tr>
        <td>
            <section class="sec">
                <div class="sec-h">Sala de entrega</div>
                <div class="sec-b">
                    <div class="rec-name">{{ $pedido->nombreSala() }}{{ $pedido->sala?->codigo ? ' · '.$pedido->sala->codigo : '' }}</div>
                    {{-- La zona de la sala o, en la tienda nueva, la del viaje; si no hay, no va
                         (antes decía «Ruta: Sin ruta»). --}}
                    @if ($pedido->zonaNombre() !== 'Sin zona')
                        <div class="f-dato"><span class="k">Zona:</span> {{ $pedido->zonaNombre() }}</div>
                    @endif
                    <div class="f-dato"><span class="k">Estado:</span> <span class="insignia insignia-{{ $estado->color() }}">{{ $estado->label() }}</span></div>
                </div>
            </section>
        </td>
        <td>
            <section class="sec">
                <div class="sec-h">Envíos</div>
                <div class="sec-b">
                    @forelse ($envios as $envio)
                        <span class="envio">Envío {{ $envio->numero }} · {{ $envio->fecha->format('d/m/Y') }} {{ $envio->created_at->format('H:i') }}</span>
                    @empty
                        Todavía no salió nada.
                    @endforelse
                    @if ($conCcf)
                        <div class="f-dato"><span class="k">Facturación:</span></div>
                        @foreach ($facturacion['facturados'] as $dte)
                            <span class="envio">CCF n.º {{ $dte->numero_control ? '…'.\App\Support\OrdenCompra::ultimosDigitos($dte->numero_control) : $dte->etiquetaNumeroSistema() }} · {{ $enviosDe($dte) }}</span>
                        @endforeach
                        @if ($facturacion['borrador'])
                            <span class="envio">CCF en borrador · {{ $enviosDe($facturacion['borrador']) }}</span>
                        @endif
                        @if ($facturacion['por_facturar']->isNotEmpty())
                            <span class="envio">Sin facturar · {{ $enviosTexto($facturacion['por_facturar']) }}</span>
                        @endif
                    @endif
                </div>
            </section>
        </td>
    </tr></table>

    <h2>Lo que se pidió y lo que llegó</h2>
    <div class="tabla-wrap">
        <table class="items">
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
                        <td class="cod mono">{{ $linea->producto?->codigo }}</td>
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

    {{-- Observaciones en palabras de lo que vino de menos (Melqui, 10/11/2026): solo con el
         pedido cerrado o mandado en parte, como la F. Sin precios. --}}
    @php
        $deMenos = ($cerrado || $parcial) ? $pedido->lineas->filter->tienePendiente() : collect();
    @endphp
    @if ($deMenos->isNotEmpty())
        <section class="sec observaciones" aria-labelledby="observaciones-titulo">
            <h2 class="sec-h" id="observaciones-titulo">Observaciones</h2>
            <ul class="sec-b">
                @foreach ($deMenos as $linea)
                    <li>{{ $linea->producto?->nombre }}: se pidieron {{ $linea->cantidadLegible() }}, vinieron {{ \App\Models\PedidoSalaLinea::legible($linea->mandado()) }} ({{ $cerrado ? 'faltaron' : 'faltan' }} {{ \App\Models\PedidoSalaLinea::legible($linea->pendiente()) }}).@if ($cerrado && filled($linea->motivo_no_enviado)) Motivo: {{ $linea->motivo_no_enviado }}.@elseif (! $cerrado) Va en otro envío.@endif</li>
                @endforeach
            </ul>
        </section>
    @endif

    <x-documento.firmas :quienes="['Entrega', 'Recibe (gerente de la sala)']" />

    <p class="pie">Hoja interna del pedido de {{ $empresa }}. No es factura ni documento tributario.</p>
</main>

</body>
</html>
