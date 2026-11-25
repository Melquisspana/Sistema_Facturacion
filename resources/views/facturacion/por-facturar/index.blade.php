{{-- Facturación › «Llegó de pedidos» (docs/GUIA_PANTALLAS.md, «Llegó de pedidos», 24/11/2026):
     una fila por sala (= por pedido) con su estado, la OC y «Facturar»; los productos se ven
     al tocar la fila. Abre con todo lo que falta facturar, de cualquier día, en grupos: «Para
     facturar (OC lista)», «Esperando OC», «Esperando el resto» y, cerrado al final, lo
     facturado hoy. El día es un filtro aparte y las zonas, pastillas para mirar, como en
     Rutas › «Por entregar».

     Variables: $pedidos (Collection), $grupos [clave, titulo, pedidos], $zonas [clave,
     nombre, cuantos], $ruta (?string), $filtroInvalido, $oc [pedido_id => propuestas,
     titulo, aviso, lista], $fecha (?Y-m-d), $facturacion [pedido_id => por_facturar,
     borrador, facturados], $mismaTienda y $otrosDiasSinFacturar (int). --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Llegó de pedidos</h1>
            {{-- x-secondary-button es solo <button>: un enlace lleva sus clases, como en el resto de Rutas. --}}
            <a href="{{ route('facturacion.index') }}"
               class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver a facturación</a>
        </div>
    </x-slot>

    @php
        $pastilla = 'inline-flex min-h-11 shrink-0 items-center rounded-full px-4 text-sm font-medium';
        $elegida = 'bg-indigo-600 text-white';
        $otra = 'bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50';
        $titulo = 'text-lg font-semibold text-gray-800';
        $fila = fn ($pedido) => [
            'pedido' => $pedido, 'oc' => $oc[$pedido->id], 'fecha' => $fecha,
            'facturacion' => $facturacion[$pedido->id], 'mismaTienda' => $mismaTienda[$pedido->id] ?? 0,
        ];
    @endphp

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <x-rutas.avisos />

            {{-- El día es un filtro aparte: sin día, todo lo pendiente. --}}
            <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label for="f-fecha" class="block text-xs font-medium text-gray-500">Día que salió de pedidos</label>
                    <input id="f-fecha" type="date" name="fecha" value="{{ $fecha }}" class="mt-1 min-h-11 rounded-md border-gray-300 text-sm">
                </div>
                <button class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Filtrar</button>
                @if ($fecha)
                    <a href="{{ route('facturacion.llego-de-fabrica') }}" class="inline-flex min-h-11 items-center text-sm text-indigo-600 underline">Limpiar</a>
                @endif
            </form>

            @if ($otrosDiasSinFacturar > 0)
                <div role="status" class="mb-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
                    Hay {{ $otrosDiasSinFacturar }} {{ $otrosDiasSinFacturar === 1 ? 'pedido' : 'pedidos' }} de otros días sin facturar.
                    <a href="{{ route('facturacion.llego-de-fabrica') }}" class="font-semibold underline">Ver todo lo pendiente</a>
                </div>
            @endif

            <p class="mb-4 text-sm text-gray-600">
                @if ($fecha)
                    Lo que salió de pedidos el {{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $fecha)->format('d/m/Y') }}.
                @else
                    Todo lo que falta facturar, de cualquier día.
                @endif
                Tocá una sala para ver sus productos. «Facturar» abre el borrador del CCF con todo lo que llegó: ahí bajás lo que la OC no pide.
            </p>

            {{-- Zonas: solo para mirar (?ruta=), como en Rutas › «Por entregar». --}}
            @if ($zonas->isNotEmpty())
                <nav aria-label="Qué zona mostrar" class="mb-2 flex flex-wrap gap-2">
                    <a href="{{ request()->fullUrlWithQuery(['ruta' => null]) }}" @if ($ruta === null) aria-current="page" @endif
                       class="{{ $pastilla }} {{ $ruta === null ? $elegida : $otra }}">Todas {{ $zonas->sum('cuantos') }}</a>
                    @foreach ($zonas as $zona)
                        <a href="{{ request()->fullUrlWithQuery(['ruta' => $zona['clave']]) }}" @if ($ruta === $zona['clave']) aria-current="page" @endif
                           class="{{ $pastilla }} {{ $ruta === $zona['clave'] ? $elegida : $otra }}">{{ $zona['nombre'] }} {{ $zona['cuantos'] }}</a>
                    @endforeach
                </nav>
            @endif

            @if ($filtroInvalido)
                <div class="mt-4 rounded-lg border border-gray-200 bg-white px-4 py-10 text-center text-gray-500">
                    Esa zona no tiene nada en esta lista.
                    <a href="{{ request()->fullUrlWithQuery(['ruta' => null]) }}" class="text-indigo-600 underline">Ver todas</a>
                </div>
            @elseif ($pedidos->isEmpty())
                <div class="mt-4 rounded-lg border border-gray-200 bg-white px-4 py-10 text-center text-gray-500">
                    @if ($fecha)
                        No salió nada de pedidos ese día.
                        <a href="{{ route('facturacion.llego-de-fabrica') }}" class="text-indigo-600 underline">Ver todo lo pendiente</a>
                    @else
                        No queda nada por facturar.
                    @endif
                </div>
            @else
                @foreach ($grupos as $grupo)
                    @continue ($grupo['pedidos']->isEmpty())
                    @if ($grupo['clave'] === 'facturados')
                        {{-- Lo ya facturado va cerrado al final: se abre si hace falta. Con el título en
                             flex el navegador no dibuja el triangulito: «Ver» / «Ocultar», como en las
                             filas, dice que se abre (sin group-open:, que no se usa en la app). --}}
                        <details class="mt-6" x-data="{ abierto: false }" @toggle="abierto = $el.open">
                            <summary class="flex min-h-11 cursor-pointer items-center gap-2">
                                <h2 class="{{ $titulo }}">{{ $grupo['titulo'] }}</h2>
                                <span class="text-sm text-gray-500">· {{ $grupo['pedidos']->count() }}</span>
                                <span class="text-sm text-indigo-600 underline" x-text="abierto ? 'Ocultar' : 'Ver'">Ver</span>
                            </summary>
                            <ul class="mt-3 space-y-3">
                                @foreach ($grupo['pedidos'] as $pedido)
                                    @include('facturacion.por-facturar._tarjeta', $fila($pedido))
                                @endforeach
                            </ul>
                        </details>
                    @else
                        <section aria-labelledby="grupo-{{ $grupo['clave'] }}" class="mt-6">
                            <h2 id="grupo-{{ $grupo['clave'] }}" class="{{ $titulo }}">{{ $grupo['titulo'] }} <span class="text-sm font-normal text-gray-500">· {{ $grupo['pedidos']->count() }}</span></h2>
                            @if ($grupo['clave'] === 'esperando_resto')
                                <p class="mt-1 text-sm text-gray-600">Ya se facturó lo que llegó y falta que pedidos mande el resto. Cuando llegue, sale arriba para facturar.</p>
                            @endif
                            <ul class="mt-3 space-y-3">
                                @foreach ($grupo['pedidos'] as $pedido)
                                    @include('facturacion.por-facturar._tarjeta', $fila($pedido))
                                @endforeach
                            </ul>
                        </section>
                    @endif
                @endforeach
            @endif
        </div>
    </div>
</x-app-layout>
