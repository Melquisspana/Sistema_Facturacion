@php($l = $lote ?? null)
<div>
    <label class="block text-sm font-medium text-gray-700">Referencia</label>
    <input type="text" name="referencia" value="{{ old('referencia', $l?->referencia) }}" required
           class="mt-1 w-full rounded-md border-gray-300 text-sm" placeholder="ej. PPQ Calleja semana 25">
    @error('referencia') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700">Fecha</label>
        <input type="date" name="fecha" value="{{ old('fecha', optional($l?->fecha)->format('Y-m-d') ?? now()->format('Y-m-d')) }}" required
               class="mt-1 w-full rounded-md border-gray-300 text-sm">
        @error('fecha') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Estado</label>
        <select name="estado" class="mt-1 w-full rounded-md border-gray-300 text-sm">
            @foreach ($estados as $opt)
                <option value="{{ $opt['value'] }}" @selected(old('estado', $l?->estado?->value ?? 'borrador') === $opt['value'])>{{ $opt['label'] }}</option>
            @endforeach
        </select>
        @error('estado') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
</div>

<div>
    <label class="block text-sm font-medium text-gray-700">Cliente</label>
    <select name="cliente_id" class="mt-1 w-full rounded-md border-gray-300 text-sm">
        <option value="">— (opcional) —</option>
        @foreach ($clientes as $cliente)
            <option value="{{ $cliente->id }}" @selected((string) old('cliente_id', $l?->cliente_id ?? ($clienteDefault ?? '')) === (string) $cliente->id)>{{ $cliente->nombre }}</option>
        @endforeach
    </select>
    @error('cliente_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
</div>

<div>
    <label class="block text-sm font-medium text-gray-700">Observaciones</label>
    <textarea name="observaciones" rows="3" class="mt-1 w-full rounded-md border-gray-300 text-sm">{{ old('observaciones', $l?->observaciones) }}</textarea>
    @error('observaciones') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
</div>
