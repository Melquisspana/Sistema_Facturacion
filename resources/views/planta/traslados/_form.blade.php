{{-- Formulario de traslado: cabecera + líneas.

     EL ORIGEN MANDA. Los lotes que se pueden trasladar son los que tienen saldo
     DISPONIBLE en la ubicación de origen, así que la lista depende de ella. Al
     cambiar el origen se recarga la página con `?origen=`: es un viaje al
     servidor, pero es la única forma de que la lista sea la verdad y no una
     copia que se queda vieja.

     No se ofrece retenido ni rechazado: el primero espera una decisión de
     calidad y el segundo está fuera de la operación. --}}
@php
    $traslado = $traslado ?? null;

    $lineasPrevias = old('detalles', $traslado?->detalles
        ->map(fn ($d) => [
            'bucket' => $d->planta_insumo_id.'|'.$d->planta_lote_id,
            'cantidad' => (string) $d->cantidad,
            'observaciones' => $d->observaciones,
        ])->values()->all() ?? []);

    $saldos = $disponibles->mapWithKeys(fn ($b) => [
        $b->planta_insumo_id.'|'.$b->planta_lote_id => [
            'insumo' => $b->insumo_codigo.' — '.$b->insumo_nombre,
            'lote' => $b->lote_codigo,
            'saldo' => (string) $b->cantidad,
            'unidad' => $b->unidad_base,
        ],
    ]);

    $etiqueta = 'block text-sm font-medium text-gray-700 dark:text-paper-200';
    $campo = 'mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
    $celda = 'w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100';
@endphp

