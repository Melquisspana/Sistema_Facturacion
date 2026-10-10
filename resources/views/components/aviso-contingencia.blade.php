@php($contingencia = app(\App\Services\Dte\ContingenciaService::class)->activa())
@if (config('dte.contingencia.enabled', false) && isset($errors))
    @foreach (['tipo', 'motivo', 'contingencia', 'cese'] as $campoContingencia)
        @error($campoContingencia)
            <p role="alert" class="bg-red-100 px-6 py-2 text-red-900">{{ $message }}</p>
        @enderror
    @endforeach
@endif
@if ($contingencia)
    <div role="status" class="border-b border-amber-400 bg-amber-100 px-6 py-3 text-amber-950">
        Modo contingencia desde las {{ $contingencia->inicio->format('H:i') }} del {{ $contingencia->inicio->format('d/m/Y') }}.
        Tipo {{ $contingencia->tipo }}: {{ [1 => 'MH no disponible', 2 => 'Sistema del emisor no disponible', 3 => 'Falla de internet del emisor', 4 => 'Falla de energía del emisor', 5 => 'Otro'][$contingencia->tipo] ?? '' }}.
        {{ $contingencia->motivo }}
        <p>Los documentos se generan y firman, pero todavía no se envían a Hacienda.</p>
        @can('dte.contingencia')
            <form method="POST" action="{{ route('facturacion.contingencia.terminar') }}" class="mt-2">
                @csrf
                <button type="submit" class="rounded border border-amber-700 px-3 py-1 font-semibold">Terminar contingencia</button>
            </form>
        @endcan
    </div>
@endif
@if (! $contingencia && config('dte.contingencia.enabled', false) && \Illuminate\Support\Facades\Schema::hasTable('contingencias'))
    @can('dte.contingencia')
        @foreach (\App\Models\Contingencia::where('estado', 'cerrada')->orderByDesc('id')->get() as $pendiente)
            <p class="border-b border-amber-400 bg-amber-100 px-6 py-3 text-amber-950">
                Contingencia terminada el {{ $pendiente->cese?->format('d/m/Y H:i') }}.
                <a class="underline" href="{{ route('facturacion.contingencia.show', $pendiente) }}">Revisar plazo y enviar aviso de contingencia</a>
            </p>
        @endforeach
        <details class="border-b border-gray-300 bg-white px-6 py-2 text-gray-900">
            <summary class="cursor-pointer">Activar modo contingencia</summary>
            <form method="POST" action="{{ route('facturacion.contingencia.activar') }}" class="mt-3 space-y-2">
                @csrf
                <label class="block">Tipo de contingencia
                    <select name="tipo" required class="rounded text-gray-900">
                        <option value="1">1 · MH no disponible</option>
                        <option value="2">2 · Sistema del emisor no disponible</option>
                        <option value="3">3 · Falla de internet del emisor</option>
                        <option value="4">4 · Falla de energía del emisor</option>
                        <option value="5">5 · Otro</option>
                    </select>
                </label>
                <label class="block">Motivo (obligatorio para tipo 5)
                    <textarea name="motivo" maxlength="500" class="block rounded text-gray-900"></textarea>
                </label>
                <button type="submit" class="rounded border border-gray-600 px-3 py-1">Activar contingencia</button>
                <p>Los documentos se firmarán sin transmitirse al MH hasta su regularización.</p>
            </form>
        </details>
    @endcan
@endif
