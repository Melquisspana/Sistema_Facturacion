<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Configurar rutas</h2>
            <a href="{{ route('rutas.dashboard') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Volver a rutas</a>
        </div>
    </x-slot>

    {{-- Todo lo que se toca de vez en cuando, en una sola página: cada ruta con cada cuánto
         se va, qué lugares cubre y qué salas le sugiere la cobertura; y los vendedores. --}}
    @php
        $caja = 'rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none';
        $campo = 'rounded-lg border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
        $gestiona = auth()->user()?->can('rutas.gestionar');
    @endphp

    <div class="py-6 sm:py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

            <x-rutas.avisos />

            {{-- ===================== Nueva ruta ===================== --}}
            @if ($gestiona)
                <form method="POST" action="{{ route('rutas.rutas.store') }}" class="{{ $caja }} flex flex-wrap items-end gap-3 p-4">
                    @csrf
                    <input type="hidden" name="en_linea" value="1">
                    <div class="min-w-0 flex-1">
                        <label for="nueva_nombre" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Nueva ruta</label>
                        <input id="nueva_nombre" name="nombre" required maxlength="120" placeholder="Puerto" class="mt-1 w-full {{ $campo }}">
                    </div>
                    <div>
                        <label for="nueva_frecuencia" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Cada cuántos días</label>
                        <input id="nueva_frecuencia" name="frecuencia_objetivo_dias" type="number" min="1" max="365" placeholder="15" class="mt-1 w-28 {{ $campo }}">
                    </div>
                    <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Crear</button>
                </form>
            @endif

            {{-- ===================== Una tarjeta por ruta ===================== --}}
            @forelse ($rutas as $ruta)
                @php $deEsta = $sugeridas[$ruta->id] ?? []; @endphp
                <div id="ruta-{{ $ruta->id }}" class="{{ $caja }} p-5 {{ $ruta->activa ? '' : 'opacity-60' }}">

                    {{-- Nombre y frecuencia, editables ahí mismo --}}
                    @if ($gestiona)
                        <form method="POST" action="{{ route('rutas.rutas.update', $ruta) }}" class="flex flex-wrap items-end gap-3">
                            @csrf @method('PUT')
                            <input type="hidden" name="en_linea" value="1">
                            <input name="nombre" value="{{ $ruta->nombre }}" required maxlength="120" aria-label="Nombre de la ruta"
                                   class="min-w-0 flex-1 rounded-lg border-transparent bg-transparent px-2 text-lg font-semibold text-gray-800 hover:border-gray-300 focus:border-indigo-500 dark:text-paper-100">
                            <label class="flex items-center gap-2 text-sm text-gray-500 dark:text-paper-400">
                                cada
                                <input name="frecuencia_objetivo_dias" type="number" min="1" max="365" value="{{ $ruta->frecuencia_objetivo_dias }}"
                                       aria-label="Cada cuántos días" class="w-20 {{ $campo }}">
                                días
                            </label>
                            <button class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-200">Guardar</button>
                        </form>
                    @else
                        <p class="text-lg font-semibold text-gray-800 dark:text-paper-100">
                            {{ $ruta->nombre }}
                            <span class="text-sm font-normal text-gray-500">{{ $ruta->frecuencia_objetivo_dias ? '· cada '.$ruta->frecuencia_objetivo_dias.' días' : '' }}</span>
                        </p>
                    @endif

                    {{-- Lugares que cubre --}}
                    <div class="mt-4">
                        <p class="text-xs font-medium text-gray-500 dark:text-paper-400">Lugares que cubre</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @forelse ($ruta->coberturas->sortBy(fn ($c) => [$c->esDepartamento() ? 0 : 1, $c->etiqueta()]) as $cobertura)
                                <span class="inline-flex items-center gap-1 rounded-full py-1 pl-3 pr-1 text-sm {{ $cobertura->esDepartamento() ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' : 'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300' }}">
                                    {{ $cobertura->esDepartamento() ? $cobertura->departamento?->nombre.' (todo)' : $cobertura->distrito?->nombre }}
                                    @if ($gestiona)
                                        <form method="POST" action="{{ route('rutas.rutas.cobertura.destroy', [$ruta, $cobertura]) }}">
                                            @csrf @method('DELETE')
                                            <button aria-label="Quitar {{ $cobertura->etiqueta() }}" class="rounded-full px-1.5 opacity-60 hover:opacity-100">×</button>
                                        </form>
                                    @endif
                                </span>
                            @empty
                                <span class="text-sm text-gray-400 dark:text-paper-500">Todavía ninguno.</span>
                            @endforelse
                        </div>

                        @if ($gestiona)
                            <div class="mt-3 flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('rutas.rutas.cobertura.store', $ruta) }}" class="flex gap-1.5">
                                    @csrf
                                    <select name="departamento_id" required aria-label="Departamento completo" class="{{ $campo }}">
                                        <option value="">+ Departamento completo</option>
                                        @foreach ($departamentos as $departamento)
                                            <option value="{{ $departamento->id }}">{{ $departamento->nombre }}</option>
                                        @endforeach
                                    </select>
                                    <button class="rounded-lg bg-gray-100 px-3 text-sm text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-200">Agregar</button>
                                </form>
                                <form method="POST" action="{{ route('rutas.rutas.cobertura.store', $ruta) }}" class="flex gap-1.5">
                                    @csrf
                                    <select name="distrito_id" required aria-label="Un pueblo" class="{{ $campo }}">
                                        <option value="">+ Solo un pueblo</option>
                                        @foreach ($distritos as $nombreDepartamento => $lista)
                                            <optgroup label="{{ $nombreDepartamento }}">
                                                @foreach ($lista as $distrito)
                                                    <option value="{{ $distrito->id }}">{{ $distrito->nombre }}</option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                    <button class="rounded-lg bg-gray-100 px-3 text-sm text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-200">Agregar</button>
                                </form>
                            </div>
                        @endif
                    </div>

                    {{-- Salas --}}
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4 dark:border-ink-700">
                        <a href="{{ route('rutas.rutas.show', $ruta) }}" class="text-sm text-gray-600 hover:underline dark:text-paper-300">
                            {{ $ruta->sucursales_count }} {{ $ruta->sucursales_count === 1 ? 'sala' : 'salas' }} · ver o cambiar
                        </a>
                        @if ($gestiona && count($deEsta) > 0)
                            <form method="POST" action="{{ route('rutas.asignacion.aplicar') }}">
                                @csrf
                                @foreach ($deEsta as $salaId)
                                    <input type="hidden" name="sucursales[]" value="{{ $salaId }}">
                                @endforeach
                                <button class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                    Asignar {{ count($deEsta) }} {{ count($deEsta) === 1 ? 'sala sugerida' : 'salas sugeridas' }}
                                </button>
                            </form>
                        @endif
                        @if ($gestiona)
                            <details class="w-full">
                                <summary class="cursor-pointer text-sm font-medium text-indigo-600 dark:text-indigo-400">+ Agregar salas por nombre</summary>
                                <div class="mt-3">
                                    <x-rutas.buscador-salas :ruta="$ruta" />
                                </div>
                            </details>
                            <form method="POST" action="{{ route('rutas.rutas.toggle-activa', $ruta) }}">
                                @csrf @method('PATCH')
                                <button class="text-xs text-gray-400 hover:underline dark:text-paper-500">{{ $ruta->activa ? 'Desactivar ruta' : 'Activar ruta' }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <div class="{{ $caja }} px-6 py-10 text-center text-sm text-gray-500 dark:text-paper-400">
                    Todavía no hay rutas. Creá la primera arriba.
                </div>
            @endforelse

            {{-- ===================== Lo que ninguna ruta cubre ===================== --}}
            @if ($sinCobertura->isNotEmpty())
                <div class="{{ $caja }} p-5">
                    <p class="text-sm font-medium text-gray-700 dark:text-paper-200">Salas sin ruta que ninguna ruta cubre</p>
                    <p class="mt-0.5 text-xs text-gray-400 dark:text-paper-500">Agregá su departamento o pueblo a alguna ruta, o asignalas a mano desde «ver o cambiar».</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($sinCobertura as $grupo)
                            <span class="rounded-full bg-amber-50 px-3 py-1 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ $grupo['departamento'] }} · {{ $grupo['salas'] }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ===================== Vendedores ===================== --}}
            <div id="vendedores" class="{{ $caja }} p-5">
                <p class="text-base font-semibold text-gray-800 dark:text-paper-100">Vendedores</p>
                <p class="mt-0.5 text-xs text-gray-400 dark:text-paper-500">Quienes salen a las rutas. Al salir se marca quiénes van.</p>

                <div class="mt-3 divide-y divide-gray-100 dark:divide-ink-700">
                    @forelse ($vendedores as $vendedor)
                        <div class="flex items-center justify-between gap-3 py-2 {{ $vendedor->activo ? '' : 'opacity-50' }}">
                            <span class="text-sm text-gray-800 dark:text-paper-100">
                                {{ $vendedor->nombre }}
                                @if ($vendedor->telefono)<span class="text-gray-400 dark:text-paper-500"> · {{ $vendedor->telefono }}</span>@endif
                            </span>
                            @can('rutas.personal.gestionar')
                                <form method="POST" action="{{ route('rutas.personal.toggle-activo', $vendedor) }}">
                                    @csrf @method('PATCH')
                                    <button class="text-xs text-gray-400 hover:underline dark:text-paper-500">{{ $vendedor->activo ? 'Ya no sale' : 'Vuelve a salir' }}</button>
                                </form>
                            @endcan
                        </div>
                    @empty
                        <p class="py-3 text-sm text-gray-500 dark:text-paper-400">Todavía no hay vendedores.</p>
                    @endforelse
                </div>

                @can('rutas.personal.gestionar')
                    <form method="POST" action="{{ route('rutas.personal.store') }}" class="mt-3 flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3 dark:border-ink-700">
                        @csrf
                        <input type="hidden" name="en_linea" value="1">
                        <input type="hidden" name="funciones[]" value="vendedor">
                        <input name="nombre" required maxlength="120" placeholder="Nombre" aria-label="Nombre del vendedor" class="min-w-0 flex-1 {{ $campo }}">
                        <input name="telefono" maxlength="30" placeholder="Teléfono (opcional)" aria-label="Teléfono" class="w-44 {{ $campo }}">
                        <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Agregar</button>
                    </form>
                @endcan
            </div>

        </div>
    </div>
</x-app-layout>
