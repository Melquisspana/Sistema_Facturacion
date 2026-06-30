<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Nuevo CCF (borrador)</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
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
                            Estos son del <strong>emisor</strong> (Dulces La Negrita), no del cliente.
                        </p>
                    </div>
                @endif

                <form method="POST" action="{{ route('facturacion.store-ccf') }}"
                      x-data="{
                          opciones: @js($opcionesCliente),
                          clienteId: @js((string) old('cliente_id', '')),
                          sucursalId: @js((string) old('cliente_sucursal_id', '')),
                          buscar: '',
                          abierto: false,
                          esAgente: false,
                          requiereOc: false,
                          descuento: '0.00',
                          condicionLabel: '—',
                          init() {
                              const sel = this.seleccionada;
                              if (sel) {
                                  this.buscar = this.etiqueta(sel);
                                  this.requiereOc = sel.requiere_oc;
                                  this.descuento = sel.descuento_porcentaje;
                                  this.condicionLabel = sel.condicion_label;
                                  this.esAgente = sel.es_agente_retencion;
                              }
                          },
                          mismaOpcion(o) {
                              return String(o.cliente_id) === String(this.clienteId)
                                  && String(o.cliente_sucursal_id ?? '') === String(this.sucursalId);
                          },
                          get seleccionada() { return this.opciones.find(o => this.mismaOpcion(o)) ?? null; },
                          etiqueta(o) {
                              return [o.nombre, o.sucursal, (o.num_documento || o.nrc)].filter(Boolean).join(' — ');
                          },
                          get filtrados() {
                              const q = this.buscar.trim().toLowerCase();
                              if (q === '') { return this.opciones.slice(0, 50); }
                              return this.opciones.filter(o =>
                                  [o.nombre, o.sucursal, o.num_documento, o.nrc]
                                      .filter(Boolean)
                                      .some(v => String(v).toLowerCase().includes(q))
                              ).slice(0, 50);
                          },
                          seleccionar(o) {
                              this.clienteId = String(o.cliente_id);
                              this.sucursalId = o.cliente_sucursal_id ? String(o.cliente_sucursal_id) : '';
                              this.buscar = this.etiqueta(o);
                              this.abierto = false;
                              this.esAgente = o.es_agente_retencion ?? false;
                              this.requiereOc = o.requiere_oc ?? false;
                              this.descuento = o.descuento_porcentaje ?? '0.00';
                              this.condicionLabel = o.condicion_label ?? '—';
                          },
                          limpiar() {
                              this.clienteId = '';
                              this.sucursalId = '';
                              this.buscar = '';
                              this.esAgente = false;
                              this.requiereOc = false;
                              this.descuento = '0.00';
                              this.condicionLabel = '—';
                          },
                      }"
                      class="space-y-6">
                    @csrf
                    <input type="hidden" name="tipo_dte" value="03">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2" @click.outside="abierto = false">
                            <x-input-label for="cliente_buscar" value="Cliente (contribuyente) / sala *" />

                            {{-- Valores reales enviados al servidor --}}
                            <input type="hidden" name="cliente_id" :value="clienteId">
                            <input type="hidden" name="cliente_sucursal_id" :value="sucursalId">

                            <div class="relative mt-1">
                                <input id="cliente_buscar" type="text" x-model="buscar" autocomplete="off"
                                       @focus="abierto = true" @input="abierto = true"
                                       placeholder="Buscar por razón social, sala/sucursal, NIT o NRC…"
                                       class="block w-full border-gray-300 rounded-md shadow-sm pr-16" />
                                <button type="button" x-show="clienteId !== ''" @click="limpiar()" x-cloak
                                        class="absolute inset-y-0 right-2 my-auto h-6 px-2 text-xs text-gray-500 hover:text-gray-700">
                                    Limpiar
                                </button>

                                <ul x-show="abierto" x-cloak
                                    class="absolute z-20 mt-1 w-full max-h-64 overflow-auto bg-white border border-gray-200 rounded-md shadow-lg text-sm">
                                    <template x-for="o in filtrados" :key="o.key">
                                        <li @click="seleccionar(o)"
                                            class="px-3 py-2 cursor-pointer hover:bg-indigo-50"
                                            :class="mismaOpcion(o) ? 'bg-indigo-50' : ''">
                                            <div class="font-medium text-gray-800">
                                                <span x-text="o.nombre"></span>
                                                <span x-show="o.sucursal" class="text-indigo-600"> — <span x-text="o.sucursal"></span></span>
                                            </div>
                                            <div class="text-xs text-gray-500"
                                                 x-text="o.num_documento ? ('NIT ' + o.num_documento) : (o.nrc ? ('NRC ' + o.nrc) : '')"></div>
                                        </li>
                                    </template>
                                    <li x-show="filtrados.length === 0" class="px-3 py-2 text-gray-400">Sin coincidencias.</li>
                                </ul>
                            </div>

                            <p class="mt-1 text-xs text-gray-400">Buscá la razón social o la sala (ej. «Selectos Santa Rosa», «Merliot»). El receptor fiscal es siempre el cliente.</p>
                            <x-input-error :messages="$errors->get('cliente_id')" class="mt-1" />
                            <x-input-error :messages="$errors->get('cliente_sucursal_id')" class="mt-1" />
                            @if (empty($opcionesCliente))
                                <p class="mt-1 text-xs text-amber-600">No hay clientes contribuyentes activos. Crea uno en Clientes.</p>
                            @endif
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
                            <p class="text-xs text-amber-600">Estos datos pertenecen a Dulces La Negrita, no a la sala del cliente. El correlativo se asigna automáticamente al generar.</p>
                        </div>

                        {{-- Apéndice / Datos adicionales: aquí vive la orden de compra (regla Calleja). --}}
                        <div class="md:col-span-2 rounded-lg border overflow-hidden"
                             :class="requiereOc ? 'border-rose-200' : 'border-gray-200'">
                            <div class="px-4 py-2.5 bg-gray-50 border-b border-gray-200 flex items-center gap-2">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Apéndice · Datos adicionales del DTE</span>
                                <span x-show="requiereOc" x-cloak
                                      class="ml-auto inline-flex items-center rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-medium text-rose-700 border border-rose-200">
                                    Orden de compra obligatoria
                                </span>
                            </div>
                            <div class="p-4">
                                <label for="numero_orden_compra" class="block text-sm font-medium text-gray-700">
                                    Orden de compra
                                    <span x-show="requiereOc" x-cloak class="text-rose-600">*</span>
                                    <span x-show="!requiereOc" x-cloak class="font-normal text-gray-400">(opcional)</span>
                                </label>
                                <p class="mt-0.5 text-xs text-gray-500"
                                   x-text="requiereOc
                                       ? 'Este cliente/sala requiere número de orden de compra para emitir el CCF. No se podrá generar el documento sin ella.'
                                       : 'Si la sala entrega una orden de compra, anótela aquí; quedará en el apéndice del DTE.'"></p>
                                <x-text-input id="numero_orden_compra" name="numero_orden_compra" type="text"
                                              class="mt-2 block w-full md:w-2/3" :value="old('numero_orden_compra')"
                                              x-bind:required="requiereOc"
                                              placeholder="N.º de orden de compra" />
                                <x-input-error :messages="$errors->get('numero_orden_compra')" class="mt-1" />
                            </div>
                        </div>

                        {{-- Informativos: se toman del cliente/sala y se congelan en el DTE --}}
                        <div class="rounded-md bg-gray-50 border border-gray-200 p-3 text-sm">
                            <span class="text-gray-500">Condición de operación aplicada:</span>
                            <span class="font-medium text-gray-800" x-text="condicionLabel"></span>
                        </div>
                        <div class="rounded-md bg-gray-50 border border-gray-200 p-3 text-sm">
                            <span class="text-gray-500">Descuento aplicado:</span>
                            <span class="font-medium text-gray-800" x-text="descuento + '%'"></span>
                        </div>

                        {{-- Retención: informativa, se decide automáticamente al recalcular --}}
                        <div class="md:col-span-2 rounded-md bg-gray-50 border border-gray-200 p-3 text-sm">
                            <span class="text-gray-500">Cliente agente de retención:</span>
                            <span class="font-medium" x-text="esAgente ? 'Sí' : 'No'"></span>
                            <p class="mt-1 text-xs text-gray-400" x-show="esAgente" x-cloak>
                                La retención (1%) se aplicará automáticamente si el monto gravado supera ${{ number_format((float) config('dte.retencion_iva_umbral', 100), 2) }}.
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button>Crear borrador</x-primary-button>
                        <a href="{{ route('facturacion.index') }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
