{{-- Una sala en «Llegó de la fábrica»: el sugerido en limpio (pidió, llegó y la «F» en lo
     que faltó, con su motivo debajo del producto), el estado, la OC, cómo va la facturación,
     la hoja para imprimir y «Facturar». Sin precios. Si el pedido tuvo envíos otros días, una
     columna aparte con lo que llegó el día elegido; con el filtro «Sin facturar», con lo que
     sigue sin facturar. La «F» solo con el pedido cerrado; mandado en parte, lo que falta «va
     en otro envío».

     «Facturar» (solo quien factura) abre el formulario del CCF ya lleno con TODO lo que llegó y
     no se facturó, de cualquier día. Sale aunque la sala espere la OC: se escribe a mano en el
     formulario y el servidor no deja crear el CCF sin ella. Con un borrador abierto no sale:
     lo que llegó después va en ese mismo CCF (FacturarPedido::BORRADOR_ABIERTO).

     Variables: $pedido (sala, ruta, sacadoPor, lineas.producto, lineas.envioLineasVigentes,
     envios vigentes con sus lineas y su dte); $oc [propuestas, titulo («OC del pedido», «OC
     del CCF» si ya está en su CCF, «OC del correo»), aviso, lista];
     $fecha (Y-m-d); $sinFacturar (bool); $facturacion (FacturarPedido::estado: por_facturar,
     borrador, facturados). --}}
@php
    $cerrado = $pedido->estado->cerrado();
    $parcial = $pedido->estado === \App\Enums\EstadoPedidoSala::Parcial;
    $porFacturar = $facturacion['por_facturar'];
    $delDia = $sinFacturar ? $porFacturar : $pedido->envios->filter(fn ($e) => $e->fecha->format('Y-m-d') === $fecha);
    $otrosDias = $delDia->count() !== $pedido->envios->count();
    $diaCorto = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $fecha)->format('d/m');
    $columnaDelDia = $sinFacturar ? 'Por facturar' : 'Llegó el '.$diaCorto;
    // Lo que «Facturar» lleva de otros días (sin el filtro): que no sorprenda en el CCF.
    $porFacturarOtroDia = ! $sinFacturar && $porFacturar->contains(fn ($e) => $e->fecha->format('Y-m-d') !== $fecha);
    $enviosTexto = fn ($envios) => ($envios->count() === 1 ? 'envío ' : 'envíos ').$envios->pluck('numero')->join(', ', ' y ');
    $llegoEseDia = [];
    foreach ($delDia as $envio) {
        foreach ($envio->lineas as $l) {
            $llegoEseDia[$l->pedido_sala_linea_id] = bcadd($llegoEseDia[$l->pedido_sala_linea_id] ?? '0', (string) $l->cantidad, 4);
        }
    }
