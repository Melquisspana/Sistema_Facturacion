<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hoja de despacho · {{ $pedido->nombreSala() }}</title>
    {{--
        La hoja de despacho de UN envío de la fábrica: lo que va en la caja, lo que no va con
        su motivo y lo que queda para otro envío. Sale sola al tocar «Imprimir y mandar» y se
        vuelve a sacar desde «Hechos hoy». Llama a window.print() al cargar. Sin precios: la
        fábrica no tiene `productos.ver`.

        Forma (12/11/2026, pedido del dueño): la misma estructura y estilo que el PDF del CCF
        (facturacion/pdf: línea de color arriba, logo y empresa a la izquierda, el recuadro del
        documento a la derecha, el bloque de a quién va, la tabla con encabezado oscuro, firmas
        y pie; bordes finos de 1px, grises #C9CDD4/#D4D8DE, tinta #20242C), pero sin montos,
        sin datos tributarios, sin sello ni código de generación, y con un acento propio para
        que nadie la confunda con una factura: el VERDE de la paleta del CCF (#235C42 /
        #2F6B4E, el de «aceptado») en lugar de su vino #6E2142, y en el recuadro de la derecha
        «HOJA DE DESPACHO» con «No es factura · Sin valor tributario». Sin número de envío ni
        de pedido. La orden de compra solo aparece si el pedido la tiene. Lo común (estilos,
        encabezado con el recuadro y la leyenda, firmas) sale de components/documento, que
        comparte con el PDF del día del vendedor («Documentos impresos» en la guía).

        No es la hoja del pedido entero (rutas/pedidos/hoja, de la oficina, con todos los
        envíos y la facturación): esta es solo de un envío y la abre la fábrica con su permiso.

        Página impresa (docs/GUIA_PANTALLAS.md, «Páginas para imprimir»): documento aparte con
        sus propios estilos, sin el bundle ni modo oscuro, carta vertical; ~12 productos entran
        en una página. Cada parte lleva su título en palabras: se lee igual en blanco y negro.

        La hoja describe el envío tal como salió: lo que no fue se calcula con ese envío y los
        anteriores, nunca con los de después (PedidosFabrica::faltanteAlEnvio). Los motivos se
        guardan por línea y no por envío: solo el último envío (sin ninguno después, ni
        deshecho) los muestra, con el estado actual (mandado en parte, «queda pendiente»;
        cerrado, «no va»). Uno anterior dice solo «No fue en este envío», sin motivos.

        Si el envío corrige a uno anulado con «Corregir», un aviso arriba: «Corrige la hoja de
        las HH:MM» (la hora de la hoja anulada).

        Variables: $pedido (con PedidosFabrica::relaciones(conSala: true)), $envio (vigente, con
        lineas.linea.producto), $faltan [['linea', 'cantidad']], $ultimo (bool) y $corrige
        (el envío anulado que corrige, o null; PedidosFabrica::corrigeA).
    --}}
    <style>
        <x-documento.estilos acento="verde" />

        @page { size: letter portrait; margin: 12mm; }
        body { padding: 24px 16px; font-size: 12px; }
        .hoja { max-width: 820px; margin: 0 auto; }

        .rec-name { font-size: 15px; font-weight: bold; }
        .f { margin-top: 3px; font-size: 11.5px; }
        .f .k { color: #8A9099; }

        h2.queda { color: #7A4A0C; }
        h2.no-va { color: #8A2440; }
        .items tbody td { font-size: 12px; }
        .items tbody tr:nth-child(even) td { background: #F8F9FA; }
        .items .ci { color: #9AA0A8; width: 22px; }
        .items .cod { width: 110px; }
        .items td.cod { font-weight: bold; font-size: 11px; }
        .items .pn { font-weight: bold; }
        .items td.r { font-size: 14px; font-weight: bold; font-variant-numeric: tabular-nums; }
        .items .motivo { font-style: italic; color: #4A4F58; }
        .nota { margin: 6px 0 0; font-size: 11px; color: #6B7280; }

        .no-print { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; max-width: 820px; margin: 0 auto 16px; }
        .no-print a, .no-print button { display: inline-flex; align-items: center; min-height: 44px; padding: 0 16px; border: 1px solid #C9CDD4; background: #fff; color: #20242C; font: inherit; font-weight: 600; text-decoration: none; border-radius: 6px; cursor: pointer; }
        .no-print a:hover, .no-print button:hover { background: #F8F9FA; }
        .no-print a:focus-visible, .no-print button:focus-visible { outline: 2px solid #2F6B4E; outline-offset: 2px; }

        /* En el celular el recuadro baja debajo de la empresa (solo pantalla; al imprimir, al lado). */
        @media screen and (max-width: 600px) {
            td.head-emisor, td.head-dbox { display: block; width: auto; padding: 0 0 14px; }
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
    // Solo si es el último envío de todos (ni uno deshecho después) el estado y los motivos actuales son los suyos.
    $queda = $ultimo && $pedido->estado === \App\Enums\EstadoPedidoSala::Parcial;
    $titulo = ! $ultimo ? 'No fue en este envío' : ($queda ? 'Queda pendiente (va en otro envío)' : 'No va');
    $clase = ! $ultimo ? '' : ($queda ? 'queda' : 'no-va');
    $direccion = $pedido->direccionSala();
    $empresa = \App\Support\NombreEmpresa::actual();
@endphp

<div class="no-print">
    <a href="{{ route('fabrica.pedidos.index') }}">Volver a Pedidos</a>
    <button type="button" onclick="window.print()">Imprimir</button>
</div>

<main class="hoja">
    <x-documento.encabezado :empresa="$empresa" :logo="asset('images/dte/logo-pdf.png')" detalle="Pedidos · lo que sale para la sala"
        etiqueta="Documento interno de pedidos" titulo="HOJA DE DESPACHO">
        <tr class="key"><td class="k">Fecha y hora</td><td class="v">{{ $envio->fecha->format('d/m/Y') }} {{ $envio->created_at->format('H:i') }}</td></tr>
        <tr><td class="k">Vendedor</td><td class="v">{{ $pedido->sacadoPor?->nombre ?? '—' }}</td></tr>
        @if (filled($pedido->numero_orden_compra))
            <tr><td class="k">Orden de compra</td><td class="v mono">{{ $pedido->numero_orden_compra }}</td></tr>
        @endif
    </x-documento.encabezado>

    @if ($corrige)
        <p class="st"><strong>Corrige la hoja de las {{ $corrige->created_at->format('H:i') }}</strong>: esa hoja quedó anulada y vale esta.</p>
    @endif

    <section class="sec">
        <div class="sec-h">Sala de entrega</div>
        <div class="sec-b">
            <div class="rec-name">{{ $pedido->nombreSala() }}</div>
            @if ($direccion)<div class="f"><span class="k">Dirección:</span> {{ $direccion }}</div>@endif
            <div class="f"><span class="k">Zona:</span> {{ $pedido->zonaNombre() }}</div>
        </div>
    </section>

    <h2>Lo que va</h2>
    <table class="items">
        <thead><tr><th class="ci">#</th><th class="cod">Código</th><th>Producto</th><th class="r">Cantidad</th></tr></thead>
        <tbody>
            @foreach ($envio->lineas as $linea)
                <tr>
                    <td class="ci">{{ $loop->iteration }}</td>
                    <td class="cod mono">{{ $linea->linea?->producto?->codigo }}</td>
                    <td class="pn">{{ $linea->linea?->producto?->nombre }}</td>
                    <td class="r">{{ $linea->cantidadLegible() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($faltan->isNotEmpty())
        <h2 class="{{ $clase }}">{{ $titulo }}</h2>
        <table class="items">
            <thead><tr><th class="ci">#</th><th class="cod">Código</th><th>Producto</th><th class="r">Cantidad</th>@if ($ultimo)<th>Motivo</th>@endif</tr></thead>
            <tbody>
                @foreach ($faltan as $falta)
                    <tr>
                        <td class="ci">{{ $loop->iteration }}</td>
                        <td class="cod mono">{{ $falta['linea']->producto?->codigo }}</td>
                        <td class="pn">{{ $falta['linea']->producto?->nombre }}</td>
                        <td class="r">{{ \App\Models\PedidoSalaLinea::legible($falta['cantidad']) }}</td>
                        @if ($ultimo)
                            <td class="motivo">{{ $falta['linea']->motivo_no_enviado ?? '' }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
    @if (! $ultimo)
        <p class="nota">Después de este salieron otros envíos.</p>
    @endif

    <x-documento.firmas :quienes="['Entrega (pedidos)', 'Recibe']" />

    <p class="pie">Hoja interna de despacho de {{ $empresa }}. No es factura ni documento tributario.</p>
</main>

{{-- Se imprime sola al abrir. --}}
<script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
