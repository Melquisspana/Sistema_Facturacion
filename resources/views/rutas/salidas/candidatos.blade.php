<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Documentos candidatos para esta ruta</h2>
            <a href="{{ route('rutas.salidas.show', $salida) }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Volver a la salida</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-rutas.avisos />

            {{-- Qué se está proponiendo y por qué. Se dice explícito para que nadie
                 interprete que esta lista es "todos los documentos de la ruta". --}}
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <p class="text-sm text-gray-700 dark:text-paper-200">
                    CCF de las salas de <strong>{{ $salida->ruta->nombre }}</strong> emitidos entre
                    <strong>{{ \Illuminate\Support\Carbon::parse($desde)->translatedFormat('d M Y') }}</strong> y
                    <strong>{{ \Illuminate\Support\Carbon::parse($hasta)->translatedFormat('d M Y') }}</strong>,
                    que todavía no pertenecen a ninguna salida abierta.
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-paper-400">
                    Es una propuesta: no se agrega nada hasta que marqués y confirmes. Un documento que ya está en otra
                    salida abierta no aparece acá — si de verdad va en esta, hay que moverlo desde donde está.
                </p>

                <form method="GET" class="mt-4 flex flex-wrap items-end gap-3">
                    <div class="min-w-48 flex-1">
                        <label for="q" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Número de control u orden de compra</label>
                        <input id="q" name="q" value="{{ $filtros['q'] ?? '' }}" placeholder="Ej. 0986 o 26060236004586"
                               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-500 dark:bg-ink-700 dark:text-paper-100">
                    </div>
                    <div>
                        <label for="sucursal_id" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Sala</label>
                        <select id="sucursal_id" name="sucursal_id" class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-500 dark:bg-ink-700 dark:text-paper-100">
                            <option value="">Todas las de la ruta</option>
                            @foreach ($sucursales as $sucursal)
                                <option value="{{ $sucursal->id }}" @selected(($filtros['sucursal_id'] ?? null) == $sucursal->id)>{{ $sucursal->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-200 dark:hover:bg-ink-600">Filtrar</button>
                    @if (($filtros['q'] ?? '') !== '' || ($filtros['sucursal_id'] ?? '') !== '')
                        <a href="{{ route('rutas.salidas.documentos.candidatos', $salida) }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Limpiar</a>
                    @endif
                </form>
            </div>

            @if ($candidatos->isEmpty())
                <div class="mt-6 rounded-xl bg-white p-8 text-center shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                    <p class="text-sm text-gray-500 dark:text-paper-400">No hay CCF que cumplan estas condiciones.</p>
                    <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">
                        Si el documento que buscás es de la serie histórica P001, agregalo desde «Agregar documento histórico».
                    </p>
                </div>
            @else
                <form method="POST" action="{{ route('rutas.salidas.documentos.store', $salida) }}" class="mt-6">
                    @csrf

                    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-ink-600 dark:bg-ink-700 dark:text-paper-400">
                                        <th class="py-3 px-4 w-10"></th>
                                        <th class="py-3 px-4">Documento</th>
                                        <th class="py-3 px-4">Sala</th>
                                        <th class="py-3 px-4">Fecha</th>
                                        <th class="py-3 px-4 text-right">Monto</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                                    @foreach ($candidatos as $dte)
                                        <tr class="hover:bg-gray-50 dark:hover:bg-ink-700/50">
                                            <td class="py-3 px-4">
                                                <input type="checkbox" name="dtes[]" value="{{ $dte->id }}"
                                                       class="rounded border-gray-300 text-indigo-600 dark:border-ink-500 dark:bg-ink-700"
                                                       aria-label="Agregar {{ $dte->numero_control }}">
                                            </td>
                                            <td class="py-3 px-4">
                                                <span class="font-mono font-semibold text-gray-800 dark:text-paper-100">…{{ \App\Support\OrdenCompra::ultimosDigitos($dte->numero_control) }}</span>
                                                <span class="ml-1 font-mono text-xs text-gray-400 dark:text-paper-500">{{ $dte->numero_control }}</span>
                                                @if ($dte->numero_orden_compra)
                                                    <p class="text-xs text-gray-500 dark:text-paper-400">OC {{ $dte->numero_orden_compra }}</p>
                                                @endif
                                            </td>
                                            <td class="py-3 px-4 text-gray-600 dark:text-paper-300">{{ $dte->clienteSucursal?->nombre ?? '—' }}</td>
                                            <td class="py-3 px-4 text-gray-600 dark:text-paper-300">{{ $dte->fecha_emision?->translatedFormat('d M Y') ?? '—' }}</td>
                                            <td class="py-3 px-4 text-right tabular-nums text-gray-800 dark:text-paper-100">${{ number_format((float) $dte->total_pagar, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="mt-4 flex items-center gap-3">
                        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            Agregar los marcados a la salida
                        </button>
                        <span class="text-xs text-gray-500 dark:text-paper-400">Se agrega solo lo que marques.</span>
                    </div>
                </form>

                <div class="mt-4">{{ $candidatos->links() }}</div>
            @endif

        </div>
    </div>
</x-app-layout>
