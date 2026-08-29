<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-paper-100 leading-tight">
                Perfil documental — {{ $cliente->nombre }}
            </h2>
            <a href="{{ route('clientes.show', $cliente) }}" class="text-sm text-gray-500 dark:text-paper-300 hover:underline">← Volver a la ficha</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if ($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700" role="alert">
                    <p class="font-semibold">Revisá lo siguiente:</p>
                    <ul class="mt-1 list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            <div class="rounded-md bg-blue-50 border border-blue-200 p-4 text-sm text-blue-700">
                <p>
                    El perfil declara las exigencias documentales propias de este cliente: qué código usa para cada
                    modalidad de nota de crédito, de dónde sale su descuento y con qué formato se le arma el archivo.
                    <strong>Un cliente sin perfil se comporta como siempre</strong>, así que activar esto no afecta a
                    ningún otro.
                </p>
                <p class="mt-2">
                    Los cambios aplican a los borradores que se recalculen desde ahora. Los documentos ya generados son
                    inmutables y no cambian.
                </p>
            </div>

            <form method="POST" action="{{ route('clientes.perfil-documento.update', $cliente) }}" class="space-y-6">
                @csrf
                @method('PUT')

                {{-- ---------- Cabecera del perfil ---------- --}}
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                    <fieldset>
                        <legend class="font-medium text-gray-700 dark:text-paper-100 mb-1">Perfil del cliente</legend>
                        <p class="text-sm text-gray-500 dark:text-paper-300 mb-4">
                            Desactivarlo conserva toda la configuración pero deja de aplicarla.
                        </p>

                        <div class="space-y-4">
                            <label for="activo" class="flex items-start gap-3">
                                <input id="activo" name="activo" type="checkbox" value="1"
                                       @checked(old('activo', $perfil?->activo ?? false))
                                       class="mt-0.5 rounded border-gray-300 text-indigo-600">
                                <span class="text-sm">
                                    <span class="font-medium text-gray-700 dark:text-paper-100">Perfil activo</span>
                                    <span class="block text-gray-500 dark:text-paper-300">Mientras esté apagado, este cliente calcula como cualquier otro.</span>
                                </span>
                            </label>

                            <label for="exige_albaran_en_nc" class="flex items-start gap-3">
                                <input id="exige_albaran_en_nc" name="exige_albaran_en_nc" type="checkbox" value="1"
                                       @checked(old('exige_albaran_en_nc', $perfil?->exige_albaran_en_nc ?? false))
                                       class="mt-0.5 rounded border-gray-300 text-indigo-600">
                                <span class="text-sm">
                                    <span class="font-medium text-gray-700 dark:text-paper-100">Exigir albarán en la nota de crédito</span>
                                    <span class="block text-gray-500 dark:text-paper-300">Sin los datos del albarán no se podrá generar la nota. Solo alcanza a las modalidades mapeadas abajo.</span>
                                </span>
                            </label>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-6">
                            <div>
                                <label for="codigo_proveedor" class="block text-sm font-medium text-gray-700 dark:text-paper-100">Código de proveedor</label>
                                <input id="codigo_proveedor" name="codigo_proveedor" type="text" maxlength="20" inputmode="numeric"
                                       value="{{ old('codigo_proveedor', $perfil?->codigo_proveedor) }}"
                                       placeholder="001065"
                                       @error('codigo_proveedor') aria-invalid="true" aria-describedby="codigo_proveedor_error" @else aria-describedby="codigo_proveedor_ayuda" @enderror
                                       class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono">
                                <p id="codigo_proveedor_ayuda" class="mt-1 text-xs text-gray-500 dark:text-paper-300">El que el cliente nos asigna. Conserva los ceros iniciales.</p>
                                @error('codigo_proveedor')<p id="codigo_proveedor_error" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>

                            <div>
                                <label for="formato_export" class="block text-sm font-medium text-gray-700 dark:text-paper-100">Formato de exportación</label>
                                <select id="formato_export" name="formato_export"
                                        @error('formato_export') aria-invalid="true" aria-describedby="formato_export_error" @enderror
                                        class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                    <option value="">— Sin exportación —</option>
                                    @foreach ($formatos as $slug)
                                        <option value="{{ $slug }}" @selected(old('formato_export', $perfil?->formato_export) === $slug)>{{ $slug }}</option>
                                    @endforeach
                                </select>
                                @error('formato_export')<p id="formato_export_error" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>

                            <div>
                                <label for="tolerancia_albaran" class="block text-sm font-medium text-gray-700 dark:text-paper-100">Tolerancia contra el albarán</label>
                                <input id="tolerancia_albaran" name="tolerancia_albaran" type="number" step="0.01" min="0" max="9999.99" required
                                       value="{{ old('tolerancia_albaran', $perfil?->tolerancia_albaran ?? '0.00') }}"
                                       @error('tolerancia_albaran') aria-invalid="true" aria-describedby="tolerancia_error" @else aria-describedby="tolerancia_ayuda" @enderror
                                       class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono">
                                <p id="tolerancia_ayuda" class="mt-1 text-xs text-gray-500 dark:text-paper-300">Diferencia que se acepta sin avisar. Nunca ajusta valores fiscales.</p>
                                @error('tolerancia_albaran')<p id="tolerancia_error" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </fieldset>
                </div>

                {{-- ---------- Modalidades ---------- --}}
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                    <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-1">Modalidades de nota de crédito</h3>
                    <p class="text-sm text-gray-500 dark:text-paper-300 mb-4">
                        Marcá solo las que este cliente usa. Una modalidad sin marcar sigue el criterio histórico:
                        devolución, faltante y avería heredan el descuento del CCF; el resto no lleva descuento.
                    </p>

                    <div class="space-y-4">
                        @foreach ($modalidades as $m)
                            @php
                                $clave = $m['tipo']->value;
                                $base = "modalidades.{$clave}";
                                $usar = old("modalidades.{$clave}.usar", $m['usar']);
                                $origen = old("modalidades.{$clave}.descuento_origen", $m['descuento_origen']);
                            @endphp
                            <fieldset class="rounded-md border border-gray-200 dark:border-ink-600 p-4"
                                      x-data="{ usar: {{ $usar ? 'true' : 'false' }}, origen: '{{ $origen }}' }">
                                <legend class="px-1">
                                    <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-paper-100">
                                        <input type="checkbox" name="modalidades[{{ $clave }}][usar]" value="1"
                                               x-model="usar"
                                               class="rounded border-gray-300 text-indigo-600">
                                        {{ $m['tipo']->label() }}
                                    </label>
                                </legend>

                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-2"
                                     :class="usar ? '' : 'opacity-50'">
                                    <div>
                                        <label for="{{ $base }}.codigo" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Código del cliente</label>
                                        <input id="{{ $base }}.codigo" name="modalidades[{{ $clave }}][codigo_externo]" type="text" maxlength="10"
                                               value="{{ old("modalidades.{$clave}.codigo_externo", $m['codigo_externo']) }}"
                                               placeholder="{{ $m['tipo']->esPorAveria() ? 'AC02' : 'AC04' }}" :disabled="! usar"
                                               @error("modalidades.{$clave}.codigo_externo") aria-invalid="true" @enderror
                                               class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono uppercase">
                                        @error("modalidades.{$clave}.codigo_externo")<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                    </div>

                                    <div>
                                        <label for="{{ $base }}.etiqueta" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Etiqueta (opcional)</label>
                                        <input id="{{ $base }}.etiqueta" name="modalidades[{{ $clave }}][etiqueta_externa]" type="text" maxlength="60"
                                               value="{{ old("modalidades.{$clave}.etiqueta_externa", $m['etiqueta_externa']) }}"
                                               placeholder="Nombre que usa el cliente" :disabled="! usar"
                                               class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                    </div>

                                    <div>
                                        <label for="{{ $base }}.origen" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Descuento</label>
                                        <select id="{{ $base }}.origen" name="modalidades[{{ $clave }}][descuento_origen]"
                                                x-model="origen" :disabled="! usar"
                                                @error("modalidades.{$clave}.descuento_origen") aria-invalid="true" @enderror
                                                class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                            @foreach ($origenes as $valor => $etiqueta)
                                                <option value="{{ $valor }}">{{ $etiqueta }}</option>
                                            @endforeach
                                        </select>
                                        @error("modalidades.{$clave}.descuento_origen")<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                    </div>

                                    <div>
                                        <label for="{{ $base }}.tasa" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Tasa propia (%)</label>
                                        <input id="{{ $base }}.tasa" name="modalidades[{{ $clave }}][descuento_tasa]" type="number" step="0.01" min="0" max="100"
                                               value="{{ old("modalidades.{$clave}.descuento_tasa", $m['descuento_tasa']) }}"
                                               :disabled="! usar || origen !== 'tasa_propia'"
                                               @error("modalidades.{$clave}.descuento_tasa") aria-invalid="true" @enderror
                                               class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono">
                                        <p class="mt-1 text-xs text-gray-500 dark:text-paper-300" x-show="origen === 'tasa_propia'" x-cloak>Solo se usa con «tasa propia».</p>
                                        @error("modalidades.{$clave}.descuento_tasa")<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                    </div>
                                </div>
                            </fieldset>
                        @endforeach
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                        Guardar perfil
                    </button>
                    <a href="{{ route('clientes.show', $cliente) }}" class="rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
