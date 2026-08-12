<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Agregar documento histórico</h2>
            <a href="{{ route('rutas.salidas.show', $salida) }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Volver a la salida</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-rutas.avisos />

            {{-- P001 es la vía SECUNDARIA a propósito: son documentos que este sistema
                 no emitió y que no se van a importar. Se busca con la misma maquinaria
                 de PPQ (los correos enviados) y se guarda solo lo que el correo dice. --}}
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <p class="text-sm text-gray-700 dark:text-paper-200">
                    Para CCF de la serie histórica <strong>P001</strong> (Conta Portable), que no están en la base de DTE de
                    este sistema. Se busca por los últimos dígitos del número de control, igual que en PPQ.
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-paper-400">
                    No se importa nada: se guarda el número de control y lo que se haya podido leer del correo. Si el
                    número resulta existir en el sistema, se agrega con sus datos reales en vez de como histórico.
                </p>

                <form method="GET" class="mt-4 flex flex-wrap items-end gap-3">
                    <div class="min-w-48 flex-1">
                        <label for="q" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Últimos dígitos o número de control</label>
                        <input id="q" name="q" value="{{ $q }}" placeholder="Ej. 0986"
                               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-500 dark:bg-ink-700 dark:text-paper-100">
                    </div>
                    <button class="rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-200 dark:hover:bg-ink-600">Buscar en Gmail</button>
                </form>

                @unless ($gmailDisponible)
                    <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                        Gmail no está disponible{{ $gmailError ? ': '.$gmailError : '' }}. Podés capturar el documento a mano
                        con el formulario de abajo; el número de control es lo único imprescindible.
                    </div>
                @endunless
            </div>

            {{-- Resultados de Gmail. Cada uno se agrega con lo que el propio correo trae. --}}
            @if (is_array($fichas))
                <div class="mt-6">
                    <h3 class="mb-3 text-sm font-semibold text-gray-700 dark:text-paper-200">
                        Resultados ({{ count($fichas) }})
                    </h3>

                    @if ($fichas === [])
                        <div class="rounded-xl bg-white p-6 text-center text-sm text-gray-500 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:text-paper-400 dark:ring-ink-600 dark:shadow-none">
                            No se encontró ningún CCF con ese número en los correos enviados.
                        </div>
                    @else
                        <div class="divide-y divide-gray-100 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200 dark:divide-ink-700 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                            @foreach ($fichas as $ficha)
                                @php $ccf = $ficha['ccf'] ?? []; @endphp
                                <div class="flex flex-wrap items-center justify-between gap-4 p-4">
                                    <div class="min-w-0">
                                        <p class="font-mono text-sm font-semibold text-gray-800 dark:text-paper-100">{{ $ccf['numeroControl'] ?? '—' }}</p>
                                        <p class="mt-0.5 text-sm text-gray-600 dark:text-paper-300">
                                            {{ $ccf['salaNombre'] ?? 'Sala sin nombre' }}
                                            @if (! empty($ccf['ordenCompra']))
                                                <span class="text-gray-400 dark:text-paper-500">· OC {{ $ccf['ordenCompra'] }}</span>
                                            @endif
                                        </p>
                                        <p class="mt-0.5 text-xs text-gray-500 dark:text-paper-400">
                                            {{ $ccf['fecha'] ?? 'Sin fecha' }}
                                            @if (isset($ccf['monto']))
                                                <span class="text-gray-400 dark:text-paper-500">· ${{ number_format((float) $ccf['monto'], 2) }}</span>
                                            @endif
                                        </p>
                                    </div>

                                    @if (in_array($ccf['numeroControl'] ?? '', $yaAsignados, true))
                                        <span class="rounded-md bg-gray-100 px-3 py-2 text-xs text-gray-500 dark:bg-ink-700 dark:text-paper-400">
                                            Ya está en una salida abierta
                                        </span>
                                    @else
                                        <form method="POST" action="{{ route('rutas.salidas.documentos.historico.store', $salida) }}">
                                            @csrf
                                            <input type="hidden" name="numero_control" value="{{ $ccf['numeroControl'] ?? '' }}">
                                            <input type="hidden" name="numero_orden_compra" value="{{ $ccf['ordenCompra'] ?? '' }}">
                                            <input type="hidden" name="sala_nombre" value="{{ $ccf['salaNombre'] ?? '' }}">
                                            <input type="hidden" name="monto" value="{{ $ccf['monto'] ?? '' }}">
                                            <input type="hidden" name="fecha_documento" value="{{ $ccf['fecha'] ?? '' }}">
                                            <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Agregar a la salida</button>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            {{-- Captura manual: el mínimo con el que el documento queda identificado.
                 Los demás campos son opcionales y se guardan tal cual: si no se saben,
                 se dejan vacíos, no se rellenan con suposiciones. --}}
            <div class="mt-6 rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-paper-200">Capturar a mano</h3>
                <p class="mt-1 text-xs text-gray-500 dark:text-paper-400">
                    Solo el número de control es obligatorio. La orden de compra es lo que después permite encontrar su
                    albarán, así que conviene ponerla si se conoce.
                </p>

                <form method="POST" action="{{ route('rutas.salidas.documentos.historico.store', $salida) }}" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @csrf
                    @php $campo = 'mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-500 dark:bg-ink-700 dark:text-paper-100'; @endphp

                    <div class="sm:col-span-2">
                        <label for="numero_control" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Número de control <span class="text-red-500">*</span></label>
                        <input id="numero_control" name="numero_control" required maxlength="40" value="{{ old('numero_control') }}"
                               placeholder="DTE-03-M001P001-000000000000986" class="{{ $campo }} font-mono">
                    </div>

                    <div>
                        <label for="numero_orden_compra" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Orden de compra</label>
                        <input id="numero_orden_compra" name="numero_orden_compra" maxlength="40" value="{{ old('numero_orden_compra') }}" class="{{ $campo }}">
                    </div>

                    <div>
                        <label for="fecha_documento" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Fecha del documento</label>
                        <input id="fecha_documento" name="fecha_documento" type="date" value="{{ old('fecha_documento') }}" class="{{ $campo }}">
                    </div>

                    <div>
                        <label for="cliente_nombre" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Cliente</label>
                        <input id="cliente_nombre" name="cliente_nombre" maxlength="255" value="{{ old('cliente_nombre') }}" class="{{ $campo }}">
                    </div>

                    <div>
                        <label for="sala_nombre" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Sala</label>
                        <input id="sala_nombre" name="sala_nombre" maxlength="255" value="{{ old('sala_nombre') }}" class="{{ $campo }}">
                    </div>

                    <div>
                        <label for="monto" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Monto</label>
                        <input id="monto" name="monto" type="number" step="0.01" min="0" value="{{ old('monto') }}" class="{{ $campo }}">
                    </div>

                    <div class="sm:col-span-2">
                        <button class="rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-900 dark:bg-paper-100 dark:text-ink-900 dark:hover:bg-white">
                            Agregar documento histórico
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
