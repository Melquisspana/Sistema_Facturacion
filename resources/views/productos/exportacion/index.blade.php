<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Productos</h2>
    </x-slot>

    @php
        // «IMPORTADOR NORTE LLC» → «Carolinas»: la etiqueta de precio tiene que caber.
        $corto = fn (?string $nombre) => \Illuminate\Support\Str::of((string) $nombre)->trim()->before(' ')->lower()->ucfirst();
        $dinero = fn ($valor) => '$'.number_format((float) $valor, 2);
        $puedeGestionar = auth()->user()?->can('exportaciones.gestionar') ?? false;
    @endphp

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-4 sm:p-6">

                <x-productos.selector activo="exportacion" />

                @if (session('status'))
                    <div class="mb-4 rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
                @endif
                @if (session('error'))
                    <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ session('error') }}</div>
                @endif

                <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
                    <form method="GET" class="flex flex-1 flex-wrap items-end gap-3">
                        <div class="min-w-48 flex-1">
                            <x-input-label for="q" value="Buscar" />
                            <x-text-input id="q" name="q" type="search" class="mt-1 block w-full"
                                          :value="$filtros['q']" placeholder="maní, coconut, 12x18…" />
                        </div>
                        <div>
                            <x-input-label for="activo" value="Estado" />
                            <select id="activo" name="activo" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="1" @selected($filtros['activo'] === '1')>Activos ({{ $totales['activos'] }})</option>
                                <option value="0" @selected($filtros['activo'] === '0')>Archivados ({{ $totales['inactivos'] }})</option>
                                <option value="" @selected($filtros['activo'] === '')>Todos</option>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <x-primary-button>Filtrar</x-primary-button>
                            @if ($filtros['q'] !== '' || $filtros['activo'] !== '1')
                                <a href="{{ route('productos.exportacion.index') }}" class="self-center px-2 text-sm text-gray-500 hover:underline">Limpiar</a>
                            @endif
                        </div>
                    </form>

                    @if ($puedeGestionar)
                        <a href="{{ route('productos.exportacion.create') }}"
                           class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            + Nuevo producto
                        </a>
                    @endif
                </div>

                <div class="space-y-6">
                    @foreach ($secciones as $seccion)
                        <section aria-labelledby="seccion-{{ $loop->index }}">
                            <h3 id="seccion-{{ $loop->index }}" class="mb-2 flex items-baseline gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                {{ $seccion['titulo'] }}
                                <span class="font-normal normal-case tracking-normal text-gray-400">{{ $seccion['productos']->count() }}</span>
                            </h3>

                            <div class="divide-y divide-gray-100 rounded-lg border border-gray-200">
                                @foreach ($seccion['productos'] as $grupo)
                                    @php $base = $grupo['base']; @endphp
                                    <article id="producto-{{ $base->id }}" style="scroll-margin-top: 6rem" class="p-3 sm:p-4 {{ $base->activo ? '' : 'opacity-70' }}">
                                        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                                            <div class="min-w-0">
                                                <h4 class="font-semibold text-gray-800">
                                                    {{ $base->nombre_es }}
                                                    @unless ($base->activo)
                                                        <span class="ms-1 rounded-full bg-gray-100 px-2 py-0.5 align-middle text-xs font-medium text-gray-600">Archivado</span>
                                                    @endunless
                                                </h4>
                                                <p class="text-sm italic text-gray-500">{{ $base->nombre_en }}</p>
                                            </div>
                                            @if ($puedeGestionar)
                                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                                                    <a href="{{ route('productos.exportacion.presentaciones.create', $base) }}" class="font-medium text-indigo-600 hover:underline">+ Presentación</a>
                                                    <a href="{{ route('productos.exportacion.base.edit', $base) }}" class="text-gray-500 hover:text-gray-700 hover:underline">Editar</a>
                                                    <form method="POST" action="{{ route('productos.exportacion.base.toggle-activo', $base) }}"
                                                          @if ($base->activo) onsubmit="return confirm('¿Archivar {{ e($base->nombre_es) }} y todas sus presentaciones? Conserva sus precios y su histórico.');" @endif>
                                                        @csrf @method('PATCH')
                                                        <button class="text-gray-500 hover:text-gray-700 hover:underline">{{ $base->activo ? 'Archivar' : 'Reactivar' }}</button>
                                                    </form>
                                                </div>
                                            @endif
                                        </div>

                                        <ul class="mt-2 space-y-1.5">
                                            @foreach ($grupo['presentaciones'] as $p)
                                                @php $editarEste = (string) old('presentacion_id') === (string) $p->id; @endphp
                                                <li class="rounded-md bg-gray-50 px-3 py-2 {{ $p->activo ? '' : 'opacity-60' }}" x-data="{ editando: @js($editarEste) }">
                                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
                                                    <div class="sm:w-56 sm:shrink-0">
                                                        <a href="{{ route('productos.exportacion.show', $p) }}" class="text-sm font-medium text-gray-700 hover:text-indigo-600 hover:underline">{{ $p->etiquetaEmpaque() }}</a>
                                                        @unless ($p->activo)
                                                            <span class="ms-1 text-xs text-gray-500">archivada</span>
                                                        @endunless
                                                        <div class="text-xs tabular-nums text-gray-500">
                                                            {{ rtrim(rtrim(number_format((float) $p->gramos_por_unidad, 2), '0'), '.') }} g · {{ number_format((float) $p->peso_neto_caja_kg, 2) }} kg neto
                                                        </div>
                                                    </div>

                                                    <div class="flex flex-1 flex-wrap gap-1.5">
                                                        @forelse ($p->asignaciones->where('activo', true) as $asignacion)
                                                            <span class="inline-flex items-baseline gap-1 rounded-full border border-gray-200 bg-white px-2 py-0.5 text-xs"
                                                                  title="{{ $asignacion->cliente?->nombre }}">
                                                                <span class="text-gray-500">{{ $corto($asignacion->cliente?->nombre) }}</span>
                                                                <span class="font-medium tabular-nums text-gray-800">{{ $dinero($asignacion->precio_caja) }}</span>
                                                            </span>
                                                        @empty
                                                            <span class="text-xs text-gray-400">Ningún cliente todavía</span>
                                                        @endforelse
                                                    </div>

                                                    <div class="flex items-center justify-between gap-3 text-xs sm:justify-end">
                                                        <span class="tabular-nums text-gray-500">
                                                            Base {{ $p->precio_caja !== null ? $dinero($p->precio_caja) : '—' }}
                                                        </span>
                                                        @if ($puedeGestionar)
                                                            <button type="button" @click="editando = !editando" :aria-expanded="editando"
                                                                    class="font-medium text-indigo-600 hover:underline"
                                                                    aria-label="Clientes de {{ $base->nombre_es }}, {{ $p->etiquetaEmpaque() }}">Clientes</button>
                                                            <a href="{{ route('productos.exportacion.edit', $p) }}" class="text-indigo-600 hover:underline"
                                                               aria-label="Editar {{ $base->nombre_es }}, {{ $p->etiquetaEmpaque() }}">Editar</a>
                                                        @endif
                                                    </div>
                                                </div>

                                                @if ($puedeGestionar)
                                                    <form method="POST" action="{{ route('productos.exportacion.clientes', $p) }}"
                                                          x-show="editando" x-cloak class="mt-2 space-y-2 border-t border-gray-200 pt-2">
                                                        @csrf @method('PUT')
                                                        <input type="hidden" name="presentacion_id" value="{{ $p->id }}">
                                                        <p class="text-xs text-gray-500">
                                                            Marcá a quién se le vende y a qué precio por caja. Vacío usa el precio base
                                                            ({{ $p->precio_caja !== null ? $dinero($p->precio_caja) : 'sin precio base' }}).
                                                        </p>
                                                        @include('productos.exportacion._clientes', [
                                                            'clientes' => $clientes,
                                                            'asignaciones' => $p->asignaciones->keyBy('exportacion_cliente_id'),
                                                            'prefijo' => 'p'.$p->id,
                                                            'usarOld' => $editarEste,
                                                        ])
                                                        <div class="flex items-center gap-3">
                                                            <x-primary-button>Guardar clientes</x-primary-button>
                                                            <button type="button" @click="editando = false" class="text-sm text-gray-500 hover:underline">Cancelar</button>
                                                        </div>
                                                    </form>
                                                @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </article>
                                @endforeach
                            </div>
                        </section>
                    @endforeach

                    @if ($sinAgrupar->isNotEmpty())
                        <section aria-labelledby="seccion-sin-agrupar">
                            <h3 id="seccion-sin-agrupar" class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Sin agrupar</h3>
                            <p class="mb-2 text-xs text-amber-700">
                                Registros del catálogo anterior que todavía no pertenecen a ningún producto. Se agrupan con
                                <code>php artisan exportacion:unificar-catalogo</code>.
                            </p>
                            <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200">
                                @foreach ($sinAgrupar as $p)
                                    <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm {{ $p->activo ? '' : 'opacity-60' }}">
                                        <div>
                                            <a href="{{ route('productos.exportacion.show', $p) }}" class="font-medium text-gray-800 hover:text-indigo-600 hover:underline">{{ $p->nombre_es }}</a>
                                            <span class="text-xs text-gray-500">{{ $p->nombre_en }}</span>
                                            @unless ($p->activo) <span class="text-xs text-gray-500">· archivado</span> @endunless
                                        </div>
                                        <span class="text-xs tabular-nums text-gray-500">{{ $p->etiquetaEmpaque() }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    @if ($secciones->isEmpty() && $sinAgrupar->isEmpty())
                        <p class="py-10 text-center text-gray-400">
                            @if ($filtros['q'] !== '')
                                Ningún producto de exportación coincide con «{{ $filtros['q'] }}».
                            @else
                                Todavía no hay productos de exportación.
                            @endif
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
