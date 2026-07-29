@php($c = $config ?? null)

@if ($errors->any())
    <div class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
        <ul class="list-disc ps-5 space-y-0.5">
            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

<div>
    <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Presentación *</label>
    <select name="planta_presentacion_id" required
            class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <option value="">— Seleccione —</option>
        @foreach ($presentaciones as $pres)
            <option value="{{ $pres->id }}" @selected(old('planta_presentacion_id', $c?->planta_presentacion_id) == $pres->id)>
                {{ $pres->productoBase?->nombre }} · {{ $pres->nombre }}{{ $pres->activo ? '' : ' — inactiva' }}
            </option>
        @endforeach
    </select>
    @error('planta_presentacion_id') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Bolsa *</label>
        {{-- Solo insumos de tipo bolsa y activos; al editar se añade el histórico. --}}
        <select name="planta_insumo_bolsa_id" required
                class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
            <option value="">— Seleccione —</option>
            @foreach ($bolsas as $bolsa)
                <option value="{{ $bolsa->id }}" @selected(old('planta_insumo_bolsa_id', $c?->planta_insumo_bolsa_id) == $bolsa->id)>
                    {{ $bolsa->nombre }} ({{ $bolsa->codigo }}){{ $bolsa->activo ? '' : ' — inactiva' }}
                </option>
            @endforeach
        </select>
        @error('planta_insumo_bolsa_id') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Viñeta</label>
        <select name="planta_insumo_vinieta_id"
                class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
            <option value="">Sin viñeta</option>
            @foreach ($vinietas as $vinieta)
                <option value="{{ $vinieta->id }}" @selected(old('planta_insumo_vinieta_id', $c?->planta_insumo_vinieta_id) == $vinieta->id)>
                    {{ $vinieta->nombre }} ({{ $vinieta->codigo }}){{ $vinieta->activo ? '' : ' — inactiva' }}
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">Opcional: hay empaques que no la llevan.</p>
        @error('planta_insumo_vinieta_id') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Mercado *</label>
        <select name="mercado" required class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
            @foreach ($mercados as $mercado)
                <option value="{{ $mercado->value }}" @selected(old('mercado', $c?->mercado?->value ?? 'nacional') === $mercado->value)>{{ $mercado->label() }}</option>
            @endforeach
        </select>
        @error('mercado') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Marca</label>
        <input type="text" name="marca" value="{{ old('marca', $c?->marca) }}" maxlength="80"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">Mayúsculas y espacios no crean duplicados.</p>
        @error('marca') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Referencia del cliente</label>
        <input type="text" name="referencia_cliente" value="{{ old('referencia_cliente', $c?->referencia_cliente) }}" maxlength="120"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">Texto libre; no se vincula con Facturación.</p>
        @error('referencia_cliente') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Vigente desde</label>
        <input type="date" name="vigente_desde" value="{{ old('vigente_desde', $c?->vigente_desde?->format('Y-m-d')) }}"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        @error('vigente_desde') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Vigente hasta</label>
        <input type="date" name="vigente_hasta" value="{{ old('vigente_hasta', $c?->vigente_hasta?->format('Y-m-d')) }}"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        @error('vigente_hasta') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-paper-200">
            <input type="hidden" name="es_predeterminada" value="0">
            <input type="checkbox" name="es_predeterminada" value="1" @checked(old('es_predeterminada', $c?->es_predeterminada ?? false))
                   class="rounded border-gray-300 dark:border-ink-600 dark:bg-ink-800">
            Predeterminada
        </label>
        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">Sustituye a la actual de ese mercado.</p>
        @error('es_predeterminada') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-paper-200">
            <input type="hidden" name="activo" value="0">
            <input type="checkbox" name="activo" value="1" @checked(old('activo', $c?->activo ?? true))
                   class="rounded border-gray-300 dark:border-ink-600 dark:bg-ink-800">
            Activo
        </label>
        @error('activo') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
</div>
