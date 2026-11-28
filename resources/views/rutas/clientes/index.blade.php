{{-- «Clientes» de Rutas (Melqui, 28/11/2026): todas las salas activas para todos los que entran,
     sin filtrar por la zona del vendedor (no la registran al salir). Cada sala, una tarjeta: lo
     último que le sacaron (fecha, quién y cuántas cajas de cada producto), su último CCF (número,
     fecha y si se entregó) y cuántos tiene por cobrar. SIN MONTOS: solo cantidades. Tocar la sala
     abre su historia, con el PDF de cada CCF. Buscador por nombre o código y zona opcional
     (docs/GUIA_PANTALLAS.md: formulario GET, botón secundario y «Limpiar» solo con filtros).
     Tarjetas también en la computadora: es una pantalla del celular. La otra pestaña, «Todos
     los pedidos», es la lista de siempre. Variables: $salas (paginador de [sala, zona]),
     $resumen (PanelClientes::resumen), $zonas, $filtro y $filtrado.

     El marco depende de quién entra: la oficina (`rutas.ver`) lo ve con la barra lateral
     (rutas.clientes.marco-oficina) y el vendedor, que tiene solo `rutas.pedidos.ver`
     (28/11/2026), en su layout del teléfono, con «Clientes» marcado en su menú y el
     encabezado arriba del contenido. --}}
@extends(auth()->user()->can('rutas.ver') ? 'rutas.clientes.marco-oficina' : 'layouts.vendedor')

@section('titulo', 'Clientes')

@section('encabezado')
    <h1 class="text-xl font-semibold leading-tight text-gray-800">Clientes</h1>
@endsection

@section('content')
    @cannot('rutas.ver')
        <div class="mb-4">@yield('encabezado')</div>
    @endcannot

    @php
        $campo = 'mt-1 min-h-11 w-full rounded-md border-gray-300 text-sm';
        $etiqueta = 'block text-xs font-medium text-gray-500';
        $secundario = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
        $dt = 'text-xs font-medium text-gray-500';
    @endphp

    <div>
            @include('rutas.clientes._pestanas', ['activa' => 'clientes'])

            <form method="GET" class="mb-4 grid grid-cols-1 items-end gap-3 sm:grid-cols-4">
                <div class="sm:col-span-2">
                    <label for="c-buscar" class="{{ $etiqueta }}">Buscar sala</label>
                    <input id="c-buscar" type="search" name="q" value="{{ $filtro['q'] }}" maxlength="100" placeholder="Nombre o código" class="{{ $campo }}">
                </div>
                <div>
                    <label for="c-zona" class="{{ $etiqueta }}">Zona (opcional)</label>
                    <select id="c-zona" name="zona" class="{{ $campo }}">
                        <option value="">Todas</option>
                        @foreach ($zonas as $z)
                            <option value="{{ $z->id }}" @selected($filtro['zona'] === $z->id)>{{ $z->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <button class="{{ $secundario }}">Buscar</button>
                    @if ($filtrado)
                        <a href="{{ route('rutas.clientes.index') }}" class="inline-flex min-h-11 items-center text-sm text-indigo-600 underline">Limpiar</a>
                    @endif
                </div>
            </form>

            @if ($salas->isEmpty())
                <div class="rounded-lg border border-gray-200 bg-white px-4 py-10 text-center text-gray-500">
                    @if ($salas->total() > 0)
                        Esa página no tiene salas. <a href="{{ $salas->url(1) }}" class="inline-flex min-h-11 items-center text-indigo-600 underline">Ir a la primera</a>
                    @elseif ($filtrado)
                        Nada coincide con estos filtros. <a href="{{ route('rutas.clientes.index') }}" class="inline-flex min-h-11 items-center text-indigo-600 underline">Limpiar filtros</a>
                    @else
                        Todavía no hay salas activas.
                    @endif
                </div>
            @else
                <ul class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($salas as ['sala' => $sala, 'zona' => $zona])
                        @php
                            ['pedido' => $pedido, 'ccf' => $ccf, 'entrega' => $entrega, 'porCobrar' => $porCobrar] = $resumen[$sala->id];
                        @endphp
                        <li class="flex flex-col rounded-lg border border-gray-200 bg-white p-4">
                            <div class="flex items-start justify-between gap-3">
                                <h2 class="min-w-0 text-base font-semibold">
                                    <a href="{{ route('rutas.clientes.show', $sala) }}" class="text-indigo-600 hover:underline">{{ $sala->nombre }}</a>
                                </h2>
                                @if ($porCobrar > 0)
                                    <span class="inline-flex shrink-0 items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">{{ $porCobrar }} CCF por cobrar</span>
                                @endif
                            </div>
                            <p class="text-sm text-gray-500">{{ collect([$sala->codigo, $zona?->nombre ?? 'Sin zona'])->filter()->implode(' · ') }}</p>

                            <dl class="mt-3 space-y-3">
                                <div>
                                    <dt class="{{ $dt }}">Último pedido</dt>
                                    <dd class="mt-0.5">
                                        @if ($pedido)
                                            <p class="text-sm font-medium text-gray-800">{{ $pedido->fecha->format('d/m/Y') }} · {{ $pedido->sacadoPor?->nombre ?? 'Sin vendedor' }}</p>
                                            @include('rutas.clientes._lineas', ['pedido' => $pedido, 'tope' => 6])
                                        @else
                                            <p class="text-sm text-gray-500">Todavía no tiene pedidos.</p>
                                        @endif
                                    </dd>
                                </div>
                                <div>
                                    <dt class="{{ $dt }}">Último CCF</dt>
                                    <dd class="mt-0.5">
                                        @if ($ccf)
                                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                                                <span class="font-mono text-gray-800" title="{{ $ccf->numero_control }}">
                                                    <span aria-hidden="true">CCF …{{ \App\Support\OrdenCompra::ultimosDigitos($ccf->numero_control) }}</span>
                                                    <span class="sr-only">CCF {{ $ccf->numero_control }}</span>
                                                </span>
                                                <span class="text-gray-600">{{ $ccf->fecha_emision?->format('d/m/Y') }}</span>
                                                @include('rutas.clientes._entrega', ['entrega' => $entrega])
                                            </div>
                                        @else
                                            <p class="text-sm text-gray-500">Todavía no tiene CCF.</p>
                                        @endif
                                    </dd>
                                </div>
                            </dl>

                            <div class="mt-auto pt-4">
                                <a href="{{ route('rutas.clientes.show', $sala) }}" class="{{ $secundario }} w-full">Ver historial<span class="sr-only"> de {{ $sala->nombre }}</span></a>
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if ($salas->hasPages())
                    <div class="mt-4 rounded-lg border border-gray-200 bg-white px-4 py-3">{{ $salas->links() }}</div>
                @endif
            @endif
    </div>
@endsection
