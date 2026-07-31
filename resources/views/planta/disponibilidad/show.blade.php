<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">
                {{ $cambio->accionLegible() }} #{{ $cambio->numero }}
                <x-planta.badge :color="$cambio->estado->color()" class="ms-2">{{ $cambio->estado->label() }}</x-planta.badge>
            </h2>
            <a href="{{ route('planta.disponibilidad.index') }}"
               class="text-sm text-gray-500 hover:underline dark:text-paper-400">Volver al listado</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-planta.avisos />

            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                    <ul class="list-disc ps-5 space-y-0.5">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @if ($cambio->esReversion())
                <div class="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                    Este documento es la REVERSIÓN del cambio
                    <a href="{{ route('planta.disponibilidad.show', $cambio->reversion_de_id) }}" class="font-semibold underline">#{{ $cambio->reversionDe?->numero }}</a>:
                    devuelve el saldo al estado en que estaba. El original se conserva intacto.
                </div>
            @elseif ($cambio->revertido_por_id)
                <div class="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                    Reversado por el documento
                    <a href="{{ route('planta.disponibilidad.show', $cambio->revertido_por_id) }}" class="font-semibold underline">#{{ $cambio->revertidoPor?->numero }}</a>.
                </div>
            @endif

            {{-- El movimiento, en una línea legible --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 dark:bg-ink-800 dark:ring-ink-600">
                <div class="flex flex-wrap items-center gap-3 text-lg">
                    <span class="font-semibold text-gray-800 dark:text-paper-100">{{ $cambio->cantidad }}</span>
                    <span class="text-sm text-gray-500 dark:text-paper-400">{{ $cambio->insumo?->unidad_base?->abreviatura() }}</span>
                    <x-planta.badge :color="$cambio->estado_origen->color()">{{ $cambio->estado_origen->label() }}</x-planta.badge>
                    <span class="text-gray-400">&rarr;</span>
                    <x-planta.badge :color="$cambio->estado_destino->color()">{{ $cambio->estado_destino->label() }}</x-planta.badge>
                </div>
                <p class="mt-2 text-xs text-gray-500 dark:text-paper-400">
                    La cantidad física no cambia: el par de movimientos suma cero. Lo que cambia es qué parte del saldo es utilizable.
                </p>

                <dl class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4 text-sm">
                    @php
                        $dt = 'text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-paper-400';
                        $dd = 'mt-0.5 text-gray-800 dark:text-paper-100';
                    @endphp
                    <div><dt class="{{ $dt }}">Insumo</dt><dd class="{{ $dd }}">{{ $cambio->insumo?->codigo }} — {{ $cambio->insumo?->nombre }}</dd></div>
                    <div><dt class="{{ $dt }}">Lote</dt><dd class="{{ $dd }}">{{ $cambio->lote?->codigo_interno ?? '—' }}</dd></div>
                    <div><dt class="{{ $dt }}">Ubicación</dt><dd class="{{ $dd }}">{{ $cambio->ubicacion?->codigo }} — {{ $cambio->ubicacion?->nombre }}</dd></div>
                    <div><dt class="{{ $dt }}">Fecha</dt><dd class="{{ $dd }}">{{ $cambio->fecha->format('d/m/Y') }}</dd></div>
                    <div><dt class="{{ $dt }}">Responsable</dt><dd class="{{ $dd }}">{{ $cambio->responsable_nombre ?? $cambio->responsable?->name ?? '—' }}</dd></div>
                    <div><dt class="{{ $dt }}">Creado por</dt><dd class="{{ $dd }}">{{ $cambio->creadoPor?->name ?? '—' }}</dd></div>
                    <div><dt class="{{ $dt }}">Confirmado por</dt><dd class="{{ $dd }}">{{ $cambio->confirmadoPor?->name ?? '—' }}</dd></div>
                    <div><dt class="{{ $dt }}">Confirmado el</dt><dd class="{{ $dd }}">{{ $cambio->confirmado_en?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                    <div class="sm:col-span-2 lg:col-span-4">
                        <dt class="{{ $dt }}">Motivo</dt><dd class="{{ $dd }}">{{ $cambio->motivo }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Saldo vivo del origen: es lo que decide si la confirmación podrá aplicarse. --}}
            @if ($cambio->esEditable())
                <div class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700 dark:border-ink-600 dark:bg-ink-900 dark:text-paper-300">
                    Saldo <strong>retenido</strong> disponible ahora mismo en ese bucket:
                    <strong>{{ $saldoRetenido }}</strong> {{ $cambio->insumo?->unidad_base?->abreviatura() }}.
                    @if (bccomp($saldoRetenido, (string) $cambio->cantidad, 4) === -1)
                        <span class="text-red-600 dark:text-red-300">
                            No alcanza para los {{ $cambio->cantidad }} de este documento: la confirmación será rechazada.
                        </span>
                    @endif
                </div>
            @endif

            {{-- Acciones. Ocultar un botón NO autoriza: cada ruta lleva su
                 permiso y el servicio revalida el estado con la fila bloqueada. --}}
            <div class="flex flex-wrap items-center justify-end gap-3">
                @can('planta.calidad.gestionar')
                    @if ($cambio->esEditable())
                        <a href="{{ route('planta.disponibilidad.edit', $cambio) }}"
                           class="rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-200 dark:hover:bg-ink-600">Editar</a>

                        <form method="POST" action="{{ route('planta.disponibilidad.anular', $cambio) }}"
                              onsubmit="return confirm('Anular el borrador #{{ $cambio->numero }}? No se podrá reabrir.')">
                            @csrf @method('PATCH')
                            <button class="rounded-md bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100 dark:bg-red-500/10 dark:text-red-300">Anular</button>
                        </form>

                        <form method="POST" action="{{ route('planta.disponibilidad.confirmar', $cambio) }}"
                              onsubmit="return confirm('Confirmar el cambio #{{ $cambio->numero }}? El inventario cambiará y ya no se podrá editar.')">
                            @csrf @method('PATCH')
                            <button class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">Confirmar cambio</button>
                        </form>
                    @endif

                    @if ($cambio->puedeReversarse())
                        <form method="POST" action="{{ route('planta.disponibilidad.reversar', $cambio) }}"
                              class="flex items-end gap-2"
                              onsubmit="return confirm('Reversar el cambio #{{ $cambio->numero }}? El saldo volverá a retenido.')">
                            @csrf @method('PATCH')
                            <div>
                                <label for="motivo" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Motivo de la reversión</label>
                                <input type="text" name="motivo" id="motivo" required minlength="10" maxlength="500"
                                       placeholder="Por qué se deshace esta decisión"
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
