{{-- Formulario de cambio de disponibilidad.

     EL ORIGEN NO SE ELIGE: siempre es `retenido`, y se muestra como dato fijo.
     Lo único que decide la persona es qué saldo retenido, cuánto y hacia dónde.

     EL BUCKET SE ELIGE ENTERO, en un solo selector alimentado con los buckets
     que de verdad tienen saldo retenido. Capturar insumo, lote y ubicación por
     separado permitiría pedir un cambio sobre una combinación que no existe, y
     el error se descubriría al confirmar en vez de al escribir. --}}
@php
    $cambio = $cambio ?? null;

    $bucketActual = $cambio
        ? $cambio->planta_insumo_id.'|'.$cambio->planta_lote_id.'|'.$cambio->planta_ubicacion_id
        : '';

    // Saldo retenido por bucket, para poder mostrarlo al elegir.
    $saldos = $buckets->mapWithKeys(fn ($b) => [
        $b->planta_insumo_id.'|'.$b->planta_lote_id.'|'.$b->planta_ubicacion_id => [
            'insumo' => $b->insumo_codigo.' — '.$b->insumo_nombre,
            'lote' => $b->lote_codigo,
            'ubicacion' => $b->ubicacion_codigo.' — '.$b->ubicacion_nombre,
            'saldo' => (string) $b->cantidad,
            'unidad' => $b->unidad_base,
        ],
    ]);

    $etiqueta = 'block text-sm font-medium text-gray-700 dark:text-paper-200';
    $campo = 'mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
@endphp

<div x-data="{
        saldos: {{ Js::from($saldos) }},
        bucket: @js(old('bucket', $bucketActual)),
        get elegido() { return this.saldos[this.bucket] ?? null; },
     }"
     class="space-y-5">

    @if ($buckets->isEmpty())
        <div class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            No hay saldo retenido en ninguna ubicación. Un cambio de disponibilidad solo mueve saldo
            que está retenido: primero tiene que entrar así por una recepción.
        </div>
    @endif

    {{-- Bucket --}}
    <div>
        <label for="bucket" class="{{ $etiqueta }}">Saldo retenido</label>
        <select name="bucket" id="bucket" x-model="bucket" required class="{{ $campo }}">
            <option value="">Selecciona el saldo a liberar o rechazar…</option>
            @foreach ($buckets as $b)
                @php $clave = $b->planta_insumo_id.'|'.$b->planta_lote_id.'|'.$b->planta_ubicacion_id; @endphp
                <option value="{{ $clave }}">
                    {{ $b->insumo_codigo }} — {{ $b->insumo_nombre }}
                    · lote {{ $b->lote_codigo }}
                    · {{ $b->ubicacion_codigo }}
                    · {{ $b->cantidad }} retenido
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-gray-500 dark:text-paper-400">
            Solo aparecen combinaciones con saldo retenido mayor que cero.
        </p>
        @error('planta_insumo_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    {{-- Resumen del bucket elegido --}}
    <template x-if="elegido">
        <div class="grid grid-cols-2 gap-4 rounded-lg border border-gray-200 p-4 text-sm sm:grid-cols-5 dark:border-ink-600">
            @php $dt = 'text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-paper-400'; @endphp
            <div><p class="{{ $dt }}">Insumo</p><p class="mt-0.5 text-gray-800 dark:text-paper-100" x-text="elegido.insumo"></p></div>
            <div><p class="{{ $dt }}">Lote</p><p class="mt-0.5 text-gray-800 dark:text-paper-100" x-text="elegido.lote"></p></div>
            <div><p class="{{ $dt }}">Ubicación</p><p class="mt-0.5 text-gray-800 dark:text-paper-100" x-text="elegido.ubicacion"></p></div>
            <div><p class="{{ $dt }}">Retenido</p><p class="mt-0.5 font-semibold text-gray-800 dark:text-paper-100" x-text="elegido.saldo"></p></div>
            <div><p class="{{ $dt }}">Unidad base</p><p class="mt-0.5 text-gray-800 dark:text-paper-100" x-text="elegido.unidad"></p></div>
        </div>
    </template>

    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
        {{-- Origen: fijo --}}
        <div>
            <label class="{{ $etiqueta }}">Estado de origen</label>
            <input type="text" value="Retenido" disabled
                   class="{{ $campo }} bg-gray-100 text-gray-500 dark:bg-ink-900 dark:text-paper-400">
            <p class="mt-1 text-xs text-gray-500 dark:text-paper-400">
                No se elige: este documento solo mueve saldo retenido.
            </p>
        </div>

        <div>
            <label for="estado_destino" class="{{ $etiqueta }}">Destino</label>
            <select name="estado_destino" id="estado_destino" required class="{{ $campo }}">
                @foreach ($destinos as $destino)
                    <option value="{{ $destino->value }}"
                        @selected(old('estado_destino', $cambio?->estado_destino?->value) === $destino->value)>
                        {{ $destino->label() }}{{ $destino->value === 'disponible' ? ' (liberar)' : ' (rechazar)' }}
                    </option>
                @endforeach
            </select>
            @error('estado_destino')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="cantidad" class="{{ $etiqueta }}">Cantidad</label>
            <input type="number" step="0.0001" min="0.0001" name="cantidad" id="cantidad" required
                   value="{{ old('cantidad', $cambio?->cantidad) }}"
                   :max="elegido ? elegido.saldo : null" class="{{ $campo }}">
            <p class="mt-1 text-xs text-gray-500 dark:text-paper-400">
                En unidad base. Nunca más de lo retenido.
            </p>
            @error('cantidad')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="fecha" class="{{ $etiqueta }}">Fecha de la decisión</label>
            <input type="date" name="fecha" id="fecha" required
                   value="{{ old('fecha', $cambio?->fecha?->toDateString() ?? now()->toDateString()) }}"
                   class="{{ $campo }}">
            @error('fecha')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="responsable_user_id" class="{{ $etiqueta }}">Responsable (usuario)</label>
            <select name="responsable_user_id" id="responsable_user_id" class="{{ $campo }}">
                <option value="">Sin usuario</option>
                @foreach ($usuarios as $usuario)
                    <option value="{{ $usuario->id }}"
                        @selected(old('responsable_user_id', $cambio?->responsable_user_id) == $usuario->id)>
                        {{ $usuario->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="responsable_nombre" class="{{ $etiqueta }}">Responsable (nombre)</label>
            <input type="text" name="responsable_nombre" id="responsable_nombre" maxlength="120"
                   value="{{ old('responsable_nombre', $cambio?->responsable_nombre) }}"
                   placeholder="Quien decidió, si no tiene cuenta" class="{{ $campo }}">
        </div>
    </div>

    <div>
        <label for="motivo" class="{{ $etiqueta }}">Motivo</label>
        <textarea name="motivo" id="motivo" rows="3" required minlength="10" maxlength="2000"
                  placeholder="Por qué este saldo se libera o se rechaza"
                  class="{{ $campo }}">{{ old('motivo', $cambio?->motivo) }}</textarea>
        <p class="mt-1 text-xs text-gray-500 dark:text-paper-400">
            Obligatorio: dentro de un mes será la única forma de saber por qué cambió.
        </p>
        @error('motivo')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
</div>
