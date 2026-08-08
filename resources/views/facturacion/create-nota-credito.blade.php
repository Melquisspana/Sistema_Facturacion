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
                          porMonto: @js($tiposPorMonto),
                          salasPorCliente: @js($salasPorCliente),
                          tipo: @js(old('tipo', array_key_first($tiposNc))),
                          clienteId: @js((string) old('cliente_id', $preCcf?->cliente_id ?? '')),
                          sucursalId: @js((string) old('cliente_sucursal_id', $preCcf?->cliente_sucursal_id ?? '')),
                          {{-- Sala RECEPTORA de la NC. Arranca en la sala del CCF; solo las
                               modalidades por monto (pronto pago…) permiten cambiarla. --}}
                          salaNcId: @js((string) old('cliente_sucursal_id', $preCcf?->cliente_sucursal_id ?? '')),
                          ccfId: @js((string) old('dte_relacionado_id', $preCcf?->id ?? '')),
                          establecimientoId: @js((string) old('establecimiento_id', $preCcf?->establecimiento_id ?? '')),
                          puntoVentaId: @js((string) old('punto_venta_id', $preCcf?->punto_venta_id ?? '')),
                          ordenCompra: @js((string) ($preCcf?->numero_orden_compra ?? '')),
                          buscar: '',
                          abierto: false,
                          descuento: '0.00',
                          condicionLabel: '—',
                          {{-- Buscador de CCF (autocomplete al servidor). `jsListo` distingue
                               «Alpine arrancó» de «no hay JS»: sin JS nada de esto corre, el
                               buscador queda oculto por [x-cloak] y se usa el select de
                               respaldo, que conserva el mismo name del POST. --}}
                          jsListo: false,
                          ccfBuscar: '',
                          ccfAbierto: false,
                          ccfCargando: false,
                          ccfResultados: [],
                          ccfPeticion: 0,
                          init() {
                              this.jsListo = true;
                              const sel = this.seleccionada;
                              if (sel) { this.buscar = this.etiqueta(sel); this.descuento = sel.descuento_porcentaje; this.condicionLabel = sel.condicion_label; }
                          },
                          {{-- Una línea por CCF, con lo que hace falta para no confundir dos
                               documentos del mismo cliente. --}}
                          etiquetaCcf(c) {
                              return ['CCF #' + (c.correlativo ?? c.id), c.cliente_nombre, c.sala, c.fecha, '$' + c.total, c.serie]
                                  .filter(Boolean).join(' · ');
                          },
                          async buscarCcf() {
                              this.ccfAbierto = true;
                              this.ccfCargando = true;
                              {{-- Solo la última petición pinta: escribir rápido no debe dejar
                                   en pantalla el resultado de un término ya viejo. --}}
                              const propia = ++this.ccfPeticion;
                              try {
                                  const url = '{{ route('facturacion.nota-credito.buscar-ccf') }}?q=' + encodeURIComponent(this.ccfBuscar);
                                  const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
                                  const data = await r.json();
                                  if (propia !== this.ccfPeticion) { return; }
                                  this.ccfResultados = data.resultados ?? [];
                              } catch (e) {
                                  if (propia === this.ccfPeticion) { this.ccfResultados = []; }
                              } finally {
                                  if (propia === this.ccfPeticion) { this.ccfCargando = false; }
                              }
                          },
                          {{-- El valor que viaja al POST sigue saliendo del <select>. Un CCF
                               traído por el buscador puede no estar entre las opciones
                               precargadas, así que primero se le agrega su <option> y solo
                               después se fija ccfId (si no, x-model lo descartaría). --}}
                          {{-- OJO: este objeto vive DENTRO del atributo x-data, delimitado por
                               comillas dobles. Ninguna comilla doble puede aparecer acá adentro:
                               cierra el atributo antes de tiempo y el resto del componente sale
                               impreso como texto en la página. Por eso la opción ya existente se
                               busca recorriendo sel.options y no con un selector CSS. --}}
                          seleccionarCcf(c) {
                              this.ccfs[c.id] = c;
                              const sel = this.$refs.selectCcf;
                              const yaEsta = sel && Array.from(sel.options).some(o => o.value === String(c.id));
                              if (sel && ! yaEsta) {
                                  const o = document.createElement('option');
                                  o.value = c.id;
                                  o.textContent = this.etiquetaCcf(c);
                                  sel.appendChild(o);
                              }
                              this.ccfId = String(c.id);
                              this.ccfAbierto = false;
                              this.ccfBuscar = '';
                              this.onCcfChange();
                          },
                          limpiarCcf() {
                              this.ccfId = '';
                              this.ccfBuscar = '';
                              this.ccfResultados = [];
                              this.ccfAbierto = false;
                              this.onCcfChange();
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
                          limpiar() { this.clienteId = ''; this.sucursalId = ''; this.salaNcId = ''; this.buscar = ''; this.descuento = '0.00'; this.condicionLabel = '—'; },
                          get requiereCcf() { return this.porProductos.includes(this.tipo); },
                          get ccf() { return this.ccfs[this.ccfId] ?? null; },
                          {{-- ¿Se puede elegir una sala receptora distinta a la del CCF?
                               Solo en las modalidades por monto y con un CCF ya elegido. --}}
                          get permiteOtraSala() { return this.ccfId !== '' && this.porMonto.includes(this.tipo); },
                          {{-- Salas del MISMO cliente del CCF (activas y que permiten NC). --}}
                          get salasCliente() { return this.salasPorCliente[this.clienteId] ?? []; },
                          get nombreSalaCcf() {
                              const s = this.salasCliente.find(s => String(s.id) === String(this.sucursalId));
                              return s ? s.nombre : '';
                          },
                          {{-- Valor realmente enviado: la sala elegida solo cuenta cuando la
                               modalidad lo permite; si no, siempre la del CCF. El servidor
                               vuelve a validar esto (no se confía en el navegador). --}}
                          get salaEnviada() { return this.permiteOtraSala ? this.salaNcId : this.sucursalId; },
                          onTipoChange() {
                              {{-- Al pasar a devolución/avería/faltante se vuelve a la sala del CCF. --}}
                              if (! this.permiteOtraSala) { this.salaNcId = this.sucursalId; }
                          },
                          onCcfChange() {
                              const c = this.ccf;
                              if (c) {
                                  this.clienteId = String(c.cliente_id ?? '');
                                  this.sucursalId = c.cliente_sucursal_id ? String(c.cliente_sucursal_id) : '';
                                  this.salaNcId = this.sucursalId;
                                  this.establecimientoId = String(c.establecimiento_id ?? '');
                                  this.puntoVentaId = String(c.punto_venta_id ?? '');
                                  this.ordenCompra = c.orden_compra ?? '';
                                  const sel = this.seleccionada;
                                  if (sel) { this.buscar = this.etiqueta(sel); this.descuento = sel.descuento_porcentaje; this.condicionLabel = sel.condicion_label; }
                              } else {
                                  this.ordenCompra = '';
                                  this.salaNcId = this.sucursalId;
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
                            <select id="tipo" name="tipo" x-model="tipo" @change="onTipoChange()" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                @foreach ($tiposNc as $valor => $label)
                                    <option value="{{ $valor }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('tipo')" class="mt-1" />
                        </div>
                        {{-- CCF RELACIONADO. El buscador consulta al servidor (~20 resultados
                             por término) en lugar de embeber cientos de documentos. El
                             <select> sigue existiendo debajo: es la fuente del id que viaja al
                             POST y el respaldo cuando no hay JavaScript. El universo ofrecido
                             es el mismo de siempre (CCF 03 con aceptación real del MH) y el
                             servidor revalida el id igual que antes. --}}
                        <div class="md:col-span-2" @click.outside="ccfAbierto = false">
                            <x-input-label for="ccf_buscar" value="CCF aceptado relacionado *" />

                            {{-- Buscador: oculto hasta que Alpine arranca. --}}
                            <div class="relative mt-1" x-show="jsListo" x-cloak>
                                <template x-if="ccfId === ''">
                                    <div>
                                        <input id="ccf_buscar" type="text" x-model="ccfBuscar" autocomplete="off"
                                               @focus="ccfAbierto = true; buscarCcf()" @input.debounce.300ms="buscarCcf()"
                                               placeholder="Buscar por correlativo (ej. 1120), N.º de control, orden de compra, cliente o sala…"
                                               class="block w-full border-gray-300 rounded-md shadow-sm" />
                                        <ul x-show="ccfAbierto" x-cloak
                                            class="absolute z-20 mt-1 w-full max-h-80 overflow-auto bg-white border border-gray-200 rounded-md shadow-lg text-sm">
                                            <li x-show="ccfCargando" class="px-3 py-2 text-gray-400">Buscando…</li>
                                            <template x-for="c in ccfResultados" :key="c.id">
                                                <li @click="seleccionarCcf(c)" class="px-3 py-2 cursor-pointer hover:bg-indigo-50 border-b border-gray-100 last:border-0">
                                                    <div class="flex justify-between gap-3">
                                                        <span class="font-medium text-gray-800">
                                                            CCF #<span x-text="c.correlativo ?? c.id"></span>
                                                            <span class="text-gray-500"> · </span>
                                                            <span x-text="c.cliente_nombre"></span>
                                                            <span x-show="c.sala" class="text-indigo-600"> — <span x-text="c.sala"></span></span>
                                                        </span>
                                                        <span class="font-mono text-gray-700 shrink-0" x-text="'$' + c.total"></span>
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        <span x-text="c.fecha"></span>
                                                        <template x-if="c.orden_compra"><span> · OC <span x-text="c.orden_compra"></span></span></template>
                                                        <span x-show="c.serie"> · <span class="font-medium text-gray-600" x-text="c.serie"></span></span>
                                                    </div>
                                                    <div class="text-xs text-gray-400 font-mono" x-text="c.numero_control ?? c.numero_interno"></div>
                                                </li>
                                            </template>
                                            <li x-show="! ccfCargando && ccfResultados.length === 0" class="px-3 py-2 text-gray-400">Sin coincidencias.</li>
                                        </ul>
                                        <p class="mt-1 text-xs text-gray-400">Solo aparecen CCF ACEPTADOS por Hacienda: es obligatorio vincular uno. Se muestran hasta 20 coincidencias.</p>
                                    </div>
                                </template>

                                {{-- Ya hay un CCF elegido: tarjeta fija, sin desplegable abierto. --}}
                                <template x-if="ccfId !== ''">
                                    <div class="flex items-start justify-between gap-3 rounded-md border border-indigo-300 bg-white p-3">
                                        <div class="text-sm">
                                            <div class="font-medium text-gray-800">
                                                CCF #<span x-text="ccf?.correlativo ?? ccfId"></span>
                                                <span class="text-gray-500"> · </span>
                                                <span x-text="ccf?.cliente_nombre"></span>
                                                <span x-show="ccf?.sala" class="text-indigo-600"> — <span x-text="ccf?.sala"></span></span>
                                            </div>
                                            <div class="text-xs text-gray-500 mt-0.5">
                                                <span x-text="ccf?.fecha"></span> · <span class="font-mono" x-text="'$' + (ccf?.total ?? '')"></span>
                                                <template x-if="ccf?.orden_compra"><span> · OC <span x-text="ccf?.orden_compra"></span></span></template>
                                                <span x-show="ccf?.serie"> · <span class="font-medium text-gray-600" x-text="ccf?.serie"></span></span>
                                            </div>
                                            <div class="text-xs text-gray-400 font-mono mt-0.5" x-text="ccf?.numero_control ?? ccf?.numero_interno"></div>
                                        </div>
                                        <button type="button" @click="limpiarCcf()"
                                                class="shrink-0 text-xs text-indigo-600 hover:text-indigo-800 hover:underline">Cambiar</button>
                                    </div>
                                </template>
                            </div>

                            {{-- RESPALDO SIN JAVASCRIPT y fuente del id del POST. Con Alpine se
                                 oculta (sigue en el DOM, así que se envía igual) y pierde
                                 `required`, que en un control display:none bloquearía el envío;
                                 con JS el botón queda deshabilitado hasta elegir un CCF. Sin
                                 Alpine ninguna de las dos cosas ocurre: select visible y
                                 obligatorio, como antes. --}}
                            <div x-show="! jsListo">
                                <select id="dte_relacionado_id" name="dte_relacionado_id" x-ref="selectCcf"
                                        x-model="ccfId" @change="onCcfChange()" :required="! jsListo"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                    <option value="">— Seleccione un CCF aceptado por Hacienda —</option>
                                    @foreach ($opcionesCcf as $ccf)
                                        <option value="{{ $ccf['id'] }}">{{ 'CCF #'.($ccf['correlativo'] ?? $ccf['id']) }} · {{ $ccf['cliente_nombre'] ?? 'Cliente' }}{{ $ccf['sala'] ? ' — '.$ccf['sala'] : '' }} · {{ $ccf['fecha'] }} · ${{ $ccf['total'] }}{{ $ccf['serie'] ? ' · '.$ccf['serie'] : '' }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-gray-400">Solo aparecen CCF ACEPTADOS por Hacienda: es obligatorio vincular uno.</p>
                            </div>

                            <x-input-error :messages="$errors->get('dte_relacionado_id')" class="mt-1" />
                        </div>

                        {{-- El resumen del CCF elegido lo pinta ahora la tarjeta del buscador
                             (cliente, sala, correlativo, N.º de control, fecha, total, OC y
                             serie), así que el panel que lo repetía se quitó. --}}

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
                        <input type="hidden" name="cliente_sucursal_id" :value="salaEnviada">
                        <x-input-error :messages="$errors->get('cliente_id')" class="md:col-span-2 -mb-2" />
                        <x-input-error :messages="$errors->get('cliente_sucursal_id')" class="md:col-span-2 -mb-2" />

                        {{-- SALA RECEPTORA DE LA NOTA DE CRÉDITO.
                             Solo aparece en las modalidades por MONTO (pronto pago, descuento
                             posterior, ajuste comercial, otro). Permite emitir la nota a una sala
                             administrativa del mismo cliente —p. ej. "Bodega Oficina Central
                             Calleja"— aunque esa sala nunca haya recibido un CCF propio.
                             El CCF relacionado y el cliente fiscal NO cambian. --}}
                        <div class="md:col-span-2 rounded-md border border-amber-200 bg-amber-50 p-3"
                             x-show="permiteOtraSala" x-cloak>
                            <x-input-label for="sala_nc" value="Sala receptora de la Nota de Crédito" />
                            <select id="sala_nc" x-model="salaNcId"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <template x-for="s in salasCliente" :key="s.id">
                                    <option :value="String(s.id)" x-text="s.nombre"></option>
                                </template>
                            </select>
                            <p class="mt-1.5 text-xs text-amber-800">
                                Predeterminada: la sala del CCF relacionado<span x-show="nombreSalaCcf"> (<span x-text="nombreSalaCcf"></span>)</span>.
                                Para <strong>pronto pago</strong> podés elegir una sala administrativa del mismo
                                cliente, como «Bodega Oficina Central Calleja», aunque no tenga CCF propios.
                            </p>
                            <p class="mt-1 text-xs text-amber-700">
                                Cambiar la sala solo cambia el establecimiento y la dirección mostrados.
                                <strong>El CCF relacionado, el cliente fiscal (NIT/NRC) y el saldo acreditable no cambian.</strong>
                            </p>
                        </div>

                        {{-- Devolución / faltante / avería: la sala queda atada a la del CCF. --}}
                        <div class="md:col-span-2 text-xs text-gray-500"
                             x-show="ccfId !== '' && ! permiteOtraSala" x-cloak>
                            Esta nota se emite a la misma sala del CCF relacionado<span x-show="nombreSalaCcf">: <span class="font-medium text-gray-700" x-text="nombreSalaCcf"></span></span>.
                            Solo las notas por monto (pronto pago, descuento posterior, ajuste comercial u otro) pueden usar otra sala.
                        </div>
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
                        {{-- Con JS el `required` del select oculto no puede actuar, así que el
                             botón toma su lugar. Sin JS el atributo sigue vivo y esto no corre.
                             El servidor valida igual en ambos casos. --}}
                        <x-primary-button ::disabled="jsListo && ccfId === ''">Crear nota de crédito</x-primary-button>
                        <a href="{{ route('facturacion.index') }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
