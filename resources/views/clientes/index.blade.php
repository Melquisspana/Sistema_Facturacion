{{-- Directorio operativo de clientes.

     La pantalla está pensada para una sola pregunta: «¿dónde está este cliente y
     cómo le agrego una sala?». Por eso el buscador entra también por los datos de
     las SALAS (nombre y código) y, cuando la coincidencia viene de ahí, la fila
     dice cuál fue —si no, el cliente aparecería sin explicación aparente—.

     Los filtros son DOS grupos independientes (estado y salas) que se combinan
     entre sí y con el buscador y el tipo: «activos + sin salas» es el pendiente
     real de trabajo, y con un grupo único había que elegir entre una cosa o la otra.

     Ubicación y correo salieron de la tabla a propósito: con cinco columnas cabe sin
     scroll horizontal y las acciones quedan siempre visibles. Siguen en la ficha.

     Dark mode: clases dark: explícitas con la paleta ink/paper de tailwind.config.
     Sus valores coinciden con los que ya aplicaban los overrides globales de
     app.css, así que el resultado es el mismo pero queda escrito en la vista y no
     depende de que esa hoja siga cubriendo estas utilidades. Los colores con
     significado (verde/ámbar/rojo de los badges) se dejan a los overrides, que ya
     los traducen bien. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-paper-100 leading-tight">Clientes</h2>
            @can('create', App\Models\Cliente::class)
                <a href="{{ route('clientes.create') }}"
                   class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    Nuevo cliente
                </a>
            @endcan
        </div>
    </x-slot>

    @php
        // Enlace que cambia SOLO lo que se le pasa y conserva el resto del estado:
        // es lo que hace que los dos grupos se combinen en vez de pisarse.
        $enlace = fn (array $cambios) => route('clientes.index', array_merge([
            'q' => $filtros['q'] !== '' ? $filtros['q'] : null,
            'tipo_cliente' => $filtros['tipo_cliente'] ?: null,
            'estado' => $filtros['estado'],
            'salas' => $filtros['salas'],
        ], $cambios));

        $gruposFiltro = [
            'estado' => [
                'etiqueta' => 'Estado',
                'opciones' => ['activos' => 'Activos', 'todos' => 'Todos', 'inactivos' => 'Inactivos'],
            ],
            'salas' => [
                'etiqueta' => 'Salas',
                'opciones' => ['todas' => 'Todas', 'sin' => 'Sin salas', 'con' => 'Con salas'],
            ],
        ];

        // Resumen legible de lo que está filtrando ahora mismo. Solo se nombra lo que
        // se apartó del valor por defecto, para que la línea diga algo cuando dice algo.
        $activos = [];
        if ($filtros['estado'] !== 'activos') {
            $activos[] = $gruposFiltro['estado']['opciones'][$filtros['estado']];
        }
        if ($filtros['salas'] !== 'todas') {
            $activos[] = $gruposFiltro['salas']['opciones'][$filtros['salas']];
        }
        if ($filtros['tipo_cliente']) {
            $activos[] = 'Tipo: '.($tiposCliente[$filtros['tipo_cliente']] ?? $filtros['tipo_cliente']);
        }
        if ($filtros['q'] !== '') {
            $activos[] = 'Búsqueda: «'.$filtros['q'].'»';
        }
        $porDefecto = $activos === [];
    @endphp

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Buscador. Una sola caja: el operador no debería tener que saber en qué
                 campo está guardado lo que recuerda del cliente. --}}
            <form method="GET" class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-64">
                    <x-input-label for="q" value="Buscar" />
                    <x-text-input id="q" name="q" type="search" class="mt-1 block w-full"
                                  :value="$filtros['q']"
                                  placeholder="Nombre, código, NIT/documento, NRC, correo, o nombre/código de sala…" />
                </div>
                <div>
                    <x-input-label for="tipo_cliente" value="Tipo" />
                    <select id="tipo_cliente" name="tipo_cliente"
                            class="mt-1 block w-full rounded-md border-gray-300 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100 shadow-sm text-sm">
                        <option value="">Todos</option>
                        @foreach ($tiposCliente as $valor => $label)
                            <option value="{{ $valor }}" @selected($filtros['tipo_cliente'] === $valor)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- Los dos grupos viajan con el formulario para no perderse al buscar. --}}
                <input type="hidden" name="estado" value="{{ $filtros['estado'] }}">
                <input type="hidden" name="salas" value="{{ $filtros['salas'] }}">
                <x-primary-button>Buscar</x-primary-button>
            </form>

            {{-- Filtros: dos grupos, combinables. Cada pastilla cambia solo su grupo. --}}
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                @foreach ($gruposFiltro as $clave => $grupo)
                    <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Filtrar por {{ $grupo['etiqueta'] }}">
                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-paper-300">{{ $grupo['etiqueta'] }}</span>
                        @foreach ($grupo['opciones'] as $valor => $label)
                            @php $activa = $filtros[$clave] === $valor; @endphp
                            <a href="{{ $enlace([$clave => $valor]) }}"
                               @if ($activa) aria-current="true" @endif
                               class="rounded-full border px-3 py-1 text-sm {{ $activa
                                    ? 'border-gray-800 bg-gray-800 text-white dark:border-ink-500 dark:bg-ink-900'
                                    : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50 dark:border-ink-500 dark:bg-ink-800 dark:text-paper-300 dark:hover:bg-ink-700' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </div>

            {{-- Qué está filtrando ahora mismo, en palabras. --}}
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-500 dark:text-paper-300">
                <span>
                    @if ($porDefecto)
                        Mostrando <strong class="font-medium text-gray-700 dark:text-paper-100">clientes activos</strong>, con o sin salas.
                    @else
                        Filtros activos:
                        <strong class="font-medium text-gray-700 dark:text-paper-100">{{ implode(' · ', $activos) }}</strong>
                    @endif
                </span>
                @unless ($porDefecto)
                    <a href="{{ route('clientes.index') }}" class="text-indigo-600 hover:underline">Limpiar</a>
                @endunless
                <span class="ml-auto">{{ $clientes->total() }} {{ $clientes->total() === 1 ? 'cliente' : 'clientes' }}</span>
            </div>

            {{-- Sin overflow-x-auto: ese contenedor recorta el menú «…» de cada fila.
                 En su lugar la tabla adelgaza por breakpoint —en móvil quedan Cliente y
                 Acciones, y lo demás se apila dentro de la primera celda—, así que
                 tampoco hay scroll horizontal. --}}
            <div class="bg-white dark:bg-ink-800 shadow-sm ring-1 ring-gray-200 dark:ring-ink-600 sm:rounded-xl">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-ink-600 bg-gray-50 dark:bg-ink-900 text-left text-xs uppercase tracking-wide text-gray-500 dark:text-paper-300">
                            <th class="px-4 py-3">Cliente</th>
                            <th class="px-4 py-3 hidden lg:table-cell">Documento / NRC</th>
                            <th class="px-4 py-3 hidden sm:table-cell">Salas</th>
                            <th class="px-4 py-3 hidden sm:table-cell">Estado</th>
                            <th class="px-4 py-3 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                        @forelse ($clientes as $cliente)
                            @php
                                // Solo viene cargada cuando hubo búsqueda, y solo con las
                                // salas que casaron: si está vacía, el cliente entró por
                                // sus propios datos y no hay nada que explicar.
                                $salasCoincidentes = $cliente->relationLoaded('sucursales')
                                    ? $cliente->sucursales
                                    : collect();
                            @endphp
                            <tr class="hover:bg-gray-50 dark:hover:bg-ink-700 {{ $cliente->activo ? '' : 'opacity-60' }}">
                                <td class="px-4 py-3 align-top">
                                    <a href="{{ route('clientes.show', $cliente) }}"
                                       class="font-medium text-gray-800 dark:text-paper-100 hover:underline">{{ $cliente->nombre }}</a>
                                    <div class="text-xs text-gray-500 dark:text-paper-300">
                                        {{ $cliente->tipo_cliente?->label() }}
                                        @if (filled($cliente->codigo))
                                            · <span class="font-mono">{{ $cliente->codigo }}</span>
                                        @endif
                                    </div>

                                    {{-- Lo que en pantalla angosta pierde su columna se
                                         apila acá, para no perder el dato ni el ancho. --}}
                                    <div class="lg:hidden text-xs text-gray-500 dark:text-paper-300 font-mono">{{ $cliente->num_documento ?? '—' }}</div>
                                    <div class="sm:hidden mt-1 flex flex-wrap items-center gap-2 text-xs">
                                        @if ((int) $cliente->salas_total === 0)
                                            <span class="text-amber-700">Sin salas</span>
                                        @else
                                            <x-clientes.resumen-salas class="text-gray-600 dark:text-paper-300"
                                                                      :total="$cliente->salas_total"
                                                                      :activas="$cliente->salas_activas" />
                                        @endif
                                        <span class="inline-flex px-2 py-0.5 rounded-full {{ $cliente->activo ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">
                                            {{ $cliente->activo ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </div>

                                    @if ($salasCoincidentes->isNotEmpty())
                                        <div class="mt-1 text-xs text-gray-600 dark:text-paper-300">
                                            Coincide en
                                            {{ $salasCoincidentes->count() === 1 ? 'la sala' : 'las salas' }}:
                                            @foreach ($salasCoincidentes->take(3) as $sala)
                                                <span class="font-medium">{{ $sala->nombre }}</span>@if (filled($sala->codigo))
                                                    <span class="font-mono">({{ $sala->codigo }})</span>@endif{{ ! $loop->last ? ',' : '' }}
                                            @endforeach
                                            @if ($salasCoincidentes->count() > 3)
                                                y {{ $salasCoincidentes->count() - 3 }} más
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top hidden lg:table-cell text-gray-600 dark:text-paper-300">
                                    <div class="font-mono">{{ $cliente->num_documento ?? '—' }}</div>
                                    <div class="text-xs text-gray-500 dark:text-paper-300">
                                        {{ $cliente->tipo_documento?->label() ?? 'Sin documento' }}
                                        · NRC <span class="font-mono">{{ $cliente->nrc ?? '—' }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top hidden sm:table-cell">
                                    @if ((int) $cliente->salas_total === 0)
                                        {{-- Ámbar, no rojo: es un hueco por llenar, no un error.
                                             La acción para llenarlo está en la misma fila. --}}
                                        <span class="text-amber-700">Sin salas</span>
                                    @else
                                        <x-clientes.resumen-salas class="text-gray-700 dark:text-paper-100"
                                                                  :total="$cliente->salas_total"
                                                                  :activas="$cliente->salas_activas" />
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top hidden sm:table-cell">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $cliente->activo ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">
                                        {{ $cliente->activo ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 align-top text-right whitespace-nowrap">
                                    <div class="inline-flex items-center gap-2 sm:gap-3">
                                        @can('update', $cliente)
                                            {{-- Acción principal de la fila. Sobria a propósito: quince
                                                 botones rellenos convertirían la tabla en un semáforo. --}}
                                            <a href="{{ route('clientes.sucursales.create', $cliente) }}"
                                               class="inline-flex items-center rounded-md border border-gray-300 dark:border-ink-500 bg-white dark:bg-ink-800 px-2.5 py-1 text-sm text-gray-700 dark:text-paper-100 hover:bg-gray-50 dark:hover:bg-ink-700">
                                                <span class="sm:hidden">+&nbsp;Sala</span>
                                                <span class="hidden sm:inline">Agregar sala</span>
                                            </a>
                                        @endcan
                                        {{-- En móvil el nombre del cliente ya es el enlace a la ficha
                                             y «Editar» baja al menú: la fila no da para más. --}}
                                        <a href="{{ route('clientes.show', $cliente) }}"
                                           class="hidden sm:inline text-gray-600 dark:text-paper-300 hover:underline">Ver</a>
                                        @can('update', $cliente)
                                            <a href="{{ route('clientes.edit', $cliente) }}"
                                               class="hidden sm:inline text-indigo-600 hover:underline">Editar</a>
                                        @endcan

                                        @canany(['update', 'delete'], $cliente)
                                            {{-- Menú discreto: lo que no se hace todos los días
                                                 (activar/inactivar, eliminar) no ocupa sitio fijo.

                                                 Teclado: el disparador es un <button> real, así que
                                                 Enter y Espacio lo abren; Escape lo cierra y devuelve
                                                 el foco; al cerrarse, x-show pone display:none y sus
                                                 opciones salen del orden de tabulación. --}}
                                            <div class="relative"
                                                 x-data="{ abierto: false }"
                                                 @click.outside="abierto = false"
                                                 @keydown.escape.window="if (abierto) { abierto = false; $refs.disparador.focus() }">
                                                <button type="button" x-ref="disparador"
                                                        @click="abierto = ! abierto"
                                                        :aria-expanded="abierto ? 'true' : 'false'"
                                                        aria-haspopup="menu"
                                                        aria-label="Más acciones para {{ $cliente->nombre }}"
                                                        class="rounded px-2 py-1 text-gray-500 dark:text-paper-300 hover:bg-gray-100 dark:hover:bg-ink-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                                    <span aria-hidden="true">&hellip;</span>
                                                </button>
                                                {{-- Sin x-transition a propósito: si el estado cambia
                                                     mientras la animación de 75 ms corre, x-show deja el
                                                     panel a medias —con opacity 1 y SIN display— y el
                                                     menú se queda visible aunque Alpine ya lo dé por
                                                     cerrado. Se reprodujo pulsando Escape justo después
                                                     de abrir. Un desvanecido de 75 ms en un menú de fila
                                                     no aporta nada que valga ese riesgo. --}}
                                                <div x-show="abierto" x-cloak role="menu"
                                                     class="absolute right-0 z-20 mt-1 w-48 rounded-md bg-white dark:bg-ink-800 py-1 text-left shadow-lg ring-1 ring-gray-200 dark:ring-ink-600">
                                                    @can('update', $cliente)
                                                        {{-- Solo en móvil: en pantalla ancha «Editar» ya
                                                             está visible en la propia fila. --}}
                                                        <a href="{{ route('clientes.edit', $cliente) }}" role="menuitem"
                                                           class="sm:hidden block px-4 py-2 text-sm text-gray-700 dark:text-paper-100 hover:bg-gray-100 dark:hover:bg-ink-700">
                                                            Editar cliente
                                                        </a>
                                                        <form method="POST" action="{{ route('clientes.toggle-activo', $cliente) }}">
                                                            @csrf @method('PATCH')
                                                            <button role="menuitem"
                                                                    class="block w-full px-4 py-2 text-left text-sm text-gray-700 dark:text-paper-100 hover:bg-gray-100 dark:hover:bg-ink-700">
                                                                {{ $cliente->activo ? 'Inactivar cliente' : 'Activar cliente' }}
                                                            </button>
                                                        </form>
                                                    @endcan
                                                    @can('delete', $cliente)
                                                        <form method="POST" action="{{ route('clientes.destroy', $cliente) }}"
                                                              onsubmit="return confirm('¿Eliminar este cliente?');">
                                                            @csrf @method('DELETE')
                                                            <button role="menuitem"
                                                                    class="block w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-gray-100 dark:hover:bg-ink-700">
                                                                Eliminar cliente
                                                            </button>
                                                        </form>
                                                    @endcan
                                                </div>
                                            </div>
                                        @endcanany
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-10 text-center text-gray-400 dark:text-paper-500">
                                    @if (! $porDefecto)
                                        Ningún cliente coincide con la búsqueda o los filtros.
                                        <a href="{{ route('clientes.index') }}" class="text-indigo-600 hover:underline">Ver todos los activos</a>.
                                    @else
                                        Todavía no hay clientes activos.
                                        @can('create', App\Models\Cliente::class)
                                            <a href="{{ route('clientes.create') }}" class="text-indigo-600 hover:underline">Dá de alta el primero</a>.
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                @if ($clientes->hasPages())
                    <div class="border-t border-gray-100 dark:border-ink-600 px-4 py-3">{{ $clientes->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
