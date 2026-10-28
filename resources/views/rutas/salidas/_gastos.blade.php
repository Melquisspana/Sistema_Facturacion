@php
    $fechaGastos = $fechaGastos ?? \App\Support\HoraNegocio::fechaHoy();
    $gastosDia = $salida->gastos()->whereDate('fecha', $fechaGastos)->orderBy('id')->get();
    $totalGastos = $gastosDia->sum(fn ($gasto) => \App\Services\Rutas\CierreDia::centavos($gasto->monto));
    $prefijoGastos = ($modoVendedor ?? false) ? 'rutas.mi-salida.' : 'rutas.salidas.';
    $agregarGasto = ! ($soloListaGastos ?? false) && auth()->user()->can('agregarGasto', [$salida, $fechaGastos]);
@endphp
<section class="mt-5 rounded-2xl bg-white p-4 text-ink-900 ring-1 ring-gray-200 dark:bg-ink-800 dark:text-paper-100 dark:ring-ink-600" aria-label="Gastos del día">
    <h2 class="text-lg font-bold">{{ $fechaGastos === \App\Support\HoraNegocio::fechaHoy() ? 'Gastos de hoy' : 'Gastos del día' }} · ${{ number_format($totalGastos / 100, 2) }}</h2>
    <ul class="mt-2 divide-y divide-gray-200 dark:divide-ink-600">
        @forelse ($gastosDia as $gasto)
            <li class="flex flex-wrap items-center gap-3 py-3">
                @if ($gasto->archivo_ruta)
                    <a href="{{ route($prefijoGastos.'gastos.foto', [$salida, $gasto]) }}" target="_blank" rel="noopener" class="rounded-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-green-600">
                        <img src="{{ route($prefijoGastos.'gastos.foto', [$salida, $gasto]) }}" alt="Comprobante de {{ $gasto->concepto->label() }}" loading="lazy" class="h-14 w-14 rounded-lg object-cover">
                    </a>
                @else
                    <span class="text-sm text-amber-800 dark:text-amber-300">Sin comprobante</span>
                @endif
                <div class="min-w-0 flex-1 break-words"><p class="font-semibold">{{ $gasto->concepto->label() }} · ${{ number_format((float) $gasto->monto, 2) }}</p><p class="text-sm text-gray-600 dark:text-paper-300">{{ $gasto->detalle }}</p></div>
                @if (! ($soloListaGastos ?? false) && auth()->user()->can('borrarGasto', [$salida, $gasto]))
                    <form method="POST" action="{{ route($prefijoGastos.'gastos.destroy', [$salida, $gasto]) }}">
                        @csrf @method('DELETE')
                        <button class="min-h-11 rounded-lg px-3 text-sm text-red-700 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-600 dark:text-red-300" aria-label="Borrar gasto de {{ $gasto->concepto->label() }} por ${{ $gasto->monto }}">Borrar</button>
                    </form>
                @endif
            </li>
        @empty
            <li class="py-3 text-sm text-gray-600 dark:text-paper-300">Todavía no hay gastos en este día.</li>
        @endforelse
    </ul>
    @if ($agregarGasto)
        <details class="mt-3" @if ($errors->hasAny(['concepto', 'monto', 'detalle', 'foto', 'personal_id', 'fecha'])) open @endif>
            <summary class="flex min-h-11 cursor-pointer items-center rounded-xl bg-gray-100 px-4 py-3 font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-green-600 dark:bg-ink-700">+ Agregar gasto</summary>
            <form method="POST" enctype="multipart/form-data" action="{{ route($prefijoGastos.'gastos.store', $salida) }}" class="mt-4 space-y-4">
                @csrf
                @if (! $modoVendedor)
                    <div><label for="gasto-fecha" class="block text-sm font-semibold">Fecha</label>
                        <input id="gasto-fecha" name="fecha" type="date" required max="{{ \App\Support\HoraNegocio::fechaHoy() }}" value="{{ old('fecha', $fechaGastos) }}" class="mt-1 min-h-11 w-full rounded-lg border-gray-300 dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100">
                        @error('fecha')<p class="mt-1 text-sm text-red-700 dark:text-red-300" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div><label for="gasto-personal" class="block text-sm font-semibold">Quién gastó</label>
                        <select id="gasto-personal" name="personal_id" class="mt-1 min-h-11 w-full rounded-lg border-gray-300 dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100">
                            <option value="">Sin indicar</option>
                            @foreach ($salida->personal as $personaGasto)<option value="{{ $personaGasto->id }}" @selected((string) old('personal_id') === (string) $personaGasto->id)>{{ $personaGasto->nombre }}</option>@endforeach
                        </select>
                        @error('personal_id')<p class="mt-1 text-sm text-red-700 dark:text-red-300" role="alert">{{ $message }}</p>@enderror
                    </div>
                @endif
                <fieldset><legend class="text-sm font-semibold">Concepto</legend>
                    <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                        @foreach (\App\Enums\ConceptoGastoRuta::opciones() as $valor => $label)
                            <label class="cursor-pointer"><input type="radio" name="concepto" value="{{ $valor }}" required @checked(old('concepto', 'parqueo') === $valor) class="peer sr-only">
                                <span class="flex min-h-11 items-center justify-center rounded-full px-3 py-3 text-sm font-semibold ring-1 ring-gray-300 peer-checked:bg-green-700 peer-checked:text-white peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-green-600 dark:ring-ink-600">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('concepto')<p class="mt-1 text-sm text-red-700 dark:text-red-300" role="alert">{{ $message }}</p>@enderror
                </fieldset>
                <div><label for="gasto-monto" class="block text-sm font-semibold">Monto ($)</label>
                    <input id="gasto-monto" name="monto" type="number" inputmode="decimal" min="0.01" max="{{ \App\Models\SalidaRutaGasto::MONTO_MAXIMO }}" step="0.01" required value="{{ old('monto') }}" class="mt-1 min-h-11 w-full rounded-lg border-gray-300 text-lg dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100">
                    @error('monto')<p class="mt-1 text-sm text-red-700 dark:text-red-300" role="alert">{{ $message }}</p>@enderror
                </div>
                <div><label for="gasto-detalle" class="block text-sm font-semibold">Detalle <span class="font-normal text-gray-600 dark:text-paper-300">(obligatorio solo para Otro)</span></label>
                    <input id="gasto-detalle" name="detalle" maxlength="200" value="{{ old('detalle') }}" class="mt-1 min-h-11 w-full rounded-lg border-gray-300 dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100">
                    @error('detalle')<p class="mt-1 text-sm text-red-700 dark:text-red-300" role="alert">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="gasto-foto" class="block font-semibold">Tomar foto del recibo</label>
                    <input id="gasto-foto" name="foto" type="file" accept="image/*" capture="environment" aria-describedby="gasto-foto-ayuda" class="mt-2 w-full text-sm text-gray-600 file:mr-3 file:min-h-11 file:cursor-pointer file:rounded-xl file:border-0 file:bg-gray-100 file:px-4 file:py-3 file:font-bold file:text-gray-800 dark:text-paper-300 dark:file:bg-ink-700 dark:file:text-paper-100">
                    <p id="gasto-foto-ayuda" class="text-sm text-gray-600 dark:text-paper-300">Opcional · JPG, PNG o WebP · Hasta 10 MB.</p>
                    @error('foto')<p class="mt-1 text-sm text-red-700 dark:text-red-300" role="alert">{{ $message }}</p>@enderror
                </div>
                <button class="min-h-11 w-full rounded-xl bg-green-700 px-5 py-4 text-lg font-bold text-white hover:bg-green-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600">Guardar gasto</button>
            </form>
        </details>
    @endif
</section>
