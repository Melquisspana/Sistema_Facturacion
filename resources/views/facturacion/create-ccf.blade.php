@php
    // Emisor: si solo hay una opción real, se auto-selecciona y se ocultan los selects
    // (el usuario no debe tener que tocarlos). Los IDs viajan igual en inputs ocultos y
    // el backend los sigue validando/asignando (required + exists). El punto de venta usa
    // el predeterminado configurado (dte.punto_venta_predeterminado) si existe; si no,
    // el único activo (comportamiento anterior). Ver ResuelveEmisorUnico.
    $estabUnico = $establecimientos->count() === 1 ? $establecimientos->first() : null;
    $pvsEmisor = $estabUnico ? $puntosVenta->where('establecimiento_id', $estabUnico->id)->values() : $puntosVenta;
    $pvUnico = $estabUnico ? \App\Support\Dte\ResuelveEmisorUnico::puntoVentaOculto($estabUnico->id) : null;
    $ocultarEstab = (bool) $estabUnico;
    $ocultarPv = (bool) $pvUnico;

    // «Facturar» un pedido (desde «Llegó de la fábrica» o el detalle del pedido): llegan
    // $pedido y $propuesta (FacturarPedido::propuesta). La sala va fija, la OC del pedido es
    // el valor inicial (old() manda), abajo va «Lo que llegó» y el POST va a
    // facturacion.pedidos.facturar, que vuelve a revisar todo. Sin $pedido, nada cambia.
    $conPedido = isset($pedido);
    if ($conPedido) {
        $salaPedido = $pedido->sala;
        $sinNada = $propuesta['envios']->isEmpty();
        $bloqueado = ! $pedido->estaVigente() || $sinNada || $propuesta['problemas'] !== [] || $propuesta['borrador'] !== null;
    }
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $conPedido ? 'Facturar el pedido N.º '.$pedido->id : 'Nuevo CCF (borrador)' }}</h2>
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
                            Estos son del <strong>emisor</strong> ({{ \App\Support\NombreEmpresa::actual() }}), no del cliente.
                        </p>
                    </div>
                @endif

                <form method="POST" action="{{ $conPedido ? route('facturacion.pedidos.facturar', $pedido) : route('facturacion.store-ccf') }}"
                      x-data="{
                          opciones: @js($opcionesCliente),
                          ordenesPorSala: @js((object) ($ordenesPorSala ?? [])),
                          clienteId: @js((string) ($conPedido ? $salaPedido?->cliente_id : old('cliente_id', ''))),
                          sucursalId: @js((string) ($conPedido ? $salaPedido?->id : old('cliente_sucursal_id', ''))),
                          buscar: '',
                          ordenElegida: @js((string) old('numero_orden_compra', $conPedido ? $propuesta['oc'] : '')),
                          abierto: false,
                          esAgente: false,
                          requiereOc: @js($conPedido && \App\Support\Dte\ReglaOrdenCompra::requerida($salaPedido?->cliente, $salaPedido)),
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
                          // Órdenes recibidas por correo de la sala elegida que todavía no tienen CCF.
                          get ordenesSala() {
                              return (this.requiereOc && this.sucursalId !== '') ? (this.ordenesPorSala[this.sucursalId] ?? []) : [];
                          },
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
                            @if ($conPedido)
                            {{-- La sala y el cliente salen del pedido y no se cambian (el servidor los toma de ahí). --}}
                            <p class="block font-medium text-sm text-gray-700">Cliente (contribuyente) / sala</p>
                            <input type="hidden" name="cliente_id" value="{{ $salaPedido?->cliente_id }}">
                            <input type="hidden" name="cliente_sucursal_id" value="{{ $salaPedido?->id }}">
                            <p class="mt-1 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-800">
                                {{ $salaPedido?->cliente?->nombre }} — {{ $salaPedido?->nombre }}{{ $salaPedido?->codigo ? ' · '.$salaPedido->codigo : '' }}
                            </p>
                            <p class="mt-1 text-xs text-gray-500">Sale del pedido N.º {{ $pedido->id }}: no se cambia.</p>
                            <x-input-error :messages="$errors->get('cliente_id')" class="mt-1" />
                            <x-input-error :messages="$errors->get('cliente_sucursal_id')" class="mt-1" />
                            @else
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

                            <p class="mt-1 text-xs text-gray-400">Buscá la razón social o la sala (ej. «Sala Centro»). El receptor fiscal es siempre el cliente.</p>
                            <x-input-error :messages="$errors->get('cliente_id')" class="mt-1" />
                            <x-input-error :messages="$errors->get('cliente_sucursal_id')" class="mt-1" />
                            @if (empty($opcionesCliente))
                                <p class="mt-1 text-xs text-amber-600">No hay clientes contribuyentes activos. Crea uno en Clientes.</p>
                            @endif
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
                            <p class="text-xs text-amber-600">Estos datos pertenecen a {{ \App\Support\NombreEmpresa::actual() }}, no a la sala del cliente. El correlativo se asigna automáticamente al generar.</p>
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
                                              class="mt-2 block w-full md:w-2/3" :value="old('numero_orden_compra', $conPedido ? $propuesta['oc'] : null)"
                                              x-ref="ordenCompra" x-bind:required="requiereOc" x-on:input="ordenElegida = $event.target.value"
                                              placeholder="N.º de orden de compra" />
                                <x-input-error :messages="$errors->get('numero_orden_compra')" class="mt-1" />
                                @if ($conPedido && $propuesta['oc_con_ccf'])
                                    <p class="mt-2 text-sm text-amber-800">
                                        La OC del pedido ya tiene un CCF:
                                        <a href="{{ route('facturacion.show', $propuesta['oc_con_ccf']) }}" class="font-semibold underline">abrirlo</a>.
                                        Revisá que no se facture dos veces.
                                    </p>
                                @endif

                                {{-- Órdenes que llegaron por correo para esta sala y no tienen CCF (de la
                                     más nueva a la más vieja). Elegir una llena el campo; se puede
                                     seguir escribiendo a mano. --}}
                                <div x-show="ordenesSala.length > 0" x-cloak class="mt-3">
                                    <p class="text-sm font-medium text-gray-700">Órdenes recibidas de esta sala sin CCF</p>
                                    <ul class="mt-2 space-y-2">
                                        <template x-for="orden in ordenesSala" :key="orden.numero">
                                            <li>
                                                <button type="button" @click="$refs.ordenCompra.value = orden.numero; ordenElegida = orden.numero"
                                                        class="flex w-full flex-wrap items-center gap-x-3 rounded-md border bg-white px-3 py-2 text-left text-sm hover:bg-gray-50 md:w-2/3"
                                                        :class="ordenElegida === orden.numero ? 'border-indigo-500' : 'border-gray-300'"
                                                        :aria-pressed="ordenElegida === orden.numero">
                                                    <span class="font-mono font-medium text-gray-800" x-text="orden.numero"></span>
                                                    <span class="text-xs text-gray-500" x-text="orden.detalle"></span>
                                                </button>
                                            </li>
                                        </template>
                                    </ul>
                                    <p class="mt-1 text-xs text-gray-500">Elegí una para llenar el campo, o escribí el número a mano.</p>
                                </div>
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

                    @if ($conPedido)
                        @include('facturacion.partials.lo-que-llego', ['pedido' => $pedido, 'propuesta' => $propuesta, 'sinNada' => $sinNada])

                        {{-- Pie de la guía: Cancelar a la izquierda (vuelve a donde se estaba: la
                             lista o la ficha del pedido, $cancelar) y el principal a la derecha. --}}
                        <div class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:justify-end">
                            <a href="{{ $cancelar }}"
                               class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</a>
                            <x-primary-button id="crear-borrador" :disabled="$bloqueado" class="min-h-11 justify-center disabled:cursor-not-allowed disabled:opacity-50">Crear borrador</x-primary-button>
                        </div>
                    @else
                    <div class="flex items-center gap-3">
                        <x-primary-button>Crear CCF</x-primary-button>
                        <a href="{{ route('facturacion.index') }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                    </div>
                    @endif
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
