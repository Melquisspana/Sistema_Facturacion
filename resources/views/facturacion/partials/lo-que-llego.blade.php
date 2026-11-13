{{-- «Lo que llegó» en el formulario del CCF de un pedido: de solo lectura, lo que va a llevar
     el borrador (todo lo que llegó de la fábrica y no se facturó, sumado por producto). Lo que
     frena va en rojo y el botón queda apagado; el servidor lo vuelve a revisar al crear.

     Variables: $pedido; $propuesta (FacturarPedido::propuesta: envios, lineas, problemas,
     borrador); $sinNada (bool, no queda ningún envío por facturar). --}}
<section aria-labelledby="llego-titulo" class="rounded-lg border border-gray-200 overflow-hidden">
    <div class="px-4 py-2.5 bg-gray-50 border-b border-gray-200">
        <h3 id="llego-titulo" class="text-xs font-semibold uppercase tracking-wide text-gray-500">Lo que llegó de pedidos</h3>
    </div>
    <div class="space-y-3 p-4 text-sm">
        @if (! $pedido->estaVigente())
            <p role="alert" class="rounded-md border border-red-200 bg-red-50 p-3 text-red-700">El vendedor anuló este pedido: no se factura.</p>
        @elseif ($propuesta['borrador'])
            <p role="alert" class="rounded-md border border-amber-300 bg-amber-50 p-3 text-amber-800">
                {{ $sinNada ? 'Todo lo que llegó ya está en un borrador abierto.' : \App\Services\Rutas\FacturarPedido::BORRADOR_ABIERTO }}
                <a href="{{ route('facturacion.edit', $propuesta['borrador']) }}" class="font-semibold underline">Abrir el borrador</a>
            </p>
        @elseif ($sinNada)
            <p class="text-gray-600">No queda nada por facturar de este pedido.</p>
        @endif

        @unless ($sinNada)
            <p class="text-gray-600">
                {{ $propuesta['envios']->map(fn ($e) => 'Envío '.$e->numero.' · '.$e->fecha->format('d/m/Y').' '.$e->created_at->format('H:i'))->implode(' · ') }}
            </p>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-3 py-2">Producto</th>
                            <th class="px-3 py-2 text-right">Cantidad</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($propuesta['lineas'] as $linea)
                            <tr>
                                <td class="px-3 py-2">
                                    <span class="block text-gray-800">{{ $linea['nombre'] }}</span>
                                    @if ($linea['producto']?->codigo)
                                        <span class="block text-xs text-gray-500">{{ $linea['producto']->codigo }}</span>
                                    @endif
                                    @if ($linea['problema'])
                                        <span class="block text-xs text-red-700">{{ $linea['problema'] }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ $linea['entero'] ?? \App\Models\PedidoSalaLinea::legible($linea['cantidad']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- La tienda nueva no frena por una línea: frena por la sala que falta enlazar. --}}
            @if ($pedido->esTiendaNueva())
                <p role="alert" class="rounded-md border border-red-200 bg-red-50 p-3 font-medium text-red-700">{{ \App\Services\Rutas\FacturarPedido::SIN_SALA }}</p>
            @elseif ($pedido->sala === null)
                <p role="alert" class="rounded-md border border-red-200 bg-red-50 p-3 font-medium text-red-700">{{ \App\Services\Rutas\FacturarPedido::SALA_BORRADA }}</p>
            @endif
            @if (collect($propuesta['lineas'])->contains(fn ($l) => $l['problema'] !== null))
                <p role="alert" class="rounded-md border border-red-200 bg-red-50 p-3 font-medium text-red-700">
                    Lo que está en rojo no deja crear el CCF. Arreglalo en Productos y volvé a abrir esta pantalla.
                </p>
            @elseif (! $pedido->esTiendaNueva())
                <p class="text-gray-600">El CCF sale con todo esto. En el editor bajás lo que la OC no pide: eso es lo que regresa.</p>
            @endif
        @endunless
    </div>
</section>
