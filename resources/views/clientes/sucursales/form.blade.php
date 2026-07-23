<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $sucursal->exists ? 'Editar' : 'Nueva' }} sucursal — {{ $cliente->nombre }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">

                @if ($errors->any())
                    <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                        <ul class="list-disc list-inside">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST"
                      action="{{ $sucursal->exists ? route('clientes.sucursales.update', [$cliente, $sucursal]) : route('clientes.sucursales.store', $cliente) }}"
                      x-data="{
                          departamentoId: @js((string) old('departamento_id', $sucursal->departamento_id)),
                          municipioSel: @js((string) old('municipio_2024', $sucursal->distrito?->municipio)),
                          distritoId: @js((string) old('distrito_id', $sucursal->distrito_id)),
                          municipioFiscalId: @js((string) old('municipio_id', $sucursal->municipio_id)),
                          distritos: @js($distritos->map(fn ($d) => ['id' => (string) $d->id, 'nombre' => $d->nombre, 'municipio' => $d->municipio, 'departamento_id' => (string) $d->departamento_id])->values()),
                          municipiosFiscales: @js($municipios->map(fn ($m) => ['id' => (string) $m->id, 'nombre' => $m->nombre, 'departamento_id' => (string) $m->departamento_id])->values()),
                          get municipiosDelDepto() { return [...new Set(this.distritos.filter(d => d.departamento_id === this.departamentoId).map(d => d.municipio))].sort(); },
                          get distritosFiltrados() { return this.distritos.filter(d => d.departamento_id === this.departamentoId && d.municipio === this.municipioSel); },
                          get municipiosFiscalesFiltrados() { return this.municipiosFiscales.filter(m => m.departamento_id === this.departamentoId); },
                      }"
                      class="space-y-6">
                    @csrf
                    @if ($sucursal->exists) @method('PUT') @endif

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="nombre" value="Sala / nombre comercial *" />
                            <x-text-input id="nombre" name="nombre" type="text" class="mt-1 block w-full"
                                          :value="old('nombre', $sucursal->nombre)" required />
                            <x-input-error :messages="$errors->get('nombre')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="codigo" value="Código interno (opcional)" />
                            <x-text-input id="codigo" name="codigo" type="text" class="mt-1 block w-full"
                                          :value="old('codigo', $sucursal->codigo)" />
                            <x-input-error :messages="$errors->get('codigo')" class="mt-1" />
                        </div>

                        <div class="md:col-span-2">
                            <x-input-label for="direccion" value="Dirección" />
                            <x-text-input id="direccion" name="direccion" type="text" class="mt-1 block w-full"
                                          :value="old('direccion', $sucursal->direccion)" />
                            <x-input-error :messages="$errors->get('direccion')" class="mt-1" />
                        </div>

                        {{-- Ubicación administrativa (división 2024): Departamento → Municipio → Distrito. Obligatoria por requisito legal. --}}
                        <div>
                            <x-input-label for="departamento_id" value="Departamento *" />
                            <select id="departamento_id" name="departamento_id" x-model="departamentoId"
                                    x-on:change="municipioSel=''; distritoId=''; municipioFiscalId=''"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                <option value="">— Seleccione —</option>
                                @foreach ($departamentos as $depto)
                                    <option value="{{ $depto->id }}">{{ $depto->nombre }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('departamento_id')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="municipio_2024" value="Municipio (agrupación 2024) *" />
                            <select id="municipio_2024" name="municipio_2024" x-model="municipioSel"
                                    x-on:change="distritoId=''"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                <option value="">— Seleccione —</option>
                                <template x-for="m in municipiosDelDepto" :key="m">
                                    <option :value="m" x-text="m"></option>
                                </template>
                            </select>
                            <x-input-error :messages="$errors->get('municipio_2024')" class="mt-1" />
                            <p class="text-xs text-gray-400 mt-1" x-show="departamentoId === ''">Seleccione primero un departamento.</p>
                        </div>

                        <div>
                            <x-input-label for="distrito_id" value="Distrito *" />
                            <select id="distrito_id" name="distrito_id" x-model="distritoId"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                <option value="">— Seleccione —</option>
                                <template x-for="d in distritosFiltrados" :key="d.id">
                                    <option :value="d.id" x-text="d.nombre"></option>
                                </template>
                            </select>
                            <x-input-error :messages="$errors->get('distrito_id')" class="mt-1" />
                            <p class="text-xs text-gray-400 mt-1" x-show="municipioSel === ''">Seleccione primero un municipio.</p>
                        </div>

                        {{-- Municipio fiscal (CAT-013): es el que viaja en el DTE como
                             receptor.direccion.municipio cuando se factura a esta sala.
                             Va aparte de la cadena 2024 porque el catálogo del MH sigue
                             siendo el anterior a la reforma. Opcional: el catálogo sembrado
                             no cubre todos los departamentos. --}}
                        <div>
                            <x-input-label for="municipio_id" value="Municipio fiscal (CAT-013)" />
                            <select id="municipio_id" name="municipio_id" x-model="municipioFiscalId"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="">— Sin municipio —</option>
                                <template x-for="m in municipiosFiscalesFiltrados" :key="m.id">
                                    <option :value="m.id" x-text="m.nombre"></option>
                                </template>
                            </select>
                            <x-input-error :messages="$errors->get('municipio_id')" class="mt-1" />
                            <p class="text-xs text-gray-400 mt-1" x-show="departamentoId === ''" x-cloak>Seleccione primero un departamento.</p>
                            <p class="text-xs text-amber-600 mt-1"
                               x-show="departamentoId !== '' && municipiosFiscalesFiltrados.length === 0" x-cloak>
                                El catálogo CAT-013 no tiene municipios cargados para este departamento.
                            </p>
                            <p class="text-xs text-amber-600 mt-1"
                               x-show="municipioFiscalId === '' && municipiosFiscalesFiltrados.length > 0" x-cloak>
                                Sin municipio fiscal, el CCF emitido a esta sala llevará el campo municipio vacío.
                            </p>
                        </div>

                        <div>
                            <x-input-label for="telefono" value="Teléfono" />
                            <x-text-input id="telefono" name="telefono" type="text" class="mt-1 block w-full"
                                          :value="old('telefono', $sucursal->telefono)" />
                            <x-input-error :messages="$errors->get('telefono')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="correo" value="Correo" />
                            <x-text-input id="correo" name="correo" type="email" class="mt-1 block w-full"
                                          :value="old('correo', $sucursal->correo)" />
                            <x-input-error :messages="$errors->get('correo')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="requiere_orden_compra" value="Requiere orden de compra" />
                            <select id="requiere_orden_compra" name="requiere_orden_compra"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                @php $ocActual = old('requiere_orden_compra', $sucursal->requiere_orden_compra); @endphp
                                <option value="" @selected(is_null($ocActual))>Heredar del cliente</option>
                                <option value="1" @selected($ocActual === true || $ocActual === '1')>Sí</option>
                                <option value="0" @selected($ocActual === false || $ocActual === '0')>No</option>
                            </select>
                            <x-input-error :messages="$errors->get('requiere_orden_compra')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="activo" value="Estado" />
                            <select id="activo" name="activo" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="1" @selected((string) old('activo', (int) $sucursal->activo) === '1')>Activa</option>
                                <option value="0" @selected((string) old('activo', (int) $sucursal->activo) === '0')>Inactiva</option>
                            </select>
                        </div>

                        <div class="md:col-span-2">
                            <x-input-label for="observaciones" value="Observaciones" />
                            <textarea id="observaciones" name="observaciones" rows="2"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('observaciones', $sucursal->observaciones) }}</textarea>
                            <x-input-error :messages="$errors->get('observaciones')" class="mt-1" />
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button>{{ $sucursal->exists ? 'Guardar cambios' : 'Crear sucursal' }}</x-primary-button>
                        <a href="{{ route('clientes.show', $cliente) }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
