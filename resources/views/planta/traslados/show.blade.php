<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">
                Traslado #{{ $traslado->numero }}
                <x-planta.badge :color="$traslado->estado->color()" class="ms-2">{{ $traslado->estado->label() }}</x-planta.badge>
            </h2>
            <a href="{{ route('planta.traslados.index') }}"
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

            @if ($traslado->esReversion())
                <div class="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                    Este documento es la REVERSIÓN del traslado
                    <a href="{{ route('planta.traslados.show', $traslado->reversion_de_id) }}" class="font-semibold underline">#{{ $traslado->reversionDe?->numero }}</a>.
                    Sus movimientos compensan a los del original, que se conserva intacto.
                </div>
            @elseif ($traslado->revertido_por_id)
                <div class="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                    Reversado por el documento
                    <a href="{{ route('planta.traslados.show', $traslado->revertido_por_id) }}" class="font-semibold underline">#{{ $traslado->revertidoPor?->numero }}</a>.
                    @if ($traslado->motivo_reversion)
                        <span class="block mt-1">Motivo: {{ $traslado->motivo_reversion }}</span>
                    @endif
                </div>
            @endif

            @if ($traslado->estaEnTransito())
                <div class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                    La mercancía está EN TRÁNSITO: ya salió del origen y todavía no está disponible en el destino.
                    Su saldo está atado a este traslado, así que ningún otro viaje puede consumirlo.
                </div>
            @endif

            {{-- Recorrido --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 dark:bg-ink-800 dark:ring-ink-600">
                <div class="flex flex-wrap items-center gap-3 text-lg">
                    <span class="font-semibold text-gray-800 dark:text-paper-100">{{ $traslado->origen?->codigo }}</span>
                    <span class="text-gray-400">&rarr;</span>
                    <span class="text-sm text-gray-500 dark:text-paper-400">tránsito</span>
                    <span class="text-gray-400">&rarr;</span>
                    <span class="font-semibold text-gray-800 dark:text-paper-100">{{ $traslado->destino?->codigo }}</span>
                </div>

                <dl class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4 text-sm">
                    @php
                        $dt = 'text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-paper-400';
                        $dd = 'mt-0.5 text-gray-800 dark:text-paper-100';
                    @endphp
                    <div><dt class="{{ $dt }}">Fecha</dt><dd class="{{ $dd }}">{{ $traslado->fecha->format('d/m/Y') }}</dd></div>
                    <div><dt class="{{ $dt }}">Origen</dt><dd class="{{ $dd }}">{{ $traslado->origen?->nombre }}</dd></div>
                    <div><dt class="{{ $dt }}">Destino</dt><dd class="{{ $dd }}">{{ $traslado->destino?->nombre }}</dd></div>
                    <div><dt class="{{ $dt }}">Responsable</dt><dd class="{{ $dd }}">{{ $traslado->responsable_nombre ?? $traslado->responsable?->name ?? '—' }}</dd></div>
                    <div><dt class="{{ $dt }}">Creado por</dt><dd class="{{ $dd }}">{{ $traslado->creadoPor?->name ?? '—' }}</dd></div>
                    <div><dt class="{{ $dt }}">Enviado por</dt><dd class="{{ $dd }}">{{ $traslado->enviadoPor?->name ?? '—' }}{{ $traslado->enviado_en ? ' · '.$traslado->enviado_en->format('d/m/Y H:i') : '' }}</dd></div>
                    <div><dt class="{{ $dt }}">Recibido por</dt><dd class="{{ $dd }}">{{ $traslado->recibidoPor?->name ?? '—' }}{{ $traslado->recibido_en ? ' · '.$traslado->recibido_en->format('d/m/Y H:i') : '' }}</dd></div>
                    @if ($traslado->observaciones)
                        <div class="sm:col-span-2 lg:col-span-4">
                            <dt class="{{ $dt }}">Observaciones</dt><dd class="{{ $dd }}">{{ $traslado->observaciones }}</dd>
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
                            <th class="px-4 py-3">Lote</th>
                            <th class="px-4 py-3 text-right">Cantidad</th>
                            <th class="px-4 py-3">Observaciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm dark:divide-ink-700">
                        @foreach ($traslado->detalles as $detalle)
                            <tr class="text-gray-700 dark:text-paper-200">
                                <td class="px-4 py-3">{{ $detalle->insumo?->codigo }} — {{ $detalle->insumo?->nombre }}</td>
                                <td class="px-4 py-3">{{ $detalle->lote?->codigo_interno ?? '—' }}</td>
                                <td class="px-4 py-3 text-right font-semibold">
                                    {{ $detalle->cantidad }}
                                    <span class="text-xs font-normal text-gray-500 dark:text-paper-400">{{ $detalle->unidad_base?->abreviatura() }}</span>
                                </td>
                                <td class="px-4 py-3">{{ $detalle->observaciones ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Acciones. Ocultar un botón NO autoriza: cada ruta lleva su
                 permiso y el servicio revalida el estado con la fila bloqueada. --}}
            <div class="flex flex-wrap items-center justify-end gap-3">
                @if ($traslado->esEditable())
                    @can('planta.traslados.crear')
                        <a href="{{ route('planta.traslados.edit', $traslado) }}"
                           class="rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-200 dark:hover:bg-ink-600">Editar</a>

                        <form method="POST" action="{{ route('planta.traslados.cancelar', $traslado) }}"
                              onsubmit="return confirm('Cancelar el borrador #{{ $traslado->numero }}? No se podrá reabrir.')">
                            @csrf @method('PATCH')
                            <button class="rounded-md bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100 dark:bg-red-500/10 dark:text-red-300">Cancelar traslado</button>
                        </form>
                    @endcan

                    @can('planta.traslados.enviar')
                        <form method="POST" action="{{ route('planta.traslados.enviar', $traslado) }}"
                              onsubmit="return confirm('Enviar el traslado #{{ $traslado->numero }}? El saldo saldrá del origen y quedará en tránsito.')">
                            @csrf @method('PATCH')
                            <button class="rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">Enviar</button>
                        </form>
                    @endcan
                @endif

                @if ($traslado->puedeRecibirse())
                    @can('planta.traslados.recibir')
                        <form method="POST" action="{{ route('planta.traslados.recibir', $traslado) }}"
                              onsubmit="return confirm('Recibir el traslado #{{ $traslado->numero }}? Se recibe exactamente lo enviado.')">
                            @csrf @method('PATCH')
                            <button class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">Recibir</button>
                        </form>
                    @endcan
                @endif

                @if ($traslado->puedeReversarse())
                    @can('planta.traslados.reversar')
                        <form method="POST" action="{{ route('planta.traslados.reversar', $traslado) }}"
                              class="flex items-end gap-2"
                              onsubmit="return confirm('Reversar el traslado #{{ $traslado->numero }}? El saldo volverá al origen.')">
                            @csrf @method('PATCH')
                            <div>
                                <label for="motivo" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Motivo de la reversión</label>
                                <input type="text" name="motivo" id="motivo" required minlength="10" maxlength="500"
                                       placeholder="Por qué se deshace este traslado"
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
