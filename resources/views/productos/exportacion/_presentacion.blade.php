{{--
    Campos de una presentación. Onzas y libras no se capturan: se muestran
    calculadas y el servidor las vuelve a calcular. El bruto propone neto + 1 kg
    hasta que alguien lo cambia a mano.
--}}
@php
    $empaques = \App\Support\Exportaciones\EmpaqueExportacion::class;
    $p = $presentacion;
    $unidades = (int) old('unidades_por_caja', $p->unidades_por_caja ?? 144);
    $unidad = (string) old('unidad', $p->unidad ?? $empaques::PREDEFINIDOS['12x12']['unidad']);
    $neto = old('peso_neto_caja_kg', $p->peso_neto_caja_kg);
    $bruto = old('peso_bruto_caja_kg', $p->peso_bruto_caja_kg);

    $inicial = [
        'empaque' => $empaques::clave($unidad, $unidades) ?? 'otro',
        'unidades' => $unidades,
        'unidad' => $unidad,
        'gramos' => old('gramos_por_unidad', $p->gramos_por_unidad) ?? '',
        'neto' => $neto ?? '',
        'bruto' => $bruto ?? '',
        // Un bruto guardado que no es neto + 1 se respeta: alguien lo puso a propósito.
        'brutoManual' => $bruto !== null && $neto !== null && abs((float) $bruto - (float) $neto - 1) > 0.001,
    ];
@endphp

<div x-data="presentacionExportacion({{ Js::from($inicial) }}, {{ Js::from($empaques::PREDEFINIDOS) }})" class="space-y-5">
    <fieldset>
        <legend class="text-sm font-medium text-gray-700">Empaque</legend>
        <div class="mt-2 flex flex-wrap gap-2" role="radiogroup">
            @foreach ($empaques::PREDEFINIDOS as $clave => $empaque)
                <button type="button" role="radio" :aria-checked="empaque === '{{ $clave }}'" @click="elegir('{{ $clave }}')"
                        :class="empaque === '{{ $clave }}' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
                        class="min-w-[7.5rem] rounded-lg border px-3 py-2 text-left text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                    <span class="block font-semibold">{{ $empaque['etiqueta'] }}</span>
                    <span class="block text-xs opacity-80">{{ $empaque['unidades'] }} unidades</span>
                </button>
            @endforeach
            <button type="button" role="radio" :aria-checked="empaque === 'otro'" @click="elegir('otro')"
                    :class="empaque === 'otro' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
                    class="min-w-[7.5rem] rounded-lg border px-3 py-2 text-left text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                <span class="block font-semibold">Otro</span>
                <span class="block text-xs opacity-80">bandeja, fardo, caja master…</span>
            </button>
        </div>

        {{-- Con un empaque predefinido estos dos campos se llenan solos y se ocultan;
             siguen en el formulario para enviarse. --}}
        <div x-show="empaque === 'otro'" x-cloak class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label for="pres_unidad" class="block text-sm font-medium text-gray-700">Cómo se empaca</label>
                <input id="pres_unidad" type="text" name="unidad" x-model="unidad" required maxlength="255"
                       class="mt-1 w-full rounded-md border-gray-300 text-sm" placeholder="ej. Empaque plástico 36x1">
            </div>
            <div>
                <label for="pres_unidades" class="block text-sm font-medium text-gray-700">Unidades por caja</label>
                <input id="pres_unidades" type="number" name="unidades_por_caja" x-model.number="unidades" required min="1" step="1"
                       class="mt-1 w-full rounded-md border-gray-300 text-sm tabular-nums">
            </div>
        </div>
        @error('unidad') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('unidades_por_caja') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </fieldset>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div>
            <label for="pres_gramos" class="block text-sm font-medium text-gray-700">Gramos por unidad</label>
            <input id="pres_gramos" type="number" name="gramos_por_unidad" x-model="gramos" required min="0.01" step="0.01"
                   class="mt-1 w-full rounded-md border-gray-300 text-sm tabular-nums">
            <p class="mt-1 text-xs text-gray-500" x-show="Number(gramos) > 0" x-text="`${onzas()} oz por unidad`"></p>
            @error('gramos_por_unidad') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="pres_neto" class="block text-sm font-medium text-gray-700">Peso neto por caja (kg)</label>
            <input id="pres_neto" type="number" name="peso_neto_caja_kg" x-model="neto" @input="netoCambiado()" required min="0.01" step="0.01"
                   class="mt-1 w-full rounded-md border-gray-300 text-sm tabular-nums">
            <p class="mt-1 text-xs text-gray-500">
                Incluye las bolsas: no es unidades × gramos<span x-show="referencia()" x-text="` (${referencia()} kg)`"></span>.
            </p>
            @error('peso_neto_caja_kg') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="pres_bruto" class="block text-sm font-medium text-gray-700">Peso bruto por caja (kg)</label>
            <input id="pres_bruto" type="number" name="peso_bruto_caja_kg" x-model="bruto" @input="brutoManual = true" min="0" step="0.01"
                   class="mt-1 w-full rounded-md border-gray-300 text-sm tabular-nums">
            <p class="mt-1 text-xs text-gray-500" x-text="brutoManual ? 'Escrito a mano.' : 'Neto + 1 kg de la caja, salvo que lo cambies.'"></p>
            @error('peso_bruto_caja_kg') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <p class="rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600 tabular-nums" x-show="Number(neto) > 0" x-cloak>
        En libras: neto <strong x-text="libras(neto)"></strong> lb · bruto <strong x-text="libras(bruto)"></strong> lb.
        Se calculan solas al guardar.
    </p>

    <div class="sm:w-1/3">
        <label for="pres_precio" class="block text-sm font-medium text-gray-700">Precio base por caja ($)</label>
        <input id="pres_precio" type="number" name="precio_caja" value="{{ old('precio_caja', $p->precio_caja) }}" min="0" step="0.01"
               class="mt-1 w-full rounded-md border-gray-300 text-sm tabular-nums" placeholder="opcional">
        <p class="mt-1 text-xs text-gray-500">Referencia. El precio de cada cliente sale de su última lista de empaque.</p>
        @error('precio_caja') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
</div>

@once
    <script>
        function presentacionExportacion(inicial, predefinidos) {
            return {
                ...inicial,
                elegir(clave) {
                    this.empaque = clave;
                    if (predefinidos[clave]) {
                        this.unidades = predefinidos[clave].unidades;
                        this.unidad = predefinidos[clave].unidad;
                    } else if (Object.values(predefinidos).some(e => e.unidad === this.unidad)) {
                        this.unidad = '';
                    }
                },
                netoCambiado() {
                    if (!this.brutoManual && this.neto !== '') {
                        this.bruto = (Number(this.neto) + 1).toFixed(2);
                    }
                },
                onzas() {
                    return (Number(this.gramos) * 0.035274).toFixed(2);
                },
                libras(kg) {
                    return (Number(kg || 0) * 2.20462).toFixed(2);
                },
                referencia() {
                    const kg = Number(this.unidades) * Number(this.gramos) / 1000;
                    return kg > 0 ? kg.toFixed(2) : '';
                },
            };
        }
    </script>
@endonce
