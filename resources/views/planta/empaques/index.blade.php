<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Configuraciones de empaque</h2>
            @can('planta.catalogos.gestionar')
                <a href="{{ route('planta.empaques.create') }}"
                   class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nueva configuración</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-planta.avisos />

            @if ($errors->any())
                <div class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                    <ul class="list-disc ps-5 space-y-0.5">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-paper-400">Producto</label>
                    <select name="producto_base" class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        <option value="">Todos</option>
                        @foreach ($productosBase as $base)
                            <option value="{{ $base->id }}" @selected(request('producto_base') == $base->id)>{{ $base->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-paper-400">Presentación</label>
                    <select name="presentacion" class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        <option value="">Todas</option>
                        @foreach ($presentaciones as $pres)
                            <option value="{{ $pres->id }}" @selected(request('presentacion') == $pres->id)>{{ $pres->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-paper-400">Mercado</label>
                    <select name="mercado" class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        <option value="">Todos</option>
                        @foreach ($mercados as $mercado)
                            <option value="{{ $mercado->value }}" @selected(request('mercado') === $mercado->value)>{{ $mercado->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-paper-400">Marca</label>
                    <input type="text" name="marca" value="{{ request('marca') }}"
                           class="mt-1 w-40 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-paper-400">Predeterminada</label>
                    <select name="predeterminada" class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        <option value="">Todas</option>
                        <option value="1" @selected(request('predeterminada') === '1')>Sí</option>
                        <option value="0" @selected(request('predeterminada') === '0')>No</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-paper-400">Estado</label>
                    <select name="activo" class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        <option value="">Todos</option>
                        <option value="1" @selected(request('activo') === '1')>Activas</option>
                        <option value="0" @selected(request('activo') === '0')>Inactivas</option>
                    </select>
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Filtrar</button>
                @if (request()->hasAny(['producto_base', 'presentacion', 'mercado', 'marca', 'predeterminada', 'activo']))
                    <a href="{{ route('planta.empaques.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Limpiar</a>
                @endif
            </form>

            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200 dark:bg-ink-700 dark:text-paper-400 dark:border-ink-600">
                                <th class="py-3 px-4">Presentación</th>
                                <th class="py-3 px-4">Mercado</th>
                                <th class="py-3 px-4">Marca</th>
                                <th class="py-3 px-4">Bolsa</th>
                                <th class="py-3 px-4">Viñeta</th>
                                <th class="py-3 px-4 text-center">Predeterminada</th>
                                <th class="py-3 px-4">Vigencia</th>
                                <th class="py-3 px-4 text-center">Activo</th>
                                <th class="py-3 px-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                            @forelse ($configs as $config)
                                <tr class="hover:bg-gray-50 dark:hover:bg-ink-700 {{ $config->activo ? '' : 'opacity-60' }}">
                                    <td class="py-3 px-4">
                                        <div class="font-medium text-gray-800 dark:text-paper-100">{{ $config->presentacion?->nombre ?? '—' }}</div>
                                        <div class="text-xs text-gray-500 dark:text-paper-400">{{ $config->presentacion?->productoBase?->nombre }}</div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <x-planta.badge :color="$config->mercado->color()">{{ $config->mercado->label() }}</x-planta.badge>
                                    </td>
                                    <td class="py-3 px-4 text-gray-600 dark:text-paper-300">{{ $config->marca ?? '—' }}</td>
                                    <td class="py-3 px-4 text-gray-600 dark:text-paper-300">{{ $config->bolsa?->nombre ?? '—' }}</td>
                                    <td class="py-3 px-4 text-gray-600 dark:text-paper-300">{{ $config->vinieta?->nombre ?? 'sin viñeta' }}</td>
                                    <td class="py-3 px-4 text-center">
                                        @if ($config->es_predeterminada)
                                            <x-planta.badge color="green">Predeterminada</x-planta.badge>
                                        @elseif (auth()->user()?->can('planta.catalogos.gestionar') && $config->activo)
                                            <form method="POST" action="{{ route('planta.empaques.predeterminada', $config) }}" class="inline">
                                                @csrf @method('PATCH')
                                                <button class="text-xs text-indigo-600 hover:underline dark:text-indigo-400"
                                                        title="Sustituye a la predeterminada actual de este mercado">Marcar</button>
                                            </form>
                                        @else
                                            <span class="text-xs text-gray-400 dark:text-paper-500">—</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4 text-xs text-gray-500 dark:text-paper-400">{{ $config->vigenciaLegible() }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <x-planta.toggle-activo :activo="$config->activo"
                                                                :accion="route('planta.empaques.toggle-activo', $config)" />
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        @can('planta.catalogos.gestionar')
                                            <a href="{{ route('planta.empaques.edit', $config) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Editar</a>
                                        @else
                                            <span class="text-xs text-gray-400 dark:text-paper-500">—</span>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="py-10 text-center text-gray-400 dark:text-paper-500">
                                        No hay configuraciones de empaque.
                                        @can('planta.catalogos.gestionar')
                                            <a href="{{ route('planta.empaques.create') }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Cree la primera</a>.
                                        @endcan
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($configs->hasPages())
                    <div class="px-4 py-3 border-t border-gray-100 dark:border-ink-700">{{ $configs->links() }}</div>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
