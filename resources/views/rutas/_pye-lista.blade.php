{{-- Una línea corta por pedido o entrega de «Pedidos y entregas» (rutas/pedidos-entregas): a
     la izquierda cuál es («Sala · N.º 7», «Sala · CCF …0004»), a la derecha lo que importa
     (estado, atraso, hora). El nombre no se corta: si es largo, la línea sigue abajo, para
     que siempre se sepa cuál es. Las primeras 8 a la vista y el resto detrás de «Ver los N
     restantes» (<details>, como Viajes). Quién lo sacó o lo entregó va en letra chica solo
     donde la tarjeta es ancha (una columna desde sm, o xl); en el celular no entra y no va.
     Recibe $filas y $tipo: pedido, fabrica, salio o entrega. --}}
@php
    $partes = array_filter([array_slice($filas, 0, 8), array_slice($filas, 8)]);
    $restantes = 'Ver '.($tipo === 'entrega' ? 'las' : 'los').' '.count($partes[1] ?? []).' restantes';
    $corto = fn (?string $numero) => $numero ? '…'.\App\Support\OrdenCompra::ultimosDigitos($numero) : '—';
    $quien = 'hidden text-xs text-gray-500 sm:inline md:hidden xl:inline';
@endphp
@foreach ($partes as $parte => $grupo)
    @if ($parte === 1)
        <details x-data="{ abierto: false }" x-on:toggle="abierto = $el.open">
            <summary class="flex min-h-11 cursor-pointer list-none items-center text-sm font-medium text-indigo-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                     x-text="abierto ? 'Ocultar' : @js($restantes)">{{ $restantes }}</summary>
    @endif
    <ul class="divide-y divide-gray-100">
        @foreach ($grupo as $fila)
            <li class="flex items-center justify-between gap-3 py-2">
                <p class="min-w-0">
                    <span class="font-medium text-gray-900">{{ $fila['sala'] }}</span>
                    @if ($tipo === 'entrega')
                        <span class="whitespace-nowrap text-sm text-gray-500" title="{{ $fila['documento'] }}"><span aria-hidden="true">· {{ $fila['tipo'] }} {{ $corto($fila['documento']) }}</span><span class="sr-only">{{ $fila['tipo'] }} {{ $fila['documento'] }}</span></span>
                        @if ($fila['quien'])
                            <span class="{{ $quien }}">{{ $fila['quien'] }}</span>
                        @endif
                    @else
                        <span class="whitespace-nowrap text-sm text-gray-500">· N.º {{ $fila['numero'] }}</span>
                        @if ($fila['resto'] ?? false)
                            <span class="text-xs text-gray-500">resto</span>
                        @endif
                        @if ($tipo === 'pedido' && $fila['vendedor'])
                            <span class="{{ $quien }}">{{ $fila['vendedor'] }}</span>
                        @endif
                    @endif
                </p>
                @switch($tipo)
                    @case('pedido')
                        <x-rutas.estado-badge :estado="$fila['estado']" class="shrink-0" />
                        @break
                    @case('fabrica')
                        @if ($fila['atraso'] > 1)
                            <span class="shrink-0 whitespace-nowrap text-sm font-medium text-amber-800">hace {{ $fila['atraso'] }} días</span>
                        @endif
                        @break
                    @case('salio')
                        <span class="shrink-0 text-sm tabular-nums text-gray-600">{{ $fila['hora'] }}</span>
                        @break
                    @case('entrega')
                        <span class="flex shrink-0 items-center gap-2 text-sm">
                            <span class="tabular-nums text-gray-600">{{ $fila['hora'] }}</span>
                            @if ($fila['entregado'])
                                <span class="font-semibold text-green-700"><span aria-hidden="true">✓</span><span class="sr-only">Entregado</span></span>
                            @else
                                <span class="font-medium text-red-700">No se pudo</span>
                            @endif
                        </span>
                        @break
                @endswitch
            </li>
        @endforeach
    </ul>
    @if ($parte === 1)
        </details>
    @endif
@endforeach
