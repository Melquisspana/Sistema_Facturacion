<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Orden para la caja · {{ $pedido->nombreSala() }}</title>
    {{--
        «Imprimir sin mandar» (Melqui, 24/11/2026): el papel que va encima de la caja armada
        que todavía no sale, para que al otro día sepan cuál es cuál y no copien la orden a
        mano. Sale de lo marcado en la hoja (o, sin marcar, de todo lo que falta, como la
        hoja), solo lectura: no registra envío, no cambia el estado ni llega a facturación, y
        se saca las veces que haga falta. La hoja de despacho sigue saliendo solo con «Imprimir
        y mandar».

        Forma: la misma de la hoja de despacho, con el mismo verde (lo pidió así), para que se
        reconozca como un papel de pedidos; lo que la distingue es el nombre «ORDEN PARA LA
        CAJA», el aviso «Todavía no salió», la columna «Falta» y el N.º de pedido en grande,
        que separa dos pedidos abiertos de la misma sala. Sin firmas: no acompaña una entrega.
        Sin precios. Página impresa («Páginas para imprimir» y «Documentos impresos» en
        docs/GUIA_PANTALLAS.md): carta vertical, se lee igual en blanco y negro y se imprime
        sola al abrir.

        Variables: $pedido (con PedidosFabrica::relaciones(conSala: true)).
    --}}
    <style>
        @include('fabrica.pedidos._estilos-despacho')
        @include('fabrica.pedidos._estilos-imprimir')

        .dbox-t td.num-grande { font-size: 2.6em; line-height: 1.05; }
        .items td.falta { font-size: 14px; font-weight: bold; font-variant-numeric: tabular-nums; color: #7A4A0C; }
    </style>
</head>
<body>
@php
    $lineas = $pedido->lineas->filter->tienePendiente();
    $conMotivo = $lineas->contains(fn ($l) => bccomp($l->faltaEnLaCaja(), '0', 4) === 1 && filled($l->motivo_borrador));
    $yaSalio = $pedido->envios->whereNull('anulado_en')->isNotEmpty();
    $direccion = $pedido->direccionSala();
    $empresa = \App\Support\NombreEmpresa::actual();
@endphp

<div class="no-print">
    <a href="{{ route('fabrica.pedidos.show', $pedido) }}">Volver al pedido</a>
    <a href="{{ route('fabrica.pedidos.index') }}">Volver a Pedidos</a>
    <button type="button" onclick="window.print()">Imprimir</button>
</div>

<main class="hoja">
    <x-documento.encabezado :empresa="$empresa" :logo="asset('images/dte/logo-pdf.png')" detalle="Pedidos · la caja que todavía no sale"
        etiqueta="Documento interno de pedidos" titulo="ORDEN PARA LA CAJA">
        <tr class="key"><td class="k">Pedido N.º</td><td class="v num-grande">{{ $pedido->id }}</td></tr>
        <tr><td class="k">Fecha del pedido</td><td class="v">{{ $pedido->fecha->format('d/m/Y') }}</td></tr>
        <tr><td class="k">Vendedor</td><td class="v">{{ $pedido->sacadoPor?->nombre ?? '—' }}</td></tr>
        @if (filled($pedido->numero_orden_compra))
            <tr><td class="k">Orden de compra</td><td class="v mono">{{ $pedido->numero_orden_compra }}</td></tr>
        @endif
    </x-documento.encabezado>

    <p class="st"><strong>Todavía no salió.</strong> Esta orden va encima de la caja. La hoja de despacho sale al mandarla.@if ($yaSalio) Ya salió una parte de este pedido: esta caja lleva lo que faltaba.@endif</p>

    <section class="sec">
        <div class="sec-h">Sala de entrega</div>
        <div class="sec-b">
            <div class="rec-name">{{ $pedido->nombreSala() }}</div>
            @if ($direccion)<div class="f"><span class="k">Dirección:</span> {{ $direccion }}</div>@endif
            <div class="f"><span class="k">Zona:</span> {{ $pedido->zonaNombre() }}</div>
            @if (filled($pedido->notas))
                <div class="obs"><strong>Notas del vendedor:</strong> {{ $pedido->notas }}</div>
            @endif
        </div>
    </section>

    <h2>Lo que va en la caja</h2>
    <table class="items">
        <thead><tr><th class="ci">#</th><th class="cod">Código</th><th>Producto</th><th class="r">Va</th><th class="r">Falta</th>@if ($conMotivo)<th>Motivo</th>@endif</tr></thead>
        <tbody>
            @foreach ($lineas as $linea)
                @php($falta = $linea->faltaEnLaCaja())
                @php($faltaAlgo = bccomp($falta, '0', 4) === 1)
                <tr>
                    <td class="ci">{{ $loop->iteration }}</td>
                    <td class="cod mono">{{ $linea->producto?->codigo }}</td>
                    <td class="pn">{{ $linea->producto?->nombre }}</td>
                    <td class="r">{{ \App\Models\PedidoSalaLinea::legible($linea->vaEnLaCaja()) }}</td>
                    <td class="r falta">{{ $faltaAlgo ? \App\Models\PedidoSalaLinea::legible($falta) : '' }}</td>
                    @if ($conMotivo)
                        <td class="motivo">{{ $faltaAlgo ? ($linea->motivo_borrador ?? '') : '' }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="pie">Orden interna de {{ $empresa }} para armar la caja. No es la hoja de despacho ni factura.</p>
</main>

{{-- Se imprime sola al abrir. --}}
<script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
