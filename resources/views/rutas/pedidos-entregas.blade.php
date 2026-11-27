{{-- «Pedidos y entregas» (Melqui, 26/11/2026): la pantalla de la dueña, primero para el celular.
     Concisa (pedido de Melqui sobre las capturas: «más concisa, cuál pedido es»): el total chico
     en el título de cada bloque y la lista a la vista, una línea corta por pedido o entrega
     (rutas/_pye-lista), sin tocar nada para verla. Solo mira: el único botón lleva a «Sacar
     pedido», que ya existe. Arriba el período en pastillas que son enlaces (?periodo=, «atrás»
     funciona). Una columna en el celular; desde md, dos. Un bloque sin nada lo dice en una línea.
     Los datos vienen armados de App\Services\Rutas\PedidosYEntregas: acá no hay consultas. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Pedidos y entregas</h1>
            @can('rutas.pedidos.registrar')
                <a href="{{ route('rutas.sacar-pedido.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Sacar un pedido</a>
            @endcan
        </div>
    </x-slot>

    @php
        $caja = 'min-w-0 rounded-lg border border-gray-200 bg-white p-4 sm:p-5';
        $titulo = 'text-base font-semibold text-gray-900';
        $total = 'font-normal text-gray-500';
        $grupo = 'mt-3 text-xs font-semibold uppercase tracking-wide text-gray-500';
        $vacio = 'mt-1 text-sm text-gray-500';
        $pastilla = 'inline-flex min-h-11 items-center justify-center whitespace-nowrap rounded-full px-4 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600';
        $plural = fn (int $n, string $uno, string $varios) => $n === 1 ? $uno : $varios;
        $cuando = ['hoy' => 'hoy', 'ayer' => 'ayer', 'semana' => 'esta semana'][$periodo];
        $dinero = fn (float $monto) => ($monto < 0 ? '−' : '').'$'.number_format(abs($monto), 2);

        $grupos = array_filter(['Listos para salir' => $fabrica['listos'], 'Armando' => $fabrica['armando'], 'Sin empezar' => $fabrica['sin_empezar']]);
        $enFabrica = array_sum(array_map('count', $grupos));
        $intentos = $entregas['entregadas'] + $entregas['no_se_pudo'];
    @endphp

    <div class="py-6 sm:py-8">
        <div class="mx-auto max-w-5xl space-y-4 px-4 sm:px-6 lg:px-8">

            {{-- El período: tres pastillas que son enlaces; la elegida en índigo. --}}
            <div class="space-y-2">
                <nav aria-label="Período" class="grid max-w-md grid-cols-3 gap-2">
                    @foreach (['hoy' => 'Hoy', 'ayer' => 'Ayer', 'semana' => 'Semana'] as $clave => $texto)
                        <a href="{{ route('rutas.pedidos-entregas', ['periodo' => $clave]) }}" @if ($periodo === $clave) aria-current="page" @endif
                           class="{{ $pastilla }} {{ $periodo === $clave ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50' }}">{{ $texto }}</a>
                    @endforeach
                </nav>
                <p class="text-sm text-gray-600">
                    <span class="font-medium text-gray-800">{{ $desde->format('d/m/Y') }}{{ $desde->isSameDay($hasta) ? '' : ' al '.$hasta->format('d/m/Y') }}</span>
                    · Actualizado a las {{ $actualizado->format('H:i') }}
                </p>
            </div>

            <div class="grid items-start gap-4 md:grid-cols-2">

                {{-- Los pedidos que entraron en el período, con su estado de ahora. --}}
                <section aria-labelledby="pye-pedidos" class="{{ $caja }}">
                    <h2 id="pye-pedidos" class="{{ $titulo }}">
                        Pedidos de las salas
                        @if ($pedidos['total'] > 0)
                            <span class="{{ $total }}">· {{ $pedidos['total'] }}</span>
                        @endif
                    </h2>
                    @if ($pedidos['total'] === 0)
                        <p class="{{ $vacio }}">Nada {{ $cuando }}.</p>
                    @else
                        <div class="mt-1">@include('rutas._pye-lista', ['filas' => $pedidos['lista'], 'tipo' => 'pedido'])</div>
                    @endif
                </section>

                {{-- Lo que está ahora en la fábrica, de cualquier día; y lo que salió en el período. --}}
                <section aria-labelledby="pye-fabrica" class="{{ $caja }}">
                    <h2 id="pye-fabrica" class="{{ $titulo }}">
                        En la fábrica
                        @if ($enFabrica > 0)
                            <span class="{{ $total }}">· {{ $enFabrica }}</span>
                        @endif
                    </h2>
                    @if ($enFabrica === 0)
                        <p class="{{ $vacio }}">Nada ahora.</p>
                    @endif
                    @foreach ($grupos as $nombre => $lista)
                        <h3 class="{{ $grupo }}">{{ $nombre }} · {{ count($lista) }}</h3>
                        @include('rutas._pye-lista', ['filas' => $lista, 'tipo' => 'fabrica'])
                    @endforeach
                    @if ($fabrica['salieron'] !== [])
                        <h3 class="{{ $grupo }}">Salió {{ $cuando }} · {{ count($fabrica['salieron']) }}</h3>
                        @include('rutas._pye-lista', ['filas' => $fabrica['salieron'], 'tipo' => 'salio'])
                    @endif
                </section>

                {{-- Las entregas del período, por zona. --}}
                <section aria-labelledby="pye-entregado" class="{{ $caja }}">
                    <h2 id="pye-entregado" class="{{ $titulo }}">
                        Entregado
                        @if ($intentos > 0)
                            <span class="{{ $total }}">· {{ $entregas['entregadas'] }}</span>
                            @if ($entregas['no_se_pudo'] > 0)
                                <span class="font-normal text-red-700">({{ $entregas['no_se_pudo'] }} no se pudo)</span>
                            @endif
                        @endif
                    </h2>
                    @if ($intentos === 0)
                        <p class="{{ $vacio }}">Nada {{ $cuando }}.</p>
                    @else
                        @foreach ($entregas['zonas'] as $zona)
                            <h3 class="{{ $grupo }}">{{ $zona['nombre'] }}</h3>
                            @include('rutas._pye-lista', ['filas' => $zona['lista'], 'tipo' => 'entrega'])
                        @endforeach
                    @endif
                </section>

                {{-- Lo vendido, en una línea, como «Ventas del día» del correo. --}}
                <section aria-labelledby="pye-vendido" class="{{ $caja }}">
                    <h2 id="pye-vendido" class="{{ $titulo }}">Vendido</h2>
                    @if ($vendido === null)
                        <p class="{{ $vacio }}">No se pudo calcular lo vendido.</p>
                    @elseif ($vendido['nacional']['facturas'] === 0 && $vendido['nacional']['monto'] == 0)
                        <p class="{{ $vacio }}">Nada {{ $cuando }}.</p>
                    @else
                        <p class="mt-1 text-gray-700">
                            <span class="text-lg font-semibold tabular-nums text-gray-900">{{ $dinero($vendido['nacional']['monto']) }}</span>
                            sin IVA · {{ $vendido['nacional']['facturas'] }} {{ $plural($vendido['nacional']['facturas'], 'factura', 'facturas') }}
                        </p>
                    @endif
                    @if ($vendido !== null && ($vendido['exportacion']['facturas'] > 0 || $vendido['exportacion']['monto'] != 0))
                        <p class="mt-1 text-sm text-gray-600">Exportación: {{ $dinero($vendido['exportacion']['monto']) }} · {{ $vendido['exportacion']['facturas'] }} {{ $plural($vendido['exportacion']['facturas'], 'factura', 'facturas') }}</p>
                    @endif
                </section>

            </div>
        </div>
    </div>
</x-app-layout>
