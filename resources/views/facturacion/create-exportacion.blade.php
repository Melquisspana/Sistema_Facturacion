@php
    // Emisor: si solo hay una opción real, se auto-selecciona y se ocultan los selects
    // (el usuario no debe tener que tocarlos). Los IDs viajan igual en inputs ocultos y
    // el backend los sigue resolviendo/validando (ResuelveEmisorUnico + required/exists).
    // El punto de venta usa el predeterminado configurado (dte.punto_venta_predeterminado)
    // si existe; si no, el único activo (comportamiento anterior).
    $estabUnico = $establecimientos->count() === 1 ? $establecimientos->first() : null;
    $pvsEmisor = $estabUnico ? $puntosVenta->where('establecimiento_id', $estabUnico->id)->values() : $puntosVenta;
    $pvUnico = $estabUnico ? \App\Support\Dte\ResuelveEmisorUnico::puntoVentaOculto($estabUnico->id) : null;
    $ocultarEstab = (bool) $estabUnico;
    $ocultarPv = (bool) $pvUnico;
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Nueva Factura de exportación</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <x-modo-dte-aviso :modo="$modoDte ?? null" />

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

                @if ($establecimientos->isEmpty() || $puntosVenta->isEmpty())
                    <div class="mb-4 rounded-md bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800">
                        <p class="font-medium">Falta configuración del emisor.</p>
                        <p class="mt-1">
                            Primero configure un establecimiento y punto de venta en
                            @role('administrador')
                                <a href="{{ route('configuracion.establecimientos.index') }}" class="underline">Configuración</a>.
                            @else
                                Configuración (pídalo a un administrador).
                            @endrole
                            Estos son del <strong>emisor</strong>, no del cliente.
                        </p>
                    </div>
                @endif

                <p class="mb-4 text-sm text-gray-500">
                    Factura de exportación (tipo 11): <strong>IVA 0%</strong> (no suma IVA). El cliente debe ser de exportación.
                    Puede llevar <strong>flete</strong> y <strong>seguro</strong>, que se suman al total.
                </p>

                <form method="POST" action="{{ route('facturacion.store-exportacion') }}"
                      x-data="{
                          clientes: @js($clientes),
                          clienteId: @js((string) old('cliente_id', '')),
                          buscar: '',
                          abierto: false,
                          descuento: '0.00',
                          condicionLabel: '—',
                          init() {
                              const sel = this.seleccionado;
                              if (sel) { this.buscar = this.etiqueta(sel); this.descuento = sel.descuento_porcentaje; this.condicionLabel = sel.condicion_label; }
                          },
                          get seleccionado() { return this.clientes.find(c => String(c.id) === String(this.clienteId)) ?? null; },
                          etiqueta(c) {
                              return [c.nombre, c.nombre_comercial, (c.num_documento || c.nrc)].filter(Boolean).join(' — ');
                          },
                          get filtrados() {
                              const q = this.buscar.trim().toLowerCase();
                              if (q === '') { return this.clientes.slice(0, 50); }
                              return this.clientes.filter(c =>
                                  [c.nombre, c.nombre_comercial, c.num_documento, c.nrc, c.correo]
                                      .filter(Boolean)
                                      .some(v => String(v).toLowerCase().includes(q))
                              ).slice(0, 50);
                          },
                          seleccionar(c) {
                              this.clienteId = String(c.id);
                              this.buscar = this.etiqueta(c);
                              this.abierto = false;
                              this.descuento = c.descuento_porcentaje ?? '0.00';
                              this.condicionLabel = c.condicion_label ?? '—';
                          },
                          limpiar() { this.clienteId = ''; this.buscar = ''; this.descuento = '0.00'; this.condicionLabel = '—'; },
                      }"
                      class="space-y-6">
                    @csrf
                    <input type="hidden" name="tipo_dte" value="11">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2" @click.outside="abierto = false">
                            <x-input-label for="cliente_buscar" value="Cliente de exportación *" />

                            <input type="hidden" name="cliente_id" :value="clienteId">

                            <div class="relative mt-1">
                                <input id="cliente_buscar" type="text" x-model="buscar" autocomplete="off"
                                       @focus="abierto = true" @input="abierto = true"
                                       placeholder="Buscar por razón social, nombre comercial, documento o correo…"
                                       class="block w-full border-gray-300 rounded-md shadow-sm pr-16" />
                                <button type="button" x-show="clienteId !== ''" @click="limpiar()" x-cloak
                                        class="absolute inset-y-0 right-2 my-auto h-6 px-2 text-xs text-gray-500 hover:text-gray-700">
                                    Limpiar
                                </button>

                                <ul x-show="abierto" x-cloak
                                    class="absolute z-20 mt-1 w-full max-h-64 overflow-auto bg-white border border-gray-200 rounded-md shadow-lg text-sm">
                                    <template x-for="c in filtrados" :key="c.id">
                                        <li @click="seleccionar(c)"
                                            class="px-3 py-2 cursor-pointer hover:bg-indigo-50"
                                            :class="String(c.id) === String(clienteId) ? 'bg-indigo-50' : ''">
                                            <div class="font-medium text-gray-800" x-text="c.nombre"></div>
                                            <div class="text-xs text-gray-500">
                                                <span x-show="c.nombre_comercial" x-text="c.nombre_comercial"></span>
                                                <span x-show="c.nombre_comercial && (c.num_documento || c.nrc)"> · </span>
                                                <span x-text="c.num_documento || c.nrc || ''"></span>
                                            </div>
                                        </li>
                                    </template>
                                    <li x-show="filtrados.length === 0" class="px-3 py-2 text-gray-400">Sin coincidencias.</li>
                                </ul>
                            </div>

                            <x-input-error :messages="$errors->get('cliente_id')" class="mt-1" />
                            @if (empty($clientes))
                                <p class="mt-1 text-xs text-amber-600">No hay clientes de exportación activos. Crea uno en Clientes.</p>
                            @endif
                        </div>

                        {{-- Establecimiento emisor: oculto si solo hay uno (auto-seleccionado). --}}
                        @if ($ocultarEstab)
                            <input type="hidden" name="establecimiento_id" value="{{ $estabUnico->id }}">
                        @else
                            <div>
                                <x-input-label for="establecimiento_id" value="Establecimiento emisor *" />
                                <select id="establecimiento_id" name="establecimiento_id"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                    <option value="">— Seleccione —</option>
                                    @foreach ($establecimientos as $est)
                                        <option value="{{ $est->id }}" @selected(old('establecimiento_id') == $est->id)>
                                            {{ $est->codigo }} — {{ $est->nombre }}
                                        </option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('establecimiento_id')" class="mt-1" />
                            </div>
                        @endif

                        {{-- Punto de venta emisor: oculto si el establecimiento único tiene un solo PV. --}}
                        @if ($ocultarPv)
                            <input type="hidden" name="punto_venta_id" value="{{ $pvUnico->id }}">
                        @else
                            <div>
                                <x-input-label for="punto_venta_id" value="Punto de venta emisor *" />
                                <select id="punto_venta_id" name="punto_venta_id"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                    <option value="">— Seleccione —</option>
                                    @foreach ($pvsEmisor as $pv)
                                        <option value="{{ $pv->id }}" @selected(old('punto_venta_id') == $pv->id)>
                                            {{ $pv->codigo }} — {{ $pv->nombre }}
                                        </option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('punto_venta_id')" class="mt-1" />
                            </div>
                        @endif

                        <div class="md:col-span-2 -mt-2 space-y-1">
                            @if ($ocultarEstab || $ocultarPv)
                                <p class="text-sm text-gray-600">
                                    @if ($ocultarEstab)Emisor: <span class="font-medium text-gray-800">{{ $estabUnico->nombre }}</span>@endif
                                    @if ($ocultarEstab && $ocultarPv) · @endif
                                    @if ($ocultarPv)Punto de venta: <span class="font-medium text-gray-800">{{ $pvUnico->nombre }}</span>@endif
                                </p>
                            @endif
                            <p class="text-xs text-amber-600">Estos datos pertenecen a Dulces La Negrita, no al cliente. El correlativo se asigna automáticamente al generar.</p>
                        </div>

                        <div>
                            <x-input-label for="flete" value="Flete" />
                            <x-text-input id="flete" name="flete" type="number" step="0.01" min="0"
                                          class="mt-1 block w-full" :value="old('flete', '0')" />
                            <x-input-error :messages="$errors->get('flete')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="seguro" value="Seguro" />
                            <x-text-input id="seguro" name="seguro" type="number" step="0.01" min="0"
                                          class="mt-1 block w-full" :value="old('seguro', '0')" />
                            <x-input-error :messages="$errors->get('seguro')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="tipo_item_expor" value="Tipo de ítem exportado *" />
                            <select id="tipo_item_expor" name="tipo_item_expor"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                <option value="">— Seleccione —</option>
                                @foreach ($tiposItemExpor as $tipo)
                                    {{-- Predeterminado: Bienes (1), el caso más común; el usuario puede cambiarlo. --}}
                                    <option value="{{ $tipo->value }}" @selected(old('tipo_item_expor', config('dte.exportacion.tipo_item_expor_default')) == $tipo->value)>
                                        {{ $tipo->label() }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('tipo_item_expor')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="recinto_fiscal" value="Recinto fiscal *" />
                            <select id="recinto_fiscal" name="recinto_fiscal"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                <option value="">— Seleccione —</option>
                                @foreach ($recintosFiscales as $r)
                                    {{-- Predeterminado: San Bartolo (config('dte.exportacion.recinto_fiscal_default')), el usuario puede cambiarlo. --}}
                                    <option value="{{ $r->codigo }}" @selected(old('recinto_fiscal', config('dte.exportacion.recinto_fiscal_default')) == $r->codigo)>
                                        {{ $r->valor }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('recinto_fiscal')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="tipo_regimen" value="Tipo de régimen *" />
                            <select id="tipo_regimen" name="tipo_regimen"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                <option value="">— Seleccione —</option>
                                @foreach ($tiposRegimen as $tr)
                                    {{-- Predeterminado: EX-1 (Exportación Definitiva), el más usado; el usuario puede cambiarlo. --}}
                                    <option value="{{ $tr->codigo }}" @selected(old('tipo_regimen', config('dte.exportacion.tipo_regimen_default')) == $tr->codigo)>
                                        {{ $tr->valor }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('tipo_regimen')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="regimen" value="Régimen de exportación *" />
                            <select id="regimen" name="regimen"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                <option value="">— Seleccione —</option>
                                @foreach ($regimenes as $rg)
                                    {{-- Predeterminado: 1000.000 (Exportación Definitiva, Régimen Común); el usuario puede cambiarlo. --}}
                                    <option value="{{ $rg->codigo }}" @selected(old('regimen', config('dte.exportacion.regimen_default')) == $rg->codigo)>
                                        {{ $rg->valor }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('regimen')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="cod_incoterms" value="INCOTERM *" />
                            <select id="cod_incoterms" name="cod_incoterms"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                <option value="">— Seleccione —</option>
                                @foreach ($incoterms as $inc)
                                    {{-- Predeterminado: 09 (FOB-Libre a bordo), el más usado; el usuario puede cambiarlo. --}}
                                    <option value="{{ $inc->codigo }}" @selected(old('cod_incoterms', config('dte.exportacion.cod_incoterms_default')) == $inc->codigo)>
                                        {{ $inc->valor }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('cod_incoterms')" class="mt-1" />
                            <p class="mt-1 text-xs text-gray-500">La descripción se resuelve automáticamente del catálogo al guardar.</p>
                        </div>

                        {{-- Informativos: se toman del cliente y se congelan en el DTE --}}
                        <div class="rounded-md bg-gray-50 border border-gray-200 p-3 text-sm">
                            <span class="text-gray-500">Condición aplicada:</span>
                            <span class="font-medium text-gray-800" x-text="condicionLabel"></span>
                        </div>
                        <div class="rounded-md bg-gray-50 border border-gray-200 p-3 text-sm">
                            <span class="text-gray-500">Descuento aplicado:</span>
                            <span class="font-medium text-gray-800" x-text="descuento + '%'"></span>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button>Crear factura exportación</x-primary-button>
                        <a href="{{ route('facturacion.index') }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
