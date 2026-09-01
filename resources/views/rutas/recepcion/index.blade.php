<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Recepción de CCF</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto space-y-6 px-4 sm:px-6 lg:px-8">

            <x-rutas.avisos />

            {{-- BUSCADOR. El campo lleva foco automático y el formulario es GET de un solo
                 campo: un lector de código de barras teclea el contenido y manda Enter, así
                 que esto ya funciona con escáner sin ninguna integración. --}}
            <form method="GET" action="{{ route('rutas.recepcion.index') }}"
                  class="bg-white p-5 shadow-sm ring-1 ring-gray-200 sm:rounded-xl dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <label for="q" class="block text-sm font-medium text-gray-700 dark:text-paper-200">
                    Escaneá o escribí el número del documento
                </label>
                <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                    <input type="text" name="q" id="q" value="{{ $q }}" autofocus autocomplete="off"
                           inputmode="text" enterkeyhint="search"
                           placeholder="N.º de control, código de generación, N.º del sistema o últimos dígitos"
                           class="w-full rounded-md border-gray-300 text-base sm:text-sm dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100 focus:border-indigo-500 focus:ring-indigo-500">
                    <button class="shrink-0 rounded-md bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 sm:py-2">
                        Buscar
                    </button>
                </div>
                <p class="mt-2 text-xs text-gray-400 dark:text-paper-500">
                    Recibir un documento anota que el CCF impreso, firmado y sellado, volvió a la empresa.
                    No registra ningún pago.
                </p>
            </form>

            {{-- RESULTADO --}}
            @if ($estado === 'sin_resultados')
                <div class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                    <p class="font-medium">No se encontró ningún documento con «{{ $q }}».</p>
                    <p class="mt-1">
                        Puede que el CCF no esté asignado a ninguna salida todavía. Revisalo en
                        <a href="{{ route('rutas.documentos.index') }}" class="underline">Documentos por cobrar</a>.
                    </p>
                </div>
            @endif

            @if ($estado === 'ambiguo')
                <div class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                    <p class="font-medium">Hay {{ $documentos->count() }} documentos que terminan en «{{ $q }}».</p>
                    <p class="mt-1">Elegí cuál tenés en la mano, o escribí el número de control completo.</p>
                </div>
            @endif

            @if ($documentos->isNotEmpty())
                <form method="POST" action="{{ route('rutas.recepcion.lote') }}" id="form-recepcion">
                    @csrf

                    <div class="space-y-3">
                        @foreach ($documentos as $documento)
                            @php
                                $yaRecibido = $documento->documentacionFisicaRecibida();
                                $custodia = $documento->estadoCustodia();
                                $tenedor = $documento->tenedorActual();
                                $responsable = $documento->salida?->participantes->firstWhere('rol', \App\Enums\RolEnSalida::Responsable);
                            @endphp

                            <div class="bg-white shadow-sm ring-1 {{ $yaRecibido ? 'ring-green-300 dark:ring-green-800' : 'ring-gray-200 dark:ring-ink-600' }} sm:rounded-xl overflow-hidden dark:bg-ink-800 dark:shadow-none">
                                <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-mono text-sm font-semibold text-gray-900 dark:text-paper-50">{{ $documento->numeroLegible() }}</span>
                                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $custodia->clase() }}">
                                                {{ $custodia->icono() }} {{ $custodia->label() }}
                                            </span>
                                            @if ($documento->entregado())
                                                <span class="rounded-full bg-green-100 px-2 py-0.5 text-[11px] font-medium text-green-700 dark:bg-green-900/40 dark:text-green-300">Entrega confirmada por albarán</span>
                                            @endif
                                        </div>

                                        <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                                            <div>
                                                <dt class="text-xs text-gray-500 dark:text-paper-400">Cliente</dt>
                                                <dd class="truncate text-gray-800 dark:text-paper-100">{{ $documento->clienteNombre() ?? '—' }}</dd>
                                            </div>
                                            <div>
                                                <dt class="text-xs text-gray-500 dark:text-paper-400">Sala</dt>
                                                <dd class="truncate text-gray-800 dark:text-paper-100">{{ $documento->salaNombre() ?? '—' }}</dd>
                                            </div>
                                            <div>
                                                <dt class="text-xs text-gray-500 dark:text-paper-400">Fecha</dt>
                                                <dd class="text-gray-800 dark:text-paper-100">{{ $documento->fecha()?->translatedFormat('d M Y') ?? '—' }}</dd>
                                            </div>
                                            <div>
                                                <dt class="text-xs text-gray-500 dark:text-paper-400">Monto</dt>
                                                <dd class="tabular-nums text-gray-800 dark:text-paper-100">
                                                    {{ $documento->monto() !== null ? '$'.number_format($documento->monto(), 2) : '—' }}
                                                </dd>
                                            </div>
                                            <div class="col-span-2">
                                                <dt class="text-xs text-gray-500 dark:text-paper-400">Salida</dt>
                                                <dd class="truncate text-gray-800 dark:text-paper-100">
                                                    @if ($documento->salida)
                                                        <a href="{{ route('rutas.salidas.show', $documento->salida) }}" class="hover:underline">{{ $documento->salida->descripcionCorta() }}</a>
                                                    @else
                                                        —
                                                    @endif
                                                </dd>
                                            </div>
                                            <div class="col-span-2">
                                                <dt class="text-xs text-gray-500 dark:text-paper-400">Lo tiene / responsable</dt>
                                                <dd class="truncate text-gray-800 dark:text-paper-100">
                                                    {{ $tenedor?->nombre ?? ($responsable?->personal?->nombre ? 'Responsable: '.$responsable->personal->nombre : '—') }}
                                                </dd>
                                            </div>
                                        </dl>

                                        @if ($yaRecibido)
                                            <p class="mt-3 rounded-md bg-green-50 px-3 py-2 text-xs text-green-800 dark:bg-green-900/20 dark:text-green-300">
                                                Ya recibido el {{ $documento->documentacion_fisica_recibida_at->translatedFormat('d M Y H:i') }}
                                                @if ($documento->documentacionRecibidaPor) por {{ $documento->documentacionRecibidaPor->name }} @endif.
                                                Para corregirlo, anulá el registro desde el detalle de la salida.
                                            </p>
                                        @endif
                                    </div>

                                    {{-- Acción individual: es el camino normal y por eso es un botón grande
                                         y aparte, no una casilla más. --}}
                                    @if (! $yaRecibido)
                                        <div class="shrink-0 sm:w-56">
                                            @can('rutas.recepcion')
                                                @if ($estado === 'ambiguo')
                                                    <label class="flex cursor-pointer items-center gap-2 rounded-md border border-gray-200 px-3 py-2 dark:border-ink-600">
                                                        <input type="checkbox" name="documentos[]" value="{{ $documento->id }}" form="form-recepcion"
                                                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-ink-600 dark:bg-ink-900">
                                                        <span class="text-sm text-gray-700 dark:text-paper-200">Marcar para recibir</span>
                                                    </label>
                                                @else
                                                    <button type="submit" form="form-uno-{{ $documento->id }}"
                                                            class="w-full rounded-md bg-green-600 px-4 py-3 text-sm font-semibold text-white hover:bg-green-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                                                        Recibir CCF firmado
                                                    </button>
                                                @endif
                                            @else
                                                <p class="text-xs text-gray-400 dark:text-paper-500">
                                                    No tenés permiso para registrar recepciones.
                                                </p>
                                            @endcan
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Confirmación del LOTE. Solo aparece cuando la búsqueda fue ambigua y hay
                         que elegir: una operación por lote tiene que mostrar antes exactamente
                         qué se va a confirmar, y eso es lo que hacen las tarjetas de arriba. --}}
                    @if ($estado === 'ambiguo')
                        @can('rutas.recepcion')
                            <div class="mt-4 rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                                <label for="observacion-lote" class="block text-sm font-medium text-gray-700 dark:text-paper-200">
                                    Observación <span class="font-normal text-gray-400 dark:text-paper-500">(opcional, se aplica a todos)</span>
                                </label>
                                <input type="text" name="observacion" id="observacion-lote" maxlength="500"
                                       class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100">
                                <button class="mt-3 rounded-md bg-green-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-green-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2">
                                    Recibir los documentos marcados
                                </button>
                            </div>
                        @endcan
                    @endif
                </form>

                {{-- Un formulario por documento para la acción individual. Van fuera del
                     formulario de lote para no anidarlos. --}}
                @can('rutas.recepcion')
                    @foreach ($documentos as $documento)
                        @if (! $documento->documentacionFisicaRecibida() && $estado !== 'ambiguo')
                            <form method="POST" action="{{ route('rutas.recepcion.recibir') }}" id="form-uno-{{ $documento->id }}" class="hidden">
                                @csrf
                                <input type="hidden" name="documento_id" value="{{ $documento->id }}">
                            </form>
                        @endif
                    @endforeach
                @endcan
            @endif

            {{-- Lo recibido hoy: deja ver avanzar la pila y detectar de inmediato un registro
                 hecho por error. --}}
            @if ($recibidosHoy->isNotEmpty())
                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                    <div class="border-b border-gray-200 px-5 py-3 dark:border-ink-600">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-paper-200">Recibidos hoy ({{ $recibidosHoy->count() }})</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200 dark:bg-ink-700 dark:text-paper-400 dark:border-ink-600">
                                    <th class="py-2.5 px-4">N.º de control</th>
                                    <th class="py-2.5 px-4">Hora</th>
                                    <th class="py-2.5 px-4">Quién</th>
                                    <th class="py-2.5 px-4">Salida</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                                @foreach ($recibidosHoy as $documento)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-ink-700">
                                        <td class="py-2 px-4 font-mono text-xs text-gray-700 dark:text-paper-200">{{ $documento->numeroLegible() }}</td>
                                        <td class="py-2 px-4 tabular-nums text-gray-600 dark:text-paper-300">{{ $documento->documentacion_fisica_recibida_at->format('H:i') }}</td>
                                        <td class="py-2 px-4 text-gray-600 dark:text-paper-300">{{ $documento->documentacionRecibidaPor?->name ?? '—' }}</td>
                                        <td class="py-2 px-4 text-gray-600 dark:text-paper-300">{{ $documento->salida?->ruta?->nombre ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
