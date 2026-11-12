<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm text-gray-500">Rutas › Tablero</p>
                <h1 class="text-xl font-semibold leading-tight text-gray-800">Mapa de ventas</h1>
            </div>
        </div>
    </x-slot>

    @php
        $caja = 'rounded-lg border border-gray-200 bg-white';
        $rotulo = 'text-xs font-semibold uppercase tracking-wide text-gray-500';
        $secundario = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
        $campo = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
        $fila = 'mv-fila flex min-h-11 w-full gap-2 rounded-md px-2 py-2 text-left hover:bg-gray-50';
        // Las pastillas de estado, con las clases de x-rutas.estado-badge.
        $pastilla = 'inline-block shrink-0 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold ';
        $roja = $pastilla.'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300';
        $ambar = $pastilla.'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300';

        $plata = fn (float $monto) => '$'.number_format($monto, 2);
        $t = $datos['totales'];
        $variacion = $t['anterior'] != 0 ? ($t['ventas'] - $t['anterior']) / $t['anterior'] * 100 : null;
        // Contra qué se compara: «frente al 1 al 9 de octubre» (mes en curso) o «frente a septiembre».
        $periodo = $datos['periodo'];
        $inicio = \Illuminate\Support\Carbon::parse($periodo['desde'])->locale('es');
        $mes = $inicio->translatedFormat('F').($inicio->year === (int) substr($datos['hoy'], 0, 4) ? '' : ' de '.$inicio->year);
        $mesAnterior = \Illuminate\Support\Carbon::parse($periodo['anteriorDesde'])->locale('es')->translatedFormat('F');
        $diaFin = (int) substr($periodo['anteriorHasta'], 8, 2);
        $frente = $datos['enCurso'] ? 'frente al '.($diaFin === 1 ? '1' : '1 al '.$diaFin).' de '.$mesAnterior : 'frente a '.$mesAnterior;
        $antes = $datos['enCurso'] ? ($diaFin === 1 ? 'El 1' : 'Del 1 al '.$diaFin).' de '.$mesAnterior : 'En '.$mesAnterior;
        // En un mes pasado, el ritmo de cada sala y la última entrega se cuentan al cierre del mes.
        $corte = $datos['enCurso'] ? null : \Illuminate\Support\Carbon::parse($periodo['hasta'])->format('d/m');
        $esteMes = $datos['enCurso'] ? 'este mes' : 'ese mes';
        $sinLugar = $datos['sinUbicacion'];
        $rutaElegida = collect($datos['rutas'])->firstWhere('id', $filtro['ruta']);
        $nombreRuta = collect($datos['rutas'])->pluck('nombre', 'id');
        $porVisitar = $t['faltan'] - $t['atrasadas'];
        $conVentas = collect($datos['departamentos'])->filter(fn ($z) => $z['ventas'] > 0)->count();

        // Las mismas frases que arma rutas-mapa.js para el mapa y la ficha.
        $plural = fn (int $n, string $uno, string $varios) => $n.' '.($n === 1 ? $uno : $varios);
        $enLista = fn (array $a) => count($a) <= 1 ? implode('', $a) : implode(', ', array_slice($a, 0, -1)).' y '.$a[count($a) - 1];
        $compraron = fn (array $z) => match (true) {
            $z['compraron'] === 0 => $datos['enCurso'] ? 'ninguna compró todavía' : 'ninguna compró',
            $z['compraron'] === $z['salas'] => $z['salas'] === 1 ? 'compró' : 'compraron todas',
            default => ($z['compraron'] === 1 ? 'compró ' : 'compraron ').$z['compraron'],
        };
        $salasTexto = fn (array $z) => $plural($z['salas'], 'sala', 'salas').' · '.$compraron($z);
        $pendientes = fn (array $z) => implode(' y ', array_filter([
            $z['atrasadas'] > 0 ? $plural($z['atrasadas'], 'sala atrasada', 'salas atrasadas') : null,
            $z['leToca'] > 0 ? ($z['atrasadas'] > 0 ? $z['leToca'] : $plural($z['leToca'], 'sala', 'salas')).' por visitar' : null,
        ]));
        $zonasDe = fn (array $z) => collect($z['rutas'])->map(fn ($id) => $nombreRuta[$id] ?? null)->filter()->values()->all();
        $zonasTexto = fn (array $z) => ($n = $zonasDe($z)) === [] ? 'Ninguna zona pasa por acá' : (count($n) === 1 ? 'Zona ' : 'Zonas ').$enLista($n);
        // El mismo azul del mapa para la barra de cada renglón: del tono bajo al alto.
        $mezcla = fn (int $p) => $p < 50
            ? 'color-mix(in oklab, var(--v-lo), var(--v-mid) '.($p * 2).'%)'
            : 'color-mix(in oklab, var(--v-mid), var(--v-hi) '.(($p - 50) * 2).'%)';
    @endphp

    {{-- El mapa necesita colores propios (el azul de las ventas, el rayado, la leyenda) y una
         grilla que el CSS de producción no trae; van en este bloque porque app.css no se
         recompila en el servidor. Los tonos siguen la clase `dark` de <html>, como el resto del
         sistema. En los dos temas el que más vende es el azul más fuerte, «$0» va en gris y
         «sin salas», rayado: ningún color del mapa es igual al fondo. --}}
    <style>
        .mv { --mv-tinta:#111827; --mv-tinta-2:#374151; --mv-tenue:#6b7280; --mv-linea:#e5e7eb; --mv-linea-2:#d1d5db; --mv-acento:#4f46e5;
              --mapa-fondo:#ffffff; --mapa-borde:#ffffff; --mapa-orilla:#9aa3b2;
              --v-lo:#c6dcfa; --v-mid:#4a8fe6; --v-hi:#0f3d80;
              --cero:#dde1e8; --raya:#aeb5c2; --raya-fondo:#f3f4f6; --fuera:#f8f9fb;
              --a-mal:#ec6a6a; --a-ojo:#f6c04d; --mal:#dc2626; --ojo:#f59e0b;
              --cuenta-fondo:#fee2e2; --cuenta-tinta:#b91c1c; --tip-fondo:#111827; --tip-tinta:#ffffff; }
        .dark .mv { --mv-tinta:#e9eaee; --mv-tinta-2:#c9cbd3; --mv-tenue:#a3a6b1; --mv-linea:#34363f; --mv-linea-2:#4a4d59; --mv-acento:#818cf8;
              --mapa-fondo:#202227; --mapa-borde:#131417; --mapa-orilla:#6b7080;
              --v-lo:#2f4d82; --v-mid:#3f7fd9; --v-hi:#69b1ff;
              --cero:#3a3d47; --raya:#5d6270; --raya-fondo:#2a2c33; --fuera:#25272d;
              --a-mal:#d9494c; --a-ojo:#d9a02a; --mal:#f0605d; --ojo:#f0b232;
              --cuenta-fondo:#3a1717; --cuenta-tinta:#fca5a5; --tip-fondo:#e9eaee; --tip-tinta:#131417; }
        .mv [hidden] { display:none !important; }

        /* El mapa y, al lado, la ficha y la lista; debajo del mapa, la tabla y la nota. En una sola
           columna (celular y pantallas medianas, por la barra lateral) van en ese mismo orden. */
        .mv-principal { display:grid; grid-template-columns:minmax(0,1fr); gap:1rem; align-items:start; }
        .mv-lado { display:grid; gap:1rem; min-width:0; }
        @media (min-width: 1280px) {
            .mv-principal { grid-template-columns:minmax(0,1fr) 360px; grid-template-rows:auto 1fr; }
            .mv-al-lado { grid-column:2; grid-row:1 / span 2; }
            .mv-abajo { grid-column:1; }
        }

        .mv-seg { display:inline-flex; gap:2px; padding:3px; border-radius:.625rem; }
        .mv-seg button { display:inline-flex; min-height:2.75rem; align-items:center; justify-content:center; gap:.5rem; padding:0 1.125rem; border-radius:.45rem; font-size:.9375rem; font-weight:600; }
        .mv-seg button[aria-pressed="false"]:hover { background:rgba(99,102,241,.08); }
        .mv-seg button[aria-pressed="true"] { background:var(--mv-acento); color:#fff; }
        .dark .mv-seg button[aria-pressed="true"] { color:#131417; }
        .mv-cuenta { display:inline-flex; min-width:1.375rem; height:1.375rem; align-items:center; justify-content:center; border-radius:999px; padding:0 .375rem; font-size:.75rem; font-weight:700; background:var(--cuenta-fondo); color:var(--cuenta-tinta); }
        .mv-dato-ir { cursor:pointer; }
        .mv-dato-ir:hover { border-color:var(--mv-acento); }
        .mv-seg button:focus-visible, .mv-fila:focus-visible, .mv-dato-ir:focus-visible, .mv-tabla > summary:focus-visible { outline:2px solid var(--mv-acento); outline-offset:2px; }

        /* ---- El dibujo. Sin `touch-action`: en el celular, deslizar sobre el mapa mueve la página. ---- */
        .mv-mapa { position:relative; overflow:hidden; }
        .mv-lienzo { position:relative; }
        /* El alto lo fija rutas-mapa.js según el ancho; este es el de mientras carga. */
        .mv-svg { display:block; width:100%; height:400px; background:var(--mapa-fondo); }
        .mv-lugar { stroke:var(--mapa-borde); stroke-width:1.5; vector-effect:non-scaling-stroke; cursor:pointer; outline:none; transition:opacity .25s; }
        .mv-lugar.mv-rayado, .mv-lugar.mv-cero { stroke:var(--mapa-orilla); stroke-width:.8; }
        .mv-lugar.mv-fuera { stroke:var(--mapa-orilla); stroke-width:.8; stroke-dasharray:3 3; }
        .mv-lugar.mv-apagado { fill:var(--raya-fondo) !important; stroke:var(--mapa-orilla); stroke-width:.6; }
        .mv-lugar.mv-abierto { fill:var(--raya-fondo) !important; stroke:none; pointer-events:none; }
        .mv-lugar:hover { stroke:var(--mv-tinta); stroke-width:1.6; }
        .mv-lugar:focus-visible { stroke:var(--mv-acento); stroke-width:3; stroke-dasharray:none; }
        .mv-marco { fill:none; stroke:var(--mv-tinta); stroke-width:2.4; vector-effect:non-scaling-stroke; pointer-events:none; stroke-linejoin:round; }
        .mv-marco-dep { stroke-width:1.6; }
        /* Nombres y cifras: con un borde del color del fondo para leerse sobre cualquier tono. */
        .mv-et { pointer-events:none; text-anchor:middle; paint-order:stroke; stroke:var(--mapa-fondo); stroke-linejoin:round; }
        .mv-et-n { font-size:11px; font-weight:600; fill:var(--mv-tinta-2); stroke-width:3px; }
        .mv-et-v { font-size:13.5px; font-weight:700; fill:var(--mv-tinta); stroke-width:3.5px; font-variant-numeric:tabular-nums; }
        .mv-et-m { font-size:10.5px; font-weight:500; fill:var(--mv-tenue); stroke-width:3px; }
        .mv-tenue { opacity:.75; }
        .mv-pastilla rect { fill:var(--mapa-fondo); stroke:var(--mv-linea-2); stroke-width:1; }
        .mv-pastilla text { font-size:12px; font-weight:700; fill:var(--mv-tinta); font-variant-numeric:tabular-nums; pointer-events:none; }
        .mv-p-mal { fill:var(--mal); }
        .mv-p-ojo { fill:var(--ojo); }
        .mv-angosto .mv-et-n { font-size:9.5px; stroke-width:2.5px; }
        .mv-angosto .mv-et-v { font-size:11.5px; stroke-width:3px; }
        .mv-angosto .mv-et-m { font-size:9px; }
        .mv-tip { position:absolute; z-index:5; pointer-events:none; max-width:240px; padding:8px 10px; border-radius:8px; font-size:12.5px; line-height:1.4;
                  background:var(--tip-fondo); color:var(--tip-tinta); box-shadow:0 6px 18px rgba(0,0,0,.25); }

        /* ---- La leyenda, debajo del dibujo: no tapa el país. ---- */
        .mv-leyenda { display:flex; flex-wrap:wrap; align-items:center; gap:.5rem 1.125rem; padding:.625rem 1rem .75rem; font-size:.8125rem; }
        .mv-rampa { display:inline-flex; align-items:center; gap:.5rem; max-width:100%; white-space:nowrap; font-variant-numeric:tabular-nums; }
        .mv-rampa i { display:block; flex:0 1 120px; width:120px; min-width:48px; height:12px; border-radius:3px;
                      background:linear-gradient(to right, var(--v-lo), var(--v-mid), var(--v-hi));
                      background:linear-gradient(to right in oklab, var(--v-lo), var(--v-mid), var(--v-hi)); }
        .mv-mues { display:inline-flex; align-items:center; gap:.375rem; }
        .mv-mues > i { display:inline-block; width:18px; height:12px; border-radius:3px; border:1px solid var(--mapa-orilla); }
        .mv-mues > i.mv-punteado { border-style:dashed; }
        .mv-rayas { background:repeating-linear-gradient(45deg, var(--raya-fondo) 0 3px, var(--raya) 3px 4.5px); }
        .mv-mini { display:inline-flex; align-items:center; gap:5px; border:1px solid var(--mv-linea-2); border-radius:999px; padding:1px 8px; font-size:12px; font-weight:700; }
        .mv-punto { display:inline-block; width:10px; height:10px; border-radius:50%; }

        /* ---- La lista y la tabla ---- */
        .mv-fila { cursor:pointer; }
        .mv-fila[aria-current="true"], .mv-fila[aria-current="true"] td { background:rgba(99,102,241,.12); }
        .mv-barra { display:block; height:5px; margin-top:6px; border-radius:3px; background:var(--mv-linea); overflow:hidden; }
        .mv-barra i { display:block; height:100%; border-radius:3px; }
        .mv-cuad { display:inline-block; width:14px; height:14px; margin-top:3px; border-radius:3px; border:1px solid var(--mapa-orilla); }
        .mv-cuad.mv-cero { background:var(--cero); }
        .mv-tabla > summary { list-style:none; }
        .mv-tabla > summary::-webkit-details-marker { display:none; }
        .mv-tabla > summary::after { content:'▾'; margin-left:.5rem; }
        .mv-tabla[open] > summary::after { content:'▴'; }

        @media (max-width: 639px) {
            .mv-seg { display:flex; }
            .mv-seg button { flex:1; padding:0 .625rem; }
        }
        @media (prefers-reduced-motion: reduce) { .mv-lugar { transition:none; } }
    </style>

    <div class="py-6 sm:py-8">
        <div class="mv mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8" id="mapa-ventas">

            @include('rutas._pestanas', ['activa' => 'mapa'])

            <x-rutas.avisos />

            {{-- ===================== Qué se ve (en el navegador) y los filtros de mes y zona (por GET) ===================== --}}
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-full sm:w-auto">
                    <span class="block text-xs font-medium text-gray-500" id="mapa-ver">Qué querés ver</span>
                    <div class="mv-seg mt-1 border border-gray-300 bg-white" role="group" aria-labelledby="mapa-ver">
                        <button type="button" class="text-gray-700" data-vista="ventas" aria-pressed="true">Ventas</button>
                        <button type="button" class="text-gray-700" data-vista="ir" aria-pressed="false">A dónde ir @if ($t['faltan'] > 0)<span class="mv-cuenta">{{ $t['faltan'] }}<span class="sr-only"> salas para visitar</span></span>@endif</button>
                    </div>
                </div>

                {{-- rutas-mapa.js le suma el #hash al enviar: así no se pierden la vista ni el lugar elegido. --}}
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
                        <label for="mapa-ruta" class="block text-xs font-medium text-gray-500">Zona</label>
                        <select id="mapa-ruta" name="ruta" class="{{ $campo }} mt-1">
                            <option value="">Todas las zonas</option>
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
            </div>

            {{-- ===================== Resumen del mes ===================== --}}
            <section aria-labelledby="mapa-resumen">
                <h2 id="mapa-resumen" class="sr-only">Resumen del mes</h2>
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
                    <div class="{{ $caja }} col-span-2 p-4 lg:col-span-1">
                        <span class="block text-sm text-gray-600">Vendido en {{ $mes }}</span>
                        <span class="mt-1 block text-2xl font-semibold tabular-nums text-gray-800">{{ $plata($t['ventas']) }}</span>
                        <span class="mt-0.5 block text-sm text-gray-500">
                            @if ($variacion === null)
                                Sin ventas para comparar {{ $frente }}
                            @else
                                <strong class="font-semibold {{ $variacion >= 0 ? 'text-green-700' : 'text-red-700' }}">{{ $variacion >= 0 ? '▲' : '▼' }} {{ number_format(abs($variacion), 0) }} %</strong> {{ $frente }}
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
                        <span class="mt-0.5 block text-sm text-gray-500">{{ $rutaElegida ? 'en la zona '.$rutaElegida['nombre'] : 'en '.$conVentas.' de los '.count($datos['departamentos']).' departamentos' }}</span>
                    </div>
                    {{-- Lleva a «A dónde ir»: es un botón que cambia la vista, no un enlace a otra pantalla. --}}
                    <button type="button" data-vista="ir" class="{{ $caja }} mv-dato-ir p-4 text-left">
                        <span class="block text-sm text-gray-600">Salas para visitar</span>
                        <span class="mt-1 block text-2xl font-semibold tabular-nums text-gray-800">{{ $t['faltan'] }}</span>
                        <span class="mt-0.5 block text-sm text-gray-500">
                            @if ($t['faltan'] === 0)
                                Ninguna atrasada ni por visitar
                            @elseif ($t['atrasadas'] === 0)
                                Ninguna atrasada; {{ $porVisitar }} por visitar
                            @else
                                <strong class="font-semibold text-red-700">{{ $t['atrasadas'] }} {{ $t['atrasadas'] === 1 ? 'atrasada' : 'atrasadas' }}</strong>@if ($porVisitar > 0) y {{ $porVisitar }} por visitar @endif
                            @endif
                            @if ($corte)
                                <span class="block">Contadas al {{ $corte }}, el cierre del mes.</span>
                            @endif
                            <span class="whitespace-nowrap font-semibold text-indigo-600" data-solo-ventas>Ver a dónde ir ›</span>
                        </span>
                    </button>
                </div>
            </section>

            @if ($sinLugar['salas'] > 0)
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" role="note">
                    <strong>{{ $sinLugar['salas'] }} {{ $sinLugar['salas'] === 1 ? 'sala sin ubicación' : 'salas sin ubicación' }}.</strong>
                    No {{ $sinLugar['salas'] === 1 ? 'tiene' : 'tienen' }} distrito cargado: no {{ $sinLugar['salas'] === 1 ? 'sale' : 'salen' }} en el mapa, pero sí en el resumen ({{ $plata($sinLugar['ventas']) }} en el mes).
                </div>
            @endif

            <div class="mv-principal">
                {{-- ===================== El mapa ===================== --}}
                <section class="{{ $caja }} mv-mapa" aria-labelledby="mapa-titulo">
                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-b border-gray-200 px-4 py-3">
                        <div class="min-w-0">
                            <h2 id="mapa-titulo" class="text-base font-semibold text-gray-800">Ventas por departamento</h2>
                            <p id="mapa-sub" class="text-sm text-gray-500">Azul más fuerte, más vendido. Tocá un departamento para ver sus distritos.</p>
                        </div>
                        <button type="button" class="{{ $secundario }}" id="mapa-volver" hidden>‹ Todo el país</button>
                    </div>
                    <div class="mv-lienzo">
                        <svg id="mapa-svg" class="mv-svg" role="group" aria-labelledby="mapa-titulo mapa-sub"></svg>
                        <div class="mv-tip" id="mapa-tip" hidden></div>
                    </div>
                    <p class="px-4 py-6 text-center text-sm text-gray-500" id="mapa-carga">Cargando el mapa…</p>
                    {{-- Sin aria-live: se redibuja en cada clic y al cambiar el tamaño, y el lector la repetiría. --}}
                    <div class="mv-leyenda border-t border-gray-200 text-gray-700" id="mapa-leyenda" hidden></div>
                </section>

                <div class="mv-lado mv-al-lado">
                    {{-- ===================== La ficha del lugar elegido (la arma rutas-mapa.js) ===================== --}}
                    <section class="{{ $caja }} p-4" id="mapa-ficha" aria-live="polite" aria-label="Ficha del lugar" hidden></section>

                    {{-- ===================== Lo mismo que el mapa, en palabras =====================
                         Una lista por vista (ventas, a dónde ir) para el país y para cada departamento;
                         el navegador muestra la que toca. Ya vienen ordenadas de MapaVentas::listas(). --}}
                    <section class="{{ $caja }}" aria-label="Lo mismo que el mapa, en una lista">
                        @foreach ($listas as $ambito => $lista)
                            @php
                                $deDep = $ambito !== 'pais';
                                $lugares = $deDep ? $datos['distritos'] : $datos['departamentos'];
                                [$uno, $varios] = $deDep ? ['distrito', 'distritos'] : ['departamento', 'departamentos'];
                                $v = $lista['ventas'];
                                $ir = $lista['ir'];
                                $listados = count($v['con']) + count($v['cero']);
                            @endphp

                            <div data-lista="{{ $ambito }}" data-lista-vista="ventas" @if ($deDep) hidden @endif>
                                <div class="px-4 pb-1 pt-4">
                                    <h2 class="text-base font-semibold text-gray-800">{{ $deDep ? 'Distritos de '.$lista['nombre'] : 'Quién vendió más' }}</h2>
                                    <p class="text-sm text-gray-500">
                                        @if ($deDep)
                                            Del que más vendió al que menos.
                                        @else
                                            {{ $rutaElegida ? 'Los departamentos de la zona '.$rutaElegida['nombre'] : 'Los '.count($lugares).' departamentos' }}, del que más vendió al que menos.
                                        @endif
                                    </p>
                                </div>
                                @if ($v['con'] !== [])
                                    <ol class="px-2 pb-2">
                                        @foreach ($v['con'] as $i => $clave)
                                            @php
                                                $z = $lugares[$clave];
                                                $p = (int) round(min(1, $z['ventas'] / $v['maximo']) * 100);
                                            @endphp
                                            <li>
                                                <button type="button" data-lugar="{{ $clave }}" class="{{ $fila }}">
                                                    <span class="w-6 shrink-0 text-sm font-semibold tabular-nums text-gray-500">{{ $i + 1 }}</span>
                                                    <span class="min-w-0 flex-1">
                                                        <span class="flex items-baseline justify-between gap-3">
                                                            <span class="min-w-0 font-semibold text-gray-800">{{ $z['nombre'] }}</span>
                                                            <span class="shrink-0 font-semibold tabular-nums text-gray-800">{{ $plata($z['ventas']) }}</span>
                                                        </span>
                                                        <span class="block text-sm text-gray-500">{{ $salasTexto($z) }}</span>
                                                        <span class="mv-barra" aria-hidden="true"><i style="width:{{ max(3, $p) }}%;background:var(--v-mid);background:{{ $mezcla($p) }}"></i></span>
                                                    </span>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ol>
                                @endif
                                @if ($v['cero'] !== [])
                                    <h3 class="{{ $rotulo }} px-4 pb-1 pt-3">Tienen salas y {{ $esteMes }} nadie compró</h3>
                                    <ul class="px-2 pb-2">
                                        @foreach ($v['cero'] as $clave)
                                            @php $z = $lugares[$clave]; @endphp
                                            <li>
                                                <button type="button" data-lugar="{{ $clave }}" class="{{ $fila }}">
                                                    <span class="w-6 shrink-0"><span class="mv-cuad mv-cero" aria-hidden="true"></span></span>
                                                    <span class="flex min-w-0 flex-1 items-baseline justify-between gap-3">
                                                        <span class="min-w-0"><span class="font-semibold text-gray-800">{{ $z['nombre'] }}</span> <span class="text-sm text-gray-500">· {{ $plural($z['salas'], 'sala', 'salas') }}</span></span>
                                                        <span class="shrink-0 tabular-nums text-gray-500">{{ $plata($z['ventas']) }}</span>
                                                    </span>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                {{-- Los distritos sin salas no se nombran (son muchos): abajo se dice cuántos son. --}}
                                @if (! $deDep && $v['sin'] !== [])
                                    <h3 class="{{ $rotulo }} px-4 pb-1 pt-3">Sin salas</h3>
                                    <ul class="px-2 pb-2">
                                        @foreach ($v['sin'] as $clave)
                                            @php $z = $lugares[$clave]; @endphp
                                            <li>
                                                <button type="button" data-lugar="{{ $clave }}" class="{{ $fila }}">
                                                    <span class="w-6 shrink-0"><span class="mv-cuad mv-rayas" aria-hidden="true"></span></span>
                                                    <span class="min-w-0 flex-1"><span class="font-semibold text-gray-800">{{ $z['nombre'] }}</span> <span class="text-sm text-gray-500">· {{ $rutaElegida ? 'sin salas de esta zona' : 'todavía no vendemos acá' }}</span></span>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($listados === 0 && ($deDep || $v['sin'] === []))
                                    <p class="px-4 pb-4 pt-2 text-sm text-gray-500">{{ $deDep ? 'Ningún distrito de '.$lista['nombre'].' tiene salas '.($rutaElegida ? 'de esta zona.' : 'todavía.') : 'Todavía no hay salas con distrito '.($rutaElegida ? 'en la zona '.$rutaElegida['nombre'].'.' : 'cargado.') }}</p>
                                @elseif ($deDep && $v['sinSalas'] > 0)
                                    <p class="border-t border-gray-200 px-4 py-3 text-sm text-gray-500">
                                        @if ($rutaElegida)
                                            {{ $v['sinSalas'] === 1 ? 'En otro distrito' : 'En otros '.$v['sinSalas'].' distritos' }} de {{ $lista['nombre'] }} pasa esta zona y todavía no tiene salas.
                                        @else
                                            {{ $v['sinSalas'] === 1 ? 'El otro distrito de '.$lista['nombre'].' no tiene salas.' : 'Los otros '.$v['sinSalas'].' distritos de '.$lista['nombre'].' no tienen salas.' }}
                                        @endif
                                    </p>
                                @endif
                            </div>

                            <div data-lista="{{ $ambito }}" data-lista-vista="ir" hidden>
                                <div class="px-4 pb-1 pt-4">
                                    <h2 class="text-base font-semibold text-gray-800">{{ $deDep ? 'A dónde ir en '.$lista['nombre'] : 'A dónde ir primero' }}</h2>
                                    @if ($ir['con'] !== [])
                                        <p class="text-sm text-gray-500">Primero donde hay salas atrasadas; después donde ya toca visita.@if ($corte) Contado al {{ $corte }}, el cierre del mes.@endif</p>
                                    @endif
                                </div>
                                @if ($ir['con'] !== [])
                                    <ol class="px-2 pb-2">
                                        @foreach ($ir['con'] as $i => $clave)
                                            @php $z = $lugares[$clave]; @endphp
                                            <li>
                                                <button type="button" data-lugar="{{ $clave }}" class="{{ $fila }}">
                                                    <span class="w-6 shrink-0 text-sm font-semibold tabular-nums text-gray-500">{{ $i + 1 }}</span>
                                                    <span class="min-w-0 flex-1">
                                                        <span class="flex items-baseline justify-between gap-3">
                                                            <span class="min-w-0 font-semibold text-gray-800">{{ $z['nombre'] }}</span>
                                                            <span class="{{ $z['atrasadas'] > 0 ? $roja : $ambar }}">{{ $z['atrasadas'] > 0 ? 'Atrasada' : 'Por visitar' }}</span>
                                                        </span>
                                                        <span class="block text-sm text-gray-800">{{ $pendientes($z) }}</span>
                                                        <span class="block text-sm text-gray-500">{{ $zonasTexto($z) }}</span>
                                                    </span>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ol>
                                @else
                                    <p class="px-4 pb-3 pt-1 text-sm text-gray-500">Ninguna sala atrasada ni por visitar{{ $deDep ? ' en '.$lista['nombre'] : '' }}.</p>
                                @endif
                                @if ($ir['alDia'] > 0)
                                    <p class="border-t border-gray-200 px-4 py-3 text-sm text-gray-500">
                                        @if ($ir['con'] === [])
                                            Todos los {{ $varios }} con salas están al día.
                                        @else
                                            {{ $ir['alDia'] === 1 ? 'El otro '.$uno.' con salas está' : 'Los otros '.$ir['alDia'].' '.$varios.' con salas están' }} al día.
                                        @endif
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </section>
                </div>

                <div class="mv-lado mv-abajo">
                    {{-- ===================== La tabla completa, plegada =====================
                         Distinta de la guía («Listas y tablas»): en el celular no se cambia por tarjetas,
                         porque las tarjetas ya son la lista de arriba; plegada, se corre de lado. --}}
                    <details class="{{ $caja }} mv-tabla">
                        <summary class="flex min-h-11 cursor-pointer items-center px-4 text-sm font-semibold text-indigo-600">
                            <span data-tabla-texto="dep">Ver todos los departamentos en una tabla</span>
                            <span data-tabla-texto="dis" hidden>Ver los distritos de <span data-nombre-dep></span> en una tabla</span>
                        </summary>
                        @foreach (['dep' => $datos['departamentos'], 'dis' => $datos['distritos']] as $nivel => $zonas)
                            @php
                                $conSalas = collect($zonas)->filter(fn ($z) => $z['salas'] > 0 || $z['ventas'] != 0)->sortByDesc('ventas')->map(function (array $zona) use ($corte) {
                                    $cambio = $zona['anterior'] != 0 ? ($zona['ventas'] - $zona['anterior']) / $zona['anterior'] * 100 : null;

                                    return $zona + [
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
                            <div class="border-t border-gray-200" data-tabla="{{ $nivel }}" @if ($nivel !== 'dep') hidden @endif>
                                @if ($conSalas->isEmpty())
                                    <p class="py-10 text-center text-gray-500">Todavía no hay salas con distrito {{ $rutaElegida ? 'en la zona '.$rutaElegida['nombre'] : 'cargado' }}.</p>
                                @else
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full text-sm">
                                            <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                                <tr>
                                                    <th scope="col" class="px-3 py-2">Lugar</th>
                                                    <th scope="col" class="px-3 py-2 text-right">Vendido</th>
                                                    <th scope="col" class="px-3 py-2 text-right" title="{{ ucfirst($frente) }}">Cambio</th>
                                                    <th scope="col" class="px-3 py-2 text-right">Salas</th>
                                                    <th scope="col" class="px-3 py-2 text-right">Compraron</th>
                                                    <th scope="col" class="px-3 py-2 text-right">Atrasadas</th>
                                                    <th scope="col" class="px-3 py-2 text-right">Por visitar</th>
                                                    <th scope="col" class="px-3 py-2">Zonas</th>
                                                    <th scope="col" class="px-3 py-2 text-right">Última entrega</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100">
                                                @foreach ($conSalas as $clave => $zona)
                                                    <tr class="mv-fila hover:bg-gray-50" data-lugar="{{ $clave }}" tabindex="0" @if ($nivel === 'dis') data-de-dep="{{ $zona['dep'] }}" @endif>
                                                        <td class="whitespace-nowrap px-3 py-2 text-gray-800">{{ $zona['nombre'] }}</td>
                                                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-gray-800">{{ $plata($zona['ventas']) }}</td>
                                                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-gray-700">{{ $zona['cambioTexto'] }}</td>
                                                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-gray-700">{{ $zona['salas'] }}</td>
                                                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-gray-700">{{ $zona['compraron'] }}</td>
                                                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums {{ $zona['atrasadas'] > 0 ? 'font-medium text-red-700' : 'text-gray-700' }}">{{ $zona['atrasadas'] }}</td>
                                                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums {{ $zona['leToca'] > 0 ? 'font-medium text-amber-800' : 'text-gray-700' }}">{{ $zona['leToca'] }}</td>
                                                        <td class="px-3 py-2 text-gray-700">{{ $enLista($zonasDe($zona)) ?: '—' }}</td>
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
                    </details>

                    <p class="text-xs text-gray-500">
                        Las ventas son las nacionales sin IVA, como en Ventas: la exportación no entra y las notas de crédito restan en el mes del CCF que corrigen.
                        <strong class="font-semibold">Atrasada:</strong> ya pasó la fecha en que le tocaba visita, según cada cuánto compra esa sala.
                        <strong class="font-semibold">Por visitar:</strong> le toca en estos días. Sin tres visitas, una sala todavía no tiene ritmo.
                        La última entrega sale de las entregas de Rutas.
                        @if ($corte)
                            En un mes pasado, el ritmo de cada sala y la última entrega se cuentan al {{ $corte }}, el cierre del mes.
                        @endif
                        Mapa: <a href="https://www.geoboundaries.org" class="underline" rel="noopener" target="_blank">geoBoundaries</a> (distritos) y © <a href="https://www.openstreetmap.org/copyright" class="underline" rel="noopener" target="_blank">OpenStreetMap</a> (departamentos, CC BY-SA).
                    </p>
                </div>
            </div>

        </div>
    </div>

    @php($paraElMapa = $datos + ['contra' => $frente, 'antes' => $antes, 'corte' => $corte])
    <script type="application/json" id="mapa-datos">@json($paraElMapa)</script>
    <script src="{{ asset('vendor/d3/d3.min.js') }}" defer></script>
    <script src="{{ $script }}" defer></script>
</x-app-layout>
