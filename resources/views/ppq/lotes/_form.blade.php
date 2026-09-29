@php($l = $lote ?? null)
<div>
    <label class="block text-sm font-medium text-gray-700">Referencia</label>
    <input type="text" name="referencia" value="{{ old('referencia', $l?->referencia) }}" required
           class="mt-1 w-full rounded-md border-gray-300 text-sm" placeholder="ej. PPQ Calleja semana 25">
    @error('referencia') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
</div>

<div>
    <label class="block text-sm font-medium text-gray-700">Fecha</label>
    <input type="date" name="fecha" value="{{ old('fecha', optional($l?->fecha)->format('Y-m-d') ?? now()->format('Y-m-d')) }}" required
           class="mt-1 w-full rounded-md border-gray-300 text-sm">
    @error('fecha') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
</div>

<div>
    <label class="block text-sm font-medium text-gray-700">Observaciones</label>
    <textarea name="observaciones" rows="3" class="mt-1 w-full rounded-md border-gray-300 text-sm">{{ old('observaciones', $l?->observaciones) }}</textarea>
    @error('observaciones') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
</div>
