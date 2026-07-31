<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">
                Recepción #{{ $recepcion->numero }}
                <x-planta.badge :color="$recepcion->estado->color()" class="ms-2">{{ $recepcion->estado->label() }}</x-planta.badge>
            </h2>
            <a href="{{ route('planta.recepciones.index') }}"
               class="text-sm text-gray-500 hover:underline dark:text-paper-400">Volver al listado</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-planta.avisos />

            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                    <ul class="list-disc ps-5 space-y-0.5">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            {{-- Relación con su reversión, en cualquiera de los dos sentidos. --}}
            @if ($recepcion->esReversion())
                <div class="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                    Este documento es la REVERSIÓN de la recepción
                    <a href="{{ route('planta.recepciones.show', $recepcion->reversion_de_id) }}" class="font-semibold underline">#{{ $recepcion->reversionDe?->numero }}</a>.
                    Sus movimientos son negativos y compensan los del original, que se conserva intacto.
                </div>
            @elseif ($recepcion->revertido_por_id)
                <div class="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                    Reversada por el documento
                    <a href="{{ route('planta.recepciones.show', $recepcion->revertido_por_id) }}" class="font-semibold underline">#{{ $recepcion->revertidoPor?->numero }}</a>.
                </div>
            @endif

            {{-- Cabecera --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 dark:bg-ink-800 dark:ring-ink-600">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4 text-sm">
                    @php
                        $dt = 'text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-paper-400';
                        $dd = 'mt-0.5 text-gray-800 dark:text-paper-100';
                    @endphp
                    <div><dt class="{{ $dt }}">Fecha</dt><dd class="{{ $dd }}">{{ $recepcion->fecha->format('d/m/Y') }}</dd></div>
                    <div><dt class="{{ $dt }}">Proveedor</dt><dd class="{{ $dd }}">{{ $recepcion->proveedor?->nombre ?? '—' }}</dd></div>
                    <div><dt class="{{ $dt }}">Ubicación</dt><dd class="{{ $dd }}">{{ $recepcion->ubicacion?->codigo }} — {{ $recepcion->ubicacion?->nombre }}</dd></div>
                    <div><dt class="{{ $dt }}">Documento de referencia</dt><dd class="{{ $dd }}">{{ $recepcion->documento_referencia ?? '—' }}</dd></div>
                    <div><dt class="{{ $dt }}">Responsable</dt><dd class="{{ $dd }}">{{ $recepcion->responsable_nombre ?? $recepcion->responsable?->name ?? '—' }}</dd></div>
                    <div><dt class="{{ $dt }}">Creado por</dt><dd class="{{ $dd }}">{{ $recepcion->creadoPor?->name ?? '—' }}</dd></div>
                    <div><dt class="{{ $dt }}">Confirmado por</dt><dd class="{{ $dd }}">{{ $recepcion->confirmadoPor?->name ?? '—' }}</dd></div>
                    <div><dt class="{{ $dt }}">Confirmado el</dt><dd class="{{ $dd }}">{{ $recepcion->confirmado_en?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                    @if ($recepcion->observaciones)
                        <div class="sm:col-span-2 lg:col-span-4">
                            <dt class="{{ $dt }}">{{ $recepcion->esReversion() ? 'Motivo de la reversión' : 'Observaciones' }}</dt>
                            <dd class="{{ $dd }}">{{ $recepcion->observaciones }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            {{-- Líneas --}}
            <div class="overflow-x-auto bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl dark:bg-ink-800 dark:ring-ink-600">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-ink-600">
                    <thead class="bg-gray-50 dark:bg-ink-900">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-paper-400">
                            <th class="px-4 py-3">Insumo</th>
                            <th class="px-4 py-3 text-right">Recibido</th>
                            <th class="px-4 py-3">Unidad</th>
                            <th class="px-4 py-3 text-right">Contenido</th>
                            <th class="px-4 py-3 text-right">Factor</th>
                            <th class="px-4 py-3 text-right">Entra</th>
                            <th class="px-4 py-3">Lote</th>
                            <th class="px-4 py-3">Destino</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm dark:divide-ink-700">
                        @foreach ($recepcion->detalles as $detalle)
                            <tr class="text-gray-700 dark:text-paper-200">
                                <td class="px-4 py-3">{{ $detalle->insumo?->codigo }} — {{ $detalle->insumo?->nombre }}</td>
                                <td class="px-4 py-3 text-right">{{ $detalle->cantidad_recibida }}</td>
                                <td class="px-4 py-3">{{ $detalle->unidad_recibida }}</td>
                                <td class="px-4 py-3 text-right">{{ $detalle->contenido_por_unidad }}</td>
                                <td class="px-4 py-3 text-right">{{ $detalle->factor_conversion }}</td>
                                <td class="px-4 py-3 text-right font-semibold">
                                    {{ $detalle->cantidad_base }}
                                    <span class="text-xs font-normal text-gray-500 dark:text-paper-400">{{ $detalle->unidad_base?->abreviatura() }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    {{ $detalle->lote?->codigo_interno ?? '—' }}
                                    @if ($detalle->lote_codigo_proveedor)
                                        <span class="block text-[11px] text-gray-500 dark:text-paper-400">prov.: {{ $detalle->lote_codigo_proveedor }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <x-planta.badge :color="$detalle->estado_destino->color()">{{ $detalle->estado_destino->label() }}</x-planta.badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Acciones. Ocultar un botón NO autoriza: cada ruta lleva su
                 permiso y el servicio revalida el estado con la fila bloqueada. --}}
            <div class="flex flex-wrap items-center justify-end gap-3">
                @if ($recepcion->esEditable())
                    @can('planta.recepciones.crear')
                        <a href="{{ route('planta.recepciones.edit', $recepcion) }}"
                           class="rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-200 dark:hover:bg-ink-600">Editar</a>

                        <form method="POST" action="{{ route('planta.recepciones.anular', $recepcion) }}"
                              onsubmit="return confirm('Anular el borrador #{{ $recepcion->numero }}? No se podrá reabrir.')">
                            @csrf @method('PATCH')
                            <button class="rounded-md bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100 dark:bg-red-500/10 dark:text-red-300">Anular</button>
                        </form>
                    @endcan

                    @can('planta.recepciones.confirmar')
                        <form method="POST" action="{{ route('planta.recepciones.confirmar', $recepcion) }}"
                              onsubmit="return confirm('Confirmar la recepción #{{ $recepcion->numero }}? El inventario cambiará y ya no se podrá editar.')">
                            @csrf @method('PATCH')
                            <button class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">Confirmar recepción</button>
                        </form>
                    @endcan
                @endif

                @if ($recepcion->puedeReversarse())
                    @can('planta.recepciones.reversar')
                        <form method="POST" action="{{ route('planta.recepciones.reversar', $recepcion) }}"
                              class="flex items-end gap-2"
                              onsubmit="return confirm('Reversar la recepción #{{ $recepcion->numero }}? Se crearán movimientos negativos espejo.')">
                            @csrf @method('PATCH')
                            <div>
                                <label for="motivo" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Motivo de la reversión</label>
                                <input type="text" name="motivo" id="motivo" required minlength="10" maxlength="500"
                                       placeholder="Por qué se deshace esta entrada"
                                       class="mt-1 w-80 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                            </div>
                            <button class="rounded-md bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700">Reversar</button>
                        </form>
                    @endcan
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