{{-- Cabecera --}}
<div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
    <div>
        <label for="fecha" class="{{ $etiqueta }}">Fecha</label>
        <input type="date" name="fecha" id="fecha" required
               value="{{ old('fecha', $traslado?->fecha?->toDateString() ?? now()->toDateString()) }}"
               class="{{ $campo }}">
        @error('fecha')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="planta_ubicacion_origen_id" class="{{ $etiqueta }}">Origen</label>
        <select name="planta_ubicacion_origen_id" id="planta_ubicacion_origen_id" required class="{{ $campo }}"
                onchange="window.location = '{{ url()->current() }}?origen=' + this.value">
            <option value="">Selecciona…</option>
            @foreach ($ubicaciones as $ubicacion)
                <option value="{{ $ubicacion->id }}"
                    @selected(old('planta_ubicacion_origen_id', $origenSeleccionado) == $ubicacion->id)>
                    {{ $ubicacion->codigo }} — {{ $ubicacion->nombre }}
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-gray-500 dark:text-paper-400">Al cambiarlo se recargan los lotes con saldo.</p>
        @error('planta_ubicacion_origen_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="planta_ubicacion_destino_id" class="{{ $etiqueta }}">Destino</label>
        <select name="planta_ubicacion_destino_id" id="planta_ubicacion_destino_id" required class="{{ $campo }}">
            <option value="">Selecciona…</option>
            @foreach ($ubicaciones as $ubicacion)
                <option value="{{ $ubicacion->id }}"
                    @selected(old('planta_ubicacion_destino_id', $traslado?->planta_ubicacion_destino_id) == $ubicacion->id)>
                    {{ $ubicacion->codigo }} — {{ $ubicacion->nombre }}
                </option>
            @endforeach
        </select>
        @error('planta_ubicacion_destino_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="responsable_user_id" class="{{ $etiqueta }}">Responsable (usuario)</label>
        <select name="responsable_user_id" id="responsable_user_id" class="{{ $campo }}">
            <option value="">Sin usuario</option>
            @foreach ($usuarios as $usuario)
                <option value="{{ $usuario->id }}"
                    @selected(old('responsable_user_id', $traslado?->responsable_user_id) == $usuario->id)>
                    {{ $usuario->name }}
                </option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="responsable_nombre" class="{{ $etiqueta }}">Responsable (nombre)</label>
        <input type="text" name="responsable_nombre" id="responsable_nombre" maxlength="120"
               value="{{ old('responsable_nombre', $traslado?->responsable_nombre) }}"
               placeholder="Quien transporta" class="{{ $campo }}">
    </div>

    <div class="sm:col-span-2 lg:col-span-3">
        <label for="observaciones" class="{{ $etiqueta }}">Observaciones</label>
        <textarea name="observaciones" id="observaciones" rows="2" class="{{ $campo }}">{{ old('observaciones', $traslado?->observaciones) }}</textarea>
    </div>
</div>

{{-- Líneas --}}
<div class="pt-5 border-t border-gray-100 dark:border-ink-700"
     x-data="{
        saldos: {{ Js::from($saldos) }},
        lineas: {{ Js::from($lineasPrevias) }},
        agregar() { this.lineas.push({ bucket: '', cantidad: '', observaciones: '' }); },
        quitar(i) { this.lineas.splice(i, 1); },
        info(linea) { return this.saldos[linea.bucket] ?? null; },
     }"
     x-init="if (lineas.length === 0) agregar()">

    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-semibold text-gray-800 dark:text-paper-100">Líneas a trasladar</h3>
        <button type="button" @click="agregar()"
                class="rounded-md bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-200 dark:hover:bg-ink-600">
            + Añadir línea
        </button>
    </div>

    @error('detalles')<p class="mb-2 text-xs text-red-600">{{ $message }}</p>@enderror

    @if ($disponibles->isEmpty())
        <div class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            @if ($origenSeleccionado)
                No hay saldo disponible en esa ubicación. Un traslado solo mueve saldo disponible:
                el retenido y el rechazado no viajan.
            @else
                Elige primero la ubicación de origen para ver qué lotes tienen saldo.
            @endif
        </div>
    @endif

    <div class="space-y-3">
        <template x-for="(linea, i) in lineas" :key="i">
            <div class="rounded-lg border border-gray-200 p-4 dark:border-ink-600">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="lg:col-span-2">
                        <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Insumo y lote</label>
                        <select :name="`detalles[${i}][bucket]`" x-model="linea.bucket" required class="{{ $celda }}">
                            <option value="">Selecciona…</option>
                            @foreach ($disponibles as $b)
                                <option value="{{ $b->planta_insumo_id }}|{{ $b->planta_lote_id }}">
                                    {{ $b->insumo_codigo }} — {{ $b->insumo_nombre }}
                                    · lote {{ $b->lote_codigo }}
                                    · {{ $b->cantidad }} disponible
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Cantidad</label>
                        <input type="number" step="0.0001" min="0.0001" required
                               :name="`detalles[${i}][cantidad]`" x-model="linea.cantidad"
                               :max="info(linea) ? info(linea).saldo : null" class="{{ $celda }}">
                    </div>

                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Disponible</label>
                        <p class="mt-1.5 text-sm font-semibold text-gray-800 dark:text-paper-100">
                            <span x-text="info(linea) ? info(linea).saldo : '—'"></span>
                            <span class="text-xs font-normal text-gray-500 dark:text-paper-400"
                                  x-text="info(linea) ? info(linea).unidad : ''"></span>
                        </p>
                    </div>

                    <div class="lg:col-span-3">
                        <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Observaciones de la línea</label>
                        <input type="text" maxlength="500" :name="`detalles[${i}][observaciones]`"
                               x-model="linea.observaciones" class="{{ $celda }}">
                    </div>

                    <div class="flex items-end justify-end">
                        <button type="button" @click="quitar(i)" x-show="lineas.length > 1"
                                class="rounded-md bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100 dark:bg-red-500/10 dark:text-red-300">
                            Quitar
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <p class="mt-3 text-xs text-gray-500 dark:text-paper-400">
        Dos líneas del mismo lote se suman en una sola. Se recibirá exactamente lo enviado:
        no hay recepción parcial.
    </p>
</div>
