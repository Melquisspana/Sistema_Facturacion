<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-paper-100 leading-tight">
                Archivo de notas de crédito <span class="font-mono">{{ $lote->referencia }}</span>
            </h2>
            <a href="{{ route('ppq.nc-exportaciones.index', ['cliente_id' => $lote->cliente_id]) }}"
               class="text-sm font-medium text-indigo-600 hover:text-indigo-800">← Formato de notas de crédito</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700" role="alert">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700" role="alert">
                    <ul class="list-disc pl-5">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            {{-- ---------- Resumen del archivo ---------- --}}
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-4 text-sm">
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-paper-300">Archivo</dt>
                        <dd class="font-mono text-gray-800 dark:text-paper-100 break-all">{{ $lote->archivo_nombre }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-paper-300">Cliente</dt>
                        <dd class="text-gray-800 dark:text-paper-100">{{ $lote->cliente?->nombre }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-paper-300">Generado</dt>
                        <dd class="text-gray-800 dark:text-paper-100">
                            {{ $lote->created_at?->format('d/m/Y H:i') }}
                            @if ($lote->usuario)
                                <span class="text-gray-500 dark:text-paper-300">· {{ $lote->usuario->name }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-paper-300">Formato</dt>
                        <dd class="text-gray-800 dark:text-paper-100">{{ \App\Services\Ppq\Exportadores\ExportadorNcFactory::etiqueta($lote->formato) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-paper-300">Notas de crédito</dt>
                        <dd class="font-mono text-gray-800 dark:text-paper-100">{{ $lote->items_count }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-paper-300">Total NC <span class="sr-only">(suma del total a pagar de las notas)</span></dt>
                        <dd class="font-mono text-lg font-semibold text-gray-900 dark:text-paper-100">{{ number_format((float) $lote->total_notas, 2) }}</dd>
                        <dd class="text-xs text-gray-500 dark:text-paper-300">Suma del total a pagar de las notas del lote</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-paper-300">Estado</dt>
                        <dd>
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $lote->estado->clase() }}"
                                  title="{{ $lote->estado->detalle() }}">{{ $lote->estado->label() }}</span>
                            @if ($lote->descargado_en)
                                <span class="block text-xs text-gray-500 dark:text-paper-300">Primera descarga {{ $lote->descargado_en->format('d/m/Y H:i') }}</span>
                            @endif
                        </dd>
                    </div>
                    <div class="flex items-end">
                        <form method="POST" action="{{ route('ppq.nc-exportaciones.descargar', $lote) }}">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center rounded-md bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700">
                                Descargar Excel<span class="sr-only"> del archivo {{ $lote->referencia }}</span>
                            </button>
                        </form>
                    </div>
                </dl>

                @if ($lote->dtes_count !== $lote->items_count)
                    {{-- Un renglón cuyo documento ya no se puede leer: se dice, no se esconde. --}}
                    <p class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800" role="alert">
                        {{ $lote->items_count - $lote->dtes_count }} renglón(es) del lote no tienen su nota disponible; el total solo suma las que sí.
                    </p>
                @endif

                {{-- Copia archivada: qué se sirve al descargar, y de dónde salió. --}}
                <div class="mt-4 text-sm">
                    <h4 class="text-xs text-gray-500 dark:text-paper-300">Copia del archivo</h4>
                    @if ($lote->tieneCopiaArchivada() && ! $lote->registroDeCopiaCompleto())
                        {{-- No se adivina la procedencia de un registro a medias. --}}
                        <p class="mt-1 rounded-md border border-red-200 bg-red-50 p-3 text-red-700" role="alert">
                            <strong>Registro de la copia incompleto.</strong> Falta la huella, la ruta, la procedencia o la
                            fecha de archivado, así que no se sabe qué se archivó ni de dónde salió. La descarga queda
                            bloqueada hasta revisarlo; no se regenera.
                        </p>
                    @elseif ($lote->archivo_origen === \App\Enums\ProcedenciaArchivoNc::Reconstruccion)
                        <p class="mt-1 rounded-md border border-amber-200 bg-amber-50 p-3 text-amber-800">
                            <strong>{{ $lote->archivo_origen->label() }}</strong>
                            ({{ $lote->archivado_en?->format('d/m/Y H:i') }}). {{ $lote->archivo_origen->detalle() }}
                        </p>
                    @elseif ($lote->archivo_origen)
                        <p class="mt-1 text-gray-700 dark:text-paper-100">
                            {{ $lote->archivo_origen->label() }} ({{ $lote->archivado_en?->format('d/m/Y H:i') }}).
                            <span class="text-gray-500 dark:text-paper-300">{{ $lote->archivo_origen->detalle() }}</span>
                        </p>
                    @elseif ($lote->descargadoSinCopia())
                        <p class="mt-1 rounded-md border border-amber-200 bg-amber-50 p-3 text-amber-800">
                            Este archivo se descargó antes de que existiera la copia archivada. La próxima descarga lo
                            <strong>reconstruirá</strong> desde sus notas y lo guardará como reconstrucción histórica: no prueba
                            cuál fue el archivo descargado entonces.
                        </p>
                    @else
                        <p class="mt-1 text-gray-500 dark:text-paper-300">Todavía no se descargó: la primera descarga guardará la copia.</p>
                    @endif
                    @if ($lote->archivo_hash)
                        <p class="mt-1 font-mono text-xs text-gray-500 dark:text-paper-300 break-all">SHA-256 {{ $lote->archivo_hash }}</p>
                    @endif
                </div>

                <p class="mt-4 text-xs text-gray-500 dark:text-paper-300">
                    Estas son las notas que entraron en el archivo al crearlo; no se vuelven a elegir. Descargarlo no agrega
                    notas ni marca el archivo como entregado.
                </p>
            </div>

            {{-- ---------- Carga al portal: un hecho aparte de la descarga ---------- --}}
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-1">Carga al portal</h3>
                <p class="text-sm text-gray-500 dark:text-paper-300 mb-3">
                    En el portal de Calleja se sube primero este archivo de NC y después el de quedan de los mismos CCF.
                    El sistema no sube nada: la carga la declara una persona. Descargar el archivo no la registra, y
                    registrarla no cambia el estado fiscal de las notas.
                </p>

                @if ($lote->presentada())
                    <p class="text-sm text-gray-800 dark:text-paper-100">
                        {{ $lote->presentadaPor?->name ?? 'Usuario no disponible' }} declaró haberlo cargado al portal el
                        {{ $lote->presentada_en->format('d/m/Y') }}.
                    </p>
                    @if ($lote->referencia_portal)
                        <p class="mt-1 text-sm text-gray-500 dark:text-paper-300">Referencia del portal: <span class="font-mono">{{ $lote->referencia_portal }}</span></p>
                    @endif
                    @if ($lote->presentada_nota)
                        <p class="mt-1 text-sm text-gray-500 dark:text-paper-300">Nota: {{ $lote->presentada_nota }}</p>
                    @endif
                @else
                    <p class="text-sm text-amber-700">Nadie registró haber cargado este archivo al portal.</p>

                    @can('ppq.gestionar')
                        @if ($lote->descargadoAlgunaVez())
                            <form method="POST" action="{{ route('ppq.nc-exportaciones.presentar', $lote) }}" class="mt-4 grid grid-cols-1 sm:grid-cols-4 gap-3 items-end text-sm">
                                @csrf
                                <div>
                                    <label for="presentada_en" class="block text-xs text-gray-500 dark:text-paper-300">Fecha de carga</label>
                                    <input id="presentada_en" type="date" name="presentada_en" value="{{ old('presentada_en', now()->toDateString()) }}" max="{{ now()->toDateString() }}"
                                           class="mt-1 w-full rounded-md border-gray-300 text-sm dark:bg-ink-700 dark:border-ink-600 dark:text-paper-100">
                                </div>
                                <div>
                                    <label for="referencia_portal" class="block text-xs text-gray-500 dark:text-paper-300">Referencia del portal (opcional)</label>
                                    <input id="referencia_portal" type="text" name="referencia_portal" maxlength="60" value="{{ old('referencia_portal') }}"
                                           class="mt-1 w-full rounded-md border-gray-300 text-sm dark:bg-ink-700 dark:border-ink-600 dark:text-paper-100">
                                </div>
                                <div>
                                    <label for="nota" class="block text-xs text-gray-500 dark:text-paper-300">Nota (opcional)</label>
                                    <input id="nota" type="text" name="nota" maxlength="255" value="{{ old('nota') }}"
                                           class="mt-1 w-full rounded-md border-gray-300 text-sm dark:bg-ink-700 dark:border-ink-600 dark:text-paper-100">
                                </div>
                                <div>
                                    <button class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                        Registrar carga al portal
                                    </button>
                                </div>
                            </form>
                        @else
                            <p class="mt-2 text-sm text-gray-500 dark:text-paper-300">Descargue el archivo y súbalo al portal; después podrá registrar la carga aquí.</p>
                        @endif
                    @endcan
                @endif
            </div>

            {{-- ---------- Descargas preparadas ---------- --}}
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-1">Descargas preparadas</h3>
                <p class="text-sm text-gray-500 dark:text-paper-300 mb-3">Cada fila es una descarga que el sistema preparó con el archivo verificado. No prueba que el navegador la haya terminado ni que el archivo se entregara al cliente.</p>

                @if ($descargas->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-paper-300">Sin descargas registradas.</p>
                @else
                    <ul class="divide-y divide-gray-100 dark:divide-ink-600 text-sm">
                        @foreach ($descargas as $d)
                            <li class="py-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                <span class="text-gray-800 dark:text-paper-100 whitespace-nowrap">{{ $d->created_at?->format('d/m/Y H:i:s') }}</span>
                                <span class="text-gray-600 dark:text-paper-300">{{ $d->causer?->name ?? 'Usuario no disponible' }}</span>
                                <span class="font-mono text-xs text-gray-500 dark:text-paper-300 break-all">{{ $d->getExtraProperty('archivo') }} · SHA-256 {{ $d->getExtraProperty('sha256') }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($descargasRegistradas > $descargas->count())
                        <p class="mt-2 text-xs text-gray-500 dark:text-paper-300">Se muestran las {{ $descargas->count() }} más recientes de {{ $descargasRegistradas }}.</p>
                    @endif
                @endif

                {{-- El contador viejo no dice quién ni cuándo: se informa la diferencia, sin inventar filas. --}}
                @if ($lote->descargas > $descargasRegistradas)
                    <p class="mt-2 text-xs text-amber-700">{{ $lote->descargas - $descargasRegistradas }} descarga(s) anterior(es) a este registro solo quedaron en el contador, sin usuario ni hora.</p>
                @endif
            </div>

            {{-- ---------- Notas del lote ---------- --}}
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
                    <h3 class="font-medium text-gray-700 dark:text-paper-100">Notas incluidas</h3>
                    @if ($items->total() > 0 && $items->isNotEmpty())
                        <p class="text-sm text-gray-500 dark:text-paper-300">Mostrando {{ $items->firstItem() }}–{{ $items->lastItem() }} de {{ $items->total() }}</p>
                    @endif
                </div>

                @if ($items->isEmpty() && $items->currentPage() > 1)
                    <p class="text-sm text-amber-700" role="status">
                        Esta página no existe. <a href="{{ $items->url(1) }}" class="font-medium text-indigo-600 hover:underline">Ir a la primera página</a>.
                    </p>
                @elseif ($items->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-paper-300">Este lote no tiene notas.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Notas de crédito del archivo {{ $lote->referencia }}</caption>
                            <thead class="bg-gray-50 text-gray-600 dark:text-paper-300">
                                <tr>
                                    <th scope="col" class="p-2 text-right font-medium">#</th>
                                    <th scope="col" class="p-2 text-left font-medium">Número de control</th>
                                    <th scope="col" class="p-2 text-left font-medium">Emitida</th>
                                    <th scope="col" class="p-2 text-left font-medium">Albarán</th>
                                    <th scope="col" class="p-2 text-right font-medium">Total nota</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                                @foreach ($items as $item)
                                    <tr>
                                        <td class="p-2 text-right font-mono text-gray-500 dark:text-paper-300">{{ $item->orden }}</td>
                                        <td class="p-2 font-mono whitespace-nowrap">{{ $item->dte?->numero_control ?? 'Nota no disponible' }}</td>
                                        <td class="p-2 whitespace-nowrap">{{ $item->dte?->fecha_emision?->format('d/m/Y') ?? '—' }}</td>
                                        <td class="p-2 font-mono">{{ $item->dte?->albaran?->numero_canonico ?? '—' }}</td>
                                        <td class="p-2 text-right font-mono">{{ $item->dte ? number_format((float) $item->dte->total_pagar, 2) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($items->hasPages())
                        <nav class="mt-4" aria-label="Páginas de notas del archivo">{{ $items->links() }}</nav>
                    @endif
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
