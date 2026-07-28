@php($u = $ubicacion ?? null)
@php($esSistema = (bool) ($u?->es_sistema))

@if ($esSistema)
    <div class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
        Esta es una ubicación de <strong>sistema</strong>. No se puede cambiar su código, quitarle
        la marca de sistema ni desactivarla. El resto de campos sí se puede editar.
    </div>
@endif

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Código *</label>
        {{-- readonly es solo una ayuda visual: el rechazo real está en UbicacionRequest. --}}
        <input type="text" name="codigo" value="{{ old('codigo', $u?->codigo) }}" required maxlength="20"
               @readonly($esSistema)
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100 {{ $esSistema ? 'bg-gray-100 dark:bg-ink-700' : '' }}"
               placeholder="ej. CASA">
        @error('codigo') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Nombre *</label>
        <input type="text" name="nombre" value="{{ old('nombre', $u?->nombre) }}" required maxlength="100"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        @error('nombre') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Tipo *</label>
        <select name="tipo" required class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
            @foreach ($tipos as $tipo)
                <option value="{{ $tipo->value }}" @selected(old('tipo', $u?->tipo?->value ?? 'fisica') === $tipo->value)>{{ $tipo->label() }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">
            El tránsito sostiene lo que ya salió del origen y todavía no llegó al destino.
        </p>
        @error('tipo') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Orden *</label>
        <input type="number" name="orden" value="{{ old('orden', $u?->orden ?? 0) }}" required min="0" max="65535" step="1"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">Solo afecta al orden en que aparecen en los listados.</p>
        @error('orden') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div>
        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-paper-200">
            <input type="hidden" name="es_sistema" value="{{ $esSistema ? '1' : '0' }}">
            <input type="checkbox" name="es_sistema" value="1" @checked(old('es_sistema', $u?->es_sistema ?? false))
                   @disabled($esSistema)
                   class="rounded border-gray-300 dark:border-ink-600 dark:bg-ink-800">
            Es de sistema
        </label>
        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">No se puede desactivar ni cambiar de código.</p>
        @error('es_sistema') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-paper-200">
            <input type="hidden" name="permite_operacion_manual" value="0">
            <input type="checkbox" name="permite_operacion_manual" value="1" @checked(old('permite_operacion_manual', $u?->permite_operacion_manual ?? true))
                   class="rounded border-gray-300 dark:border-ink-600 dark:bg-ink-800">
            Permite operación manual
        </label>
        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">Debe quedar desmarcado si el tipo es tránsito.</p>
        @error('permite_operacion_manual') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-paper-200">
            <input type="hidden" name="activo" value="{{ $esSistema ? '1' : '0' }}">
            <input type="checkbox" name="activo" value="1" @checked(old('activo', $u?->activo ?? true))
                   @disabled($esSistema)
                   class="rounded border-gray-300 dark:border-ink-600 dark:bg-ink-800">
            Activo
        </label>
        @error('activo') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
</div>
