<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-paper-100 leading-tight">
                Solicitud de quedan <span class="font-mono break-all">{{ $solicitud->referencia }}</span>
            </h2>
            <a href="{{ route('cobros.index', ['cliente_id' => $solicitud->cliente_id]) }}#solicitudes"
               class="text-sm font-medium text-indigo-600 hover:text-indigo-800">← Cobros</a>
        </div>
    </x-slot>

    @php
        $gris = 'text-gray-500 dark:text-paper-300';
        $texto = 'text-gray-800 dark:text-paper-100';
    @endphp

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <p class="rounded-md bg-blue-50 border border-blue-200 p-3 text-sm text-blue-700">
                Ficha de solo lectura. Cada bloque es un hecho distinto: que el archivo exista o se haya descargado
                <strong>no significa que se presentó</strong>; la presentación la declara una persona y el acuse lo da el cliente.
            </p>

            {{-- ---------- Resumen y corrección ---------- --}}
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-4 text-sm">
                    <div>
                        <dt class="text-xs {{ $gris }}">Cliente</dt>
                        <dd class="{{ $texto }}">{{ $solicitud->cliente?->nombre }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs {{ $gris }}">Generada</dt>
                        <dd class="{{ $texto }}">
                            {{ $solicitud->created_at?->format('d/m/Y H:i') }}
                            @if ($solicitud->usuario)
                                <span class="{{ $gris }}">· {{ $solicitud->usuario->name }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs {{ $gris }}">Estado</dt>
                        <dd>
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $solicitud->estado->clase() }}"
                                  title="{{ $solicitud->estado->detalle() }}">{{ $solicitud->estado->label() }}</span>
                            <span class="block text-xs {{ $gris }}">{{ $solicitud->estado->detalle() }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs {{ $gris }}">Documentos · total</dt>
                        <dd class="font-mono {{ $texto }}">{{ $solicitud->items_count }} · {{ number_format((float) $total, 2) }}</dd>
                        <dd class="text-xs {{ $gris }}">Con los importes congelados; las NC restan.</dd>
                    </div>
                </dl>

                @if ($solicitud->corrigeA || $solicitud->correcciones->isNotEmpty())
                    <div class="mt-4 space-y-1 text-sm">
                        @if ($solicitud->corrigeA)
                            <p class="{{ $texto }}">
                                Reenvío que corrige a
                                <a href="{{ route('cobros.solicitudes.show', $solicitud->corrigeA) }}" class="font-mono text-indigo-600 hover:underline">{{ $solicitud->corrigeA->referencia }}</a>.
                                @if ($solicitud->motivo_correccion)
                                    <span class="{{ $gris }}">Motivo: {{ $solicitud->motivo_correccion }}</span>
                                @endif
                            </p>
                        @endif
                        @foreach ($solicitud->correcciones as $correccion)
                            <p class="text-rose-700">
                                Reemplazada por
                                <a href="{{ route('cobros.solicitudes.show', $correccion) }}" class="font-mono text-indigo-600 hover:underline">{{ $correccion->referencia }}</a>
                                ({{ $correccion->created_at?->format('d/m/Y H:i') }}). Se conserva como constancia de lo que se entregó.
                            </p>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- ---------- Archivo preparado y descargas ---------- --}}
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-2">
                    <h3 class="font-medium text-gray-700 dark:text-paper-100">Archivo preparado y descargas</h3>
                    <form method="POST" action="{{ route('cobros.solicitudes.descargar', $solicitud) }}" class="inline">
                        @csrf
                        <button type="submit"
                                class="inline-flex rounded-md bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700">
                            Descargar el archivo original<span class="sr-only"> de {{ $solicitud->referencia }}</span>
                        </button>
                    </form>
                </div>
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-3 text-sm">
                    <div>
                        <dt class="text-xs {{ $gris }}">Archivo</dt>
                        <dd class="font-mono break-all {{ $texto }}">{{ $solicitud->archivo_nombre }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs {{ $gris }}">Copia archivada</dt>
                        <dd class="{{ $texto }}">
                            @if ($solicitud->archivo_hash)
                                <span class="block font-mono text-xs break-all">SHA-256 {{ $solicitud->archivo_hash }}</span>
                            @else
                                Todavía no: la primera descarga la guardará.
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs {{ $gris }}">Primera descarga · contador</dt>
                        <dd class="{{ $texto }}">{{ $solicitud->descargada_en?->format('d/m/Y H:i') ?? '—' }} · {{ $solicitud->descargas }}</dd>
                    </div>
                </dl>

                <h4 class="mt-5 text-sm font-medium text-gray-700 dark:text-paper-100">Descargas preparadas</h4>
                <p class="text-xs {{ $gris }} mb-2">Cada fila es una descarga que el sistema preparó con el archivo verificado. No prueba que el navegador la haya terminado ni que el archivo se haya presentado.</p>

                @if ($descargas->isEmpty() && $descargas->currentPage() > 1)
                    <p class="text-sm text-amber-700" role="status">Esta página no existe. <a href="{{ $descargas->url(1) }}" class="font-medium text-indigo-600 hover:underline">Ir a la primera</a>.</p>
                @elseif ($descargas->isEmpty())
                    <p class="text-sm {{ $gris }}">Sin descargas registradas.</p>
                @else
                    <ul class="divide-y divide-gray-100 dark:divide-ink-600 text-sm">
                        @foreach ($descargas as $d)
                            <li class="py-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                <span class="whitespace-nowrap {{ $texto }}">{{ $d->created_at?->format('d/m/Y H:i:s') }}</span>
                                <span class="text-gray-600 dark:text-paper-300">{{ $d->causer?->name ?? 'Usuario no disponible' }}</span>
                                <span class="font-mono text-xs break-all {{ $gris }}">{{ $d->getExtraProperty('archivo') }} · SHA-256 {{ $d->getExtraProperty('sha256') }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($descargas->hasPages())
                        <nav class="mt-3" aria-label="Páginas de descargas preparadas">{{ $descargas->links() }}</nav>
                    @endif
                @endif

                {{-- El contador viejo no dice quién ni cuándo: se informa la diferencia, sin inventar filas. --}}
                @if ($solicitud->descargas > $descargasRegistradas)
                    <p class="mt-2 text-xs text-amber-700">{{ $solicitud->descargas - $descargasRegistradas }} descarga(s) anterior(es) a este registro solo quedaron en el contador, sin usuario ni hora.</p>
                @endif
            </div>

            {{-- ---------- Presentación declarada ---------- --}}
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-2">Presentación declarada</h3>
                @if ($solicitud->presentada_en)
                    <p class="text-sm {{ $texto }}">{{ $solicitud->presentadaPor?->name ?? 'Usuario no disponible' }} declaró haberla subido al portal el {{ $solicitud->presentada_en->format('d/m/Y') }}.</p>
                    @if ($solicitud->presentada_nota)
                        <p class="mt-1 text-sm {{ $gris }}">Nota: {{ $solicitud->presentada_nota }}</p>
                    @endif
                @else
                    <p class="text-sm {{ $gris }}">Nadie declaró haberla presentado.</p>
                @endif
            </div>

            {{-- ---------- Acuse del cliente ---------- --}}
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-2">Acuse del cliente</h3>
                @if ($solicitud->referencia_calleja)
                    <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-3 text-sm">
                        <div>
                            <dt class="text-xs {{ $gris }}">Referencia del cliente</dt>
                            <dd class="font-mono {{ $texto }}">{{ $solicitud->referencia_calleja }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs {{ $gris }}">Recibida</dt>
                            <dd class="{{ $texto }}">{{ $solicitud->recibida_en?->format('d/m/Y H:i') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs {{ $gris }}">Pago programado</dt>
                            <dd class="{{ $texto }}">{{ $solicitud->fecha_programada_pago?->format('d/m/Y') ?? 'No informado' }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="text-sm {{ $gris }}">Sin acuse registrado.</p>
                @endif

                <h4 class="mt-5 text-sm font-medium text-gray-700 dark:text-paper-100">Correos asociados a esta solicitud</h4>
                <p class="text-xs {{ $gris }} mb-2">Solo los que quedaron ligados a esta solicitud al leerse el buzón, con su estado y motivo.</p>
                @if ($correos->isEmpty())
                    <p class="text-sm {{ $gris }}">Ningún correo asociado.</p>
                @else
                    <ul class="divide-y divide-gray-100 dark:divide-ink-600 text-sm">
                        @foreach ($correos as $correo)
                            <li class="py-2">
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                    <span class="whitespace-nowrap {{ $texto }}">{{ $correo->fecha_mensaje?->format('d/m/Y H:i') ?? '—' }}</span>
                                    <span class="text-xs {{ $gris }}">{{ $correo->tipo }}</span>
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] {{ $correo->estado === 'asociado' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-800' }}">{{ $correo->estado }}</span>
                                </div>
                                <p class="text-xs break-words {{ $texto }}">{{ \Illuminate\Support\Str::limit((string) $correo->asunto, 120) }}</p>
                                @if ($correo->motivo)
                                    <p class="text-xs {{ $gris }}">{{ $correo->motivo }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- ---------- Ajustes QD ---------- --}}
            @if ($ajustes->isNotEmpty())
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                    <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-1">Ajustes informados por el cliente (QD)</h3>
                    <p class="text-xs {{ $gris }} mb-2">Ligados a esta solicitud. No se reparten entre sus documentos.</p>
                    <ul class="divide-y divide-gray-100 dark:divide-ink-600 text-sm">
                        @foreach ($ajustes as $ajuste)
                            <li><x-cobros.ajuste :ajuste="$ajuste" /></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- ---------- NC congeladas: qué notas, en qué lote ya cargado, respaldaron cada CCF ---------- --}}
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-1">Notas de crédito que respaldaron esta solicitud</h3>
                <p class="text-xs {{ $gris }} mb-3">
                    Congeladas al prepararla: cada NC aceptada de sus CCF y el archivo de NC cuya carga al portal estaba registrada.
                    Si la nota cambió después, esta lista sigue diciendo con qué se preparó.
                </p>
                @if ($notasCongeladas->isEmpty())
                    <p class="text-sm {{ $gris }}">
                        No hay notas congeladas: sus CCF no tenían NC aceptadas relacionadas en este sistema, eran externos
                        (sin verificar) o la solicitud es anterior a este control.
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Notas de crédito congeladas de la solicitud {{ $solicitud->referencia }}</caption>
                            <thead class="bg-gray-50 dark:bg-ink-700 text-gray-600 dark:text-paper-300">
                                <tr>
                                    <th scope="col" class="p-2 text-left font-medium">CCF</th>
                                    <th scope="col" class="p-2 text-left font-medium">Nota de crédito</th>
                                    <th scope="col" class="p-2 text-left font-medium">Albarán propio</th>
                                    <th scope="col" class="p-2 text-right font-medium">Importe hoy</th>
                                    <th scope="col" class="p-2 text-left font-medium">Archivo de NC</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                                @foreach ($notasCongeladas as $vinculo)
                                    <tr>
                                        <td class="p-2 font-mono whitespace-nowrap">{{ $vinculo->documento?->numero_control ?? 'Documento no disponible' }}</td>
                                        <td class="p-2 font-mono whitespace-nowrap">{{ $vinculo->dte?->numero_control ?? 'Nota no disponible' }}</td>
                                        <td class="p-2 font-mono text-xs">{{ $vinculo->dte?->albaran?->numero_canonico ?? '—' }}</td>
                                        <td class="p-2 text-right font-mono">{{ $vinculo->dte ? number_format((float) $vinculo->dte->total_pagar, 2) : '—' }}</td>
                                        <td class="p-2 text-xs">
                                            @if ($vinculo->exportacion)
                                                <a href="{{ route('ppq.nc-exportaciones.show', $vinculo->exportacion) }}" class="font-mono text-indigo-600 hover:underline">{{ $vinculo->exportacion->referencia }}</a>
                                                @if ($vinculo->exportacion->presentada_en)
                                                    <span class="block {{ $gris }}">
                                                        cargado el {{ $vinculo->exportacion->presentada_en->format('d/m/Y') }}
                                                        · {{ $vinculo->exportacion->presentadaPor?->name ?? 'usuario no disponible' }}
                                                        @if ($vinculo->exportacion->referencia_portal)
                                                            · ref. {{ $vinculo->exportacion->referencia_portal }}
                                                        @endif
                                                    </span>
                                                @endif
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ---------- Renglones congelados ---------- --}}
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <div class="flex flex-wrap items-baseline justify-between gap-2 mb-1">
                    <h3 class="font-medium text-gray-700 dark:text-paper-100">Renglones del archivo</h3>
                    @if ($renglones->isNotEmpty())
                        <p class="text-sm {{ $gris }}">Mostrando {{ $renglones->firstItem() }}–{{ $renglones->lastItem() }} de {{ $renglones->total() }}</p>
                    @endif
                </div>
                <p class="text-xs {{ $gris }} mb-3">
                    Sala, albarán, año, mes, tipo e importe son los datos <strong>congelados</strong> con que se escribió el archivo.
                    Las columnas «Hoy» muestran el documento tal como está ahora: su pago viene de los pagos registrados y sus
                    observaciones son del documento, no necesariamente de esta solicitud.
                </p>

                @if ($renglones->isEmpty() && $renglones->currentPage() > 1)
                    <p class="text-sm text-amber-700" role="status">Esta página no existe. <a href="{{ $renglones->url(1) }}" class="font-medium text-indigo-600 hover:underline">Ir a la primera</a>.</p>
                @elseif ($renglones->isEmpty())
                    <p class="text-sm {{ $gris }}">La solicitud no tiene renglones.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Renglones de la solicitud {{ $solicitud->referencia }}</caption>
                            <thead class="bg-gray-50 dark:bg-ink-700 text-gray-600 dark:text-paper-300">
                                <tr>
                                    <th scope="col" class="p-2 text-right font-medium">#</th>
                                    <th scope="col" class="p-2 text-left font-medium">Documento</th>
                                    <th scope="col" class="p-2 text-left font-medium">Sala</th>
                                    <th scope="col" class="p-2 text-left font-medium">Albarán</th>
                                    <th scope="col" class="p-2 text-left font-medium">Año/mes</th>
                                    <th scope="col" class="p-2 text-left font-medium">Tipo</th>
                                    <th scope="col" class="p-2 text-right font-medium">Importe</th>
                                    <th scope="col" class="p-2 text-left font-medium">Hoy: pago</th>
                                    <th scope="col" class="p-2 text-left font-medium">Hoy: notas</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                                @foreach ($renglones as $r)
                                    @php $doc = $r->documento; @endphp
                                    <tr>
                                        <td class="p-2 text-right font-mono {{ $gris }}">{{ $r->orden }}</td>
                                        <td class="p-2 font-mono whitespace-nowrap">
                                            @if ($doc)
                                                <a href="{{ route('cobros.documentos.show', $doc) }}" class="text-indigo-600 hover:underline">{{ $doc->numero_control }}</a>
                                                @if ((int) $doc->cobro_solicitud_id !== (int) $solicitud->id)
                                                    <span class="block font-sans text-[11px] text-rose-700">ya no cuelga de esta solicitud</span>
                                                @endif
                                                @if ($doc->dte_id === null)
                                                    <span class="block font-sans text-[11px] text-amber-700">CCF externo: sus NC no se verificaron</span>
                                                @endif
                                            @else
                                                <span class="{{ $gris }}">Documento no disponible</span>
                                            @endif
                                        </td>
                                        <td class="p-2 font-mono">{{ $r->sala_codigo }}</td>
                                        <td class="p-2 font-mono">{{ $r->albaran_numero }}</td>
                                        <td class="p-2 font-mono whitespace-nowrap">{{ $r->albaran_anio }}/{{ $r->albaran_mes }}</td>
                                        <td class="p-2 font-mono">{{ $r->albaran_tipo }}</td>
                                        <td class="p-2 text-right font-mono">{{ number_format((float) $r->monto, 2) }}</td>
                                        <td class="p-2 text-xs">
                                            @if ($doc)
                                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] {{ $doc->pago_estado->clase() }}">{{ $doc->pago_estado->label() }}</span>
                                                @if ((float) $doc->monto_pagado > 0)
                                                    <span class="block font-mono {{ $gris }}">cobrado {{ number_format((float) $doc->monto_pagado, 2) }}</span>
                                                @endif
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="p-2 text-xs {{ $gris }}">
                                            @if ($doc && $doc->observaciones_cliente_count > 0)
                                                <span class="block">{{ $doc->observaciones_cliente_count }} observación(es) del cliente</span>
                                            @endif
                                            @if ($doc?->observaciones)
                                                <span class="block">{{ \Illuminate\Support\Str::limit($doc->observaciones, 80) }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($renglones->hasPages())
                        <nav class="mt-4" aria-label="Páginas de renglones">{{ $renglones->links() }}</nav>
                    @endif
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
