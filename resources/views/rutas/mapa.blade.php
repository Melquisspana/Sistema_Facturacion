<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm text-gray-500">Rutas › Tablero</p>
                <h1 class="text-xl font-semibold leading-tight text-gray-800">Mapa de ventas por zona</h1>
            </div>
        </div>
    </x-slot>

    @php
        $caja = 'rounded-lg border border-gray-200 bg-white';
        $titulo = 'text-xs font-semibold uppercase tracking-wide text-gray-500';
        $secundario = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
        $campo = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
        $plata = fn (float $monto) => '$'.number_format($monto, 2);
        $t = $datos['totales'];
        $variacion = $t['anterior'] != 0 ? ($t['ventas'] - $t['anterior']) / $t['anterior'] * 100 : null;
        $contra = $datos['enCurso'] ? 'frente al mismo tramo del mes anterior' : 'frente al mes anterior';
        // Lo mismo, corto, para la tabla y la ficha: «vs. 1 al 9 de octubre» o «vs. septiembre».
        $periodo = $datos['periodo'];
        $mesAnterior = \Illuminate\Support\Carbon::parse($periodo['anteriorDesde'])->locale('es')->translatedFormat('F');
        $diaFin = (int) substr($periodo['anteriorHasta'], 8, 2);
        $contraCorto = 'vs. '.($datos['enCurso'] ? ($diaFin === 1 ? '1' : '1 al '.$diaFin).' de '.$mesAnterior : $mesAnterior);
        // En un mes pasado, el ritmo de cada sala y la última entrega se cuentan al cierre del mes.
        $corte = $datos['enCurso'] ? null : \Illuminate\Support\Carbon::parse($periodo['hasta'])->format('d/m');
        $alCorte = $corte ? ' al '.$corte : '';
        $sinLugar = $datos['sinUbicacion'];
        $rutaElegida = collect($datos['rutas'])->firstWhere('id', $filtro['ruta']);

        // La tabla y «Dónde falta» se dibujan aquí para los dos niveles; el navegador muestra
        // el que se elige. Así se leen también sin JavaScript y con lector de pantalla.
        $niveles = ['dep' => $datos['departamentos'], 'dis' => $datos['distritos']];
        $nombreRuta = collect($datos['rutas'])->pluck('nombre', 'id');
        $rutasTexto = fn (array $ids) => collect($ids)->map(fn ($id) => $nombreRuta[$id] ?? null)->filter()->implode(', ');
    @endphp

    {{-- El mapa necesita colores propios (rampas, rayado, leyenda) y una grilla que el CSS de
         producción no trae; van en este bloque porque app.css no se recompila en el servidor.
         Los tonos siguen la clase `dark` de <html>, como el resto del sistema. --}}
    <style>
        .mv { --mv-fondo:#f9fafb; --mv-tinta:#111827; --mv-tinta-2:#374151; --mv-tenue:#6b7280; --mv-linea:#e5e7eb;
              --mv-acento:#4f46e5; --mv-vacia:#eceef2; --mv-raya:#c9cdd6; --mv-borde-zona:#ffffff; --mv-borde-dep:#6b7280;
              --q1:#cde2fb; --q2:#86b6ef; --q3:#3987e5; --q4:#1c5cab; --q5:#0d366b;
              --f1:#fde4d6; --f2:#f6b48f; --f3:#eb6834; --f4:#b9481c; --f5:#7a2c0e;
              --r-on:#4f46e5; --r-cover:#c7d2fe; --bien:#0ca30c; --ojo:#d99400; --mal:#d03b3b;
              --tip-fondo:#111827; --tip-tinta:#ffffff; }
        .dark .mv { --mv-fondo:#1a1b20; --mv-tinta:#e9eaee; --mv-tinta-2:#c9cbd3; --mv-tenue:#a3a6b1; --mv-linea:#34363f;
              --mv-acento:#818cf8; --mv-vacia:#2a2c33; --mv-raya:#4a4d59; --mv-borde-zona:#1a1b20; --mv-borde-dep:#a3a6b1;
              --q1:#1d2b45; --q2:#1c5cab; --q3:#2a78d6; --q4:#5598e7; --q5:#b7d3f6;
              --f1:#3a2418; --f2:#8f3712; --f3:#c24e1f; --f4:#eb6834; --f5:#f8b48c;
              --r-on:#818cf8; --r-cover:#33356b; --bien:#22c55e; --ojo:#fab219; --mal:#f87171;
              --tip-fondo:#e9eaee; --tip-tinta:#131417; }
        .mv-principal, .mv-abajo { display:grid; grid-template-columns:minmax(0,1fr); gap:1rem; align-items:start; }
        @media (min-width: 1024px) { .mv-principal { grid-template-columns:minmax(0,1fr) 340px; } }
        /* Con la barra lateral, la tabla necesita todo el ancho hasta las pantallas grandes. */
        @media (min-width: 1536px) { .mv-abajo { grid-template-columns:360px minmax(0,1fr); } }
        .mv-falta-lista { display:grid; gap:.5rem; grid-template-columns:repeat(auto-fill, minmax(16rem, 1fr)); }
        .mv-falta-vacias:has(details[open]) { grid-column:1 / -1; }
        .mv-seg { display:inline-flex; gap:2px; padding:3px; border-radius:.625rem; }
        .mv-seg button { min-height:2.75rem; padding:0 .75rem; border-radius:.45rem; font-size:.875rem; font-weight:500; }
        .mv-seg button[aria-pressed="false"]:hover { background:rgba(99,102,241,.08); }
        .mv-seg button[aria-pressed="true"] { background:var(--mv-acento); color:#fff; }
        .dark .mv-seg button[aria-pressed="true"] { color:#131417; }
        .mv-seg button:focus-visible, .mv-zbtn:focus-visible, .mv-fila:focus-visible, .mv-zona:focus-visible { outline:2px solid var(--mv-acento); outline-offset:2px; }
        .mv [hidden] { display:none !important; }
        .mv-mapa { position:relative; overflow:hidden; container-type:inline-size; }
        /* El alto lo fija rutas-mapa.js según el ancho; este es el de mientras carga. */
        .mv-svg { display:block; width:100%; height:400px; background:var(--mv-fondo); touch-action:none; cursor:grab; }
        .mv-svg:active { cursor:grabbing; }
        .mv-zona { stroke:var(--mv-borde-zona); stroke-width:.8; vector-effect:non-scaling-stroke; cursor:pointer; transition:fill .25s; }
        .mv-zona:hover { stroke:var(--mv-tinta); stroke-width:1.4; }
        .mv-zona.mv-sel { stroke:var(--mv-tinta); stroke-width:2.4; }
        .mv-limite { fill:none; stroke:var(--mv-borde-dep); stroke-width:1.3; vector-effect:non-scaling-stroke; pointer-events:none; opacity:.8; }
        .mv-etiqueta { font-size:11px; font-weight:600; fill:var(--mv-tinta-2); paint-order:stroke; stroke:var(--mv-fondo); stroke-width:3px; pointer-events:none; text-anchor:middle; }
        .mv-lienzo { position:relative; }
        .mv-zoom { position:absolute; right:12px; top:12px; display:grid; gap:6px; }
        .mv-zbtn { width:44px; height:44px; border-radius:.625rem; font-size:18px; font-weight:600; line-height:1; }
        .mv-leyenda { position:absolute; left:12px; bottom:12px; display:grid; gap:6px; padding:10px 12px; max-width:calc(100% - 24px); }
        .mv-rampa { display:flex; gap:2px; }
        .mv-rampa span { flex:1; min-width:38px; height:10px; border-radius:2px; }
        /* Las cifras van en el borde entre dos tonos (rutas-mapa.js pone left en %). */
        .mv-rampa-txt { position:relative; height:14px; font-size:11px; font-variant-numeric:tabular-nums; }
        .mv-rampa-txt span { position:absolute; top:0; white-space:nowrap; }
        /* Dónde falta: un tono por tramo, con sus números debajo. */
        .mv-clases { display:flex; gap:2px; font-size:11px; font-variant-numeric:tabular-nums; }
        .mv-clases span { flex:1; min-width:38px; display:grid; gap:3px; text-align:center; }
        .mv-clases i { height:10px; border-radius:2px; }
        .mv-muestra { display:flex; align-items:center; gap:6px; font-size:12px; }
        .mv-muestra i { width:14px; height:10px; border-radius:2px; display:inline-block; }
        .mv-rayado { background:repeating-linear-gradient(45deg,var(--mv-vacia) 0 3px,var(--mv-raya) 3px 5px); }
        .mv-tip { position:absolute; z-index:5; pointer-events:none; max-width:240px; padding:8px 10px; border-radius:8px; font-size:12px; line-height:1.4;
                  background:var(--tip-fondo); color:var(--tip-tinta); box-shadow:0 6px 18px rgba(0,0,0,.25); }
        .mv-punto { width:8px; height:8px; border-radius:50%; display:inline-block; flex-shrink:0; }
        .mv-barra { height:6px; border-radius:3px; background:var(--mv-linea); overflow:hidden; }
        .mv-barra span { display:block; height:100%; background:var(--f3); border-radius:3px; }
        .mv-fila { cursor:pointer; }
        .mv-fila[aria-current="true"] td { background:rgba(99,102,241,.10); }
        /* Con el mapa angosto, la leyenda va debajo y no tapa el país. */
        @container (max-width: 760px) { .mv-leyenda { position:static; margin:0 12px 12px; width:max-content; } }
        @media (max-width: 520px) {
            .mv-svg { height:260px; }
            .mv-zoom { top:8px; right:8px; grid-auto-flow:column; }
            .mv-seg button { padding:0 .55rem; }
        }
        @media (prefers-reduced-motion: reduce) { .mv-zona { transition:none; } }
    </style>

    <div class="py-6 sm:py-8">
        <div class="mv mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8" id="mapa-ventas">

            @include('rutas._pestanas', ['activa' => 'mapa'])

            <x-rutas.avisos />

            {{-- ===================== Filtros: mes y ruta por GET; modo y nivel en el navegador ===================== --}}
            <div class="flex flex-wrap items-end gap-3">
                {{-- rutas-mapa.js le suma el #hash al enviar: así no se pierden el modo, el nivel ni la zona. --}}
                <form method="GET" action="{{ route('rutas.mapa') }}" class="flex flex-wrap items-end gap-3" id="mapa-filtros">
                    <div class="w-full sm:w-72">
                        <label for="mapa-mes" class="block text-xs font-medium text-gray-500">Mes</label>
                        <select id="mapa-mes" name="mes" class="{{ $campo }} mt-1">
                            @foreach ($meses as $valor => $etiqueta)
                                <option value="{{ $valor }}" @selected($filtro['mes'] === $valor)>{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="w-full sm:w-56">
                        <label for="mapa-ruta" class="block text-xs font-medium text-gray-500">Ruta</label>
                        <select id="mapa-ruta" name="ruta" class="{{ $campo }} mt-1">
                            <option value="">Todas las rutas</option>
                            @foreach ($datos['rutas'] as $ruta)
                                <option value="{{ $ruta['id'] }}" @selected($filtro['ruta'] === $ruta['id'])>{{ $ruta['nombre'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="{{ $secundario }}">Filtrar</button>
                    @if ($hayFiltro)
                        <a href="{{ route('rutas.mapa') }}" class="inline-flex min-h-11 items-center text-sm text-indigo-600 hover:underline">Limpiar</a>
                    @endif
                </form>

                <div>
                    <span class="block text-xs font-medium text-gray-500" id="mapa-ver">Ver en el mapa</span>
                    <div class="mv-seg mt-1 border border-gray-300 bg-white" role="group" aria-labelledby="mapa-ver">
                        <button type="button" class="text-gray-700" data-modo="ventas" aria-pressed="true">Ventas</button>
                        <button type="button" class="text-gray-700" data-modo="falta" aria-pressed="false">Dónde falta</button>
                        <button type="button" class="text-gray-700" data-modo="rutas" aria-pressed="false">Rutas</button>
                    </div>
                </div>
                <div>
                    <span class="block text-xs font-medium text-gray-500" id="mapa-nivel">Zonas</span>
                    <div class="mv-seg mt-1 border border-gray-300 bg-white" role="group" aria-labelledby="mapa-nivel">
                        <button type="button" class="text-gray-700" data-nivel="dep" aria-pressed="true">Departamentos</button>
                        <button type="button" class="text-gray-700" data-nivel="dis" aria-pressed="false">Distritos</button>
                    </div>
                </div>
            </div>

            {{-- ===================== Resumen del mes ===================== --}}
            <section aria-labelledby="mapa-resumen">
                <h2 id="mapa-resumen" class="sr-only">Resumen del mes</h2>
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <div class="{{ $caja }} p-4">
                        <span class="block text-sm text-gray-600">Vendido en el mes</span>
                        <span class="mt-1 block text-2xl font-semibold tabular-nums text-gray-800">{{ $plata($t['ventas']) }}</span>
                        <span class="mt-0.5 block text-xs text-gray-500">
                            @if ($variacion === null)
                                Sin ventas para comparar {{ $contra }}
                            @else
                                {{ $variacion >= 0 ? '▲' : '▼' }} {{ number_format(abs($variacion), 0) }} % {{ $contra }}
                            @endif
                        </span>
                        {{-- Es el total de Ventas: lo vendido sin sala también entra, pero no tiene lugar en el mapa. --}}
                        @if ($sinLugar['sinSala'] != 0)
                            <span class="mt-0.5 block text-xs text-gray-500">Incluye {{ $plata($sinLugar['sinSala']) }} sin sala (consumidor final), que no sale en el mapa.</span>
                        @endif
                    </div>
                    <div class="{{ $caja }} p-4">
                        <span class="block text-sm text-gray-600">Salas que compraron</span>
                        <span class="mt-1 block text-2xl font-semibold tabular-nums text-gray-800">{{ $t['compraron'] }} de {{ $t['salas'] }}</span>
                        <span class="mt-0.5 block text-xs text-gray-500">{{ $rutaElegida ? 'en '.$rutaElegida['nombre'] : 'en todas las rutas' }}</span>
                    </div>
                    @php
                        $leTocan = $t['faltan'] - $t['atrasadas'];
                    @endphp
                    <div class="{{ $caja }} p-4">
                        <span class="block text-sm text-gray-600">Salas atrasadas</span>
                        <span class="mt-1 block text-2xl font-semibold tabular-nums {{ $t['atrasadas'] > 0 ? 'text-red-700' : 'text-gray-800' }}">{{ $t['atrasadas'] }}</span>
                        <span class="mt-0.5 block text-xs text-gray-500">y a {{ $leTocan }} {{ $leTocan === 1 ? 'le toca' : 'les toca' }} visita, según cada cuánto compra cada una{{ $corte ? ', contados al '.$corte.', el cierre del mes' : '' }}; van primero en «Dónde falta»</span>
                    </div>
                    <div class="{{ $caja }} p-4">
                        <span class="block text-sm text-gray-600">Distritos sin ninguna sala</span>
                        <span class="mt-1 block text-2xl font-semibold tabular-nums text-gray-800">{{ $t['distritosSinSalas'] }}</span>
                        <span class="mt-0.5 block text-xs text-gray-500">donde todavía no vendemos{{ $rutaElegida ? ', en todo el país y con cualquier ruta' : '' }}</span>
                    </div>
                </div>
            </section>

            @if ($sinLugar['salas'] > 0)
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" role="note">
                    <strong>{{ $sinLugar['salas'] }} {{ $sinLugar['salas'] === 1 ? 'sala sin ubicación' : 'salas sin ubicación' }}.</strong>
                    No {{ $sinLugar['salas'] === 1 ? 'tiene' : 'tienen' }} distrito cargado: no {{ $sinLugar['salas'] === 1 ? 'sale' : 'salen' }} en el mapa, pero sí en el resumen ({{ $plata($sinLugar['ventas']) }} en el mes).
                </div>
            @endif

            {{-- ===================== Mapa y ficha de la zona ===================== --}}
            <div class="mv-principal">
                <section class="{{ $caja }} mv-mapa" aria-labelledby="mapa-titulo">
                    <div class="border-b border-gray-200 px-4 py-3">
                        <h2 id="mapa-titulo" class="text-base font-semibold text-gray-800">Ventas del mes por departamento</h2>
                        <p id="mapa-sub" class="text-sm text-gray-500">Color más intenso, más vendido. Tocá una zona para ver su ficha.</p>
                    </div>
                    <div class="mv-lienzo">
                        <svg id="mapa-svg" class="mv-svg" role="group" aria-labelledby="mapa-titulo mapa-sub"></svg>
                        <div class="mv-zoom">
                            <button type="button" class="mv-zbtn border border-gray-300 bg-white text-gray-700 shadow-sm" id="mapa-acercar" aria-label="Acercar">+</button>
                            <button type="button" class="mv-zbtn border border-gray-300 bg-white text-gray-700 shadow-sm" id="mapa-alejar" aria-label="Alejar">−</button>
                            <button type="button" class="mv-zbtn border border-gray-300 bg-white text-gray-700 shadow-sm" id="mapa-todo" aria-label="Ver todo el país">⤢</button>
                        </div>
                    </div>
                    {{-- Sin aria-live: se redibuja en cada clic y al cambiar el tamaño, y el lector la repetiría. --}}
                    <div class="mv-leyenda rounded-lg border border-gray-200 bg-white text-gray-700 shadow-sm" id="mapa-leyenda"></div>
                    <div class="mv-tip" id="mapa-tip" hidden></div>
                    <p class="px-4 py-6 text-center text-sm text-gray-500" id="mapa-carga">Cargando el mapa…</p>
                </section>

                <section class="{{ $caja }} p-4" id="mapa-ficha" aria-live="polite" aria-label="Ficha de la zona">
                    <p class="text-sm text-gray-500">Tocá una zona del mapa o de la tabla para ver su ficha.</p>
                </section>
            </div>

            {{-- ===================== Dónde falta y tabla de zonas ===================== --}}
            <div class="mv-abajo">
                <section class="{{ $caja }} p-4" aria-labelledby="mapa-falta">
                    <h2 id="mapa-falta" class="text-base font-semibold text-gray-800">Dónde falta</h2>
                    @foreach ($niveles as $nivel => $zonas)
                        @php
                            // Las zonas con más salas atrasadas y a las que les toca, por su ritmo.
                            $faltan = collect($zonas)->filter(fn ($z) => $z['faltan'] > 0)
                                ->sortBy([fn ($a, $b) => $b['atrasadas'] <=> $a['atrasadas'], fn ($a, $b) => $b['faltan'] <=> $a['faltan'], fn ($a, $b) => $b['anterior'] <=> $a['anterior']])
                                ->take(5);
                            $maximo = max(1, (int) $faltan->max('faltan'));
                            // Por donde pasa una ruta (la elegida, si hay una) y sin ninguna sala de
                            // ninguna ruta: con el filtro, las zonas de otras rutas no están vacías.
                            $vacias = collect($zonas)->filter(fn ($z) => $z['todas'] === 0 && ($rutaElegida ? in_array($rutaElegida['id'], $z['rutas'], true) : $z['rutas'] !== []))->sortBy('nombre');
                            $palabra = $nivel === 'dep' ? ($vacias->count() === 1 ? 'departamento' : 'departamentos') : ($vacias->count() === 1 ? 'distrito' : 'distritos');
                        @endphp
                        <ol class="mv-falta-lista mt-3" data-nivel-lista="{{ $nivel }}" @if ($nivel !== 'dep') hidden @endif>
                            @forelse ($faltan as $clave => $zona)
                                <li>
                                    <button type="button" data-zona="{{ $clave }}" class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-left hover:bg-gray-50">
                                        <span class="flex items-center justify-between gap-3">
                                            <span class="font-semibold text-gray-800">{{ $zona['nombre'] }}@if ($nivel === 'dis')<span class="font-normal text-gray-500"> · {{ $datos['departamentos'][$zona['dep']]['nombre'] ?? '' }}</span>@endif</span>
                                            <span class="inline-flex shrink-0 items-center gap-1.5 text-xs font-semibold text-gray-700"><span class="mv-punto" style="background:var({{ $zona['atrasadas'] > 0 ? '--mal' : '--ojo' }})"></span>{{ $zona['faltan'] }} de {{ $zona['salas'] }}</span>
                                        </span>
                                        <span class="mt-0.5 block text-xs text-gray-500">
                                            {{ $zona['atrasadas'] }} {{ $zona['atrasadas'] === 1 ? 'atrasada' : 'atrasadas' }} · a {{ $zona['leToca'] }} {{ $zona['leToca'] === 1 ? 'le toca' : 'les toca' }}{{ $alCorte }} · {{ $rutasTexto($zona['rutas']) ?: 'sin ruta' }}
                                        </span>
                                        <span class="mv-barra mt-1.5 block"><span style="width: {{ round($zona['faltan'] / $maximo * 100) }}%"></span></span>
                                    </button>
                                </li>
                            @empty
                                <li class="rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-500">Ninguna sala está atrasada ni le toca visita todavía.</li>
                            @endforelse
                            <li class="mv-falta-vacias rounded-lg border border-gray-200 px-3 py-2">
                                <span class="block text-sm text-gray-800"><strong>{{ $vacias->count() }} {{ $palabra }}</strong> {{ $rutaElegida ? 'de '.$rutaElegida['nombre'].' sin ninguna sala' : 'con ruta y sin ninguna sala' }}</span>
                                <span class="mt-0.5 block text-xs text-gray-500">
                                    @if ($vacias->isNotEmpty())
                                        Ya pasamos cerca: son los primeros para ofrecer.
                                    @else
                                        {{ $rutaElegida ? 'Cada lugar por donde pasa esta ruta ya tiene salas.' : 'Las rutas ya tienen salas en cada lugar que cubren.' }}
                                    @endif
                                </span>
                                {{-- Los nombres, para encontrarlas sin el dibujo y con el teclado. --}}
                                @if ($vacias->isNotEmpty())
                                    <details class="mt-1">
                                        <summary class="inline-flex min-h-11 cursor-pointer items-center text-sm font-medium text-indigo-600 hover:underline">Ver cuáles</summary>
                                        <ul class="flex flex-wrap gap-2 pb-1">
                                            @foreach ($vacias as $clave => $zona)
                                                <li>
                                                    <button type="button" data-zona="{{ $clave }}" class="inline-flex min-h-11 items-center rounded-full border border-gray-300 bg-white px-3 text-left text-sm text-gray-700 hover:bg-gray-50">
                                                        {{ $zona['nombre'] }}@if ($nivel === 'dis')<span class="text-gray-500">&nbsp;· {{ $datos['departamentos'][$zona['dep']]['nombre'] ?? '' }}</span>@endif
                                                    </button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif
                            </li>
                        </ol>
                    @endforeach
                </section>

                <section class="{{ $caja }} min-w-0 p-4" aria-labelledby="mapa-tabla">
                    <h2 id="mapa-tabla" class="text-base font-semibold text-gray-800"><span data-nivel-texto="dep">Departamentos</span><span data-nivel-texto="dis" hidden>Distritos con salas</span></h2>
                    @foreach ($niveles as $nivel => $zonas)
                        @php
                            $conSalas = collect($zonas)->filter(fn ($z) => $z['salas'] > 0 || $z['ventas'] != 0)->sortByDesc('ventas')->map(function (array $zona) use ($nivel, $datos, $corte) {
                                $cambio = $zona['anterior'] != 0 ? ($zona['ventas'] - $zona['anterior']) / $zona['anterior'] * 100 : null;

                                return $zona + [
                                    'depNombre' => $nivel === 'dis' ? ($datos['departamentos'][$zona['dep']]['nombre'] ?? '') : null,
                                    'cambioTexto' => $cambio === null ? '—' : ($cambio >= 0 ? '▲ ' : '▼ ').number_format(abs($cambio), 0).' %',
                                    // En un mes pasado, la fecha: «hoy» o «ayer» serían del cierre del mes, no de hoy.
                                    'entregaTexto' => match (true) {
                                        $zona['entrega'] === null => null,
                                        $corte !== null => \Illuminate\Support\Carbon::parse($zona['entrega'])->format('d/m/Y'),
                                        default => match ($zona['entregaDias']) { 0 => 'hoy', 1 => 'ayer', default => 'hace '.$zona['entregaDias'].' días' },
                                    },
                                ];
                            });
                        @endphp
                        <div class="mt-3" data-nivel-lista="{{ $nivel }}" @if ($nivel !== 'dep') hidden @endif>
                            @if ($conSalas->isEmpty())
                                <p class="py-10 text-center text-gray-500">Todavía no hay salas con distrito {{ $rutaElegida ? 'en '.$rutaElegida['nombre'] : 'cargado' }}.</p>
                            @else
                                {{-- En el celular, tarjetas en vez de la tabla (docs/GUIA_PANTALLAS.md, «Listas y tablas»). --}}
                                <ul class="divide-y divide-gray-100 sm:hidden">
                                    @foreach ($conSalas as $clave => $zona)
                                        <li>
                                            <button type="button" data-zona="{{ $clave }}" class="block min-h-11 w-full py-3 text-left">
                                                <span class="flex items-baseline justify-between gap-3">
                                                    <span class="min-w-0 font-medium text-gray-800">{{ $zona['nombre'] }}@if ($zona['depNombre'])<span class="font-normal text-gray-500"> · {{ $zona['depNombre'] }}</span>@endif</span>
                                                    <span class="shrink-0 tabular-nums text-gray-800">{{ $plata($zona['ventas']) }}</span>
                                                </span>
                                                <span class="mt-0.5 block text-sm text-gray-600">
                                                    {{ $zona['cambioTexto'] }} {{ $contraCorto }} · {{ $zona['salas'] }} {{ $zona['salas'] === 1 ? 'sala' : 'salas' }} · <span class="{{ $zona['atrasadas'] > 0 ? 'font-medium text-red-700' : '' }}">{{ $zona['atrasadas'] }} {{ $zona['atrasadas'] === 1 ? 'atrasada' : 'atrasadas' }}, a {{ $zona['leToca'] }} {{ $zona['leToca'] === 1 ? 'le toca' : 'les toca' }}{{ $alCorte }}</span>
                                                </span>
                                                <span class="block text-sm text-gray-500">{{ $rutasTexto($zona['rutas']) ?: 'Sin ruta' }} · última entrega {{ $zona['entregaTexto'] ?? '—' }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                                <div class="hidden overflow-x-auto sm:block">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                        <tr>
                                            <th scope="col" class="px-3 py-2">Zona</th>
                                            <th scope="col" class="px-3 py-2 text-right">Vendido</th>
                                            <th scope="col" class="px-3 py-2 text-right">{{ $contraCorto }}</th>
                                            <th scope="col" class="px-3 py-2 text-right">Salas</th>
                                            <th scope="col" class="px-3 py-2 text-right">Atrasadas</th>
                                            <th scope="col" class="px-3 py-2 text-right">Les toca</th>
                                            <th scope="col" class="px-3 py-2">Ruta</th>
                                            <th scope="col" class="px-3 py-2 text-right">Última entrega</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($conSalas as $clave => $zona)
                                            <tr class="mv-fila hover:bg-gray-50" data-zona="{{ $clave }}" tabindex="0">
                                                <td class="whitespace-nowrap px-3 py-2 text-gray-800">{{ $zona['nombre'] }}@if ($zona['depNombre'])<span class="text-gray-500"> · {{ $zona['depNombre'] }}</span>@endif</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-gray-800">{{ $plata($zona['ventas']) }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-gray-700">{{ $zona['cambioTexto'] }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-gray-700">{{ $zona['salas'] }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums {{ $zona['atrasadas'] > 0 ? 'font-medium text-red-700' : 'text-gray-700' }}">{{ $zona['atrasadas'] }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums {{ $zona['leToca'] > 0 ? 'font-medium text-amber-800' : 'text-gray-700' }}">{{ $zona['leToca'] }}</td>
                                                <td class="px-3 py-2 text-gray-700">{{ $rutasTexto($zona['rutas']) ?: '—' }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-right text-gray-700">
                                                    @if ($zona['entregaTexto'])
                                                        <time datetime="{{ $zona['entrega'] }}" title="{{ \Illuminate\Support\Carbon::parse($zona['entrega'])->format('d/m/Y') }}">{{ $zona['entregaTexto'] }}</time>
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                </div>
                            @endif
                        </div>
                    @endforeach
                    <p class="mt-3 text-xs text-gray-500">
                        Las ventas son las nacionales sin IVA, como en Ventas: la exportación no entra y las notas de crédito restan en el mes del CCF que corrigen.
                        La última entrega sale de las entregas de Rutas.
                        Una sala está atrasada si pasó su fecha de visita, contada por cada cuánto compra; sin tres visitas todavía no tiene ritmo.
                        @if ($corte)
                            En un mes pasado, el ritmo de cada sala y la última entrega se cuentan al {{ $corte }}, el cierre del mes.
                        @endif
                        Mapa: <a href="https://www.geoboundaries.org" class="underline" rel="noopener" target="_blank">geoBoundaries</a> (distritos) y © <a href="https://www.openstreetmap.org/copyright" class="underline" rel="noopener" target="_blank">OpenStreetMap</a> (departamentos, CC BY-SA).
                    </p>
                </section>
            </div>

        </div>
    </div>

    @php($paraElMapa = $datos + ['contra' => $contraCorto, 'corte' => $corte])
    <script type="application/json" id="mapa-datos">@json($paraElMapa)</script>
    <script src="{{ asset('vendor/d3/d3.min.js') }}" defer></script>
    <script src="{{ $script }}" defer></script>
</x-app-layout>
