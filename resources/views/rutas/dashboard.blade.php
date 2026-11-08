<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Rutas</h1>
            <div class="flex flex-wrap items-center gap-3">
                @if ($vaEnUnaSalida)
                    <a href="{{ route('rutas.mi-salida.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Mi salida</a>
                @endif
                @canany(['rutas.gestionar', 'rutas.salidas.armar'])
                    <a href="{{ route('rutas.salidas.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Armar salida</a>
                @endcanany
            </div>
        </div>
    </x-slot>

    @php
        $R = \App\Services\Rutas\RitmoRutas::class;
        $S = \App\Services\Rutas\Ritmo::class;

        // Colores de la guía: rojo atrasada, ámbar toca ir, verde al día, gris sin
        // ritmo, celeste en camino. Clases completas para que Tailwind las vea.
        $insignias = [
            $R::ATRASADA => ['Atrasada', 'bg-red-100 text-red-700'],
            $R::PRONTO => ['Toca ir', 'bg-amber-100 text-amber-800'],
            $R::AL_DIA => ['Al día', 'bg-green-100 text-green-700'],
            $R::EN_RUTA => ['En camino', 'bg-sky-100 text-sky-700'],
            $R::PROGRAMADA => ['Programada', 'bg-indigo-100 text-indigo-700'],
            $R::SIN_RITMO => ['Sin ritmo', 'bg-gray-100 text-gray-600'],
            $R::SIN_SALIDAS => ['Sin salidas', 'bg-gray-100 text-gray-600'],
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

            <x-rutas.avisos />

            {{-- Las salidas de «Por entregar» se finalizan solas: el efectivo espera «Terminar ruta». --}}
            @if ($porLiquidar > 0)
                <a href="{{ route('rutas.salidas.index', ['por_liquidar' => 1]) }}" class="{{ $caja }} flex min-h-11 items-center px-4 py-3 text-sm font-medium text-amber-800 hover:bg-gray-50 dark:text-amber-300">
                    {{ $porLiquidar === 1 ? '1 salida por liquidar' : $porLiquidar.' salidas por liquidar' }} · Ver
                </a>
            @endif

            {{-- ===================== Resumen: tocar un número filtra la lista ===================== --}}
            <section aria-labelledby="resumen-titulo">
                <h2 id="resumen-titulo" class="sr-only">Resumen</h2>
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    @foreach ([
                        ['n' => $resumen['rutasAtrasadas'], 'texto' => $resumen['rutasAtrasadas'] === 1 ? 'ruta atrasada' : 'rutas atrasadas', 'detalle' => $resumen['rutasAlDia'].' al día de '.$resumen['rutas'], 'color' => 'text-red-700', 'url' => route('rutas.dashboard', ['rutas' => 'atrasadas']).'#rutas'],
                        ['n' => $resumen['salasAtrasadas'], 'texto' => $resumen['salasAtrasadas'] === 1 ? 'sala atrasada' : 'salas atrasadas', 'detalle' => $resumen['salasAlDia'].' al día de '.$resumen['salas'], 'color' => 'text-red-700', 'url' => route('rutas.dashboard', ['ver' => 'atrasadas']).'#salas'],
                        ['n' => $resumen['salasSemana'], 'texto' => $resumen['salasSemana'] === 1 ? 'sala toca esta semana' : 'salas tocan esta semana', 'detalle' => null, 'color' => 'text-amber-800', 'url' => route('rutas.dashboard', ['ver' => 'semana']).'#salas'],
                        ['n' => $resumen['enCamino'], 'texto' => $resumen['enCamino'] === 1 ? 'salida en camino' : 'salidas en camino', 'detalle' => null, 'color' => 'text-sky-700', 'url' => '#en-camino'],
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
                @if ($facturado !== [])
                    <p class="mt-3 text-sm text-gray-600">
                        Facturado en los últimos 30 días: <span class="font-semibold tabular-nums text-gray-800">{{ $plata(array_sum($facturado)) }}</span>
                        <span class="text-gray-500">en CCF, sin IVA. Cada ruta dice el suyo.</span>
                    </p>
                @endif
            </section>

            {{-- ===================== En camino y programadas =====================
                 «En camino» son solo las en curso, igual que el número del resumen. Las armadas
                 para otro día (o para hoy y todavía sin empezar) van aparte, en «Programadas». --}}
            @foreach ([
                ['id' => 'en-camino', 'titulo' => 'En camino', 'lista' => $enCamino],
                ['id' => 'programadas', 'titulo' => 'Programadas', 'lista' => $programadas],
            ] as $grupo)
                @continue($grupo['lista']->isEmpty())
                <section id="{{ $grupo['id'] }}" aria-labelledby="{{ $grupo['id'] }}-titulo">
                    <h2 id="{{ $grupo['id'] }}-titulo" class="{{ $titulo }}">{{ $grupo['titulo'] }}</h2>
                    <ul class="mt-3 space-y-3">
                        @foreach ($grupo['lista'] as ['salida' => $salida, 'resumen' => $avance])
                            @php $enCurso = $salida->estado === \App\Enums\EstadoSalidaRuta::EnCurso; @endphp
                            <li class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4 {{ $enCurso ? 'border-sky-200 bg-sky-50' : 'border-gray-200 bg-white' }}">
                                <div class="min-w-0">
                                    <p class="font-semibold {{ $enCurso ? 'text-sky-900' : 'text-gray-800' }}">
                                        {{ $salida->ruta->nombre }} · {{ $salida->quienesVan() ?: 'Sin vendedores' }}
                                    </p>
                                    <p class="text-sm {{ $enCurso ? 'text-sky-800' : 'text-gray-600' }}">
                                        @if ($enCurso)
                                            En camino {{ $salida->fecha_inicio->isToday() ? 'desde hoy' : 'desde el '.$salida->fecha_inicio->format('d/m/Y') }}
                                            · {{ $avance['entregados'] }} de {{ $avance['total'] }} entregados
                                            @if ($avance['no_entregados'] > 0) · {{ $avance['no_entregados'] }} no entregado{{ $avance['no_entregados'] === 1 ? '' : 's' }} @endif
                                        @else
                                            Sale {{ $salida->fecha_inicio->isToday() ? 'hoy' : 'el '.$salida->fecha_inicio->format('d/m/Y') }}
                                            · lleva {{ $avance['total'] }} CCF
                                        @endif
                                    </p>
                                </div>
                                <a href="{{ route('rutas.salidas.show', $salida) }}" class="{{ $secundario }}">Abrir hoja</a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach

            {{-- ===================== Rutas, las más urgentes primero ===================== --}}
            <section id="rutas" aria-labelledby="rutas-titulo">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="rutas-titulo" class="{{ $titulo }}">{{ $soloRutasAtrasadas ? 'Rutas atrasadas' : 'Rutas' }}</h2>
                    @if ($soloRutasAtrasadas)
                        <a href="{{ route('rutas.dashboard') }}#rutas" class="inline-flex min-h-11 items-center text-sm text-indigo-600 hover:underline">Ver todas las rutas</a>
                    @endif
                </div>

                @if ($filas->isEmpty())
                    <div class="{{ $caja }} mt-3 px-6 py-10 text-center text-gray-500">
                        @if ($soloRutasAtrasadas)
                            <p>Ninguna ruta está atrasada.</p>
                        @else
                            <p class="font-medium text-gray-800">Todavía no hay rutas.</p>
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
                                    {{-- Con la salida ya armada no se alarma con el atraso: se dice para cuándo. --}}
                                    @if (! $fila['enCurso'] && $fila['proxima'])
                                        <p class="font-medium text-indigo-700">
                                            Ya tiene salida armada para {{ $fila['proxima']->fecha_inicio->isToday() ? 'hoy' : 'el '.$fila['proxima']->fecha_inicio->format('d/m/Y') }}
                                        </p>
                                    @endif
                                    <p>
                                        @if ($fila['enCurso'])
                                            En camino desde {{ $fila['enCurso']->fecha_inicio->isToday() ? 'hoy' : 'el '.$fila['enCurso']->fecha_inicio->format('d/m/Y') }}
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
                                        · {{ $ruta->sucursales_count }} {{ $ruta->sucursales_count === 1 ? 'sala' : 'salas' }}
                                    </p>
                                </div>

                                <div class="mt-auto flex flex-wrap gap-2 pt-4">
                                    <a href="{{ route('rutas.dashboard', ['ruta' => $ruta->id]) }}#salas" class="{{ $secundario }}">Ver salas</a>
                                    @if ($fila['enCurso'] ?? $fila['proxima'])
                                        <a href="{{ route('rutas.salidas.show', $fila['enCurso'] ?? $fila['proxima']) }}" class="{{ $secundario }}">Abrir hoja</a>
                                    @endif
                                </div>

                                {{-- Salir a esta ruta: se eligen quiénes van y listo. También el jefe de vendedores. --}}
                                @canany(['rutas.gestionar', 'rutas.salidas.armar'])
                                    @if (! $fila['enCurso'])
                                        <div class="pt-2">
                                            @if ($vendedores->isEmpty())
                                                @if (auth()->user()->can('planilla.ver') && auth()->user()->can('rutas.personal.ver'))
                                                    <a href="{{ route('rutas.personal.index') }}" class="inline-flex min-h-11 items-center text-sm text-indigo-600 hover:underline">Agregá un vendedor para salir</a>
                                                @else
                                                    <p class="inline-flex min-h-11 items-center text-sm text-gray-500">No hay vendedores activos: pedile a la administración que agregue uno.</p>
                                                @endif
                                            @else
                                                <details class="group">
                                                    <summary class="flex min-h-11 cursor-pointer list-none items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">
                                                        Salir a esta ruta
                                                    </summary>
                                                    <form method="POST" action="{{ route('rutas.salidas.store') }}" class="mt-2 space-y-3 rounded-md border border-gray-200 p-3">
                                                        @csrf
                                                        <input type="hidden" name="ruta_id" value="{{ $ruta->id }}">
                                                        <fieldset>
                                                            <legend class="text-xs font-medium text-gray-500">¿Quiénes van?</legend>
                                                            <div class="mt-2 flex flex-wrap gap-2">
                                                                @foreach ($vendedores as $v)
                                                                    <label class="cursor-pointer">
                                                                        <input type="checkbox" name="personal[]" value="{{ $v->id }}" class="peer sr-only" @checked($vendedores->count() === 1)>
                                                                        <span class="inline-flex min-h-11 items-center rounded-full px-4 text-sm ring-1 ring-gray-300 peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:ring-indigo-600 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-indigo-600">{{ $v->nombre }}</span>
                                                                    </label>
                                                                @endforeach
                                                            </div>
                                                        </fieldset>
                                                        <button class="inline-flex min-h-11 w-full items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">
                                                            Salir hoy{{ $pend['cantidad'] > 0 ? ' con '.$pend['cantidad'].' CCF' : '' }}
                                                        </button>
                                                    </form>
                                                </details>
                                            @endif
                                        </div>
                                    @endif
                                @endcanany
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
                        <label for="salas-ruta" class="block text-xs font-medium text-gray-500">Ruta</label>
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
                                <p>Todavía no hay salas en las rutas.</p>
                                @can('rutas.gestionar')
                                    <a href="{{ route('rutas.asignacion.index') }}" class="mt-2 inline-flex min-h-11 items-center text-sm text-indigo-600 hover:underline">Asignar salas</a>
                                @endcan
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
                                            <p class="text-sm text-gray-500">{{ $fila['ruta']->nombre }}</p>
                                        </div>
                                        <span class="{{ $insignia }} {{ $estadoClase }}">{{ $estadoTexto($fila['estado'], $fila['faltan']) }}</span>
                                    </div>
                                    <dl class="mt-2 grid grid-cols-1 gap-1 text-sm text-gray-600 sm:grid-cols-3">
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
                                            Ahora: {{ $tarjeta['ahora']->ruta->nombre }}, desde {{ $tarjeta['ahora']->fecha_inicio->isToday() ? 'hoy' : 'el '.$tarjeta['ahora']->fecha_inicio->format('d/m/Y') }}
                                        @elseif ($tarjeta['ultima'])
                                            Última: {{ $tarjeta['ultima']->ruta->nombre }}, {{ ($tarjeta['ultima']->fecha_fin_real ?? $tarjeta['ultima']->fecha_inicio)->format('d/m/Y') }}
                                        @else
                                            <span class="text-gray-500">Sin salidas en los últimos 90 días</span>
                                        @endif
                                    </p>
                                    @if ($tarjeta['ultimaEntrega'])
                                        <p>Última entrega <time datetime="{{ $tarjeta['ultimaEntrega']->toDateString() }}" title="{{ $tarjeta['ultimaEntrega']->format('d/m/Y') }}">{{ $hace($desdeHoy($tarjeta['ultimaEntrega'])) }}</time></p>
                                    @endif
                                    @if ($tarjeta['rutas'] !== [])
                                        <p class="text-gray-500">Sus rutas: {{ implode(', ', $tarjeta['rutas']) }}</p>
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

            @if ($salasSinRuta > 0)
                <a href="{{ route('rutas.rutas.index') }}"
                   class="flex min-h-11 flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 hover:bg-amber-100">
                    <span><strong>{{ $salasSinRuta }} {{ $salasSinRuta === 1 ? 'sala activa' : 'salas activas' }} sin ruta.</strong> No cuentan en ninguna ruta hasta asignarlas.</span>
                    <span class="shrink-0 font-medium">Asignar</span>
                </a>
            @endif

        </div>
    </div>
</x-app-layout>
