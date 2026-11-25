<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Salió de la fábrica · {{ $contenido['fecha'] }}</title>
    <style>
        @include('fabrica.pedidos._estilos-despacho')
        /* DomPDF: sin padding de pantalla; cada despacho comienza en una página. */
        body { padding: 0; }
        .pagina-nueva { page-break-before: always; }
        .hoja { max-width: none; }
        .head, .sec { page-break-inside: avoid; }
    </style>
</head>
<body>
@foreach ($hojas as $hoja)
    <div class="{{ $loop->first ? '' : 'pagina-nueva' }}">
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
@php
    $detalles = collect($contenido['salas'])->filter(fn ($d) => count($d['deshecho']) + count($d['falto']) + count($d['no_se_trae']) + count($d['motivos_cierre'] ?? []) + count($d['por_mandar']) + count($d['armados'] ?? []) > 0);
@endphp
@if ($detalles->isNotEmpty())
<div class="{{ count($hojas) > 0 ? 'pagina-nueva' : '' }}">
<h1>Salió de la fábrica · {{ $contenido['fecha'] }}</h1>
@foreach ($detalles as $sala => $datos)
    <h2>{{ str_replace(' (web)', '', $sala) }}</h2>
    @if (count($datos['deshecho']) > 0)
        <h3>Se deshizo (había salido en un correo anterior)</h3>
        @foreach ($datos['deshecho'] as $envio)
            <p>Envío {{ $envio['numero'] }} ({{ $envio['hora'] }}): {{ collect($envio['productos'])->map(fn ($p) => $p['nombre'].' '.$p['cantidad'])->implode(', ') }}</p>
        @endforeach
    @endif
    @if (count($datos['falto']) > 0)
        <h3>Faltó</h3>
        @foreach ($datos['falto'] as $p)
            <p>{{ $p['nombre'] }}: {{ $p['cantidad'] }}@if ($p['motivo']) — {{ $p['motivo'] }}@endif</p>
        @endforeach
    @endif
    @if (count($datos['no_se_trae']) > 0)
        <h3>No se trae</h3>
        @foreach ($datos['no_se_trae'] as $p)
            <p>{{ $p['nombre'] }}: {{ $p['cantidad'] }}</p>
        @endforeach
    @endif
    @foreach ($datos['motivos_cierre'] ?? [] as $motivo)
        <p>Por qué no se mandó: {{ $motivo }}</p>
    @endforeach
    {{-- Las cajas listas para salir (24/11/2026), con el N.º que lleva su orden encima. --}}
    @if (count($datos['armados'] ?? []) > 0)
        <h3>Listos para salir</h3>
        @foreach ($datos['armados'] as $pedido)
            <p>Pedido N.º {{ $pedido['numero'] }} del {{ $pedido['fecha'] }}: {{ collect($pedido['productos'])->map(fn ($p) => $p['nombre'].' '.$p['cantidad'])->implode(', ') }}</p>
        @endforeach
    @endif
    @if (count($datos['por_mandar']) > 0)
        <h3>Sigue por mandar</h3>
        @foreach ($datos['por_mandar'] as $pedido)
            <p>Pedido del {{ $pedido['fecha'] }} ({{ $pedido['atraso'] === 0 ? 'de hoy' : ($pedido['atraso'] === 1 ? '1 día de atraso' : $pedido['atraso'].' días de atraso') }}): {{ collect($pedido['productos'])->map(fn ($p) => $p['nombre'].' '.$p['cantidad'])->implode(', ') }}</p>
        @endforeach
    @endif
@endforeach
</div>
@endif
</body>
</html>
