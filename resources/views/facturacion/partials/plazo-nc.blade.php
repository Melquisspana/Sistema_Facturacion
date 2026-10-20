@if ($plazoNc = \App\Support\Dte\PlazoNotaCredito::fueraDePlazo($dte))
    <div class="mb-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
        <p id="{{ $prefijoPlazo }}aviso_plazo_nc">{{ \App\Support\Dte\PlazoNotaCredito::aviso($plazoNc) }}</p>
        <label class="mt-2 flex items-center gap-2" for="{{ $prefijoPlazo }}confirmar_plazo_nc">
            <input id="{{ $prefijoPlazo }}confirmar_plazo_nc" type="checkbox" name="confirmar_plazo_nc" value="1" required @checked(old('confirmar_plazo_nc')) aria-describedby="{{ $prefijoPlazo }}aviso_plazo_nc" class="rounded border-gray-300">
            Confirmo la excepción legal al plazo de 3 meses.
        </label>
        <label for="{{ $prefijoPlazo }}motivo_plazo_nc" class="mt-2 block font-medium">Motivo de la excepción legal</label>
        <textarea id="{{ $prefijoPlazo }}motivo_plazo_nc" name="motivo_plazo_nc" required minlength="10" maxlength="500" rows="3" aria-describedby="{{ $prefijoPlazo }}motivo_plazo_nc_error" @error('motivo_plazo_nc') aria-invalid="true" @enderror class="mt-1 w-full rounded-md border-gray-300 text-sm dark:bg-gray-900">{{ old('motivo_plazo_nc') }}</textarea>
        <x-input-error id="{{ $prefijoPlazo }}motivo_plazo_nc_error" :messages="$errors->get('motivo_plazo_nc')" class="mt-1" />
    </div>
@endif
