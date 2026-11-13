<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Rutas</h1>
        </div>
    </x-slot>

    @php
        $R = \App\Services\Rutas\RitmoRutas::class;
        $S = \App\Services\Rutas\Ritmo::class;

        // Colores de la guía: rojo atrasada, ámbar le toca, verde al día, gris sin
        // ritmo, celeste en camino. Clases completas para que Tailwind las vea.
        $insignias = [
            $R::ATRASADA => ['Atrasada', 'bg-red-100 text-red-700'],
            $R::PRONTO => ['Le toca', 'bg-amber-100 text-amber-800'],
            $R::AL_DIA => ['Al día', 'bg-green-100 text-green-700'],
            $R::EN_RUTA => ['Viaje abierto', 'bg-sky-100 text-sky-700'],
            $R::SIN_RITMO => ['Sin ritmo', 'bg-gray-100 text-gray-600'],
            $R::SIN_SALIDAS => ['Sin viajes', 'bg-gray-100 text-gray-600'],
        ];
        $insignia = 'inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-medium';
        $caja = 'rounded-lg border border-gray-200 bg-white';
        $titulo = 'text-xs font-semibold uppercase tracking-wide text-gray-500';
        $secundario = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
        $campo = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';

        $dias = fn (int $n) => $n.' '.($n === 1 ? 'día' : 'días');
        // «hoy», «ayer» o «hace N días».
        $hace = fn (int $n) => match ($n) { 0 => 'hoy', 1 => 'ayer', default => 'hace '.$n.' días' };
        $ritmoTexto = fn (int $n, ?string $origen) => 'cada '.$dias($n).match ($origen) { $S::APROXIMADO => ' (aprox.)', $R::MANUAL => ' (cargado a mano)', default => '' };
        // La insignia de una ruta o sala: la atrasada dice por cuántos días.
        $estadoTexto = fn (string $estado, ?int $faltan) => $estado === $R::ATRASADA && $faltan !== null && $faltan < 0
            ? 'Atrasada '.$dias(abs($faltan))
            : $insignias[$estado][0];
        $plata = fn (float $monto) => '$'.number_format($monto, 2);
        $desdeHoy = fn ($fecha) => max(0, (int) $fecha->diffInDays(today()));
    @endphp

    <div class="py-6 sm:py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">

            @include('rutas._pestanas', ['activa' => 'resumen'])

            <x-rutas.avisos />
            @include('rutas._sin-albaran')


            {{-- ===================== Resumen: tocar un número filtra la lista ===================== --}}
            <section aria-labelledby="resumen-titulo">
                <h2 id="resumen-titulo" class="sr-only">Resumen</h2>
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    @foreach ([
                        ['n' => $resumen['rutasAtrasadas'], 'texto' => $resumen['rutasAtrasadas'] === 1 ? 'zona atrasada' : 'zonas atrasadas', 'detalle' => $resumen['rutasAlDia'].' al día de '.$resumen['rutas'], 'color' => 'text-red-700', 'url' => route('rutas.dashboard', ['rutas' => 'atrasadas']).'#rutas'],
                        ['n' => $resumen['salasAtrasadas'], 'texto' => $resumen['salasAtrasadas'] === 1 ? 'sala atrasada' : 'salas atrasadas', 'detalle' => $resumen['salasAlDia'].' al día de '.$resumen['salas'], 'color' => 'text-red-700', 'url' => route('rutas.dashboard', ['ver' => 'atrasadas']).'#salas'],
                        ['n' => $resumen['salasSemana'], 'texto' => $resumen['salasSemana'] === 1 ? 'sala toca esta semana' : 'salas tocan esta semana', 'detalle' => null, 'color' => 'text-amber-800', 'url' => route('rutas.dashboard', ['ver' => 'semana']).'#salas'],
                        ['n' => $resumen['enCamino'], 'texto' => $resumen['enCamino'] === 1 ? 'viaje abierto' : 'viajes abiertos', 'detalle' => null, 'color' => 'text-sky-700', 'url' => '#en-camino'],
                    ] as $tarjeta)
                        <a href="{{ $tarjeta['url'] }}" class="{{ $caja }} block p-4 hover:bg-gray-50">
                            <span class="block text-3xl font-semibold tabular-nums {{ $tarjeta['n'] > 0 ? $tarjeta['color'] : 'text-gray-400' }}">{{ $tarjeta['n'] }}</span>
                            <span class="mt-1 block text-sm text-gray-600">{{ $tarjeta['texto'] }}</span>
                            @if ($tarjeta['detalle'])
                                <span class="mt-0.5 block text-xs text-gray-500">{{ $tarjeta['detalle'] }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
                {{-- El aviso de «Por entregar» a la segunda vez: el jefe lo ve acá y las mira en Viajes. --}}
                @if ($fallas > 0)
                    <p class="mt-3 text-sm">
                        <a href="{{ route('rutas.viajes') }}#fallas-titulo" class="inline-flex min-h-11 items-center font-medium text-red-700 underline">{{ $fallas === 1 ? '1 factura no se pudo entregar '.\App\Services\Rutas\EntregasCcf::FALLAS_AVISO.' veces o más' : $fallas.' facturas no se pudieron entregar '.\App\Services\Rutas\EntregasCcf::FALLAS_AVISO.' veces o más' }}</a>
                        <span class="text-gray-600">· Siguen por entregar: mirá cuáles en Viajes.</span>
                    </p>
                @endif
                @if ($facturado !== [])
                    <p class="mt-3 text-sm text-gray-600">
                        Facturado en los últimos 30 días: <span class="font-semibold tabular-nums text-gray-800">{{ $plata(array_sum($facturado)) }}</span>
                        <span class="text-gray-500">en CCF, sin IVA. Cada zona dice el suyo.</span>
                    </p>
                @endif
            </section>

            {{-- ===================== Viajes abiertos =====================
                 Los viajes (y las salidas de antes) en curso, igual que el número del resumen. --}}
            @if ($enCamino->isNotEmpty())
                <section id="en-camino" aria-labelledby="en-camino-titulo">
                    <h2 id="en-camino-titulo" class="{{ $titulo }}">Viajes abiertos</h2>
                    <ul class="mt-3 space-y-3">
                        @foreach ($enCamino as ['salida' => $salida, 'resumen' => $avance])
                            <li class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-sky-200 bg-sky-50 p-4">
                                <div class="min-w-0">
                                    <p class="font-semibold text-sky-900">
                                        {{ $salida->titulo() }} · {{ $salida->quienesVan() ?: 'Sin vendedores' }}
                                    </p>
                                    <p class="text-sm text-sky-800">
                                        {{ $salida->estadoLegible() }} {{ $salida->fecha_inicio->isToday() ? 'desde hoy' : 'desde el '.$salida->fecha_inicio->format('d/m/Y') }}
                                        · {{ $avance['total'] === 0 ? 'todavía sin facturas' : $avance['entregados'].' de '.$avance['total'].' entregados' }}
                                        @if ($avance['no_entregados'] > 0) · {{ $avance['no_entregados'] }} no entregado{{ $avance['no_entregados'] === 1 ? '' : 's' }} @endif
                                    </p>
                                </div>
                                <a href="{{ route('rutas.salidas.show', $salida) }}" class="{{ $secundario }}">{{ $salida->esViaje() ? 'Ver viaje' : 'Ver salida' }}</a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- ===================== Rutas, las más urgentes primero ===================== --}}
            <section id="rutas" aria-labelledby="rutas-titulo">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="rutas-titulo" class="{{ $titulo }}">{{ $soloRutasAtrasadas ? 'Zonas atrasadas' : 'Zonas' }}</h2>
                    @if ($soloRutasAtrasadas)
                        <a href="{{ route('rutas.dashboard') }}#rutas" class="inline-flex min-h-11 items-center text-sm text-indigo-600 hover:underline">Ver todas las zonas</a>
                    @endif
                </div>

                @if ($filas->isEmpty())
                    <div class="{{ $caja }} mt-3 px-6 py-10 text-center text-gray-500">
                        @if ($soloRutasAtrasadas)
                            <p>Ninguna zona está atrasada.</p>
                        @else
                            <p class="font-medium text-gray-800">Todavía no hay zonas.</p>
                            @can('rutas.gestionar')
                                <a href="{{ route('rutas.rutas.index') }}" class="mt-2 inline-flex min-h-11 items-center text-sm text-indigo-600 hover:underline">Crear la primera</a>
                            @endcan
                        @endif
                    </div>
                @else
                    <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($filas as $fila)
                            @php
                                $ruta = $fila['ruta'];
                                $estadoClase = $insignias[$fila['estado']][1];
                                $pend = $pendientes[$ruta->id] ?? ['cantidad' => 0, 'monto' => 0];
                                ['atrasadas' => $atrasadas, 'semana' => $tocan] = $conteoRuta->get($ruta->id, ['atrasadas' => 0, 'semana' => 0]);
                                $faltan = $fila['faltan'];
                            @endphp
                            <article class="{{ $caja }} flex flex-col p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <h3 class="text-base font-semibold text-gray-800">{{ $ruta->nombre }}</h3>
                                    <span class="{{ $insignia }} {{ $estadoClase }}">{{ $estadoTexto($fila['estado'], $faltan) }}</span>
                                </div>

                                <div class="mt-2 space-y-0.5 text-sm text-gray-700">
                                    <p>
                                        @if ($fila['enCurso'])
                                            Viaje abierto desde {{ $fila['enCurso']->fecha_inicio->isToday() ? 'hoy' : 'el '.$fila['enCurso']->fecha_inicio->format('d/m/Y') }}
                                        @elseif ($fila['dias'] === null)
                                            Nunca se ha ido
                                        @elseif ($fila['desde'] === $R::DESDE_SALIDA)
                                            {{ $fila['dias'] === 0 ? 'Volvió hoy' : 'Se fue '.$hace($fila['dias']) }}
                                        @else
                                            Última visita a sus salas {{ $hace($fila['dias']) }}
                                        @endif
                                        {{-- La atrasada ya dice sus días en la insignia. --}}
                                        @if ($fila['estado'] === $R::PRONTO && $faltan !== null)
                                            · <span class="font-medium">{{ $faltan < 0 ? $dias(abs($faltan)).' tarde' : ($faltan === 0 ? 'toca hoy' : 'faltan '.$dias($faltan)) }}</span>
                                        @endif
                                    </p>
                                    <p class="text-gray-500">
                                        {{ $fila['ritmo'] ? 'Suele ir '.$ritmoTexto($fila['ritmo'], $fila['origenRitmo']) : 'Todavía sin ritmo' }}
                                    </p>
                                    <p>
                                        @if ($atrasadas + $tocan > 0)
                                            <span class="{{ $atrasadas > 0 ? 'font-medium text-red-700' : '' }}">{{ $atrasadas }} {{ $atrasadas === 1 ? 'sala atrasada' : 'salas atrasadas' }}</span>
                                            · {{ $tocan }} {{ $tocan === 1 ? 'toca' : 'tocan' }} esta semana
                                        @else
                                            Ninguna sala atrasada
                                        @endif
                                    </p>
                                    <p>Facturado en 30 días: <span class="tabular-nums">{{ $plata($facturado[$ruta->id] ?? 0) }}</span></p>
                                    <p class="text-xs text-gray-500">
                                        {{ $pend['cantidad'] > 0 ? $pend['cantidad'].' CCF por entregar · $'.number_format($pend['monto'], 2) : 'Sin CCF por entregar' }}
                                        · {{ $fila['salas']->count() }} {{ $fila['salas']->count() === 1 ? 'sala' : 'salas' }}
                                    </p>
                                </div>

                                <div class="mt-auto flex flex-wrap gap-2 pt-4">
                                    <a href="{{ route('rutas.dashboard', ['ruta' => $ruta->id]) }}#salas" class="{{ $secundario }}">Ver salas</a>
                                    @if ($fila['enCurso'])
                                        <a href="{{ route('rutas.salidas.show', $fila['enCurso']) }}" class="{{ $secundario }}">{{ $fila['enCurso']->esViaje() ? 'Ver viaje' : 'Ver salida' }}</a>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            {{-- ===================== Salas que tocan ===================== --}}
            <section id="salas" aria-labelledby="salas-titulo">
                <h2 id="salas-titulo" class="{{ $titulo }}">Salas que tocan</h2>

                <form method="GET" action="{{ route('rutas.dashboard') }}#salas" class="mt-3 flex flex-wrap items-end gap-3">
                    <input type="hidden" name="ver" value="{{ $filtro['ver'] }}">
                    <div class="min-w-0 flex-1">
                        <label for="salas-q" class="block text-xs font-medium text-gray-500">Buscar sala</label>
                        <input id="salas-q" name="q" type="search" value="{{ $filtro['q'] }}" maxlength="100" placeholder="Nombre o código" class="{{ $campo }} mt-1">
                    </div>
                    <div class="w-full sm:w-56">
                        <label for="salas-ruta" class="block text-xs font-medium text-gray-500">Zona</label>
                        <select id="salas-ruta" name="ruta" class="{{ $campo }} mt-1">
                            <option value="">Todas</option>
                            @foreach ($salasDeRuta->keys() as $id)
                                @php $nombre = $salasDeRuta[$id]->first()['ruta']->nombre; @endphp
                                <option value="{{ $id }}" @selected($filtro['ruta'] === $id)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="{{ $secundario }}">Filtrar</button>
                    @if ($filtro['ruta'] !== null || $filtro['q'] !== '')
                        <a href="{{ route('rutas.dashboard', ['ver' => $filtro['ver'] === 'todas' ? null : $filtro['ver']]) }}#salas" class="inline-flex min-h-11 items-center text-sm text-indigo-600 hover:underline">Limpiar</a>
                    @endif
                </form>

                <nav aria-label="Qué salas mostrar" class="mt-3 flex flex-wrap gap-2">
                    @foreach (['atrasadas' => 'Atrasadas', 'semana' => 'Tocan esta semana', 'todas' => 'Todas'] as $clave => $texto)
                        <a href="{{ request()->fullUrlWithQuery(['ver' => $clave === 'todas' ? null : $clave, 'page' => null]) }}#salas" @if ($filtro['ver'] === $clave) aria-current="page" @endif
                           class="inline-flex min-h-11 shrink-0 items-center rounded-full px-4 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 {{ $filtro['ver'] === $clave ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50' }}">{{ $texto }} {{ $conteoVer[$clave] }}</a>
                    @endforeach
                </nav>

                <div class="{{ $caja }} mt-3">
                    @if ($salas->isEmpty())
                        <div class="px-4 py-10 text-center text-gray-500">
                            @if ($hayFiltro)
                                <p>Nada coincide con estos filtros.</p>
                                <a href="{{ route('rutas.dashboard') }}#salas" class="mt-2 inline-flex min-h-11 items-center text-sm text-indigo-600 hover:underline">Limpiar filtros</a>
                            @else
                                <p>Todavía no hay salas activas.</p>
                            @endif
                        </div>
                    @else
                        <ul class="divide-y divide-gray-100">
                            @foreach ($salas as $fila)
                                @php
                                    $sala = $fila['sala'];
                                    $estadoClase = $insignias[$fila['estado']][1];
                                    $entrega = $fila['ultimaEntrega'];
                                    $pedido = $fila['ultimoPedido'];
                                @endphp
                                <li class="px-4 py-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="font-medium text-gray-800">{{ $sala->nombre }}</p>
                                            <p class="text-sm text-gray-500">{{ $fila['ruta']?->nombre ?? 'Sin zona' }}</p>
                                        </div>
                                        <span class="{{ $insignia }} {{ $estadoClase }}">{{ $estadoTexto($fila['estado'], $fila['faltan']) }}</span>
                                    </div>
                                    <dl class="mt-2 grid grid-cols-1 gap-1 text-sm text-gray-600 sm:grid-cols-2 lg:grid-cols-4">
                                        {{-- El último CCF facturado o la última entrega marcada en Rutas, la más nueva. --}}
                                        <div>
                                            <dt class="sr-only">Última entrega</dt>
                                            <dd>
                                                @if ($entrega)
                                                    Última entrega <time datetime="{{ $entrega->toDateString() }}" title="{{ $entrega->format('d/m/Y') }}">{{ $hace($desdeHoy($entrega)) }}</time>
                                                @else
                                                    Sin entregas en 12 meses
                                                @endif
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="sr-only">Ritmo</dt>
                                            <dd class="{{ $fila['ritmo']->confianza() === $S::APROXIMADO ? 'text-gray-500' : '' }}">
                                                {{ $fila['ritmo']->dias !== null ? 'Compra '.$ritmoTexto($fila['ritmo']->dias, $fila['ritmo']->confianza()) : 'Todavía sin ritmo' }}
                                            </dd>
                                        </div>
                                        @if ($pedido)
                                            <div>
                                                <dt class="sr-only">Último pedido sacado</dt>
                                                <dd>Pedido sacado <time datetime="{{ $pedido->toDateString() }}" title="{{ $pedido->format('d/m/Y') }}">{{ $hace($desdeHoy($pedido)) }}</time></dd>
                                            </div>
                                        @endif
                                        @if ($fila['proxima'])
                                            <div>
                                                <dt class="sr-only">Próxima visita</dt>
                                                <dd class="{{ $fila['estado'] === $S::ATRASADA ? 'font-medium text-red-700' : '' }}">Próxima visita <time datetime="{{ $fila['proxima']->toDateString() }}">{{ $fila['proxima']->format('d/m/Y') }}</time></dd>
                                            </div>
                                        @endif
                                    </dl>
                                    @if ($fila['dejoDeComprar'])
                                        <p class="mt-1 text-sm font-medium text-red-700">¿Dejó de comprar?</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if ($salas->hasPages())
                            <div class="border-t border-gray-100 px-4 py-3">{{ $salas->links() }}</div>
                        @endif
                    @endif
                </div>
            </section>

            {{-- ===================== Vendedores ===================== --}}
            <section id="vendedores" aria-labelledby="vendedores-titulo">
                <h2 id="vendedores-titulo" class="{{ $titulo }}">Vendedores</h2>
                @if ($tarjetasVendedores->isEmpty())
                    <div class="{{ $caja }} mt-3 px-4 py-10 text-center text-gray-500">
                        <p>Todavía no hay vendedores activos.</p>
                    </div>
                @else
                    <ul class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($tarjetasVendedores as $tarjeta)
                            @php
                                $persona = $tarjeta['persona'];
                                $telefono = $persona->telefonoMarcable();
                            @endphp
                            <li class="{{ $caja }} flex flex-col p-4">
                                <h3 class="text-base font-semibold text-gray-800">{{ $persona->nombre }}</h3>
                                <div class="mt-2 space-y-0.5 text-sm text-gray-700">
                                    <p>
                                        @if ($telefono !== '')
                                            <a href="tel:{{ $telefono }}" class="inline-flex min-h-11 items-center text-indigo-600 hover:underline">Tel. {{ $persona->telefono }}</a>
                                        @elseif ($tarjeta['ficha'])
                                            <a href="{{ $tarjeta['ficha'] }}" class="inline-flex min-h-11 items-center text-gray-500 hover:underline">Sin teléfono</a>
                                        @else
                                            <span class="text-gray-500">Sin teléfono</span>
                                        @endif
                                    </p>
                                    <p>
                                        @if ($tarjeta['ahora'])
                                            Ahora: {{ $tarjeta['ahora']->titulo() }}, desde {{ $tarjeta['ahora']->fecha_inicio->isToday() ? 'hoy' : 'el '.$tarjeta['ahora']->fecha_inicio->format('d/m/Y') }}
                                        @elseif ($tarjeta['ultima'])
                                            Última: {{ $tarjeta['ultima']->titulo() }}, {{ ($tarjeta['ultima']->fecha_fin_real ?? $tarjeta['ultima']->fecha_inicio)->format('d/m/Y') }}
                                        @else
                                            <span class="text-gray-500">Sin viajes en los últimos 90 días</span>
                                        @endif
                                    </p>
                                    @if ($tarjeta['ultimaEntrega'])
                                        <p>Última entrega <time datetime="{{ $tarjeta['ultimaEntrega']->toDateString() }}" title="{{ $tarjeta['ultimaEntrega']->format('d/m/Y') }}">{{ $hace($desdeHoy($tarjeta['ultimaEntrega'])) }}</time></p>
                                    @endif
                                    @if ($tarjeta['rutas'] !== [])
                                        <p class="text-gray-500">Sus zonas: {{ implode(', ', $tarjeta['rutas']) }}</p>
                                    @endif
                                </div>
                                @if ($tarjeta['ficha'])
                                    <div class="mt-auto pt-3">
                                        <a href="{{ $tarjeta['ficha'] }}" class="{{ $secundario }}">Ver ficha</a>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Sin zona: ninguna cubre su lugar. Sale en la lista y se le saca pedido igual. --}}
            @if ($salasSinZona > 0)
                <a href="{{ route('rutas.rutas.index') }}"
                   class="flex min-h-11 flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 hover:bg-amber-100">
                    <span>
                        <strong>{{ $salasSinZona }} {{ $salasSinZona === 1 ? 'sala activa' : 'salas activas' }} sin zona.</strong> Ninguna zona cubre su distrito ni su departamento.
                        {{-- Sus CCF no son de ninguna tarjeta de zona: se cuentan acá (clave 0). --}}
                        @if (($pendientes[0]['cantidad'] ?? 0) > 0)
                            {{ $pendientes[0]['cantidad'] }} CCF por entregar · ${{ number_format($pendientes[0]['monto'], 2) }}.
                        @endif
                    </span>
                    <span class="shrink-0 font-medium">Ver zonas</span>
                </a>
            @endif

        </div>
    </div>
</x-app-layout>