@endphp
<li class="rounded-lg border border-gray-200 bg-white p-4 sm:p-5">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0">
            <h2 class="text-base font-semibold text-gray-800">{{ $pedido->sala?->nombre }}</h2>
            <p class="text-sm text-gray-600">
                {{ $pedido->sala?->codigo ? 'Código '.$pedido->sala->codigo.' · ' : '' }}{{ $pedido->ruta?->nombre ?? 'Sin ruta' }}
            </p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
            <x-rutas.estado-badge :estado="$pedido->estado" />
            @if ($oc['aviso'])
                <x-planta.badge color="amber">Revisar OC</x-planta.badge>
            @elseif ($oc['lista'])
                <x-planta.badge color="green">Lista</x-planta.badge>
            @else
                <x-planta.badge color="amber">Esperando OC</x-planta.badge>
            @endif
        </div>
    </div>

    <dl class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
        <div><dt class="text-xs font-medium text-gray-500">Pedido</dt><dd class="text-gray-800">N.º {{ $pedido->id }} · {{ $pedido->fecha->format('d/m/Y') }}</dd></div>
        <div><dt class="text-xs font-medium text-gray-500">Lo sacó</dt><dd class="text-gray-800">{{ $pedido->sacadoPor?->nombre ?? '—' }}</dd></div>
        <div><dt class="text-xs font-medium text-gray-500">Envíos</dt><dd class="text-gray-800">
            {{ $pedido->envios->map(fn ($e) => 'Envío '.$e->numero.' · '.$e->fecha->format('d/m').' '.$e->created_at->format('H:i'))->implode(' · ') }}
        </dd></div>
        <div><dt class="text-xs font-medium text-gray-500">{{ $oc['titulo'] }}</dt>
            <dd class="font-mono text-gray-800">
                @if ($oc['propuestas'] === [])
                    <span class="font-sans {{ $oc['lista'] ? 'text-gray-600' : 'text-amber-800' }}">{{ $oc['lista'] ? 'La sala no pide OC.' : 'La sala pide OC y todavía no llegó.' }}</span>
                @else
                    {{ implode(', ', $oc['propuestas']) }}
                    @if ($oc['aviso'])
                        <span class="block font-sans text-amber-800">{{ $oc['aviso'] }}</span>
                    @endif
                @endif
            </dd>
        </div>
        {{-- Cómo va la facturación: lo que sigue sin facturar, el borrador y los CCF ya hechos. --}}
        <div class="sm:col-span-2"><dt class="text-xs font-medium text-gray-500">Facturación</dt>
            <dd class="space-y-1 text-gray-800">
                @foreach ($facturacion['facturados'] as $dte)
                    <p>@include('facturacion.por-facturar._ccf', ['dte' => $dte]) · {{ $enviosTexto($pedido->envios->where('dte_id', $dte->id)) }}</p>
                @endforeach
                @if ($facturacion['borrador'])
                    <p>@include('facturacion.por-facturar._ccf', ['dte' => $facturacion['borrador']]) · {{ $enviosTexto($pedido->envios->where('dte_id', $facturacion['borrador']->id)) }}</p>
                @endif
                @if ($porFacturar->isNotEmpty())
                    <p><x-planta.badge color="amber">Por facturar</x-planta.badge> {{ $enviosTexto($porFacturar) }}</p>
                    @if ($facturacion['borrador'])
                        <p class="text-amber-800">{{ \App\Services\Rutas\FacturarPedido::LLEGO_DESPUES_DEL_BORRADOR }}</p>
                    @endif
                @endif
            </dd>
        </div>
    </dl>

    @if ($otrosDias)
        <p class="mt-3 text-sm text-amber-800">
            @if ($sinFacturar)
                {{-- Con un borrador abierto no se facturó nada todavía: lo explica «Facturación». --}}
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

    <div class="mt-3 overflow-x-auto">
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
                            {{-- El motivo va debajo del producto: así la tabla entra en la tarjeta. --}}
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

    {{-- Sin permiso para ver el pedido (Contabilidad), la tarjeta no lleva pie. --}}
    @can('view', $pedido)
        <div class="mt-4 flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:justify-end">
            <a href="{{ route('rutas.pedidos.show', $pedido) }}"
               class="inline-flex min-h-11 items-center justify-center text-sm text-indigo-600 underline">Ver pedido</a>
            <a href="{{ route('rutas.pedidos.hoja', [$pedido, 'fecha' => $fecha]) }}" target="_blank" rel="noopener"
               class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Imprimir hoja</a>
            {{-- x-primary-button es solo <button>: el enlace lleva sus clases (docs/GUIA_PANTALLAS.md). --}}
            @if ($porFacturar->isNotEmpty() && ! $facturacion['borrador'])
                @can('create', App\Models\Dte::class)
                    {{-- Con el filtro de la lista: «Cancelar» del formulario vuelve a lo que se miraba. --}}
                    <a href="{{ route('facturacion.create-ccf', ['pedido' => $pedido->id] + ($sinFacturar ? ['sin_facturar' => 1] : ['fecha' => $fecha])) }}"
                       class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Facturar</a>
                @endcan
            @endif
        </div>
    @endcan
</li>
