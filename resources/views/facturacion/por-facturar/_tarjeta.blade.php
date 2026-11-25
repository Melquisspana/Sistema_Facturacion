{{-- Una sala en «Llegó de pedidos» (24/11/2026): una fila con el nombre, el estado, la OC,
     cómo va la facturación, «Imprimir hoja» y «Facturar». Al tocar la fila se abren los
     productos: el sugerido en limpio (pidió, llegó y la «F» en lo que faltó, con su motivo
     debajo del producto), sin precios. Si una parte ya se facturó (o, con un día elegido, si
     hubo envíos otros días), una columna aparte con lo que va en el CCF (o lo que llegó ese
     día). La «F» solo con el pedido cerrado; mandado en parte, lo que falta «va en otro envío».

     «Facturar» (solo quien factura) lleva TODO lo que llegó y no se facturó, de cualquier
     día. Con una sola OC clara va directo al borrador ($oc['directo']); si falta la OC o hay
     varias, abre el formulario, donde se escribe o se elige (el servidor no deja crear el CCF
     sin la que pide la sala). Con un borrador abierto no sale: lo que llegó después va en ese
     mismo CCF (FacturarPedido::BORRADOR_ABIERTO).

     Variables: $pedido (sala, ruta, sacadoPor, lineas.producto, lineas.envioLineasVigentes,
     envios vigentes con sus lineas y su dte); $oc (FacturarPedido::oc: propuestas, titulo,
     aviso, lista, y directo); $fecha (?Y-m-d); $facturacion (FacturarPedido::estado: por_facturar,
     borrador, facturados); $mismaTienda (int, cuántos pedidos más hay de la misma tienda nueva:
     PedidosSala::cuantosMasDeLaTienda). --}}
@php
    $cerrado = $pedido->estado->cerrado();
    // El resto «Listo en fábrica» (24/11/2026) también va en otro envío.
    $parcial = $pedido->enParte();
    $porFacturar = $facturacion['por_facturar'];
    $delDia = $fecha === null ? $porFacturar : $pedido->envios->filter(fn ($e) => $e->fecha->format('Y-m-d') === $fecha);
    $otrosDias = $delDia->isNotEmpty() && $delDia->count() !== $pedido->envios->count();
    $columnaDelDia = $fecha === null ? 'Por facturar' : 'Llegó el '.\Illuminate\Support\Carbon::createFromFormat('Y-m-d', $fecha)->format('d/m');
    // Lo que «Facturar» lleva de otros días (con un día elegido): que no sorprenda en el CCF.
    $porFacturarOtroDia = $fecha !== null && $porFacturar->contains(fn ($e) => $e->fecha->format('Y-m-d') !== $fecha);
    $enviosTexto = fn ($envios) => ($envios->count() === 1 ? 'envío ' : 'envíos ').$envios->pluck('numero')->join(', ', ' y ');
    $llegoEseDia = [];
    foreach ($delDia as $envio) {
        foreach ($envio->lineas as $l) {
            $llegoEseDia[$l->pedido_sala_linea_id] = bcadd($llegoEseDia[$l->pedido_sala_linea_id] ?? '0', (string) $l->cantidad, 4);
        }
    }
    $idProductos = 'productos-'.$pedido->id;
