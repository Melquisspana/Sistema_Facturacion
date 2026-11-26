{{-- «Pedidos y entregas» (Melqui, 26/11/2026): la pantalla de la dueña, primero para el celular.
     Solo mira: el único botón lleva a «Sacar pedido», que ya existe. Arriba el período en
     pastillas que son enlaces (?periodo=, «atrás» funciona); debajo, cuatro tarjetas que dicen
     primero el número grande y, al tocarlas, abren la lista (<details>, como Viajes; «Ver» /
     «Ocultar» con Alpine, y sin JavaScript queda «Ver»). Una columna en el celular; desde md, dos.
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
        $caja = 'rounded-lg border border-gray-200 bg-white';
        $titulo = 'text-xs font-semibold uppercase tracking-wide text-gray-500';
        $grande = 'text-4xl font-semibold leading-none tabular-nums text-gray-900 sm:text-5xl';
        $resumen = 'block cursor-pointer list-none rounded-lg p-5 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600';
        $ver = 'mt-4 text-sm font-medium text-indigo-600';
        $subtitulo = 'bg-gray-50 px-5 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500';
        $pastilla = 'inline-flex min-h-11 items-center justify-center whitespace-nowrap rounded-full px-4 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600';
        // El desglose debajo del número grande: una fila por cuenta, el número a la derecha.
        $desglose = 'mt-4 divide-y divide-gray-100 rounded-md border border-gray-200';
        $filaDesglose = 'flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-3 py-2';
        $cifra = 'text-lg font-semibold tabular-nums text-gray-900';
        // Un bloque sin nada lo dice en una frase, sin el cero grande.
        $vacio = 'mt-3 text-lg text-gray-700';
        $plural = fn (int $n, string $uno, string $varios) => $n === 1 ? $uno : $varios;
        $cuando = ['hoy' => 'hoy', 'ayer' => 'ayer', 'semana' => 'esta semana'][$periodo];
        $dia = fn (int $atraso, string $fecha) => match ($atraso) { 0 => 'de hoy', 1 => 'de ayer', default => 'del '.substr($fecha, 0, 5).' · hace '.$atraso.' días' };
        $corto = fn (?string $numero) => $numero ? '…'.\App\Support\OrdenCompra::ultimosDigitos($numero) : '—';

        $enFabrica = count($fabrica['sin_empezar']) + count($fabrica['armando']) + count($fabrica['listos']);
        $masViejo = max([0, ...array_column([...$fabrica['sin_empezar'], ...$fabrica['armando'], ...$fabrica['listos']], 'atraso')]);
        $grupos = ['Listos para salir' => $fabrica['listos'], 'Armando' => $fabrica['armando'], 'Sin empezar' => $fabrica['sin_empezar']];
        $intentos = $entregas['entregadas'] + $entregas['no_se_pudo'];
    @endphp

    <div class="py-6 sm:py-8">
        <div class="mx-auto max-w-5xl space-y-5 px-4 sm:px-6 lg:px-8">

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

                {{-- ===================== Pedidos de las salas ===================== --}}
                <section aria-labelledby="pye-pedidos" class="{{ $caja }}">
                    @php
                        $cuentas = collect($pedidos['por_estado'])->filter()->map(fn ($n, $valor) => [\App\Enums\EstadoPedidoSala::from($valor), $n]);
                    @endphp
                    <details x-data="{ abierto: false }" x-on:toggle="abierto = $el.open">
                        <summary class="{{ $resumen }}">
                            <h2 id="pye-pedidos" class="{{ $titulo }}">Pedidos de las salas</h2>
                            @if ($pedidos['total'] === 0)
                                <p class="{{ $vacio }}">No entraron pedidos {{ $cuando }}.</p>
                            @else
                                <p class="mt-3 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                    <span class="{{ $grande }}">{{ $pedidos['total'] }}</span>
                                    <span class="text-base text-gray-700">{{ $plural($pedidos['total'], 'pedido entró', 'pedidos entraron') }} {{ $cuando }}</span>
                                </p>
                                {{-- El estado con su etiqueta de siempre: el color lo da el enum. --}}
                                <ul class="{{ $desglose }}">
                                    @foreach ($cuentas as [$estado, $n])
                                        <li class="{{ $filaDesglose }}">
                                            <x-rutas.estado-badge :estado="$estado" />
                                            <span class="{{ $cifra }}">{{ $n }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                <p class="{{ $ver }}" x-text="abierto ? 'Ocultar la lista' : @js($plural($pedidos['total'], 'Ver el pedido', 'Ver los '.$pedidos['total'].' pedidos'))">{{ $plural($pedidos['total'], 'Ver el pedido', 'Ver los '.$pedidos['total'].' pedidos') }}</p>
                            @endif
                        </summary>
                        @if ($pedidos['total'] > 0)
                            <ul class="divide-y divide-gray-100 border-t border-gray-100">
                                @foreach ($pedidos['lista'] as $fila)
                                    <li class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-5 py-3">
                                        <div class="min-w-0">
                                            <p class="font-medium text-gray-800">{{ $fila['sala'] }}</p>
                                            <p class="text-sm text-gray-600">N.º {{ $fila['numero'] }}{{ $fila['vendedor'] ? ' · '.$fila['vendedor'] : '' }}</p>
                                        </div>
                                        <x-rutas.estado-badge :estado="$fila['estado']" />
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </details>
                </section>

                {{-- ===================== En la fábrica ===================== --}}
                <section aria-labelledby="pye-fabrica" class="{{ $caja }}">
                    <details x-data="{ abierto: false }" x-on:toggle="abierto = $el.open">
                        <summary class="{{ $resumen }}">
                            <h2 id="pye-fabrica" class="{{ $titulo }}">En la fábrica</h2>
                            @if ($enFabrica === 0)
                                <p class="{{ $vacio }}">No hay nada en la fábrica.</p>
                            @else
                                <p class="mt-3 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                    <span class="{{ $grande }}">{{ $enFabrica }}</span>
                                    <span class="text-base text-gray-700">{{ $plural($enFabrica, 'pedido está', 'pedidos están') }} en la fábrica ahora</span>
                                </p>
                                <ul class="{{ $desglose }}">
                                    @foreach ($grupos as $nombre => $lista)
                                        @if (count($lista) > 0)
                                            <li class="{{ $filaDesglose }}">
                                                <span class="text-sm font-medium text-gray-700">{{ $nombre }}</span>
                                                <span class="{{ $cifra }}">{{ count($lista) }}</span>
                                            </li>
                                        @endif
                                    @endforeach
                                </ul>
                                @if ($masViejo > 0)
                                    <p class="mt-3 text-sm font-medium text-amber-800">El más viejo es {{ $masViejo === 1 ? 'de ayer' : 'de hace '.$masViejo.' días' }}.</p>
                                @endif
                            @endif
                            <p class="mt-3 border-t border-gray-100 pt-3 text-sm text-gray-700">
                                @if ($fabrica['salieron'] === [])
                                    No salió ningún pedido {{ $cuando }}.
                                @else
                                    Salió {{ $cuando }}: <span class="font-semibold tabular-nums text-gray-900">{{ count($fabrica['salieron']) }}</span> {{ $plural(count($fabrica['salieron']), 'pedido', 'pedidos') }}.
                                @endif
                            </p>
                            @if ($enFabrica > 0 || $fabrica['salieron'] !== [])
                                <p class="{{ $ver }}" x-text="abierto ? 'Ocultar la lista' : 'Ver la lista'">Ver la lista</p>
                            @endif
                        </summary>
                        @if ($enFabrica > 0 || $fabrica['salieron'] !== [])
                            <div class="border-t border-gray-100">
                                @foreach ($grupos as $nombre => $lista)
                                    @if (count($lista) > 0)
                                        <h3 class="{{ $subtitulo }}">{{ $nombre }}</h3>
                                        <ul class="divide-y divide-gray-100">
                                            @foreach ($lista as $fila)
                                                <li class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-5 py-3">
                                                    <div class="min-w-0">
                                                        <p class="font-medium text-gray-800">{{ $fila['sala'] }}</p>
                                                        <p class="text-sm text-gray-600">{{ $fila['resto'] ? 'El resto del ' : '' }}N.º {{ $fila['numero'] }}</p>
                                                    </div>
                                                    <p class="text-sm {{ $fila['atraso'] > 1 ? 'font-medium text-amber-800' : 'text-gray-600' }}">{{ $dia($fila['atraso'], $fila['fecha']) }}</p>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                @endforeach
                                @if ($fabrica['salieron'] !== [])
                                    <h3 class="{{ $subtitulo }}">Salió {{ $cuando }}</h3>
                                    <ul class="divide-y divide-gray-100">
                                        @foreach ($fabrica['salieron'] as $fila)
                                            <li class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-5 py-3">
                                                <div class="min-w-0">
                                                    <p class="font-medium text-gray-800">{{ $fila['sala'] }}</p>
                                                    <p class="text-sm text-gray-600">N.º {{ $fila['numero'] }}</p>
                                                </div>
                                                <p class="text-sm tabular-nums text-gray-600">{{ $fila['hora'] }}</p>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endif
                    </details>
                </section>

                {{-- ===================== Entregado ===================== --}}
                <section aria-labelledby="pye-entregado" class="{{ $caja }}">
                    <details x-data="{ abierto: false }" x-on:toggle="abierto = $el.open">
                        <summary class="{{ $resumen }}">
                            <h2 id="pye-entregado" class="{{ $titulo }}">Entregado</h2>
                            @if ($intentos === 0)
                                <p class="{{ $vacio }}">No se registraron entregas {{ $cuando }}.</p>
                            @else
                                <p class="mt-3 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                    <span class="{{ $grande }}">{{ $entregas['entregadas'] }}</span>
                                    <span class="text-base text-gray-700">{{ $plural($entregas['entregadas'], 'entregada', 'entregadas') }} {{ $cuando }}</span>
                                </p>
                                @if ($entregas['no_se_pudo'] > 0)
                                    <p class="mt-2 text-sm font-medium text-red-700">No se pudo entregar: {{ $entregas['no_se_pudo'] }}</p>
                                @endif
                                {{-- Por zona, en palabras: cuántas se entregaron y cuántas no se pudieron. --}}
                                <ul class="{{ $desglose }}">
                                    @foreach ($entregas['zonas'] as $zona)
                                        <li class="{{ $filaDesglose }} text-sm">
                                            <span class="min-w-0 font-medium text-gray-700">{{ $zona['nombre'] }}</span>
                                            <span class="tabular-nums text-gray-800">
                                                {{ $zona['entregadas'] }} {{ $plural($zona['entregadas'], 'entregada', 'entregadas') }}@if ($zona['no_se_pudo'] > 0)<span class="text-red-700"> · {{ $zona['no_se_pudo'] }} no se {{ $plural($zona['no_se_pudo'], 'pudo', 'pudieron') }}</span>@endif
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                                <p class="{{ $ver }}" x-text="abierto ? 'Ocultar la lista' : @js($plural($intentos, 'Ver la entrega', 'Ver las '.$intentos.' entregas'))">{{ $plural($intentos, 'Ver la entrega', 'Ver las '.$intentos.' entregas') }}</p>
                            @endif
                        </summary>
                        @if ($intentos > 0)
                            <div class="border-t border-gray-100">
                                @foreach ($entregas['zonas'] as $zona)
                                    <h3 class="{{ $subtitulo }}">{{ $zona['nombre'] }}</h3>
                                    <ul class="divide-y divide-gray-100">
                                        @foreach ($zona['lista'] as $fila)
                                            <li class="flex items-start justify-between gap-3 px-5 py-3">
                                                <div class="min-w-0">
                                                    <p class="font-medium text-gray-800">{{ $fila['sala'] }}</p>
                                                    <p class="text-sm text-gray-600">
                                                        <span title="{{ $fila['documento'] }}"><span aria-hidden="true">{{ $fila['tipo'] }} {{ $corto($fila['documento']) }}</span><span class="sr-only">{{ $fila['tipo'] }} {{ $fila['documento'] }}</span></span>{{ $fila['quien'] ? ' · '.$fila['quien'] : '' }}
                                                    </p>
                                                </div>
                                                <div class="shrink-0 text-right">
                                                    <p class="text-sm font-medium {{ $fila['entregado'] ? 'text-green-700' : 'text-red-700' }}">{{ $fila['entregado'] ? 'Entregado' : 'No se pudo' }}</p>
                                                    <p class="text-sm tabular-nums text-gray-600">{{ $fila['hora'] }}</p>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endforeach
                            </div>
                        @endif
                    </details>
                </section>

                {{-- ===================== Vendido ===================== --}}
                {{-- Sin lista: solo el número, como «Ventas del día» del correo. --}}
                <section aria-labelledby="pye-vendido" class="{{ $caja }} p-5">
                    <h2 id="pye-vendido" class="{{ $titulo }}">Vendido</h2>
                    @if ($vendido === null)
                        <p class="mt-3 text-base font-medium text-gray-800">No se pudo calcular lo vendido.</p>
                        <p class="mt-1 text-sm text-gray-600">Probá de nuevo en unos minutos.</p>
                    @else
                        @php
                            $nacional = $vendido['nacional'];
                            $exportacion = $vendido['exportacion'];
                        @endphp
                        @if ($nacional['facturas'] === 0 && $nacional['monto'] == 0)
                            <p class="{{ $vacio }}">No se facturó nada {{ $cuando }}.</p>
                        @else
                            <p class="mt-3 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                <span class="{{ $grande }}">{{ ($nacional['monto'] < 0 ? '−' : '').'$'.number_format(abs($nacional['monto']), 2) }}</span>
                                <span class="text-base text-gray-700">{{ $cuando }}, sin IVA</span>
                            </p>
                            <p class="mt-2 text-sm text-gray-700">En <span class="font-semibold tabular-nums">{{ $nacional['facturas'] }}</span> {{ $plural($nacional['facturas'], 'factura', 'facturas') }}.</p>
                        @endif
                        @if ($exportacion['facturas'] > 0 || $exportacion['monto'] != 0)
                            <p class="mt-3 border-t border-gray-100 pt-3 text-sm text-gray-700">Exportación: <span class="font-semibold tabular-nums">${{ number_format($exportacion['monto'], 2) }}</span> en {{ $exportacion['facturas'] }} {{ $plural($exportacion['facturas'], 'factura', 'facturas') }}.</p>
                        @endif
                    @endif
                </section>

            </div>
        </div>
    </div>
</x-app-layout>
