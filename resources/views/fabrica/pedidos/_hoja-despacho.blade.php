@php
    // Solo si es el último envío de todos (ni uno deshecho después) el estado y los motivos actuales son los suyos.
    // El resto que ya quedó «Listo para salir» (24/11/2026) también va en otro envío: enParte().
    $queda = $ultimo && $pedido->enParte();
    $titulo = ! $ultimo ? 'No fue en este envío' : ($queda ? 'Queda pendiente (va en otro envío)' : 'No va');
    $clase = ! $ultimo ? '' : ($queda ? 'queda' : 'no-va');
    $direccion = $pedido->direccionSala();
    $empresa = \App\Support\NombreEmpresa::actual();
@endphp
<main class="hoja">
    <x-documento.encabezado :empresa="$empresa" :logo="$logo ?? asset('images/dte/logo-pdf.png')" detalle="Pedidos · lo que sale para la sala"
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
            {{-- Las notas del vendedor (cambios por avería, etc.) viajan con la hoja. --}}
            @if (filled($pedido->notas))
                <div class="obs"><strong>Notas del vendedor:</strong> {{ $pedido->notas }}</div>
            @endif
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