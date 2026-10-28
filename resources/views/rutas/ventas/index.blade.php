<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-xl font-semibold text-gray-800 dark:text-paper-100">Ventas nacionales</h2>
            <div class="flex flex-wrap justify-end">
                <a href="{{ route('rutas.ventas.excel', $filtros) }}" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm text-indigo-600 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-indigo-400">Descargar Excel</a>
                <a href="{{ route('rutas.ventas.pdf', $filtros) }}" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm text-indigo-600 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-indigo-400">Descargar PDF</a>
            </div>
        </div>
    </x-slot>
    @php
        $url = fn ($cambios = []) => route('rutas.ventas.index', array_filter(array_replace($filtros, $cambios), fn ($v) => $v !== null && $v !== ''));
        $dinero = fn ($n) => ($n < 0 ? '−' : '').'$'.number_format(abs($n), 2);
        $vista = $filtros['vista'] ?? (isset($filtros['producto']) || isset($filtros['ruta']) ? 'salas' : 'productos');
        $filas = $vista === 'devoluciones' ? [] : ${$vista};
        $maximo = max([1, ...array_column($filas, 'actual'), ...array_column($filas, 'anterior')]);
        $pastilla = 'inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500';
    @endphp
    <div class="py-6 font-sans tabular-nums text-gray-900 dark:text-paper-100 sm:py-8">
        <div class="mx-auto max-w-4xl space-y-7 px-4 sm:px-6">
            <section aria-label="Período de ventas" class="space-y-3">
                <nav aria-label="Elegir período" class="grid grid-cols-2 gap-1 rounded-2xl bg-gray-100 p-1 dark:bg-ink-800 sm:inline-flex">
                    @foreach (['mes' => 'Este mes', 'mes_pasado' => 'Mes pasado', 'tres_meses' => '3 meses', 'rango' => 'Elegir fechas'] as $clave => $nombre)
                        <a href="{{ $url(['periodo' => $clave, 'desde' => $clave === 'rango' ? $periodo['desde']->toDateString() : null, 'hasta' => $clave === 'rango' ? $periodo['hasta']->toDateString() : null]) }}" @if (($filtros['periodo'] ?? 'mes') === $clave) aria-current="true" @endif class="{{ $pastilla }} {{ ($filtros['periodo'] ?? 'mes') === $clave ? 'bg-white text-indigo-700 shadow-sm dark:bg-ink-600 dark:text-indigo-300' : 'text-gray-600 hover:bg-white dark:text-paper-300 dark:hover:bg-ink-700' }}">{{ $nombre }}</a>
                    @endforeach
                </nav>
                @if (($filtros['periodo'] ?? 'mes') === 'rango')
                    <form action="{{ route('rutas.ventas.index') }}" method="GET" class="flex flex-wrap items-end gap-3">
                        @foreach ($filtros as $clave => $valor)
                            @if (!in_array($clave, ['desde', 'hasta'])) <input type="hidden" name="{{ $clave }}" value="{{ $valor }}"> @endif
                        @endforeach
                        <label class="text-sm">Desde<input type="date" name="desde" required value="{{ $filtros['desde'] }}" class="mt-1 block min-h-11 rounded-xl border-gray-300 dark:border-ink-600 dark:bg-ink-800"></label>
                        <label class="text-sm">Hasta<input type="date" name="hasta" required value="{{ $filtros['hasta'] }}" class="mt-1 block min-h-11 rounded-xl border-gray-300 dark:border-ink-600 dark:bg-ink-800"></label>
                        <button class="{{ $pastilla }} bg-indigo-600 text-white hover:bg-indigo-700">Ver</button>
                    </form>
                @endif
                @if ($errors->any()) <p role="alert" class="text-sm text-red-600 dark:text-red-300">{{ $errors->first() }}</p> @endif
                <p class="text-sm text-gray-500 dark:text-paper-300">{{ ucfirst($periodo['etiqueta']) }} comparado con {{ $periodo['etiqueta_anterior'] }}</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($chips as $clave => $nombre)
                        <a href="{{ $url([$clave => null, 'vista' => null]) }}" aria-label="Quitar filtro {{ $nombre }}" class="{{ $pastilla }} gap-3 bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">{{ $nombre }} <span aria-hidden="true">×</span></a>
                    @endforeach
                </div>
            </section>

            <section aria-label="Resumen de ventas" class="space-y-3 py-2">
                <p class="text-sm text-gray-500 dark:text-paper-300">Vendido sin IVA</p>
                <div class="flex flex-wrap items-center gap-x-5 gap-y-3">
                    <p class="text-4xl font-semibold tracking-tight sm:text-5xl">{{ $dinero($totales['actual']) }}</p>
                    @if ($totales['porcentaje'] !== null)
                        <div class="space-y-1">
                            <span class="inline-flex rounded-full px-3 py-1 text-sm font-medium {{ $totales['diferencia'] >= 0 ? 'bg-green-50 text-green-700 dark:bg-green-500/15 dark:text-green-300' : 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300' }}">{{ $totales['diferencia'] >= 0 ? '▲' : '▼' }} {{ number_format(abs($totales['porcentaje']), 0) }} %</span>
                            <p class="text-xs text-gray-500 dark:text-paper-300">{{ $totales['diferencia'] > 0 ? '+' : '' }}{{ $dinero($totales['diferencia']) }}</p>
                        </div>
                    @else
                        <p class="text-sm text-gray-500 dark:text-paper-300">Sin datos del período anterior</p>
                    @endif
                    @if ($totales['porcentaje_anio'] !== null)
                        <span class="inline-flex rounded-full bg-indigo-50 px-3 py-1 text-sm text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">vs {{ $periodo['desde']->copy()->subYearNoOverflow()->translatedFormat('F Y') }} {{ $totales['porcentaje_anio'] >= 0 ? '▲' : '▼' }} {{ number_format(abs($totales['porcentaje_anio']), 0) }} %</span>
                    @endif
                </div>
                <x-rutas.tendencia :serie="$tendencia" />
                <p class="text-sm text-gray-500 dark:text-paper-300">{{ $contadores['ccf'] }} CCF · {{ $contadores['salas'] }} salas · {{ $dinero($contadores['nc']) }} en notas de crédito</p>
            </section>

            @if ($ficha_sala)
                <section aria-label="Ficha de la sala" class="rounded-2xl bg-white p-4 ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 sm:p-5">
                    <div class="grid grid-cols-3 gap-3">
                        <div><h3 class="text-xs text-gray-500 dark:text-paper-300">Última compra</h3><p class="mt-2 font-semibold">{{ $ficha_sala['ultima_compra'] ? \Illuminate\Support\Carbon::parse($ficha_sala['ultima_compra'])->format('d/m/Y') : 'Sin compras' }}</p>@if ($ficha_sala['dias'] !== null)<p class="mt-1 text-xs text-gray-500 dark:text-paper-300">{{ match ($ficha_sala['dias']) { 0 => 'hoy', 1 => 'ayer', default => 'hace '.$ficha_sala['dias'].' días' } }}</p>@endif</div>
                        <div><h3 class="text-xs text-gray-500 dark:text-paper-300">Cada cuánto compra</h3><p class="mt-2 font-semibold">{{ $ficha_sala['frecuencia'] !== null ? number_format($ficha_sala['frecuencia'], 0).' días' : 'Sin datos suficientes' }}</p></div>
                        <div><h3 class="text-xs text-gray-500 dark:text-paper-300">Compra promedio</h3><p class="mt-2 text-lg font-semibold">{{ $ficha_sala['promedio'] !== null ? $dinero($ficha_sala['promedio']) : 'Sin compras' }}</p><p class="mt-1 text-xs text-gray-500 dark:text-paper-300">Últimos 12 meses</p></div>
                    </div>
                    @if (count($ficha_sala['oportunidades']))
                        <h3 class="mt-5 text-sm font-semibold">Oportunidades</h3>
                        <div class="mt-2 divide-y divide-gray-100 dark:divide-ink-700">
                            @foreach ($ficha_sala['oportunidades'] as $oportunidad)
                                <a href="{{ $url(['sala' => null, 'producto' => $oportunidad['id'], 'vista' => 'salas']) }}" class="block rounded-lg py-3 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500"><span class="font-medium text-indigo-600 dark:text-indigo-300">{{ $oportunidad['nombre'] }}</span><span class="mt-1 block text-xs text-gray-500 dark:text-paper-300">Lo compran {{ $oportunidad['salas'] }} de {{ $oportunidad['total'] }} salas de la ruta</span></a>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif

            @if (count($resumen['monto']))
                @php
                    $top = $filtros['top'] ?? 'monto';
                    $caja = 'rounded-2xl bg-white p-4 ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 sm:p-5';
                    $unidades = fn ($n) => number_format($n, fmod($n, 1) == 0 ? 0 : 2);
                @endphp
                <section aria-label="Resumen de productos" class="grid gap-4 sm:grid-cols-2">
                    <div class="{{ $caja }}">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-semibold">Lo que más se vende</h3>
                            <nav aria-label="Ordenar por" class="flex gap-1 rounded-xl bg-gray-100 p-1 text-xs dark:bg-ink-700">
                                @foreach (['monto' => 'Monto', 'cantidad' => 'Cantidad'] as $clave => $nombre)
                                    <a href="{{ $url(['top' => $clave === 'monto' ? null : $clave]) }}" @if ($top === $clave) aria-current="true" @endif
                                       class="rounded-lg px-2.5 py-1.5 font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500 {{ $top === $clave ? 'bg-white text-indigo-700 shadow-sm dark:bg-ink-600 dark:text-indigo-300' : 'text-gray-600 dark:text-paper-300' }}">{{ $nombre }}</a>
                                @endforeach
                            </nav>
                        </div>
                        <ol class="mt-3 space-y-1">
                            @foreach ($resumen[$top] as $i => $producto)
                                <li>
                                    <a href="{{ $url(['producto' => $producto['id'], 'vista' => 'salas']) }}" class="flex min-h-11 items-center gap-3 rounded-lg px-1 text-sm hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500 dark:hover:bg-ink-700">
                                        <span class="w-5 text-center text-xs font-semibold text-gray-400 dark:text-paper-500">{{ $i + 1 }}</span>
                                        <span class="min-w-0 flex-1 truncate">{{ $producto['nombre'] }}</span>
                                        <span class="font-semibold">{{ $top === 'monto' ? $dinero($producto['actual']) : $unidades($producto['cantidad']).' u' }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                    <div class="{{ $caja }}">
                        <h3 class="font-semibold">Contra {{ $periodo['etiqueta_anterior'] }}</h3>
                        @foreach (['suben' => ['Subieron', '▲', 'text-green-700 dark:text-green-300'], 'bajan' => ['Bajaron', '▼', 'text-red-700 dark:text-red-300']] as $clave => [$titulo, $flecha, $color])
                            <p class="mt-3 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-paper-300">{{ $titulo }}</p>
                            @forelse ($resumen[$clave] as $producto)
                                <a href="{{ $url(['producto' => $producto['id'], 'vista' => 'salas']) }}" class="flex min-h-11 items-center justify-between gap-3 rounded-lg px-1 text-sm hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500 dark:hover:bg-ink-700">
                                    <span class="min-w-0 truncate">{{ $producto['nombre'] }}</span>
                                    <span class="shrink-0 font-semibold {{ $color }}">{{ $flecha }} {{ $dinero(abs($producto['diferencia'])) }}</span>
                                </a>
                            @empty
                                <p class="py-2 text-sm text-gray-500 dark:text-paper-300">Ninguno.</p>
                            @endforelse
                        @endforeach
                    </div>
                </section>
            @endif

            @if (count($alertas))
                <section aria-label="Salas que compraron menos" class="rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/30 sm:p-5">
                    <h3 class="font-semibold text-amber-900 dark:text-amber-200">{{ count($alertas) }} salas compraron menos</h3>
                    <div class="mt-2 divide-y divide-amber-200 dark:divide-amber-500/20">
                        @foreach (array_slice($alertas, 0, 5) as $alerta)
                            <a href="{{ $url(['sala' => $alerta['id'] ?: null, 'vista' => 'productos']) }}" class="flex min-h-11 flex-wrap items-center justify-between gap-2 rounded-lg py-3 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500">
                                <span class="font-medium">{{ $alerta['nombre'] }}</span>
                                <span class="text-amber-800 dark:text-amber-200">{{ $alerta['alerta'] }} · {{ $dinero($alerta['anterior']) }} → {{ $dinero($alerta['actual']) }}</span>
                            </a>
                        @endforeach
                    </div>
                    @if (count($alertas) > 5) <p class="mt-2 text-sm text-amber-800 dark:text-amber-200">y {{ count($alertas) - 5 }} más</p> @endif
                </section>
            @endif

            <section aria-label="Detalle de ventas" class="space-y-4">
                <nav aria-label="Agrupar ventas" class="grid grid-cols-2 gap-1 rounded-2xl bg-gray-100 p-1 dark:bg-ink-800 sm:grid-cols-4">
                    @foreach (['productos' => 'Productos', 'salas' => 'Salas', 'rutas' => 'Rutas', 'devoluciones' => 'Devoluciones'] as $clave => $nombre)
                        <a href="{{ $url(['vista' => $clave]) }}" @if ($vista === $clave) aria-current="page" @endif class="{{ $pastilla }} {{ $vista === $clave ? 'bg-white text-indigo-700 shadow-sm dark:bg-ink-600 dark:text-indigo-300' : 'text-gray-600 hover:bg-white dark:text-paper-300 dark:hover:bg-ink-700' }}">{{ $nombre }}</a>
                    @endforeach
                </nav>
                @if ($vista === 'devoluciones')
                    <p class="text-sm font-medium">Devuelto {{ $dinero($devoluciones['total']) }} · {{ $devoluciones['porcentaje'] !== null ? number_format($devoluciones['porcentaje'], 1).' % de lo vendido' : 'Sin venta de referencia' }}</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach (['productos' => 'Productos', 'salas' => 'Salas'] as $grupo => $titulo)
                            <section class="overflow-hidden rounded-2xl bg-white p-4 ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                                <h3 class="font-semibold">{{ $titulo }}</h3>
                                @forelse ($devoluciones[$grupo] as $fila)
                                    <a href="{{ $url($grupo === 'productos' ? ['producto' => $fila['id'], 'vista' => 'salas'] : ['sala' => $fila['id'] ?: null, 'vista' => 'productos']) }}" class="mt-2 block rounded-lg py-3 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500">
                                        <span class="flex justify-between gap-3"><span class="font-medium">{{ $fila['nombre'] }}</span><span class="shrink-0 font-semibold text-indigo-600 dark:text-indigo-300">{{ $fila['porcentaje'] !== null ? number_format($fila['porcentaje'], 1).' %' : 'Sin base' }}</span></span>
                                        <span class="mt-1 block text-xs text-gray-500 dark:text-paper-300">Vendido {{ $dinero($fila['vendido']) }} · Devuelto {{ $dinero($fila['devuelto']) }}</span>
                                    </a>
                                @empty
                                    <p class="mt-3 text-sm text-gray-500 dark:text-paper-300">Sin devoluciones en este período.</p>
                                @endforelse
                            </section>
                        @endforeach
                    </div>
                @endif
                @if ($contadores['ccf'] === 0 && $contadores['nc'] == 0)
                    <div class="rounded-2xl bg-white p-6 ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                        <p>No hubo ventas en este período</p>
                        <a href="{{ $url(['periodo' => 'mes_pasado', 'desde' => null, 'hasta' => null]) }}" class="inline-flex min-h-11 items-center rounded-lg text-sm text-indigo-600 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-indigo-400">Ver el mes pasado</a>
                    </div>
                @endif
                @if (count($filas))
                    <p class="flex items-center gap-4 text-xs text-gray-500 dark:text-paper-300">
                        <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="h-2 w-5 rounded-full bg-indigo-500"></span>{{ $periodo['etiqueta'] }}</span>
                        <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="h-3 w-0.5 bg-gray-600 dark:bg-paper-300"></span>{{ $periodo['etiqueta_anterior'] }}</span>
                    </p>
                    <div class="divide-y divide-gray-100 overflow-hidden rounded-2xl bg-white ring-1 ring-gray-200 dark:divide-ink-700 dark:bg-ink-800 dark:ring-ink-600">
                        @foreach ($filas as $fila)
                            @php
                                $detalle = match ($vista) {
                                    'productos' => ['producto' => $fila['id'], 'vista' => 'salas'],
                                    'salas' => ['sala' => $fila['id'] ?: null, 'vista' => 'productos'],
                                    'rutas' => ['ruta' => $fila['id'], 'vista' => 'salas'],
                                };
                                $ancho = max(0, min(100, $fila['actual'] / $maximo * 100));
                                $marca = max(0, min(100, $fila['anterior'] / $maximo * 100));
                            @endphp
                            <a href="{{ $url($detalle) }}" class="block px-4 py-4 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-indigo-500 dark:hover:bg-ink-700 sm:px-5">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 class="break-words font-semibold">{{ $fila['nombre'] }}</h3>
                                        <p class="mt-1 text-xs text-gray-500 dark:text-paper-300">{{ $vista === 'salas' ? $fila['cliente'] : number_format($fila['cantidad'], fmod($fila['cantidad'], 1) == 0 ? 0 : 2).' unidades' }}</p>
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <p class="text-lg font-semibold sm:text-xl">{{ $dinero($fila['actual']) }}</p>
                                        @if ($fila['porcentaje'] !== null)
                                            <span class="mt-1 inline-block rounded-full px-2 py-0.5 text-xs {{ $fila['diferencia'] >= 0 ? 'bg-green-50 text-green-700 dark:bg-green-500/15 dark:text-green-300' : 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300' }}">{{ $fila['diferencia'] >= 0 ? '▲' : '▼' }} {{ number_format(abs($fila['porcentaje']), 0) }} %</span>
                                        @else <span class="text-xs text-gray-500 dark:text-paper-300">Sin anterior</span> @endif
                                    </div>
                                </div>
                                <div role="img" aria-label="{{ $periodo['etiqueta'] }} {{ $dinero($fila['actual']) }}; {{ $periodo['etiqueta_anterior'] }} {{ $dinero($fila['anterior']) }}" class="relative mt-4 h-2 rounded-full bg-gray-100 dark:bg-ink-700">
                                    <div class="h-2 rounded-full bg-indigo-500" style="width: {{ $ancho }}%"></div>
                                    @if ($fila['anterior'] > 0)
                                        <span aria-hidden="true" class="absolute -top-1 h-4 w-0.5 -translate-x-1/2 rounded-full bg-gray-600 dark:bg-paper-300" style="left: {{ $marca }}%"></span>
                                    @endif
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
