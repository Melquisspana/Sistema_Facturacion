@php
    // Emisor: si solo hay una opción real se auto-selecciona y los selects se ocultan.
    // Los IDs viajan igual en inputs ocultos y el backend SIEMPRE los recalcula desde el
    // CCF relacionado (la NC comparte serie con su original), así que esto es solo UX.
    $estabUnico = $establecimientos->count() === 1 ? $establecimientos->first() : null;
    $pvsEmisor = $estabUnico ? $puntosVenta->where('establecimiento_id', $estabUnico->id)->values() : $puntosVenta;
    $pvUnico = $estabUnico ? \App\Support\Dte\ResuelveEmisorUnico::puntoVentaOculto($estabUnico->id) : null;
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Nueva nota de crédito</h2>
    </x-slot>

    {{--
        Formulario ÚNICO de nota de crédito, para cualquier cliente.

        Se entra por dos puertas y las dos terminan en el mismo editor: esta pantalla
        (buscando el CCF) y «Revertir con nota de crédito» desde la ficha de un CCF, que
        crea el borrador con todas las líneas ya acreditadas. La INVALIDACIÓN oficial ante
        Hacienda es otro proceso y vive en su propia tarjeta; no se mezcla acá.

        Orden: primero el CCF —es lo que fija cliente, sala, emisor y orden de compra—, y
        después el tipo de nota, que solo abre los campos que ese tipo necesita.

        Genérico a propósito: ningún cliente está cableado en la pantalla. Los códigos de
        albarán salen del perfil documental del cliente elegido y solo aparecen si ese
        cliente declaró uno; para el resto —que son casi todos— la nota se emite con las
        reglas fiscales generales y no se rotula nada.

        Nada de acá decide nada fiscal: el servidor revalida todo
        (storeNotaCreditoIndependiente + DteBorradorService::crearNotaCredito).
    --}}

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
                        <p class="mt-1">Primero configure un establecimiento y punto de venta del emisor. Son del emisor, no del cliente.</p>
                    </div>
                @endif

                {{-- La URL del buscador va como atributo normal y NO dentro del @js:
                     json_encode escapa las barras y dejaría la ruta ilegible en el HTML. --}}
                <form method="POST" action="{{ route('facturacion.store-nota-credito') }}"
                      data-buscar-ccf="{{ route('facturacion.nota-credito.buscar-ccf') }}"
                      x-data="ncFormulario(@js($datosNc))"
                      class="space-y-5">
                    @csrf

                    {{-- =========== CCF relacionado: lo primero, siempre =========== --}}
                    <div @click.outside="ccfAbierto = false">
                        <x-input-label for="ccf_buscar" value="Buscar CCF relacionado *" />
                        <p class="text-xs text-gray-400">Solo CCF aceptados por Hacienda. Los más recientes primero.</p>

                        {{-- Buscador: oculto hasta que Alpine arranca. Sin JS queda el select
                             de respaldo de más abajo, con el mismo name del POST. --}}
                        <div class="mt-1" x-show="jsListo" x-cloak>
                            <template x-if="ccfId === ''">
                                <div class="relative">
                                    <input id="ccf_buscar" type="text" x-model="ccfBuscar" autocomplete="off"
                                           @focus="buscarCcf(1)" @input.debounce.300ms="buscarCcf(1)"
                                           placeholder="Buscar por correlativo (ej. 1120), N.º de control, orden de compra, cliente o sala…"
                                           class="block w-full border-gray-300 rounded-md shadow-sm text-sm">

                                    <ul x-show="ccfAbierto" x-cloak
                                        class="absolute z-20 mt-1 w-full max-h-96 overflow-auto divide-y divide-gray-100 border border-gray-200 rounded-md bg-white shadow-lg text-sm">
                                        <li x-show="ccfCargando" class="px-3 py-2.5 text-gray-400">Buscando…</li>
                                        <template x-for="c in ccfResultados" :key="c.id">
                                            <li @click="seleccionarCcf(c)" class="px-3 py-2.5 cursor-pointer hover:bg-indigo-50">
                                                <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                                    <span class="font-medium text-gray-900">
                                                        CCF <span x-text="c.numero"></span>
                                                        <span class="font-normal text-gray-500"> · <span x-text="c.cliente_nombre"></span></span>
                                                    </span>
                                                    <span class="font-mono font-semibold text-gray-900" x-text="'$' + c.total"></span>
                                                </div>
                                                <div class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-gray-500">
                                                    <span x-show="c.sala" class="text-indigo-600" x-text="c.sala"></span>
                                                    <span x-text="c.fecha"></span>
                                                    <span x-show="c.orden_compra">OC <span x-text="c.orden_compra"></span></span>
                                                    <span class="font-mono text-gray-400" x-text="c.numero_control"></span>
                                                </div>
                                            </li>
                                        </template>
                                        <li x-show="! ccfCargando && ccfResultados.length === 0" class="px-3 py-2.5 text-gray-400">
                                            Sin coincidencias.
                                        </li>
                                        {{-- Paginación: no se carga el histórico, se avanza. --}}
                                        <li class="flex items-center justify-between gap-2 bg-gray-50 px-3 py-1.5 text-xs">
                                            <button type="button" @click="buscarCcf(ccfPagina - 1)" :disabled="! ccfHayPrevia || ccfCargando"
                                                    class="rounded border border-gray-300 bg-white px-2 py-1 font-medium text-gray-700 disabled:opacity-40 disabled:cursor-not-allowed">
                                                &larr; Anterior
                                            </button>
                                            <span class="text-gray-500">Página <span x-text="ccfPagina"></span></span>
                                            <button type="button" @click="buscarCcf(ccfPagina + 1)" :disabled="! ccfHayMas || ccfCargando"
                                                    class="rounded border border-gray-300 bg-white px-2 py-1 font-medium text-gray-700 disabled:opacity-40 disabled:cursor-not-allowed">
                                                Siguiente &rarr;
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </template>

                            {{-- Resumen COMPACTO del CCF elegido: lo que hay que verificar antes
                                 de acreditar, en una sola línea de datos. --}}
                            <template x-if="ccfId !== ''">
                                <div class="rounded-md border border-indigo-300 bg-indigo-50/40 px-4 py-3">
                                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                        <p class="font-semibold text-gray-900">
                                            CCF <span x-text="ccf?.numero"></span>
                                            <span class="font-normal text-gray-600"> · <span x-text="ccf?.cliente_nombre"></span></span>
                                            <span class="ml-1 inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-[11px] font-medium text-green-800">Aceptado</span>
                                        </p>
                                        <button type="button" @click="limpiarCcf()"
                                                class="text-xs font-medium text-indigo-700 hover:underline">Cambiar</button>
                                    </div>
                                    <dl class="mt-1.5 flex flex-wrap gap-x-5 gap-y-1 text-xs text-gray-600">
                                        <div><dt class="inline text-gray-400">Sala:</dt> <dd class="inline text-indigo-700" x-text="ccf?.sala ?? '—'"></dd></div>
                                        <div><dt class="inline text-gray-400">Fecha:</dt> <dd class="inline" x-text="ccf?.fecha ?? '—'"></dd></div>
                                        <div><dt class="inline text-gray-400">OC:</dt> <dd class="inline" x-text="ccf?.orden_compra ?? '—'"></dd></div>
                                        <div><dt class="inline text-gray-400">Total:</dt> <dd class="inline font-mono font-semibold text-gray-900" x-text="'$' + (ccf?.total ?? '')"></dd></div>
                                        <div><dt class="inline text-gray-400">N.º control:</dt> <dd class="inline font-mono" x-text="ccf?.numero_control ?? '—'"></dd></div>
                                    </dl>
                                </div>
                            </template>
                        </div>

                        {{-- RESPALDO SIN JAVASCRIPT y fuente del id que viaja al POST. Con Alpine
                             se oculta (sigue en el DOM, así que se envía igual) y pierde
                             `required`, que en un control display:none bloquearía el envío; con
                             JS el botón toma su lugar. --}}
                        <div x-show="! jsListo">
                            <select id="dte_relacionado_id" name="dte_relacionado_id" x-ref="selectCcf"
                                    x-model="ccfId" @change="onCcfChange()" :required="! jsListo"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm" required>
                                <option value="">— Seleccione un CCF aceptado por Hacienda —</option>
                                @foreach ($opcionesCcf as $ccf)
                                    <option value="{{ $ccf['id'] }}">{{ 'CCF '.($ccf['numero'] ?? $ccf['id']) }} · {{ $ccf['cliente_nombre'] ?? 'Cliente' }}{{ $ccf['sala'] ? ' — '.$ccf['sala'] : '' }} · {{ $ccf['fecha'] }} · ${{ $ccf['total'] }}{{ $ccf['numero_control'] ? ' · '.$ccf['numero_control'] : '' }}</option>
                                @endforeach
                            </select>
                        </div>

                        <input type="hidden" name="cliente_id" :value="clienteId">
                        <x-input-error :messages="$errors->get('dte_relacionado_id')" class="mt-1" />
                        <x-input-error :messages="$errors->get('cliente_id')" class="mt-1" />
                    </div>

                    {{-- =========== Todo lo demás aparece con el CCF ya elegido =========== --}}
                    <div x-show="ccfId !== ''" x-cloak class="space-y-5 border-t border-gray-100 pt-5">

                        {{-- Tipo de nota: radios en línea, compactos. --}}
                        <div>
                            <x-input-label value="Tipo de nota *" />
                            <div class="mt-1.5 flex flex-wrap gap-2">
                                @foreach ($modalidades as $m)
                                    <label class="inline-flex cursor-pointer items-center gap-2 rounded-md border px-3 py-1.5 text-sm transition"
                                           :class="modalidad === '{{ $m['valor'] }}'
                                               ? 'border-indigo-500 bg-indigo-50 text-indigo-900 font-medium'
                                               : 'border-gray-300 bg-white text-gray-700 hover:border-gray-400'">
                                        <input type="radio" name="modalidad" value="{{ $m['valor'] }}" x-model="modalidad"
                                               @change="onModalidadChange()" class="text-indigo-600 border-gray-300" required>
                                        <span>{{ $m['label'] }}</span>
                                        {{-- Código de albarán que ESTE cliente declaró para esta
                                             modalidad. Sin perfil no aparece nada. --}}
                                        <span x-show="codigoDe('{{ $m['valor'] }}')" x-cloak
                                              class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] text-gray-600"
                                              x-text="codigoDe('{{ $m['valor'] }}')"></span>
                                    </label>
                                @endforeach
                            </div>
                            <x-input-error :messages="$errors->get('modalidad')" class="mt-1" />
                        </div>

                        {{-- Campos propios del tipo elegido. Solo se muestra lo que aplica. --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                            {{-- Devolución / faltante: cuál de los dos (mismo tratamiento fiscal). --}}
                            <div class="sm:col-span-2" x-show="submotivos.length > 0" x-cloak>
                                <x-input-label value="Motivo del ajuste *" />
                                <div class="mt-1.5 flex flex-wrap gap-4">
                                    <template x-for="s in submotivos" :key="s.valor">
                                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                            <input type="radio" name="tipo" :value="s.valor" x-model="tipo" class="text-indigo-600 border-gray-300">
                                            <span x-text="s.label"></span>
                                        </label>
                                    </template>
                                </div>
                                <x-input-error :messages="$errors->get('tipo')" class="mt-1" />
                            </div>

                            {{-- Avería: dónde se detectó. --}}
                            <div class="sm:col-span-2" x-show="pideOrigenAveria" x-cloak>
                                <x-input-label value="¿Dónde se detectó? *" />
                                <div class="mt-1.5 flex flex-wrap gap-4">
                                    @foreach ($origenesAveria as $o)
                                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                            <input type="radio" name="origen_averia" value="{{ $o['valor'] }}" x-model="origenAveria"
                                                   @change="onOrigenChange()" class="text-indigo-600 border-gray-300"
                                                   :required="pideOrigenAveria">
                                            <span>{{ $o['label'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <x-input-error :messages="$errors->get('origen_averia')" class="mt-1" />
                            </div>

                            {{-- Avería de inventario: en qué sala apareció. Puede no ser la del
                                 CCF —se revisa una sala y el producto se facturó en otra del mismo
                                 cliente—, y ahí el motivo pasa a ser obligatorio. --}}
                            <div x-show="pideSalaHallazgo" x-cloak>
                                <x-input-label for="sucursal_hallazgo_id" value="Sala donde se encontró" />
                                <select id="sucursal_hallazgo_id" name="sucursal_hallazgo_id" x-model="salaHallazgoId"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                                    <option value="">— La misma del CCF —</option>
                                    <template x-for="s in salasCliente" :key="s.id">
                                        <option :value="String(s.id)" x-text="s.nombre"></option>
                                    </template>
                                </select>
                                <x-input-error :messages="$errors->get('sucursal_hallazgo_id')" class="mt-1" />
                            </div>

                            {{-- Pronto pago / otro ajuste: a qué sala se emite. --}}
                            <div x-show="permiteOtraSalaReceptora" x-cloak>
                                <x-input-label for="sala_nc" value="Sala receptora de la Nota de Crédito" />
                                <select id="sala_nc" x-model="salaNcId"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                                    <template x-for="s in salasCliente" :key="s.id">
                                        <option :value="String(s.id)" x-text="s.nombre"></option>
                                    </template>
                                </select>
                                <p class="mt-1 text-xs text-gray-400">Predeterminada: la sala del CCF. Puede ser una sala administrativa del mismo cliente.</p>
                            </div>

                            {{-- Motivo: obligatorio solo cuando se cruza de sala. --}}
                            <div class="sm:col-span-2">
                                <x-input-label for="motivo">
                                    <span>Motivo / observaciones</span>
                                    <span x-show="motivoObligatorio" x-cloak class="font-semibold text-amber-700"> — obligatorio</span>
                                    <span x-show="! motivoObligatorio" class="font-normal text-gray-400"> (opcional)</span>
                                </x-input-label>
                                <x-text-input id="motivo" name="motivo" type="text" class="mt-1 block w-full text-sm" :value="old('motivo')"
                                              x-model="motivo" ::required="motivoObligatorio"
                                              placeholder="Ej. Devolución parcial de mercadería" />
                                <x-input-error :messages="$errors->get('motivo')" class="mt-1" />
                            </div>
                        </div>

                        {{-- Advertencia de sala cruzada: un solo cartel, con el porqué. --}}
                        <div x-show="salaCruzada" x-cloak role="status"
                             class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                            <span x-show="receptoraCruzada">
                                La nota se emitirá a una <strong>sala distinta</strong> a la del CCF: cambian el
                                establecimiento y la dirección impresos. El CCF relacionado, el cliente fiscal
                                (NIT/NRC) y el saldo acreditable <strong>no cambian</strong>.
                            </span>
                            <span x-show="hallazgoCruzado && ! receptoraCruzada">
                                La avería se encontró en una <strong>sala distinta</strong> a la del CCF relacionado.
                                Es del mismo cliente, pero conviene dejar constancia.
                            </span>
                            <span class="mt-0.5 block">Explicá el motivo: queda en la auditoría del documento.</span>
                        </div>

                        <input type="hidden" name="cliente_sucursal_id" :value="salaEnviada">
                        <x-input-error :messages="$errors->get('cliente_sucursal_id')" />

                        {{-- Emisor. Con una sola opción real no se pregunta: se dice. --}}
                        @if ($estabUnico)
                            <input type="hidden" name="establecimiento_id" value="{{ $estabUnico->id }}">
                        @endif
                        @if ($pvUnico)
                            <input type="hidden" name="punto_venta_id" value="{{ $pvUnico->id }}">
                        @endif

                        @if (! $estabUnico || ! $pvUnico)
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                @unless ($estabUnico)
                                    <div>
                                        <x-input-label for="establecimiento_id" value="Establecimiento emisor *" />
                                        <select id="establecimiento_id" name="establecimiento_id" x-model="establecimientoId"
                                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm" required>
                                            <option value="">— Seleccione —</option>
                                            @foreach ($establecimientos as $est)
                                                <option value="{{ $est->id }}">{{ $est->codigo }} — {{ $est->nombre }}</option>
                                            @endforeach
                                        </select>
                                        <x-input-error :messages="$errors->get('establecimiento_id')" class="mt-1" />
                                    </div>
                                @endunless
                                @unless ($pvUnico)
                                    <div>
                                        <x-input-label for="punto_venta_id" value="Punto de venta emisor *" />
                                        <select id="punto_venta_id" name="punto_venta_id" x-model="puntoVentaId"
                                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm" required>
                                            <option value="">— Seleccione —</option>
                                            @foreach ($pvsEmisor as $pv)
                                                <option value="{{ $pv->id }}">{{ $pv->codigo }} — {{ $pv->nombre }}</option>
                                            @endforeach
                                        </select>
                                        <x-input-error :messages="$errors->get('punto_venta_id')" class="mt-1" />
                                    </div>
                                @endunless
                            </div>
                        @endif

                        @if ($estabUnico || $pvUnico)
                            <p class="text-xs text-gray-500">
                                @if ($estabUnico)Emisor: <span class="font-medium text-gray-700">{{ $estabUnico->nombre }}</span>@endif
                                @if ($estabUnico && $pvUnico) · @endif
                                @if ($pvUnico)Punto de venta: <span class="font-medium text-gray-700">{{ $pvUnico->nombre }}</span>@endif
                                · El correlativo se asigna al generar.
                            </p>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4">
                        {{-- Con JS el `required` del select oculto no puede actuar, así que el
                             botón toma su lugar. Sin JS el atributo sigue vivo y esto no corre. --}}
                        <x-primary-button ::disabled="jsListo && ccfId === ''">Crear nota de crédito</x-primary-button>
                        <a href="{{ route('facturacion.index') }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                        <span class="text-xs text-gray-400">Se crea un borrador: no se emite, firma ni transmite nada.</span>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{--
        El componente vive en un <script> y NO dentro del atributo x-data. En el atributo,
        cualquier comilla doble lo cierra antes de tiempo y el resto del objeto cae al
        documento como TEXTO VISIBLE para el usuario (ya pasó una vez con un
        querySelector). Acá el atributo se reduce a una llamada de una línea.

        Script clásico en el <body>: se ejecuta durante el parseo, o sea ANTES que el
        módulo diferido de Vite que hace Alpine.start(), así que el listener de
        `alpine:init` siempre llega a tiempo.
    --}}
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('ncFormulario', (d) => ({
                rutaBuscarCcf: '',
                salasPorCliente: d.salasPorCliente,
                submotivosPorModalidad: d.submotivosPorModalidad,
                origenPorModalidad: d.origenPorModalidad,
                otraSalaPorModalidad: d.otraSalaPorModalidad,
                codigosPorCliente: d.codigosPorCliente,
                ccfs: d.ccfs,

                jsListo: false,
                modalidad: d.modalidad,
                tipo: d.tipo,
                origenAveria: d.origenAveria,
                motivo: d.motivo,

                clienteId: d.clienteId,
                salaHallazgoId: d.salaHallazgoId,

                ccfId: d.ccfId,
                ccfBuscar: '',
                ccfAbierto: false,
                ccfCargando: false,
                ccfResultados: [],
                ccfPagina: 1,
                ccfHayMas: false,
                ccfHayPrevia: false,
                ccfPeticion: 0,

                salaNcId: d.salaNcId,
                establecimientoId: d.establecimientoId,
                puntoVentaId: d.puntoVentaId,

                init() {
                    this.jsListo = true;
                    this.rutaBuscarCcf = this.$el.dataset.buscarCcf;
                    this.onModalidadChange();
                    if (this.ccfId !== '') { this.onCcfChange(); }
                },

                // ---- Tipo de nota ----
                get submotivos() { return this.submotivosPorModalidad[this.modalidad] ?? []; },
                get pideOrigenAveria() { return this.origenPorModalidad.includes(this.modalidad); },
                get permiteOtraSalaReceptora() { return this.otraSalaPorModalidad.includes(this.modalidad); },
                // La sala del hallazgo solo tiene sentido cuando la avería NO salió de una
                // entrega: en una entrega el lugar ya lo dice la entrega.
                get pideSalaHallazgo() { return this.pideOrigenAveria && this.origenAveria === 'inventario_sala'; },

                // Código de albarán que ESTE cliente declaró para esta modalidad, o vacío.
                // La mayoría de los clientes no tiene perfil y no ve ningún código.
                codigoDe(modalidad) {
                    return (this.codigosPorCliente[this.clienteId] ?? {})[modalidad] ?? '';
                },

                onModalidadChange() {
                    // El submotivo pertenece a UNA modalidad: al cambiar se reinicia al primero
                    // válido en vez de arrastrar uno ajeno, que el servidor rechazaría.
                    const subs = this.submotivos;
                    this.tipo = subs.length > 0
                        ? (subs.some((s) => s.valor === this.tipo) ? this.tipo : subs[0].valor)
                        : '';
                    if (!this.pideOrigenAveria) { this.origenAveria = ''; }
                    if (!this.pideSalaHallazgo) { this.salaHallazgoId = ''; }
                    if (!this.permiteOtraSalaReceptora) { this.salaNcId = this.salaCcfId; }
                },
                onOrigenChange() {
                    if (!this.pideSalaHallazgo) { this.salaHallazgoId = ''; }
                },

                // ---- Buscador de CCF ----
                async buscarCcf(pagina) {
                    const destino = Math.max(1, pagina || 1);
                    this.ccfAbierto = true;
                    this.ccfCargando = true;
                    // Solo la última petición pinta: escribir rápido, o pulsar Siguiente dos
                    // veces, no debe dejar en pantalla el resultado de una consulta vieja.
                    const propia = ++this.ccfPeticion;

                    const params = new URLSearchParams({ q: this.ccfBuscar, pagina: String(destino) });

                    try {
                        const r = await fetch(this.rutaBuscarCcf + '?' + params.toString(), {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                        });
                        const data = await r.json();
                        if (propia !== this.ccfPeticion) { return; }
                        this.ccfResultados = data.resultados ?? [];
                        this.ccfPagina = data.pagina ?? destino;
                        this.ccfHayMas = !!data.hay_mas;
                        this.ccfHayPrevia = !!data.hay_previa;
                    } catch (e) {
                        if (propia === this.ccfPeticion) {
                            this.ccfResultados = [];
                            this.ccfHayMas = false;
                            this.ccfHayPrevia = false;
                        }
                    } finally {
                        if (propia === this.ccfPeticion) { this.ccfCargando = false; }
                    }
                },

                // El id que viaja al POST sale del <select> de respaldo. Un CCF traído por el
                // buscador puede no estar entre las opciones precargadas, así que primero se
                // le agrega su <option> y solo después se fija ccfId (si no, x-model lo
                // descartaría por no existir).
                seleccionarCcf(c) {
                    this.ccfs[c.id] = c;
                    const sel = this.$refs.selectCcf;
                    if (sel && !Array.from(sel.options).some((o) => o.value === String(c.id))) {
                        const o = document.createElement('option');
                        o.value = c.id;
                        o.textContent = 'CCF ' + (c.numero ?? c.id);
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
                    this.ccfPagina = 1;
                    this.ccfHayMas = false;
                    this.ccfHayPrevia = false;
                    this.onCcfChange();
                },
                get ccf() { return this.ccfs[this.ccfId] ?? null; },
                onCcfChange() {
                    const c = this.ccf;
                    // Cambiar de CCF cambia el cliente y con él las salas y los códigos: lo
                    // elegido para el CCF anterior deja de ser válido y se suelta.
                    this.salaHallazgoId = '';
                    if (c) {
                        this.clienteId = String(c.cliente_id ?? '');
                        this.salaNcId = this.salaCcfId;
                        this.establecimientoId = String(c.establecimiento_id ?? '');
                        this.puntoVentaId = String(c.punto_venta_id ?? '');
                    } else {
                        this.clienteId = '';
                        this.salaNcId = '';
                    }
                },

                // ---- Salas y motivo ----
                get salaCcfId() { return this.ccf && this.ccf.cliente_sucursal_id ? String(this.ccf.cliente_sucursal_id) : ''; },
                get salasCliente() { return this.salasPorCliente[this.clienteId] ?? []; },
                // Valor realmente enviado: la sala elegida solo cuenta cuando el tipo lo
                // permite; si no, siempre la del CCF. El servidor vuelve a validarlo.
                get salaEnviada() { return this.permiteOtraSalaReceptora ? this.salaNcId : this.salaCcfId; },
                get receptoraCruzada() {
                    return this.ccfId !== '' && this.salaEnviada !== '' && this.salaEnviada !== this.salaCcfId;
                },
                get hallazgoCruzado() {
                    return this.pideSalaHallazgo && this.salaHallazgoId !== '' && this.salaHallazgoId !== this.salaCcfId;
                },
                get salaCruzada() { return this.receptoraCruzada || this.hallazgoCruzado; },
                // Las dos formas de cruzar de sala cobran lo mismo: una explicación escrita.
                get motivoObligatorio() { return this.salaCruzada; },
            }));
        });
    </script>
</x-app-layout>
