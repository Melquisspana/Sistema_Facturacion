<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Zonas</h1>
            <a href="{{ route('rutas.dashboard') }}" class="inline-flex min-h-11 items-center text-sm text-gray-500 hover:underline dark:text-paper-300">Volver al tablero</a>
        </div>
    </x-slot>

    {{-- «Zonas» (10/11/2026): el catálogo que le pone nombre al viaje («Lunes · San Miguel») y
         agrupa el tablero y el mapa. De cada zona se toca solo el nombre y los lugares que
         cubre (departamentos completos o pueblos sueltos). Ya no se asignan salas a mano ni se
         cargan días: la zona de una sala sale de su lugar y el ritmo, de sus pedidos.
         Variables: $rutas (con coberturas), $sinCobertura, $departamentos y $distritos. --}}
    @php
        $caja = 'rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none';
        $campo = 'min-h-11 rounded-lg border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
        $secundario = 'inline-flex min-h-11 items-center justify-center rounded-lg bg-gray-100 px-3 text-sm text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-100';
        $gestiona = auth()->user()?->can('rutas.gestionar');
    @endphp

    <div class="py-6 sm:py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

            <x-rutas.avisos />

            <p class="text-sm text-gray-600 dark:text-paper-300">Una zona es un nombre y los lugares que cubre. Con eso se nombra cada viaje y se arma el mapa. El viaje se abre solo: acá no se arma nada.</p>

            {{-- ===================== Nueva zona ===================== --}}
            @if ($gestiona)
                <form method="POST" action="{{ route('rutas.rutas.store') }}" class="{{ $caja }} flex flex-wrap items-end gap-3 p-4">
                    @csrf
                    <input type="hidden" name="en_linea" value="1">
                    <input type="hidden" name="activa" value="1">
                    <div class="min-w-0 flex-1">
                        <label for="nueva_nombre" class="block text-xs font-medium text-gray-500 dark:text-paper-300">Nueva zona</label>
                        <input id="nueva_nombre" name="nombre" required maxlength="120" placeholder="Puerto" value="{{ old('nombre') }}" class="mt-1 w-full {{ $campo }}">
                        <x-input-error :messages="$errors->get('nombre')" class="mt-1" />
                    </div>
                    <button class="inline-flex min-h-11 items-center justify-center rounded-lg bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700">Crear</button>
                </form>
            @endif

            {{-- ===================== Una tarjeta por zona ===================== --}}
            @forelse ($rutas as $ruta)
                <div id="ruta-{{ $ruta->id }}" class="{{ $caja }} p-5 {{ $ruta->activa ? '' : 'opacity-60' }}">

                    {{-- Nombre, editable ahí mismo --}}
                    @if ($gestiona)
                        <form method="POST" action="{{ route('rutas.rutas.update', $ruta) }}" class="flex flex-wrap items-end gap-3">
                            @csrf @method('PUT')
                            <input type="hidden" name="en_linea" value="1">
                            <input type="hidden" name="activa" value="{{ $ruta->activa ? 1 : 0 }}">
                            <input name="nombre" value="{{ $ruta->nombre }}" required maxlength="120" aria-label="Nombre de la zona"
                                   class="min-h-11 min-w-0 flex-1 rounded-lg border-transparent bg-transparent px-2 text-lg font-semibold text-gray-800 hover:border-gray-300 focus:border-indigo-500 dark:text-paper-100">
                            <button class="{{ $secundario }}">Guardar nombre</button>
                        </form>
                    @else
                        <p class="text-lg font-semibold text-gray-800 dark:text-paper-100">{{ $ruta->nombre }}</p>
                    @endif
                    @unless ($ruta->activa)
                        <p class="mt-1 text-sm text-gray-500 dark:text-paper-300">Zona desactivada: no nombra viajes nuevos ni sale en el tablero.</p>
                    @endunless

                    {{-- Lugares que cubre --}}
                    <div class="mt-4">
                        <p class="text-xs font-medium text-gray-500 dark:text-paper-300">Lugares que cubre</p>
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
                                    <button class="{{ $secundario }}">Agregar</button>
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
                                    <button class="{{ $secundario }}">Agregar</button>
                                </form>
                            </div>
                        @endif
                    </div>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4 dark:border-ink-700">
                        <a href="{{ route('rutas.dashboard', ['ruta' => $ruta->id]) }}#salas" class="inline-flex min-h-11 items-center text-sm text-indigo-600 hover:underline dark:text-indigo-300">Ver sus salas en el tablero</a>
                        @if ($gestiona)
                            <form method="POST" action="{{ route('rutas.rutas.toggle-activa', $ruta) }}">
                                @csrf @method('PATCH')
                                <button class="min-h-11 text-sm text-gray-500 hover:underline dark:text-paper-300">{{ $ruta->activa ? 'Desactivar zona' : 'Activar zona' }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <div class="{{ $caja }} px-6 py-10 text-center text-sm text-gray-500 dark:text-paper-300">
                    Todavía no hay zonas. {{ $gestiona ? 'Creá la primera arriba.' : '' }}
                </div>
            @endforelse

            {{-- ===================== Lo que ninguna zona cubre ===================== --}}
            @if ($sinCobertura->isNotEmpty())
                <div class="{{ $caja }} p-5">
                    <p class="text-sm font-medium text-gray-700 dark:text-paper-100">Salas que ninguna zona cubre</p>
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-paper-300">Se les saca pedido y se les entrega igual, pero el viaje sale «Sin zona». Agregá su departamento o su pueblo a una zona.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($sinCobertura as $grupo)
                            <span class="rounded-full bg-amber-50 px-3 py-1 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ $grupo['departamento'] }} · {{ $grupo['salas'] }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