@endphp
<li x-data="{ abierto: false }" class="rounded-lg border border-gray-200 bg-white">
    <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0 flex-1">
            {{-- Toda la cabecera de la fila abre y cierra los productos. --}}
            <h3>
                <button type="button" class="block w-full text-left" @click="abierto = ! abierto"
                        :aria-expanded="abierto ? 'true' : 'false'" aria-expanded="false" aria-controls="{{ $idProductos }}">
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="text-base font-semibold text-gray-800">{{ $pedido->nombreSala() }}</span>
                        <x-rutas.estado-badge :estado="$pedido->estado" />
                        {{-- «OC lista», no «Lista»: que no se lea como «el pedido está listo». --}}
                        @if ($oc['aviso'])
                            <x-planta.badge color="amber">Revisar OC</x-planta.badge>
                        @elseif ($oc['lista'])
                            <x-planta.badge color="green">OC lista</x-planta.badge>
                        @else
                            <x-planta.badge color="amber">Esperando OC</x-planta.badge>
                        @endif
                    </span>
                    <span class="mt-1 block text-sm text-gray-600">
                        {{ $pedido->sala?->codigo ? 'Código '.$pedido->sala->codigo.' · ' : '' }}{{ $pedido->zonaNombre() }} · Pedido N.º {{ $pedido->id }} del {{ $pedido->fecha->format('d/m/Y') }} · Lo sacó {{ $pedido->sacadoPor?->nombre ?? '—' }}
                    </span>
                    <span class="mt-1 block text-sm text-indigo-600 underline"><span x-text="abierto ? 'Ocultar productos' : 'Ver productos'">Ver productos</span></span>
                </button>
            </h3>
            {{-- La OC y cómo va la facturación, fuera del botón: llevan enlaces. --}}
            <p class="mt-1 text-sm text-gray-600">
                {{ $oc['titulo'] }}:
                @if ($oc['propuestas'] === [])
                    <span class="{{ $oc['lista'] ? 'text-gray-600' : 'text-amber-800' }}">{{ $oc['lista'] ? 'La sala no pide OC.' : 'La sala pide OC y todavía no llegó.' }}</span>
                @else
                    <span class="font-mono text-gray-800">{{ implode(', ', $oc['propuestas']) }}</span>
                    @if ($oc['aviso'])
                        <span class="text-amber-800">{{ $oc['aviso'] }}</span>
                    @endif
                @endif
            </p>
            <p class="mt-1 text-sm text-gray-800">
                @foreach ($facturacion['facturados'] as $dte)
                    <span class="mr-2">@include('facturacion.por-facturar._ccf', ['dte' => $dte]) · {{ $enviosTexto($pedido->envios->where('dte_id', $dte->id)) }}</span>
                @endforeach
                @if ($facturacion['borrador'])
                    <span class="mr-2">@include('facturacion.por-facturar._ccf', ['dte' => $facturacion['borrador']]) · {{ $enviosTexto($pedido->envios->where('dte_id', $facturacion['borrador']->id)) }}</span>
                @endif
                @if ($porFacturar->isNotEmpty())
                    <span class="text-amber-800">Por facturar: {{ $enviosTexto($porFacturar) }}</span>
                @endif
            </p>
            @if ($porFacturar->isNotEmpty() && $facturacion['borrador'])
                <p class="mt-1 text-sm text-amber-800">{{ \App\Services\Rutas\FacturarPedido::LLEGO_DESPUES_DEL_BORRADOR }}</p>
            @endif
            @if (($mismaTienda ?? 0) > 0)
                {{-- La misma tienda nueva en otros pedidos (13/11/2026): al darla de alta se enlazan todos. --}}
                <p class="mt-1 text-sm text-indigo-800">Hay {{ $mismaTienda === 1 ? '1 pedido más' : $mismaTienda.' pedidos más' }} de esta tienda nueva con el mismo nombre: al darla de alta se enlazan todos.</p>
            @endif
        </div>

        {{-- Sin permiso para ver el pedido (Contabilidad), la fila no lleva botones. --}}
        @can('view', $pedido)
            <div class="flex flex-col gap-2 sm:shrink-0 sm:flex-row">
                <a href="{{ route('rutas.pedidos.hoja', array_filter([$pedido, 'fecha' => $fecha])) }}" target="_blank" rel="noopener"
                   class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Imprimir hoja</a>
                {{-- x-primary-button es solo <button>: el enlace lleva sus clases (docs/GUIA_PANTALLAS.md). --}}
                @if ($pedido->esTiendaNueva())
                    {{-- Tienda nueva: antes de facturar se da de alta y se enlaza a su sala. --}}
                    @can('enlazarSala', $pedido)
                        <a href="{{ route('facturacion.pedidos.tienda', $pedido) }}"
                           class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Dar de alta la tienda</a>
                    @endcan
                @elseif ($porFacturar->isNotEmpty() && ! $facturacion['borrador'])
                    @can('create', App\Models\Dte::class)
                        {{-- Con el día de la lista: «Cancelar» del formulario vuelve a lo que se miraba. --}}
                        @include('facturacion.por-facturar._facturar', [
                            'formulario' => route('facturacion.create-ccf', array_filter(['pedido' => $pedido->id, 'fecha' => $fecha])),
                            'desde' => null,
                        ])
                    @endcan
                @endif
            </div>
        @endcan
    </div>

    <div id="{{ $idProductos }}" x-show="abierto" x-cloak class="border-t border-gray-100 px-4 pb-4 pt-3">
        <p class="text-sm text-gray-600">
            {{ $pedido->envios->map(fn ($e) => 'Envío '.$e->numero.' · '.$e->fecha->format('d/m').' '.$e->created_at->format('H:i'))->implode(' · ') }}
        </p>

        @if ($otrosDias)
            <p class="mt-2 text-sm text-amber-800">
                @if ($fecha === null)
                    {{-- Con un borrador abierto no se facturó nada todavía: lo explica la línea de arriba. --}}
                    @unless ($facturacion['borrador'])
                        Una parte ya se facturó: «{{ $columnaDelDia }}» es lo que va en el CCF.
                    @endunless
                @else
                    También tuvo envíos otros días.
                    @if ($porFacturarOtroDia)
                        «Facturar» lleva todo lo que sigue sin facturar, también lo de otros días.
                    @endif
                @endif
            </p>
        @endif

        <div class="mt-2 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-3 py-2">Producto</th>
                        <th class="px-3 py-2 text-right">Pidió</th>
                        @if ($otrosDias)
                            <th class="px-3 py-2 text-right">{{ $columnaDelDia }}</th>
                            <th class="px-3 py-2 text-right">Total</th>
                        @else
                            <th class="px-3 py-2 text-right">Llegó</th>
                        @endif
                        <th class="px-3 py-2 text-center">Falta</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($pedido->lineas as $linea)
                        @php
                            $pendiente = $linea->tienePendiente();
                            $falto = $pendiente && $cerrado;
                        @endphp
                        <tr>
                            <td class="px-3 py-2">
                                <span class="block text-gray-800">{{ $linea->producto?->nombre }}</span>
                                <span class="block text-xs text-gray-500">{{ $linea->producto?->codigo }}</span>
                                {{-- El motivo va debajo del producto: así la tabla entra en el celular. --}}
                                @if ($falto && filled($linea->motivo_no_enviado))
                                    <span class="block text-xs text-amber-800">Motivo: {{ $linea->motivo_no_enviado }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $linea->cantidadLegible() }}</td>
                            @if ($otrosDias)
                                <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ \App\Models\PedidoSalaLinea::legible($llegoEseDia[$linea->id] ?? '0') }}</td>
                            @endif
                            <td class="px-3 py-2 text-right tabular-nums">{{ \App\Models\PedidoSalaLinea::legible($linea->mandado()) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-center tabular-nums">
                                @if ($falto)
                                    <span class="inline-block rounded border-2 border-red-400 px-1.5 font-bold text-red-700" role="img" aria-label="Faltó">F</span>
                                    {{ \App\Models\PedidoSalaLinea::legible($linea->pendiente()) }}
                                @elseif ($pendiente && $parcial)
                                    <span class="text-xs text-amber-800">Va en otro envío</span>
                                    {{ \App\Models\PedidoSalaLinea::legible($linea->pendiente()) }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @can('view', $pedido)
            <a href="{{ route('rutas.pedidos.show', $pedido) }}" class="mt-2 inline-flex min-h-11 items-center text-sm text-indigo-600 underline">Ver pedido</a>
        @endcan
    </div>
</li>
