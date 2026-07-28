@php($i = $insumo ?? null)

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Código *</label>
        <input type="text" name="codigo" value="{{ old('codigo', $i?->codigo) }}" required maxlength="30"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100"
               placeholder="ej. AZUCAR">
        @error('codigo') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Nombre *</label>
        <input type="text" name="nombre" value="{{ old('nombre', $i?->nombre) }}" required maxlength="150"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100"
               placeholder="ej. Azúcar blanca">
        @error('nombre') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Tipo *</label>
        <select name="tipo" required class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
            @foreach ($tipos as $tipo)
                <option value="{{ $tipo->value }}" @selected(old('tipo', $i?->tipo?->value) === $tipo->value)>{{ $tipo->label() }}</option>
            @endforeach
        </select>
        @error('tipo') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Unidad base *</label>
        <select name="unidad_base" required class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
            @foreach ($unidades as $unidad)
                <option value="{{ $unidad->value }}" @selected(old('unidad_base', $i?->unidad_base?->value) === $unidad->value)>{{ $unidad->label() }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">En esta unidad se llevarán todos los saldos del insumo.</p>
        @error('unidad_base') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-paper-200">
            <input type="hidden" name="controla_lotes" value="0">
            <input type="checkbox" name="controla_lotes" value="1" @checked(old('controla_lotes', $i?->controla_lotes ?? true))
                   class="rounded border-gray-300 dark:border-ink-600 dark:bg-ink-800">
            Controla lotes
        </label>
        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">Si se desmarca, sus entradas se agruparán en un lote único.</p>
        @error('controla_lotes') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-paper-200">
            <input type="hidden" name="permite_fraccion" value="0">
            <input type="checkbox" name="permite_fraccion" value="1" @checked(old('permite_fraccion', $i?->permite_fraccion ?? true))
                   class="rounded border-gray-300 dark:border-ink-600 dark:bg-ink-800">
            Permite fracción
        </label>
        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">Las bolsas y las viñetas se cuentan enteras: desmárquelo.</p>
        @error('permite_fraccion') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
</div>

<div class="rounded-lg border border-gray-100 p-4 dark:border-ink-700">
    <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-paper-500">Ayudas de captura para la recepción</p>
    <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">
        Son solo sugerencias: el valor real de cada entrada se guarda en la recepción, así que
        cambiarlas aquí no altera lo ya recibido.
    </p>

    <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Unidad de recepción</label>
            <input type="text" name="unidad_recepcion_sugerida" value="{{ old('unidad_recepcion_sugerida', $i?->unidad_recepcion_sugerida) }}" maxlength="30"
                   class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100"
                   placeholder="ej. saco">
            @error('unidad_recepcion_sugerida') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Contenido por unidad</label>
            <input type="number" name="contenido_sugerido" value="{{ old('contenido_sugerido', $i?->contenido_sugerido) }}" step="0.0001" min="0"
                   class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100"
                   placeholder="ej. 100">
            @error('contenido_sugerido') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Factor de conversión</label>
            <input type="number" name="factor_conversion_sugerido" value="{{ old('factor_conversion_sugerido', $i?->factor_conversion_sugerido) }}" step="0.00000001" min="0"
                   class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100"
                   placeholder="ej. 2.20462262">
            @error('factor_conversion_sugerido') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Stock mínimo</label>
        <input type="number" name="stock_minimo" value="{{ old('stock_minimo', $i?->stock_minimo) }}" step="0.0001" min="0"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">Solo alerta visual; no bloquea ninguna operación.</p>
        @error('stock_minimo') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-paper-200">
            <input type="hidden" name="activo" value="0">
            <input type="checkbox" name="activo" value="1" @checked(old('activo', $i?->activo ?? true))
                   class="rounded border-gray-300 dark:border-ink-600 dark:bg-ink-800">
            Activo
        </label>
        @error('activo') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
</div>

<div>
    <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Observaciones</label>
    <textarea name="observaciones" rows="2"
              class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">{{ old('observaciones', $i?->observaciones) }}</textarea>
    @error('observaciones') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
</div>
