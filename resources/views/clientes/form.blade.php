<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $cliente->exists ? 'Editar' : 'Nuevo' }} cliente
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">

                @if ($errors->any())
                    <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                        <p class="font-medium">Corrige los siguientes errores:</p>
                        <ul class="list-disc list-inside mt-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST"
                      action="{{ $cliente->exists ? route('clientes.update', $cliente) : route('clientes.store') }}"
                      x-data="{
                          tipo: @js(old('tipo_cliente', $cliente->tipo_cliente?->value)),
                          tamanio: @js(old('tamanio_contribuyente', $cliente->tamanio_contribuyente?->value)),
                          requiereOc: @js((bool) old('requiere_orden_compra', $cliente->requiere_orden_compra)),
                          departamentoId: @js((string) old('departamento_id', $cliente->departamento_id)),
                          municipioId: @js((string) old('municipio_id', $cliente->municipio_id)),
                          distritoId: @js((string) old('distrito_id', $cliente->distrito_id)),
                          municipios: @js($municipios->map(fn ($m) => ['id' => (string) $m->id, 'nombre' => $m->nombre, 'departamento_id' => (string) $m->departamento_id])->values()),
                          distritos: @js($distritos->map(fn ($d) => ['id' => (string) $d->id, 'nombre' => $d->nombre, 'municipio' => $d->municipio, 'departamento_id' => (string) $d->departamento_id])->values()),
                          get esNacional() { return this.tipo === 'consumidor_final' || this.tipo === 'contribuyente'; },
                          get municipiosFiltrados() { return this.municipios.filter(m => m.departamento_id === this.departamentoId); },
                          get distritosFiltrados() { return this.distritos.filter(d => d.departamento_id === this.departamentoId); },
                      }"
                      class="space-y-6">
                    @csrf
                    @if ($cliente->exists) @method('PUT') @endif

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="tipo_cliente" value="Tipo de cliente *" />
                            <select id="tipo_cliente" name="tipo_cliente" x-model="tipo"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                <option value="">— Seleccione —</option>
                                @foreach ($tiposCliente as $valor => $label)
                                    <option value="{{ $valor }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('tipo_cliente')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="tipo_persona" value="Tipo de persona" />
                            <select id="tipo_persona" name="tipo_persona" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="">— Seleccione —</option>
                                @foreach ($tiposPersona as $valor => $label)
                                    <option value="{{ $valor }}" @selected(old('tipo_persona', $cliente->tipo_persona?->value) === $valor)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('tipo_persona')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="tipo_documento" value="Tipo de documento" />
                            <select id="tipo_documento" name="tipo_documento" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="">— Seleccione —</option>
                                @foreach ($tiposDocumento as $valor => $label)
                                    <option value="{{ $valor }}" @selected(old('tipo_documento', $cliente->tipo_documento?->value) === $valor)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('tipo_documento')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="num_documento" value="Número de documento" />
                            <x-text-input id="num_documento" name="num_documento" type="text" class="mt-1 block w-full"
                                :value="old('num_documento', $cliente->num_documento)" />
                            <x-input-error :messages="$errors->get('num_documento')" class="mt-1" />
                        </div>

                        <div x-show="tipo === 'contribuyente'" x-cloak>
                            <x-input-label for="nrc" value="NRC *" />
                            <x-text-input id="nrc" name="nrc" type="text" class="mt-1 block w-full"
                                :value="old('nrc', $cliente->nrc)" />
                            <x-input-error :messages="$errors->get('nrc')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="actividad_economica_id" value="Actividad económica" />
                            <select id="actividad_economica_id" name="actividad_economica_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="">— Seleccione —</option>
                                @foreach ($actividades as $actividad)
                                    <option value="{{ $actividad->id }}" @selected(old('actividad_economica_id', $cliente->actividad_economica_id) == $actividad->id)>{{ $actividad->codigo }} — {{ $actividad->nombre }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('actividad_economica_id')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="nombre" value="Nombre o razón social *" />
                            <x-text-input id="nombre" name="nombre" type="text" class="mt-1 block w-full"
                                :value="old('nombre', $cliente->nombre)" required />
                            <x-input-error :messages="$errors->get('nombre')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="nombre_comercial" value="Nombre comercial" />
                            <x-text-input id="nombre_comercial" name="nombre_comercial" type="text" class="mt-1 block w-full"
                                :value="old('nombre_comercial', $cliente->nombre_comercial)" />
                            <x-input-error :messages="$errors->get('nombre_comercial')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="pais_id" value="País" />
                            <select id="pais_id" name="pais_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="">— Seleccione —</option>
                                @foreach ($paises as $pais)
                                    <option value="{{ $pais->id }}" @selected(old('pais_id', $cliente->pais_id ?? $paisElSalvadorId) == $pais->id)>{{ $pais->nombre }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('pais_id')" class="mt-1" />
                        </div>
                        <div class="hidden md:block"></div>

                        {{-- Ubicación nacional: visible y obligatoria solo para clientes nacionales.
                             Para exportación los selects se deshabilitan (no se envían). --}}
                        <div x-show="esNacional" x-cloak>
                            <x-input-label for="departamento_id" value="Departamento" />
                            <select id="departamento_id" name="departamento_id"
                                    x-model="departamentoId" x-on:change="municipioId=''; distritoId=''"
                                    :disabled="!esNacional"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="">— Seleccione —</option>
                                @foreach ($departamentos as $depto)
                                    <option value="{{ $depto->id }}">{{ $depto->nombre }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('departamento_id')" class="mt-1" />
                        </div>
                        <div x-show="esNacional" x-cloak>
                            <x-input-label for="municipio_id" value="Municipio" />
                            <select id="municipio_id" name="municipio_id"
                                    x-model="municipioId" :disabled="!esNacional"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="">— Seleccione —</option>
                                <template x-for="m in municipiosFiltrados" :key="m.id">
                                    <option :value="m.id" x-text="m.nombre"></option>
                                </template>
                            </select>
                            <x-input-error :messages="$errors->get('municipio_id')" class="mt-1" />
                            <p class="text-xs text-gray-400 mt-1" x-show="esNacional && departamentoId === ''">Seleccione primero un departamento.</p>
                        </div>
                        <div x-show="esNacional" x-cloak>
                            <x-input-label for="distrito_id" value="Distrito (división 2024)" />
                            <select id="distrito_id" name="distrito_id"
                                    x-model="distritoId" :disabled="!esNacional"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="">— Seleccione —</option>
                                <template x-for="d in distritosFiltrados" :key="d.id">
                                    <option :value="d.id" x-text="d.municipio + ' — ' + d.nombre"></option>
                                </template>
                            </select>
                            <x-input-error :messages="$errors->get('distrito_id')" class="mt-1" />
                        </div>

                        <div class="md:col-span-2">
                            <x-input-label for="direccion" value="Dirección" />
                            <x-text-input id="direccion" name="direccion" type="text" class="mt-1 block w-full"
                                :value="old('direccion', $cliente->direccion)" />
                            <x-input-error :messages="$errors->get('direccion')" class="mt-1" />
                        </div>
                        <div class="md:col-span-2">
                            <x-input-label for="complemento_direccion" value="Complemento de dirección" />
                            <x-text-input id="complemento_direccion" name="complemento_direccion" type="text" class="mt-1 block w-full"
                                :value="old('complemento_direccion', $cliente->complemento_direccion)" />
                            <x-input-error :messages="$errors->get('complemento_direccion')" class="mt-1" />
                            <p class="text-xs text-gray-400 mt-1" x-show="tipo === 'exportacion'" x-cloak>Para exportación, indique dirección o complemento.</p>
                        </div>

                        <div>
                            <x-input-label for="correo" value="Correo" />
                            <x-text-input id="correo" name="correo" type="email" class="mt-1 block w-full"
                                :value="old('correo', $cliente->correo)" />
                            <x-input-error :messages="$errors->get('correo')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="telefono" value="Teléfono" />
                            <x-text-input id="telefono" name="telefono" type="text" class="mt-1 block w-full"
                                :value="old('telefono', $cliente->telefono)" />
                            <x-input-error :messages="$errors->get('telefono')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="contacto_principal" value="Contacto principal" />
                            <x-text-input id="contacto_principal" name="contacto_principal" type="text" class="mt-1 block w-full"
                                :value="old('contacto_principal', $cliente->contacto_principal)" />
                            <x-input-error :messages="$errors->get('contacto_principal')" class="mt-1" />
                        </div>
                        <div class="md:col-span-2">
                            <x-input-label for="observaciones" value="Observaciones" />
                            <textarea id="observaciones" name="observaciones" rows="2"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('observaciones', $cliente->observaciones) }}</textarea>
                            <x-input-error :messages="$errors->get('observaciones')" class="mt-1" />
                        </div>
                    </div>

                    {{-- Clasificación fiscal y descuentos (datos del cliente, no del documento) --}}
                    <div class="border-t border-gray-100 pt-5">
                        <h3 class="text-sm font-medium text-gray-700 mb-3">Clasificación fiscal y descuentos</h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="tamanio_contribuyente" value="Tamaño de contribuyente" />
                                <select id="tamanio_contribuyente" name="tamanio_contribuyente" x-model="tamanio"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                    <option value="">— Seleccione —</option>
                                    @foreach ($tamaniosContribuyente as $valor => $label)
                                        <option value="{{ $valor }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('tamanio_contribuyente')" class="mt-1" />

                                {{-- Retención: informativa, no manual. Se deriva del tamaño. --}}
                                <p class="mt-2 text-xs" x-show="tamanio === 'grande'" x-cloak>
                                    <span class="inline-flex px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700">
                                        Este cliente se marcará como agente de retención.
                                    </span>
                                </p>
                                <p class="mt-2 text-xs text-gray-500" x-show="tamanio === 'pequeno' || tamanio === 'mediano'" x-cloak>
                                    Este cliente no se marcará como agente de retención.
                                </p>
                                <p class="mt-2 text-xs text-gray-400" x-show="!tamanio" x-cloak>
                                    Seleccione el tamaño del contribuyente para determinar la retención.
                                </p>
                            </div>

                            <div>
                                <x-input-label for="descuento_global_default" value="Descuento global (%)" />
                                <x-text-input id="descuento_global_default" name="descuento_global_default" type="number"
                                    min="0" max="100" step="0.01" placeholder="0.00" class="mt-1 block w-full"
                                    :value="old('descuento_global_default', $cliente->descuento_global_default)" />
                                <x-input-error :messages="$errors->get('descuento_global_default')" class="mt-1" />
                                <p class="text-xs text-gray-400 mt-1">
                                    Es un porcentaje. Ej. escriba <strong>5</strong> para aplicar 5% de descuento automáticamente en los documentos de este cliente, salvo que una sucursal tenga un porcentaje específico.
                                </p>
                            </div>
                        </div>
                    </div>

                    {{-- Configuración de facturación (CCF) --}}
                    <div class="border-t border-gray-100 pt-5">
                        <h3 class="text-sm font-medium text-gray-700 mb-3">Configuración de facturación (CCF)</h3>

                        <label class="inline-flex items-center">
                            <input type="hidden" name="requiere_orden_compra" value="0">
                            <input type="checkbox" name="requiere_orden_compra" value="1" x-model="requiereOc"
                                class="rounded border-gray-300"
                                @checked(old('requiere_orden_compra', $cliente->requiere_orden_compra))>
                            <span class="ml-2 text-sm text-gray-700">Requiere orden de compra en CCF</span>
                        </label>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                            <div x-show="requiereOc" x-cloak>
                                <x-input-label for="etiqueta_orden_compra" value="Etiqueta del campo" />
                                <x-text-input id="etiqueta_orden_compra" name="etiqueta_orden_compra" type="text" class="mt-1 block w-full"
                                    :value="old('etiqueta_orden_compra', $cliente->etiqueta_orden_compra ?? 'Orden de compra')" />
                                <x-input-error :messages="$errors->get('etiqueta_orden_compra')" class="mt-1" />
                                <p class="text-xs text-gray-400 mt-1">Si se deja vacío, se usará "Orden de compra".</p>
                            </div>
                            <div class="md:col-span-2">
                                <x-input-label for="observaciones_facturacion" value="Observaciones de facturación" />
                                <textarea id="observaciones_facturacion" name="observaciones_facturacion" rows="2"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('observaciones_facturacion', $cliente->observaciones_facturacion) }}</textarea>
                                <x-input-error :messages="$errors->get('observaciones_facturacion')" class="mt-1" />
                            </div>
                        </div>
                    </div>

                    <label class="inline-flex items-center">
                        <input type="hidden" name="activo" value="0">
                        <input type="checkbox" name="activo" value="1" class="rounded border-gray-300"
                            @checked(old('activo', $cliente->activo ?? true))>
                        <span class="ml-2 text-sm text-gray-700">Activo</span>
                    </label>

                    <div class="flex items-center gap-3">
                        <x-primary-button>Guardar</x-primary-button>
                        <a href="{{ route('clientes.index') }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
