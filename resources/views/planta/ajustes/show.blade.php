<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">
                Ajuste #{{ $ajuste->numero }}
                <x-planta.badge :color="$ajuste->tipo->color()" class="ms-2">{{ $ajuste->tipo->label() }}</x-planta.badge>
                <x-planta.badge :color="$ajuste->estado->color()" class="ms-1">{{ $ajuste->estado->label() }}</x-planta.badge>
            </h2>
            <a href="{{ route('planta.ajustes.index') }}"
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

            @if ($ajuste->esReversion())
                <div class="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                    Este documento es la REVERSIÓN del ajuste
                    <a href="{{ route('planta.ajustes.show', $ajuste->reversion_de_id) }}" class="font-semibold underline">#{{ $ajuste->reversionDe?->numero }}</a>:
                    aplica su efecto al revés. El original se conserva intacto.
                </div>
            @elseif ($ajuste->revertido_por_id)
                <div class="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                    Reversado por el documento
                    <a href="{{ route('planta.ajustes.show', $ajuste->revertido_por_id) }}" class="font-semibold underline">#{{ $ajuste->revertidoPor?->numero }}</a>.
                </div>
            @endif

            {{-- Cabecera --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 dark:bg-ink-800 dark:ring-ink-600">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4 text-sm">
                    @php
                        $dt = 'text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-paper-400';
                        $dd = 'mt-0.5 text-gray-800 dark:text-paper-100';
                    @endphp
                    <div><dt class="{{ $dt }}">Fecha</dt><dd class="{{ $dd }}">{{ $ajuste->fecha->format('d/m/Y') }}</dd></div>
                    <div><dt class="{{ $dt }}">Responsable</dt><dd class="{{ $dd }}">{{ $ajuste->responsable_nombre ?? $ajuste->responsable?->name ?? '—' }}</dd></div>
                    <div><dt class="{{ $dt }}">Creado por</dt><dd class="{{ $dd }}">{{ $ajuste->creadoPor?->name ?? '—' }}</dd></div>
                    <div><dt class="{{ $dt }}">Confirmado por</dt><dd class="{{ $dd }}">{{ $ajuste->confirmadoPor?->name ?? '—' }}{{ $ajuste->confirmado_en ? ' · '.$ajuste->confirmado_en->format('d/m/Y H:i') : '' }}</dd></div>
                    <div class="sm:col-span-2 lg:col-span-4">
                        <dt class="{{ $dt }}">Motivo</dt><dd class="{{ $dd }}">{{ $ajuste->motivo }}</dd>
                    </div>
                    @if ($ajuste->observaciones)
                        <div class="sm:col-span-2 lg:col-span-4">
                            <dt class="{{ $dt }}">Observaciones</dt><dd class="{{ $dd }}">{{ $ajuste->observaciones }}</dd>
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
                            <th class="px-4 py-3">Ubicación</th>
                            <th class="px-4 py-3">Disponibilidad</th>
                            @if ($ajuste->esCorreccionDeConteo())
                                <th class="px-4 py-3 text-right">Sistema</th>
                                <th class="px-4 py-3 text-right">Contado</th>
                                <th class="px-4 py-3 text-right">Diferencia</th>
                            @else
                                <th class="px-4 py-3 text-right">Cantidad</th>
                            @endif
                            <th class="px-4 py-3 text-right">Saldo actual</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm dark:divide-ink-700">
                        @foreach ($ajuste->detalles as $detalle)
                            <tr class="text-gray-700 dark:text-paper-200">
                                <td class="px-4 py-3">{{ $detalle->insumo?->codigo }} — {{ $detalle->insumo?->nombre }}</td>
                                <td class="px-4 py-3">
                                    {{ $detalle->lote?->codigo_interno ?? '—' }}
                                    @if ($detalle->lote?->fecha_vencimiento)
                                        <span class="block text-[11px] text-gray-500 dark:text-paper-400">vence {{ $detalle->lote->fecha_vencimiento->toDateString() }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $detalle->ubicacion?->codigo ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <x-planta.badge :color="$detalle->estado_disponibilidad->color()">{{ $detalle->estado_disponibilidad->label() }}</x-planta.badge>
                                </td>
                                @if ($ajuste->esCorreccionDeConteo())
                                    <td class="px-4 py-3 text-right">{{ $detalle->cantidad_sistema ?? '—' }}</td>
                                    <td class="px-4 py-3 text-right">{{ $detalle->cantidad_conteo ?? '—' }}</td>
                                    <td class="px-4 py-3 text-right font-semibold">
                                        @if ($detalle->diferencia === null)
                                            <span class="text-gray-400">pendiente</span>
                                        @elseif ($detalle->diferenciaEsCero())
                                            <span class="text-gray-500">sin diferencia</span>
                                        @else
                                            {{ $detalle->diferencia }}
                                        @endif
                                    </td>
                                @else
                                    <td class="px-4 py-3 text-right font-semibold">
                                        {{ $ajuste->sumaSaldo() === false ? '−' : '+' }}{{ $detalle->cantidad }}
                                        <span class="text-xs font-normal text-gray-500 dark:text-paper-400">{{ $detalle->unidad_base?->abreviatura() }}</span>
                                    </td>
                                @endif
                                <td class="px-4 py-3 text-right text-gray-500 dark:text-paper-400">{{ $saldos[$detalle->id] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($ajuste->esCorreccionDeConteo() && $ajuste->esEditable())
                <p class="text-xs text-gray-500 dark:text-paper-400">
                    La cantidad del sistema y la diferencia se calculan al confirmar, con el saldo bloqueado.
                    Lo que se muestre ahora como «saldo actual» puede cambiar antes de eso.
                </p>
            @endif

            {{-- Acciones. Ocultar un botón NO autoriza: cada ruta lleva su
                 permiso y el servicio revalida el estado con la fila bloqueada. --}}
            <div class="flex flex-wrap items-center justify-end gap-3">
                @can('planta.ajustes.crear')
                    @if ($ajuste->esEditable())
                        <a href="{{ route('planta.ajustes.edit', $ajuste) }}"
                           class="rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-200 dark:hover:bg-ink-600">Editar</a>

                        <form method="POST" action="{{ route('planta.ajustes.anular', $ajuste) }}"
                              onsubmit="return confirm('Anular el borrador #{{ $ajuste->numero }}? No se podrá reabrir.')">
                            @csrf @method('PATCH')
                            <button class="rounded-md bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100 dark:bg-red-500/10 dark:text-red-300">Anular</button>
                        </form>

                        <form method="POST" action="{{ route('planta.ajustes.confirmar', $ajuste) }}"
                              onsubmit="return confirm('Confirmar el ajuste #{{ $ajuste->numero }}? El inventario cambiará y ya no se podrá editar.')">
                            @csrf @method('PATCH')
                            <button class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">Confirmar ajuste</button>
                        </form>
                    @endif

                    @if ($ajuste->puedeReversarse())
                        <form method="POST" action="{{ route('planta.ajustes.reversar', $ajuste) }}"
                              class="flex items-end gap-2"
                              onsubmit="return confirm('Reversar el ajuste #{{ $ajuste->numero }}? Se aplicará su efecto al revés.')">
                            @csrf @method('PATCH')
                            <div>
                                <label for="motivo" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Motivo de la reversión</label>
                                <input type="text" name="motivo" id="motivo" required minlength="10" maxlength="500"
                                       placeholder="Por qué se deshace este ajuste"
                                       class="mt-1 w-80 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                            </div>
                            <button class="rounded-md bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700">Reversar</button>
                        </form>
                    @endif
                @endcan
            </div>
        </div>
    </div>
</x-app-layout>
