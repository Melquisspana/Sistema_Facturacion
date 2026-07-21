<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Nueva nota de crédito</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <x-modo-dte-aviso :modo="$modoDte ?? null" />
            <div class="bg-white shadow sm:rounded-lg p-6">

                @if ($errors->any())
                    <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                        <p class="font-medium">Corrige los siguientes errores:</p>
                        <ul class="list-disc list-inside mt-1">
                            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                @if ($establecimientos->isEmpty() || $puntosVenta->isEmpty())
                    <div class="mb-4 rounded-md bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800">
                        <p class="font-medium">Falta configuración del emisor.</p>
                        <p class="mt-1">Primero configure un establecimiento y punto de venta del emisor (Dulces La Negrita).</p>
                    </div>
                @endif

                <div class="mb-4 rounded-md bg-indigo-50 border border-indigo-200 p-4 text-sm text-indigo-900">
                    <p class="font-medium">La nota de crédito se emite contra un CCF aceptado por Hacienda.</p>
                    <p class="mt-1 text-indigo-800">El CCF relacionado es <strong>obligatorio</strong> para cualquier tipo de nota (devolución, faltante, avería, pronto pago, ajuste). El cliente, la sala y la orden de compra se toman de él.</p>
                </div>

                <form method="POST" action="{{ route('facturacion.store-nota-credito') }}"
                      x-data="{
                          opciones: @js($opcionesCliente),
                          ccfs: @js(collect($opcionesCcf)->keyBy('id')),
                          porProductos: @js($tiposPorProductos),
                          tipo: @js(old('tipo', array_key_first($tiposNc))),
                          clienteId: @js((string) old('cliente_id', $preCcf?->cliente_id ?? '')),
                          sucursalId: @js((string) old('cliente_sucursal_id', $preCcf?->cliente_sucursal_id ?? '')),
                          ccfId: @js((string) old('dte_relacionado_id', $preCcf?->id ?? '')),
                          establecimientoId: @js((string) old('establecimiento_id', $preCcf?->establecimiento_id ?? '')),
                          puntoVentaId: @js((string) old('punto_venta_id', $preCcf?->punto_venta_id ?? '')),
                          ordenCompra: @js((string) ($preCcf?->numero_orden_compra ?? '')),
                          buscar: '',
                          abierto: false,
                          descuento: '0.00',
                          condicionLabel: '—',
                          init() {
                              const sel = this.seleccionada;
                              if (sel) { this.buscar = this.etiqueta(sel); this.descuento = sel.descuento_porcentaje; this.condicionLabel = sel.condicion_label; }
                          },
                          mismaOpcion(o) { return String(o.cliente_id) === String(this.clienteId) && String(o.cliente_sucursal_id ?? '') === String(this.sucursalId); },
                          get seleccionada() { return this.opciones.find(o => this.mismaOpcion(o)) ?? null; },
                          etiqueta(o) { return [o.nombre, o.sucursal, (o.num_documento || o.nrc)].filter(Boolean).join(' — '); },
                          get filtrados() {
                              const q = this.buscar.trim().toLowerCase();
                              const base = q === '' ? this.opciones : this.opciones.filter(o =>
                                  [o.nombre, o.sucursal, o.num_documento, o.nrc].filter(Boolean).some(v => String(v).toLowerCase().includes(q)));
                              return base.slice(0, 50);
                          },
                          seleccionar(o) {
                              this.clienteId = String(o.cliente_id);
                              this.sucursalId = o.cliente_sucursal_id ? String(o.cliente_sucursal_id) : '';
                              this.buscar = this.etiqueta(o);
                              this.abierto = false;
                              this.descuento = o.descuento_porcentaje ?? '0.00';
                              this.condicionLabel = o.condicion_label ?? '—';
                          },
                          limpiar() { this.clienteId = ''; this.sucursalId = ''; this.buscar = ''; this.descuento = '0.00'; this.condicionLabel = '—'; },
                          get requiereCcf() { return this.porProductos.includes(this.tipo); },
                          get ccf() { return this.ccfs[this.ccfId] ?? null; },
                          onCcfChange() {
                              const c = this.ccf;
                              if (c) {
                                  this.clienteId = String(c.cliente_id ?? '');
                                  this.sucursalId = c.cliente_sucursal_id ? String(c.cliente_sucursal_id) : '';
                                  this.establecimientoId = String(c.establecimiento_id ?? '');
                                  this.puntoVentaId = String(c.punto_venta_id ?? '');
                                  this.ordenCompra = c.orden_compra ?? '';
                                  const sel = this.seleccionada;
                                  if (sel) { this.buscar = this.etiqueta(sel); this.descuento = sel.descuento_porcentaje; this.condicionLabel = sel.condicion_label; }
                              } else {
                                  this.ordenCompra = '';
                              }
                          },
                      }"
                      class="space-y-6">
                    @csrf

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {{-- Tipo + CCF relacionado: se eligen PRIMERO. El cliente se completa
                             automáticamente a partir del CCF elegido (ver bloque más abajo). --}}
                        <div>
                            <x-input-label for="tipo" value="Tipo de nota de crédito *" />
                            <select id="tipo" name="tipo" x-model="tipo" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                @foreach ($tiposNc as $valor => $label)
                                    <option value="{{ $valor }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('tipo')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="dte_relacionado_id" value="CCF aceptado relacionado *" />
                            <select id="dte_relacionado_id" name="dte_relacionado_id" x-model="ccfId" @change="onCcfChange()"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                <option value="">— Seleccione un CCF aceptado por Hacienda —</option>
                                @foreach ($opcionesCcf as $ccf)
                                    <option value="{{ $ccf['id'] }}">{{ $ccf['numero_control'] ?? $ccf['numero'] }} — {{ $ccf['cliente_nombre'] ?? 'Cliente' }} — {{ $ccf['fecha'] }} — ${{ $ccf['total'] }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-400">Solo aparecen CCF ACEPTADOS por Hacienda: es obligatorio vincular uno.</p>
                            <x-input-error :messages="$errors->get('dte_relacionado_id')" class="mt-1" />
                        </div>

                        {{-- Resumen del CCF elegido --}}
                        <div class="md:col-span-2 rounded-md bg-indigo-50 border border-indigo-200 p-3 text-sm" x-show="ccfId !== ''" x-cloak>
                            <p class="font-medium text-indigo-900 mb-1.5">CCF relacionado</p>
                            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-1">
                                <div><dt class="text-gray-500 text-xs">Cliente</dt><dd class="font-medium text-gray-800" x-text="ccf?.cliente_nombre"></dd></div>
                                <div><dt class="text-gray-500 text-xs">N° de control</dt><dd class="font-medium text-gray-800 font-mono" x-text="ccf?.numero_control"></dd></div>
                                <div><dt class="text-gray-500 text-xs">Fecha</dt><dd class="font-medium text-gray-800" x-text="ccf?.fecha"></dd></div>
                                <div><dt class="text-gray-500 text-xs">Total original</dt><dd class="font-medium text-gray-800" x-text="'$' + ccf?.total"></dd></div>
                            </dl>
                        </div>

                        {{-- Orden de compra vinculada (informativa, copiada del CCF) --}}
                        <div class="md:col-span-2 rounded-md bg-gray-50 border border-gray-200 p-3 text-sm" x-show="ccfId !== ''" x-cloak>
                            <template x-if="ordenCompra">
                                <span><span class="text-gray-500">Orden de compra vinculada:</span> <span class="font-medium" x-text="ordenCompra"></span></span>
                            </template>
                            <template x-if="!ordenCompra">
                                <span class="text-gray-500">El CCF relacionado no tiene orden de compra.</span>
                            </template>
                        </div>

                        {{-- Cliente / sala: SE COMPLETA SOLO al elegir el CCF de arriba (ver
                             "Resumen del CCF elegido"). Este buscador es opcional y solo ayuda a
                             ubicar el CCF por cliente; no filtra el select de CCF ni decide el
                             cliente final. Se oculta una vez que hay un CCF elegido para no
                             sugerir que se pueda cambiar el cliente por separado. --}}
                        <input type="hidden" name="cliente_id" :value="clienteId">
                        <input type="hidden" name="cliente_sucursal_id" :value="sucursalId">
                        <x-input-error :messages="$errors->get('cliente_id')" class="md:col-span-2 -mb-2" />
                        <x-input-error :messages="$errors->get('cliente_sucursal_id')" class="md:col-span-2 -mb-2" />
                        <div class="md:col-span-2" x-show="ccfId === ''" @click.outside="abierto = false">
                            <x-input-label for="cliente_buscar" value="Cliente (contribuyente) / sala" />
                            <p class="text-xs text-gray-400 mb-1">Opcional: el cliente definitivo lo determina el CCF que elijas arriba; buscá aquí solo si te ayuda a ubicarlo.</p>
                            <div class="relative">
                                <input id="cliente_buscar" type="text" x-model="buscar" autocomplete="off"
                                       @focus="abierto = true" @input="abierto = true"
                                       placeholder="Buscar por razón social, sala/sucursal, NIT o NRC…"
                                       class="block w-full border-gray-300 rounded-md shadow-sm pr-16" />
                                <button type="button" x-show="clienteId !== ''" @click="limpiar()" x-cloak
                                        class="absolute inset-y-0 right-2 my-auto h-6 px-2 text-xs text-gray-500 hover:text-gray-700">Limpiar</button>
                                <ul x-show="abierto" x-cloak
                                    class="absolute z-20 mt-1 w-full max-h-64 overflow-auto bg-white border border-gray-200 rounded-md shadow-lg text-sm">
                                    <template x-for="o in filtrados" :key="o.key">
                                        <li @click="seleccionar(o)" class="px-3 py-2 cursor-pointer hover:bg-indigo-50"
                                            :class="mismaOpcion(o) ? 'bg-indigo-50' : ''">
                                            <div class="font-medium text-gray-800">
                                                <span x-text="o.nombre"></span>
                                                <span x-show="o.sucursal" class="text-indigo-600"> — <span x-text="o.sucursal"></span></span>
                                            </div>
                                            <div class="text-xs text-gray-500" x-text="o.num_documento ? ('NIT ' + o.num_documento) : (o.nrc ? ('NRC ' + o.nrc) : '')"></div>
                                        </li>
                                    </template>
                                    <li x-show="filtrados.length === 0" class="px-3 py-2 text-gray-400">Sin coincidencias.</li>
                                </ul>
                            </div>
                        </div>

                        {{-- Emisor: si solo hay una opción real, se auto-selecciona y se ocultan los
                             selects. Los IDs viajan igual en inputs ocultos; el backend SIEMPRE
                             recalcula estos valores desde el CCF relacionado antes de guardar (la NC
                             debe coincidir con su original — ver storeNotaCreditoIndependiente() y
                             DteBorradorService::crearNotaCredito()), así que lo que se muestre acá es
                             solo informativo/UX. Sin CCF elegido, usa el predeterminado configurado
                             (dte.punto_venta_predeterminado) o el único activo. --}}
                        @php
                            $estabUnico = $establecimientos->count() === 1 ? $establecimientos->first() : null;
                            $pvsEmisor = $estabUnico ? $puntosVenta->where('establecimiento_id', $estabUnico->id)->values() : $puntosVenta;
                            $pvUnico = $estabUnico ? \App\Support\Dte\ResuelveEmisorUnico::puntoVentaOculto($estabUnico->id) : null;
                            $ocultarEstab = (bool) $estabUnico;
                            $ocultarPv = (bool) $pvUnico;
                        @endphp

                        @if ($ocultarEstab)
                            <input type="hidden" name="establecimiento_id" value="{{ $estabUnico->id }}">
                        @else
                            <div>
                                <x-input-label for="establecimiento_id" value="Establecimiento emisor *" />
                                <select id="establecimiento_id" name="establecimiento_id" x-model="establecimientoId"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                    <option value="">— Seleccione —</option>
                                    @foreach ($establecimientos as $est)
                                        <option value="{{ $est->id }}">{{ $est->codigo }} — {{ $est->nombre }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('establecimiento_id')" class="mt-1" />
                            </div>
                        @endif

                        @if ($ocultarPv)
                            <input type="hidden" name="punto_venta_id" value="{{ $pvUnico->id }}">
                        @else
                            <div>
                                <x-input-label for="punto_venta_id" value="Punto de venta emisor *" />
                                <select id="punto_venta_id" name="punto_venta_id" x-model="puntoVentaId"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                    <option value="">— Seleccione —</option>
                                    @foreach ($pvsEmisor as $pv)
                                        <option value="{{ $pv->id }}">{{ $pv->codigo }} — {{ $pv->nombre }}</option>
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
                            <p class="text-xs text-amber-600">Estos datos pertenecen a Dulces La Negrita, no a la sala del cliente. El correlativo se asigna al generar.</p>
                        </div>

                        {{-- Informativos cliente/sala --}}
                        <div class="rounded-md bg-gray-50 border border-gray-200 p-3 text-sm">
                            <span class="text-gray-500">Condición aplicada:</span>
                            <span class="font-medium text-gray-800" x-text="condicionLabel"></span>
                        </div>
                        <div class="rounded-md bg-gray-50 border border-gray-200 p-3 text-sm">
                            <span class="text-gray-500">Descuento aplicado:</span>
                            <span class="font-medium text-gray-800" x-text="descuento + '%'"></span>
                        </div>

                        {{-- Motivo --}}
                        <div class="md:col-span-2">
                            <x-input-label for="motivo" value="Motivo / observaciones (opcional)" />
                            <x-text-input id="motivo" name="motivo" type="text" class="mt-1 block w-full" :value="old('motivo')"
                                          placeholder="Ej. Descuento por pronto pago, devolución parcial, ajuste comercial…" />
                            <x-input-error :messages="$errors->get('motivo')" class="mt-1" />
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button>Crear nota de crédito</x-primary-button>
                        <a href="{{ route('facturacion.index') }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
