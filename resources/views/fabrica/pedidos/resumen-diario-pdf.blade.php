<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Pedidos del {{ $contenido['fecha'] }}</title>
    {{-- El PDF del correo de las 18:00 (ResumenDiarioPedidos). Primero el resumen, con las mismas
         tres partes del correo y en el mismo orden (Melqui, 25/11/2026), ahora con los productos:
         lo que llegó de los vendedores, lo que salió de la fábrica (con lo que faltó y por qué) y
         lo que quedó allá (listos para salir, armando y sin empezar). Después, la hoja de
         despacho de cada envío, una por página. Si una parte no tuvo nada, una frase. Con el
         verde de los papeles de pedidos (docs/GUIA_PANTALLAS.md, «Documentos impresos»). --}}
    <style>
        @include('fabrica.pedidos._estilos-despacho')
        /* DomPDF: sin padding de pantalla; cada despacho comienza en una página. */
        body { padding: 0; }
        .pagina-nueva { page-break-before: always; }
        .hoja { max-width: none; }
        .head, .sec { page-break-inside: avoid; }
        h2.parte { margin-top: 1.6em; font-size: 1.25em; border-bottom: 2px solid #2F6B4E; padding-bottom: .2em; }
        h3 { margin: 1.1em 0 .35em; font-size: .85em; letter-spacing: .6px; text-transform: uppercase; color: #4A4F58; }
        .vacio { margin: .4em 0 0; color: #4A4F58; }
        .items td.sala { font-weight: bold; width: 26%; }
        .items td.num { white-space: nowrap; width: 12%; }
        .items .linea { display: block; }
    </style>
</head>
<body>
@php
    $salas = collect($contenido['salas']);
    $sinWeb = fn (string $sala) => str_replace(' (web)', '', $sala);
    $lista = fn (array $productos) => collect($productos)->map(fn ($p) => $p['nombre'].' '.$p['cantidad'])->implode(', ');
    $llegaron = $salas->filter(fn ($d) => $d['llego'] !== []);
    $salio = $salas->filter(fn ($d) => $d['salio'] !== [] || $d['cerrados'] !== [] || $d['deshecho'] !== []);
    $quedo = [
        'Listos para salir' => ['Va en la caja', $salas->map(fn ($d) => $d['listos'])->filter()],
        'Armando' => ['Falta mandar', $salas->map(fn ($d) => array_values(array_filter($d['por_mandar'], fn ($p) => $p['armando'])))->filter()],
        'Sin empezar' => ['Falta mandar', $salas->map(fn ($d) => array_values(array_filter($d['por_mandar'], fn ($p) => ! $p['armando'])))->filter()],
    ];
@endphp
<main class="hoja">
    <x-documento.encabezado :empresa="$empresa" :logo="$logo" detalle="Pedidos · resumen del día"
        etiqueta="Documento interno de pedidos" titulo="RESUMEN DE PEDIDOS">
        <tr class="key"><td class="k">Fecha</td><td class="v">{{ $contenido['fecha'] }}</td></tr>
    </x-documento.encabezado>

    <h2 class="parte">Llegó de los vendedores</h2>
    @if ($llegaron->isEmpty())
        <p class="vacio">No llegaron pedidos nuevos.</p>
    @else
        <table class="items">
            <thead><tr><th>Sala</th><th>Pedido</th><th>Vendedor</th><th>Productos</th></tr></thead>
            <tbody>
            @foreach ($llegaron as $sala => $datos)
                @foreach ($datos['llego'] as $pedido)
                    <tr><td class="sala">{{ $sinWeb($sala) }}</td>
                        <td class="num">N.º {{ $pedido['numero'] }}</td>
                        <td>{{ $pedido['vendedor'] ?? '—' }}</td>
                        <td>{{ $lista($pedido['productos']) }}</td></tr>
                @endforeach
            @endforeach
            </tbody>
        </table>
    @endif

    <h2 class="parte">Salió de la fábrica</h2>
    @if (! $contenido['salio_algo'])
        <p class="vacio">Hoy no salió ningún pedido.</p>
    @endif
    @if ($salio->isNotEmpty())
        <table class="items">
            <thead><tr><th>Sala</th><th>Qué salió</th><th>Qué faltó y por qué</th></tr></thead>
            <tbody>
            @foreach ($salio as $sala => $datos)
                <tr>
                    <td class="sala">{{ $sinWeb($sala) }}</td>
                    <td>
                        @foreach ($datos['salio'] as $envio)
                            <span class="linea">N.º {{ $envio['pedido'] }} · {{ $envio['hora'] }}: {{ $lista($envio['productos']) }}</span>
                        @endforeach
                        @foreach ($datos['deshecho'] as $envio)
                            <span class="linea">Se deshizo el envío del N.º {{ $envio['pedido'] }} ({{ $envio['hora'] }}): {{ $lista($envio['productos']) }}. Había salido en el correo anterior.</span>
                        @endforeach
                    </td>
                    <td>
                        @foreach ($datos['cerrados'] as $cerrado)
                            @if ($cerrado['volvio'] ?? false)
                                <span class="linea">Volvió a la fábrica el pedido N.º {{ $cerrado['numero'] }}@if ($cerrado['motivo']) — {{ $cerrado['motivo'] }}@endif</span>
                            @elseif ($cerrado['nada'])
                                <span class="linea">No se mandó el pedido N.º {{ $cerrado['numero'] }}: {{ $lista($cerrado['productos']) }}@if ($cerrado['motivo']) — {{ $cerrado['motivo'] }}@endif</span>
                            @else
                                @foreach ($cerrado['productos'] as $p)
                                    <span class="linea">{{ $p['nombre'] }} {{ $p['cantidad'] }}@if ($p['motivo']) — {{ $p['motivo'] }}@endif</span>
                                @endforeach
                                @if ($cerrado['motivo'])
                                    <span class="linea">Lo que faltó ya no va: {{ $cerrado['motivo'] }}</span>
                                @endif
                            @endif
                        @endforeach
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
    @if (count($hojas) > 0)
        <p class="nota">La hoja de despacho de cada envío va después, una por página.</p>
    @endif

    <h2 class="parte">Quedó allá</h2>
    @php $quedoNada = true; @endphp
    @foreach ($quedo as $grupo => [$columna, $porSala])
        @continue($porSala->isEmpty())
        @php $quedoNada = false; @endphp
        <h3>{{ $grupo }}</h3>
        <table class="items">
            <thead><tr><th>Sala</th><th>Pedido</th><th>Del</th><th>{{ $columna }}</th></tr></thead>
            <tbody>
            @foreach ($porSala as $sala => $pedidos)
                @foreach ($pedidos as $pedido)
                    <tr><td class="sala">{{ $sinWeb($sala) }}</td>
                        <td class="num">N.º {{ $pedido['numero'] }}@if ($pedido['resto']) (el resto)@endif</td>
                        <td class="num">{{ $pedido['fecha'] }}</td>
                        <td>{{ $lista($pedido['productos']) }}</td></tr>
                @endforeach
            @endforeach
            </tbody>
        </table>
    @endforeach
    @if ($quedoNada)
        <p class="vacio">No quedó nada por mandar.</p>
    @endif
</main>

@foreach ($hojas as $hoja)
    <div class="pagina-nueva">
    @if ($hoja['web'])
        @php
            $pedido = $hoja['pedido'];
            $web = $pedido->pedidoWeb;
            $nombres = collect($web->lineas ?? [])->pluck('nombre', 'producto');
        @endphp
        <x-documento.encabezado :empresa="$empresa" :logo="$logo" detalle="Pedidos web"
            etiqueta="Documento interno de pedidos" titulo="HOJA DE DESPACHO" />
        <h2>{{ $pedido->nombreSala() }}</h2>
        <p>Pedido {{ $web->codigo }} · {{ collect($web->cajitas_detalle ?? [])->sum(fn ($c) => $c['cantidad'] ?? 1) }} cajitas</p>
        @foreach ($web->cajitas_detalle ?? [] as $cajita)
            <h2>Cajita {{ $cajita['n'] ?? $loop->iteration }} · {{ $cajita['cantidad'] ?? 1 }} cajitas</h2>
            <ul>
            @foreach ($cajita['contenido'] ?? [] as $producto => $cantidad)
                <li>{{ $nombres[$producto] ?? 'Producto sin nombre' }}: {{ $cantidad }}</li>
            @endforeach
            </ul>
        @endforeach
    @else
        @include('fabrica.pedidos._hoja-despacho', $hoja)
    @endif
    </div>
@endforeach
</body>
</html>
