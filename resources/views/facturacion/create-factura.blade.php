<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Nueva Factura (consumidor final)</h2>
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
                    En la Factura (tipo 01) el precio del producto <strong>ya incluye IVA</strong>; el IVA no se suma aparte.
                    El cliente es opcional y no se pide orden de compra ni retención.
                </p>

                <form method="POST" action="{{ route('facturacion.store-factura') }}"
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
                    <input type="hidden" name="tipo_dte" value="01">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2" @click.outside="abierto = false">
                            <x-input-label for="cliente_buscar" value="Cliente (opcional)" />

                            <input type="hidden" name="cliente_id" :value="clienteId">

                            <div class="relative mt-1">
                                <input id="cliente_buscar" type="text" x-model="buscar" autocomplete="off"
                                       @focus="abierto = true" @input="abierto = true"
                                       placeholder="Buscar por razón social, nombre comercial, NIT, NRC o correo…"
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
                                                <span x-text="c.num_documento ? ('NIT ' + c.num_documento) : (c.nrc ? ('NRC ' + c.nrc) : '')"></span>
                                            </div>
                                        </li>
                                    </template>
                                    <li x-show="filtrados.length === 0" class="px-3 py-2 text-gray-400">Sin coincidencias.</li>
                                </ul>
                            </div>

                            <p class="mt-1 text-xs text-gray-400">Dejá el cliente vacío para una venta a consumidor final sin identificar.</p>
                            <x-input-error :messages="$errors->get('cliente_id')" class="mt-1" />
                        </div>

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

                        <div>
                            <x-input-label for="punto_venta_id" value="Punto de venta emisor *" />
                            <select id="punto_venta_id" name="punto_venta_id"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                <option value="">— Seleccione —</option>
                                @foreach ($puntosVenta as $pv)
                                    <option value="{{ $pv->id }}" @selected(old('punto_venta_id') == $pv->id)>
                                        {{ $pv->codigo }} — {{ $pv->nombre }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('punto_venta_id')" class="mt-1" />
                        </div>

                        <div class="md:col-span-2 -mt-2">
                            <p class="text-xs text-amber-600">Estos datos pertenecen a Dulces La Negrita, no al cliente. El correlativo se asigna automáticamente al generar.</p>
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
                        <x-primary-button>Crear factura consumidor final</x-primary-button>
                        <a href="{{ route('facturacion.index') }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
