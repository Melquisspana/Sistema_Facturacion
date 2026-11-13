<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Envío {{ $envio->numero }} · {{ $pedido->nombreSala() }}</title>
    {{--
        La hoja de UN envío de la fábrica (12/11/2026): lo que va en la caja, lo que no va con
        su motivo y lo que queda para otro envío. Sale sola al tocar «Imprimir y mandar» y se
        vuelve a sacar desde «Hechos hoy». Llama a window.print() al cargar. Sin precios: la
        fábrica no tiene `productos.ver`.

        No es la hoja del pedido entero (rutas/pedidos/hoja, de la oficina, con todos los
        envíos y la facturación): esta es solo de un envío y la abre la fábrica con su permiso.

        Página impresa (docs/GUIA_PANTALLAS.md, «Páginas para imprimir»): documento aparte con
        sus propios estilos, sin el bundle ni modo oscuro, hoja carta vertical. Los colores son
        tonos oscuros y cada parte lleva su título en palabras: se lee igual en blanco y negro.

        La hoja describe el envío tal como salió: lo que no fue se calcula con ese envío y los
        anteriores, nunca con los de después (PedidosFabrica::faltanteAlEnvio). Los motivos se
        guardan por línea y no por envío: solo el último envío vigente los muestra, con el
        estado actual (mandado en parte, «queda pendiente»; cerrado, «no va»). Uno anterior
        dice solo «No fue en este envío», sin motivos.

        Variables: $pedido (con PedidosFabrica::relaciones(conSala: true)), $envio (vigente, con
        lineas.linea.producto), $faltan [['linea', 'cantidad']] y $ultimo (bool).
    --}}
    <style>
        :root {
            --tinta: #111827; --suave: #4b5563;
            --indigo: #3730a3; --indigo-borde: #c7d2fe; --indigo-claro: #eef2ff;
            --ambar: #92400e; --ambar-claro: #fef3c7;
            --rojo: #991b1b; --rojo-claro: #fee2e2;
        }
        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body { margin: 0; padding: 24px 16px; background: #fff; color: var(--tinta); font-family: "Segoe UI", Arial, Helvetica, sans-serif; font-size: 13px; line-height: 1.4; }
        .hoja { max-width: 820px; margin: 0 auto; }
        .mono { font-family: "Consolas", "Courier New", monospace; }

        .franja { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 8px 24px; margin-bottom: 16px; padding: 14px 20px; background: var(--indigo); color: #fff; border-radius: 10px; border-bottom: 5px solid #818cf8; }
        .empresa { margin: 0 0 2px; font-size: 11px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: #e0e7ff; }
        h1 { margin: 0; font-size: 22px; font-weight: 800; line-height: 1.2; }
        .numero { margin: 0; text-align: right; }
        .numero span { display: block; font-size: 10px; letter-spacing: .12em; text-transform: uppercase; color: #e0e7ff; }
        .numero strong { font-size: 28px; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }

        .datos { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin: 0 0 16px; }
        .datos > div { min-width: 0; padding: 8px 12px; background: var(--indigo-claro); border: 1px solid var(--indigo-borde); border-radius: 8px; }
        .datos .ancho { grid-column: span 2; }
        .datos dt { font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--indigo); }
        .datos dd { margin: 2px 0 0; font-size: 14px; font-weight: 600; overflow-wrap: anywhere; }

        h2 { margin: 18px 0 6px; font-size: 15px; }
        h2.ambar { color: var(--ambar); }
        h2.rojo { color: var(--rojo); }
        table { width: 100%; border-collapse: collapse; border: 1px solid var(--indigo); }
        th, td { border: 1px solid #a5b4fc; padding: 6px 8px; vertical-align: middle; }
        thead th { background: var(--indigo); color: #fff; border-color: #6366f1; font-size: 10px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; text-align: left; }
        tbody tr:nth-child(even) td { background: #f5f7ff; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        td.num { font-size: 16px; font-weight: 700; }
        td.motivo { font-style: italic; color: #374151; }
        table.queda td { background: var(--ambar-claro); }
        table.no-va td { background: var(--rojo-claro); }

        .firma { margin-top: 56px; display: flex; flex-wrap: wrap; gap: 32px 48px; }
        .firma div { flex: 1 1 200px; border-top: 2px solid var(--indigo); padding-top: 4px; text-align: center; font-size: 11px; color: var(--suave); }

        .no-print { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; max-width: 820px; margin: 0 auto 16px; }
        .no-print a, .no-print button { display: inline-flex; align-items: center; min-height: 44px; padding: 0 16px; border: 1px solid #d1d5db; background: #fff; color: #374151; font: inherit; font-weight: 500; text-decoration: none; border-radius: 6px; cursor: pointer; }
        .no-print a:hover, .no-print button:hover { background: #f9fafb; }
        .no-print a:focus-visible, .no-print button:focus-visible { outline: 2px solid #4f46e5; outline-offset: 2px; }

        @media (max-width: 640px) { .datos { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 400px) { .datos { grid-template-columns: minmax(0, 1fr); } .datos .ancho { grid-column: auto; } .numero { text-align: left; } }
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
    // Solo si es el último envío vigente el estado y los motivos actuales son los suyos.
    $queda = $ultimo && $pedido->estado === \App\Enums\EstadoPedidoSala::Parcial;
    $titulo = ! $ultimo ? 'No fue en este envío' : ($queda ? 'Queda pendiente (va en otro envío)' : 'No va');
    $clase = ! $ultimo ? '' : ($queda ? 'ambar' : 'rojo');
    $direccion = $pedido->direccionSala();
@endphp

<div class="no-print">
    <a href="{{ route('fabrica.pedidos.index') }}">Volver a Pedidos</a>
    <button type="button" onclick="window.print()">Imprimir</button>
</div>

<main class="hoja">
    <header class="franja">
        <div>
            <p class="empresa">{{ \App\Support\NombreEmpresa::actual() }}</p>
            <h1>Envío de la fábrica</h1>
        </div>
        <p class="numero"><span>Pedido {{ $pedido->id }} · envío n.º</span> <strong>{{ $envio->numero }}</strong></p>
    </header>

    <dl class="datos">
        <div class="ancho"><dt>Sala</dt><dd>{{ $pedido->nombreSala() }}</dd></div>
        <div class="ancho"><dt>Dirección</dt><dd>{{ $direccion ?? '—' }}</dd></div>
        <div><dt>Zona</dt><dd>{{ $pedido->zonaNombre() }}</dd></div>
        <div><dt>Orden de compra</dt><dd class="mono">{{ $pedido->numero_orden_compra ?? 'Sin OC' }}</dd></div>
        <div><dt>Vendedor</dt><dd>{{ $pedido->sacadoPor?->nombre ?? '—' }}</dd></div>
        <div><dt>Fecha y hora</dt><dd>{{ $envio->fecha->format('d/m/Y') }} {{ $envio->created_at->format('H:i') }}</dd></div>
    </dl>

    <h2>Lo que va</h2>
    <table>
        <thead><tr><th>Código</th><th>Producto</th><th class="num">Cantidad</th></tr></thead>
        <tbody>
            @foreach ($envio->lineas as $linea)
                <tr>
                    <td class="mono">{{ $linea->linea?->producto?->codigo }}</td>
                    <td>{{ $linea->linea?->producto?->nombre }}</td>
                    <td class="num">{{ $linea->cantidadLegible() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($faltan->isNotEmpty())
        <h2 class="{{ $clase }}">{{ $titulo }}</h2>
        <table class="{{ ! $ultimo ? '' : ($queda ? 'queda' : 'no-va') }}">
            <thead><tr><th>Código</th><th>Producto</th><th class="num">Cantidad</th>@if ($ultimo)<th>Motivo</th>@endif</tr></thead>
            <tbody>
                @foreach ($faltan as $falta)
                    <tr>
                        <td class="mono">{{ $falta['linea']->producto?->codigo }}</td>
                        <td>{{ $falta['linea']->producto?->nombre }}</td>
                        <td class="num">{{ \App\Models\PedidoSalaLinea::legible($falta['cantidad']) }}</td>
                        @if ($ultimo)
                            <td class="motivo">{{ $falta['linea']->motivo_no_enviado ?? '' }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
    @if (! $ultimo)
        <p>Después de este salieron otros envíos.</p>
    @endif

    <div class="firma">
        <div>Entrega (fábrica)</div>
        <div>Recibe</div>
    </div>
</main>

{{-- Se imprime sola al abrir. --}}
<script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
