{{-- Los envíos de la fábrica, uno por caja: n.º, fecha y hora, quién lo marcó y qué llevó.
     Los anulados se ven tachados con quién y cuándo los anuló. Lo usan el detalle de la
     oficina (rutas/pedidos/show) y «Hechos» de la fábrica (fabrica/pedidos/index).

     Variables: $envios (con lineas.linea.producto, registradoPor, anuladoPor y, si
     $conSala, pedido.sala; con $conFacturacion o $conDeshacer, también dte); $conSala (bool);
     $conDeshacer (bool, solo la fábrica; si ya se facturó, en vez del botón dice que avise a
     la oficina); $conFacturacion (bool, solo la oficina: «Por facturar»,
     «Borrador abierto» o «Facturado en CCF n.º …» por envío; la fábrica no ve nada de esto). --}}
@php
    $conSala ??= false;
    $conDeshacer ??= false;
    $conFacturacion ??= false;
@endphp
<ul class="space-y-3">
    @foreach ($envios as $envio)
        @php $anulado = ! $envio->estaVigente(); @endphp
        <li class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="font-semibold text-gray-800 {{ $anulado ? 'line-through text-gray-400' : '' }}">
                    Envío {{ $envio->numero }}{{ $conSala ? ' · '.$envio->pedido?->sala?->nombre : '' }} · {{ $envio->created_at->format('d/m/Y H:i') }} · lo marcó {{ $envio->registradoPor?->name }}
                </p>
                <x-rutas.estado-badge :estado="$anulado ? \App\Enums\EstadoPedidoSala::Anulado : \App\Enums\EstadoPedidoSala::Enviado" />
            </div>
            @if ($anulado)
                <p class="mt-1 text-sm text-gray-500">Anulado por {{ $envio->anuladoPor?->name }} el {{ $envio->anulado_en->format('d/m/Y') }}</p>
            @elseif ($conFacturacion)
                <p class="mt-1 text-sm text-gray-700">
                    @if ($envio->estaFacturado())
                        @include('facturacion.por-facturar._ccf', ['dte' => $envio->dte])
                    @else
                        <x-planta.badge color="amber">Por facturar</x-planta.badge>
                    @endif
                </p>
            @endif
            <ul class="mt-2 space-y-1 text-sm {{ $anulado ? 'line-through text-gray-400' : 'text-gray-700' }}">
                @foreach ($envio->lineas as $linea)
                    <li><span class="font-semibold tabular-nums">{{ $linea->cantidadLegible() }}</span> × {{ $linea->linea?->producto?->nombre }}</li>
                @endforeach
            </ul>
            @if ($conDeshacer)
                @can('anular', $envio)
                    <form method="POST" action="{{ route('fabrica.pedidos.anular-envio', [$envio->pedido_sala_id, $envio]) }}" class="mt-3"
                          onsubmit="return confirm('¿Deshacer este envío? Lo que llevaba vuelve a quedar pendiente.');">
                        @csrf @method('PATCH')
                        <button class="inline-flex min-h-12 items-center justify-center rounded-xl border border-gray-300 bg-white px-5 py-4 text-lg font-semibold text-gray-800 hover:bg-gray-50">Deshacer</button>
                    </form>
                @else
                    {{-- Sin «Deshacer» porque la oficina ya lo facturó: la fábrica sabe a quién
                         avisar (el mismo texto que da PedidosFabrica si tocan a la vez). --}}
                    @if (! $anulado && $envio->estaFacturado())
                        <p class="mt-3 text-base font-medium text-amber-800">{{ \App\Services\Rutas\PedidosFabrica::FACTURADO }}</p>
                    @endif
                @endcan
            @endif
        </li>
    @endforeach
</ul>
