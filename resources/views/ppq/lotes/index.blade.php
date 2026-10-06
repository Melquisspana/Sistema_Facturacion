<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-paper-100 leading-tight">Historial PPQ</h2>
            <div class="flex gap-2">
                <a href="{{ route('ppq.index') }}" class="rounded-md bg-gray-100 dark:bg-ink-700 px-3 py-2 text-sm text-gray-700 dark:text-paper-100 hover:bg-gray-200 dark:hover:bg-ink-600">Buscar CCF</a>
                @can('ppq.gestionar')
                    <a href="{{ route('ppq.lotes.create') }}" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nuevo PPQ</a>
                @endcan
            </div>
        </div>
    </x-slot>

    @php
        // Clases Tailwind completas y literales (claro y oscuro), para que no las purgue el build.
        $badge = [
            'borrador' => 'bg-gray-100 text-gray-700 dark:bg-ink-700 dark:text-paper-100',
            'listo' => 'bg-blue-100 text-blue-700 dark:bg-sky-900/40 dark:text-sky-300',
            'enviado' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
            'pagado' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
            'observado' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        ];
        $etiqueta = 'block text-xs font-medium text-gray-600 dark:text-paper-300';
        $control = 'mt-1 w-full rounded-md border-gray-300 dark:border-ink-500 dark:bg-ink-800 dark:text-paper-100 text-sm';
    @endphp
    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">{{ session('status') }}</div>
            @endif

            {{-- ---------- Filtros (todos opcionales) ---------- --}}
            <form method="GET" action="{{ route('ppq.lotes.index') }}"
                  class="mb-4 bg-white dark:bg-ink-800 shadow-sm ring-1 ring-gray-200 dark:ring-ink-600 sm:rounded-xl p-4">
                <fieldset>
                    <legend class="sr-only">Filtrar lotes</legend>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
                        <div>
                            <label for="f-q" class="{{ $etiqueta }}">Referencia o n.º de lote</label>
                            <input id="f-q" name="q" type="search" value="{{ $filtros['q'] }}" maxlength="60" placeholder="PPQ junio · #12"
                                   class="{{ $control }}">
                        </div>
                        <div>
                            <label for="f-cliente" class="{{ $etiqueta }}">Cliente</label>
                            <select id="f-cliente" name="cliente_id" class="{{ $control }}">
                                <option value="">Todos</option>
                                @foreach ($clientes as $c)
                                    <option value="{{ $c->id }}" @selected($filtros['cliente_id'] === (string) $c->id)>{{ $c->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="f-estado" class="{{ $etiqueta }}">Estado</label>
                            <select id="f-estado" name="estado" class="{{ $control }}">
                                <option value="">Todos</option>
                                @foreach ($estados as $e)
                                    <option value="{{ $e['value'] }}" @selected($filtros['estado'] === $e['value'])>{{ $e['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="f-desde" class="{{ $etiqueta }}">Fecha del lote desde</label>
                            <input id="f-desde" name="desde" type="date" value="{{ $filtros['desde'] }}" class="{{ $control }}">
                        </div>
                        <div>
                            <label for="f-hasta" class="{{ $etiqueta }}">hasta</label>
                            <input id="f-hasta" name="hasta" type="date" value="{{ $filtros['hasta'] }}" class="{{ $control }}">
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <button class="rounded-md bg-gray-800 dark:bg-ink-600 px-4 py-2 text-sm text-white hover:bg-gray-700 dark:hover:bg-ink-500">Filtrar</button>
                        @if ($hayFiltros)
                            <a href="{{ route('ppq.lotes.index') }}" class="text-sm text-indigo-600 dark:text-indigo-300 hover:underline">Quitar filtros</a>
                        @endif
                        <p class="text-sm text-gray-500 dark:text-paper-300 sm:ml-auto" aria-live="polite">
                            @if ($lotes->isNotEmpty())
                                Mostrando {{ $lotes->firstItem() }}–{{ $lotes->lastItem() }} de {{ $lotes->total() }} lote(s){{ $hayFiltros ? ' con los filtros aplicados' : '' }}
                            @else
                                {{ $lotes->total() }} lote(s){{ $hayFiltros ? ' con los filtros aplicados' : '' }}
                            @endif
                        </p>
                    </div>
                </fieldset>
            </form>

            <div class="bg-white dark:bg-ink-800 shadow-sm ring-1 ring-gray-200 dark:ring-ink-600 sm:rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">Lotes PPQ, página {{ $lotes->currentPage() }} de {{ $lotes->lastPage() }}</caption>
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-paper-300 bg-gray-50 dark:bg-ink-900 border-b border-gray-200 dark:border-ink-600">
                                <th scope="col" class="py-3 px-4">#</th>
                                <th scope="col" class="py-3 px-4">Referencia</th>
                                <th scope="col" class="py-3 px-4">Fecha</th>
                                <th scope="col" class="py-3 px-4">Cliente</th>
                                <th scope="col" class="py-3 px-4">Estado</th>
                                <th scope="col" class="py-3 px-4">Avance</th><th scope="col" class="py-3 px-4 text-center">Documentos</th>
                                <th scope="col" class="py-3 px-4 text-right">Total CCF/NC</th>
                                <th scope="col" class="py-3 px-4 text-right"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                            @forelse ($lotes as $lote)
                                @php($real = $estadosReales[$lote->id])
                                <tr class="hover:bg-gray-50 dark:hover:bg-ink-700">
                                    <td class="py-3 px-4 text-gray-400 dark:text-paper-500">{{ $lote->id }}</td>
                                    <td class="py-3 px-4 font-medium text-gray-800 dark:text-paper-100">
                                        <a href="{{ route('ppq.lotes.show', $lote) }}" class="hover:text-indigo-600 dark:hover:text-indigo-300">{{ $lote->referencia }}</a>
                                    </td>
                                    <td class="py-3 px-4 text-gray-600 dark:text-paper-300 whitespace-nowrap">{{ $lote->fecha->format('d/m/Y') }}</td>
                                    <td class="py-3 px-4 text-gray-600 dark:text-paper-300">{{ $lote->cliente?->nombre ?? $real['cliente_derivado']?->nombre ?? '—' }}</td>
                                    <td class="py-3 px-4">
                                        <span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-medium {{ $real['estado']['clase'] }}">{{ $real['estado']['label'] }}</span>
                                        @if ($real['estado']['key'] === 'presentado')
                                            <div class="text-xs text-gray-500 dark:text-paper-300">{{ $real['fecha']?->format('d/m/Y') }}</div>
                                        @endif
                                        @if ($real['devueltos'] > 0)
                                            <div class="text-xs text-gray-600 dark:text-paper-300">Devuelto: {{ $real['devueltos'] }}</div>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="text-xs text-gray-600 dark:text-paper-300">{{ $real['pagados'] }} de {{ $real['ccf_total'] }} pagados</div>
                                        <div class="mt-1 w-full rounded-full bg-gray-100 dark:bg-ink-700" role="progressbar" aria-label="CCF pagados" aria-valuenow="{{ $real['pagados'] }}" aria-valuemin="0" aria-valuemax="{{ max(1, $real['ccf_total']) }}">
                                            <div class="rounded-full bg-green-600 py-0.5" style="width: {{ $real['ccf_total'] > 0 ? round(100 * $real['pagados'] / $real['ccf_total'], 2) : 0 }}%"></div>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="inline-flex items-center justify-center min-w-[1.75rem] rounded-full bg-gray-100 dark:bg-ink-700 px-2 py-0.5 text-xs font-medium text-gray-700 dark:text-paper-100">{{ $lote->items_count }}</span>
                                    </td>
                                    @php($t = $real['total_neto'])
                                    <td class="py-3 px-4 text-right font-semibold text-gray-800 dark:text-paper-100 whitespace-nowrap">{{ ($t < 0 ? '−$' : '$').number_format(abs($t), 2) }}<div class="text-xs font-medium text-gray-500 dark:text-paper-300">Cobrado ${{ number_format($real['cobrado'], 2) }} · Pendiente ${{ number_format($real['pendiente'], 2) }}</div></td>
                                    <td class="py-3 px-4">
                                        <div class="flex items-center justify-end gap-3">
                                            <a href="{{ route('ppq.lotes.show', $lote) }}" class="text-indigo-600 dark:text-indigo-300 hover:underline">Ver<span class="sr-only"> el lote {{ $lote->referencia }}</span></a>
                                            @if ($lote->items_count > 0)
                                                <a href="{{ route('ppq.lotes.excel', $lote) }}" class="inline-flex items-center gap-1 rounded-md bg-green-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-700">
                                                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 2.75a.75.75 0 00-1.5 0v8.614L6.295 8.235a.75.75 0 10-1.09 1.03l4.25 4.5a.75.75 0 001.09 0l4.25-4.5a.75.75 0 00-1.09-1.03l-2.955 3.129V2.75z"/><path d="M3.5 12.75a.75.75 0 00-1.5 0v2.5A2.75 2.75 0 004.75 18h10.5A2.75 2.75 0 0018 15.25v-2.5a.75.75 0 00-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5z"/></svg>
                                                    Excel<span class="sr-only"> del lote {{ $lote->referencia }}</span>
                                                </a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="py-10 text-center text-gray-500 dark:text-paper-300">
                                    @if ($lotes->currentPage() > 1 && $lotes->total() > 0)
                                        Esta página no existe ({{ $lotes->total() }} lote(s) en {{ $lotes->lastPage() }} página(s)).
                                        <a href="{{ $lotes->url(1) }}" class="text-indigo-600 dark:text-indigo-300 hover:underline">Ir a la primera página</a>.
                                    @elseif ($hayFiltros)
                                        Ningún lote coincide con los filtros.
                                        <a href="{{ route('ppq.lotes.index') }}" class="text-indigo-600 dark:text-indigo-300 hover:underline">Quitar filtros</a>.
                                    @else
                                        No hay lotes todavía.@can('ppq.gestionar') <a href="{{ route('ppq.lotes.create') }}" class="text-indigo-600 dark:text-indigo-300 hover:underline">Creá el primero</a>.@endcan
                                    @endif
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($lotes->hasPages())
                    <nav class="px-4 py-3 border-t border-gray-100 dark:border-ink-600" aria-label="Páginas del historial PPQ">{{ $lotes->links() }}</nav>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
