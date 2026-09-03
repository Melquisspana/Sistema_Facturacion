<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Nueva nota de crédito</h2>
    </x-slot>

    {{--
        FORMULARIO ÚNICO de nota de crédito. Antes había dos puertas distintas —esta
        pantalla y la tarjeta del CCF— y un select plano con las siete modalidades
        internas. Ahora las dos puertas ofrecen LAS MISMAS cuatro modalidades operativas
        (ver App\Enums\ModalidadNotaCredito) y esta pantalla es la completa.

        Orden deliberado de los pasos: primero QUÉ pasó (modalidad), después CON QUIÉN
        (cliente y sala) y recién entonces CONTRA QUÉ (el CCF). El CCF se busca acotado
        al cliente y a la sala ya elegidos, y eso es lo que permite reemplazar la lista
        extensa por un buscador de diez resultados por página.

        La INVALIDACIÓN oficial de un DTE no está acá: no es una modalidad de nota de
        crédito sino otro proceso (x-dte.invalidacion-oficial, en la ficha del documento).

        Nada de esta pantalla decide nada fiscal. El servidor revalida todo
        (storeNotaCreditoIndependiente + DteBorradorService::crearNotaCredito): cliente,
        elegibilidad del CCF, sala, origen de la avería y motivo obligatorio.
    --}}

    <div class="py-6">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <x-modo-dte-aviso :modo="$modoDte ?? null" />

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
                <p class="mt-1 text-indigo-800">
                    El CCF relacionado es <strong>obligatorio</strong> en las cuatro modalidades.
                    El cliente, la sala y la orden de compra se toman de él.
                </p>
            </div>

            {{-- La URL del buscador va como atributo normal y NO dentro del @js: json_encode
                 escapa las barras («http:\/\/…») y dejaría la ruta ilegible en el HTML, que
                 es justo donde uno la busca cuando algo falla. --}}
            <form method="POST" action="{{ route('facturacion.store-nota-credito') }}"
                  data-buscar-ccf="{{ route('facturacion.nota-credito.buscar-ccf') }}"
                  x-data="ncFormulario(@js($datosNc))"
                  class="space-y-4">
                @csrf

                {{-- =============== 1. MODALIDAD =============== --}}
                <section class="bg-white shadow sm:rounded-lg p-5">
                    <h3 class="font-semibold text-gray-800">1 &middot; ¿Qué pasó?</h3>
                    <p class="mt-1 text-sm text-gray-500">Elegí la situación que originó la nota. Determina qué se captura después y qué reglas se aplican.</p>

                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach ($modalidades as $m)
                            <label class="relative flex cursor-pointer rounded-lg border p-4 transition"
                                   :class="modalidad === '{{ $m['valor'] }}'
                                       ? 'border-indigo-500 ring-2 ring-indigo-200 bg-indigo-50/50'
                                       : 'border-gray-200 hover:border-gray-300 bg-white'">
                                <input type="radio" name="modalidad" value="{{ $m['valor'] }}" x-model="modalidad"
                                       @change="onModalidadChange()" class="mt-0.5 shrink-0 text-indigo-600 border-gray-300" required>
                                <span class="ml-3 min-w-0">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium text-gray-900">{{ $m['label'] }}</span>
                                        @if ($m['codigo'])
                                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-mono text-gray-600">{{ $m['codigo'] }}</span>
                                        @endif
                                    </span>
                                    <span class="mt-1 block text-xs text-gray-500">{{ $m['descripcion'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('modalidad')" class="mt-2" />

                    {{-- Submotivo: devolución vs. faltante. Mismo tratamiento fiscal (AC04);
                         se conserva la distinción porque son dos hechos distintos. --}}
                    <div class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-3" x-show="submotivos.length > 0" x-cloak>
                        <p class="text-sm font-medium text-gray-700">¿Cuál de los dos fue?</p>
                        <p class="text-xs text-gray-500">Los dos se acreditan igual; la distinción queda registrada en el documento.</p>
                        <div class="mt-2 flex flex-wrap gap-4">
                            <template x-for="s in submotivos" :key="s.valor">
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="radio" name="tipo" :value="s.valor" x-model="tipo" class="text-indigo-600 border-gray-300">
                                    <span x-text="s.label"></span>
                                </label>
                            </template>
                        </div>
                    </div>
                    <x-input-error :messages="$errors->get('tipo')" class="mt-2" />

                    {{-- Origen operativo de la avería. Obligatorio: sin esto no se distingue la
                         avería que apareció con el pedido en la mano de la que apareció
                         revisando un estante, y son dos cosas que no se atienden igual. --}}
                    <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3" x-show="pideOrigenAveria" x-cloak>
                        <p class="text-sm font-medium text-amber-900">¿Dónde se detectó la avería? *</p>
                        <div class="mt-2 space-y-2">
                            @foreach ($origenesAveria as $o)
                                <label class="flex items-start gap-2 text-sm text-amber-900">
                                    <input type="radio" name="origen_averia" value="{{ $o['valor'] }}" x-model="origenAveria"
                                           class="mt-0.5 text-amber-600 border-amber-300" :required="pideOrigenAveria">
                                    <span>
                                        <span class="font-medium">{{ $o['label'] }}</span>
                                        <span class="block text-xs text-amber-800">{{ $o['descripcion'] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('origen_averia')" class="mt-2" />
                    </div>
                </section>

                {{-- =============== 2. CLIENTE Y SALA =============== --}}
                <section class="bg-white shadow sm:rounded-lg p-5" @click.outside="clienteAbierto = false">
                    <h3 class="font-semibold text-gray-800">2 &middot; ¿Qué cliente y qué sala?</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Es el contexto de trabajo: acota la búsqueda del CCF. Una nota de crédito
                        <strong>nunca</strong> puede cruzar de cliente.
                    </p>

                    <div class="relative mt-3">
                        <x-input-label for="cliente_buscar" value="Cliente (contribuyente) / sala *" />
                        <input id="cliente_buscar" type="text" x-model="clienteBuscar" autocomplete="off"
                               @focus="clienteAbierto = true" @input="clienteAbierto = true"
                               placeholder="Buscar por razón social, sala/sucursal, NIT o NRC…"
                               class="mt-1 block w-full border-gray-300 rounded-md shadow-sm pr-20">
                        <button type="button" x-show="clienteId !== ''" @click="limpiarCliente()" x-cloak
                                class="absolute right-2 top-8 h-6 px-2 text-xs text-gray-500 hover:text-gray-700">Limpiar</button>
                        <ul x-show="clienteAbierto" x-cloak
                            class="absolute z-20 mt-1 w-full max-h-64 overflow-auto bg-white border border-gray-200 rounded-md shadow-lg text-sm">
                            <template x-for="o in clientesFiltrados" :key="o.key">
                                <li @click="seleccionarCliente(o)" class="px-3 py-2 cursor-pointer hover:bg-indigo-50"
                                    :class="esClienteActual(o) ? 'bg-indigo-50' : ''">
                                    <div class="font-medium text-gray-800">
                                        <span x-text="o.nombre"></span>
                                        <span x-show="o.sucursal" class="text-indigo-600"> &mdash; <span x-text="o.sucursal"></span></span>
                                    </div>
                                    <div class="text-xs text-gray-500" x-text="o.num_documento ? ('NIT ' + o.num_documento) : (o.nrc ? ('NRC ' + o.nrc) : '')"></div>
                                </li>
                            </template>
                            <li x-show="clientesFiltrados.length === 0" class="px-3 py-2 text-gray-400">Sin coincidencias.</li>
                        </ul>
                    </div>

                    <input type="hidden" name="cliente_id" :value="clienteId">
                    <input type="hidden" name="contexto_sucursal_id" :value="contextoSalaId">
                    <x-input-error :messages="$errors->get('cliente_id')" class="mt-1" />

                    <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm" x-show="clienteId !== ''" x-cloak>
                        <div class="rounded-md bg-gray-50 border border-gray-200 p-3">
                            <span class="text-gray-500">Condición aplicada:</span>
                            <span class="font-medium text-gray-800" x-text="condicionLabel"></span>
                        </div>
                        <div class="rounded-md bg-gray-50 border border-gray-200 p-3">
                            <span class="text-gray-500">Descuento aplicado:</span>
                            <span class="font-medium text-gray-800" x-text="descuento + '%'"></span>
                        </div>
                    </div>
                </section>

                {{-- =============== 3. CCF RELACIONADO =============== --}}
                <section class="bg-white shadow sm:rounded-lg p-5" @click.outside="ccfAbierto = false">
                    <h3 class="font-semibold text-gray-800">3 &middot; ¿Contra qué CCF?</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Toda nota de crédito se emite contra un CCF <strong>aceptado por Hacienda</strong> del mismo cliente.
                        Nunca se ofrecen documentos rechazados, invalidados, archivados ni de otro ambiente.
                    </p>

                    {{-- Salir de la sala del contexto: se permite, pero avisado y con motivo. --}}
                    <label class="mt-3 flex items-start gap-2 text-sm text-gray-700" x-show="clienteId !== ''" x-cloak>
                        <input type="checkbox" x-model="otraSala" @change="reiniciarBusqueda()" class="mt-0.5 rounded border-gray-300 text-amber-600">
                        <span>Buscar CCF de <strong>otra sala</strong> del mismo cliente</span>
                    </label>
                    <div class="mt-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" x-show="otraSala" x-cloak role="status">
                        <p class="font-semibold">Estás saliendo de la sala del contexto.</p>
                        <p class="mt-1">
                            Acreditar contra un CCF de otra sala del mismo cliente es excepcional.
                            El <strong>motivo pasa a ser obligatorio</strong> (paso 5) para dejar constancia de por qué.
                        </p>
                    </div>

                    {{-- Buscador paginado. Oculto hasta que Alpine arranca: sin JS se usa el
                         select de respaldo de más abajo, que conserva el mismo name del POST. --}}
                    <div class="relative mt-3" x-show="jsListo" x-cloak>
                        <template x-if="ccfId === ''">
                            <div>
                                <x-input-label for="ccf_buscar" value="CCF relacionado (aceptado) *" />
                                <input id="ccf_buscar" type="text" x-model="ccfBuscar" autocomplete="off"
                                       :disabled="clienteId === ''"
                                       @focus="buscarCcf(1)" @input.debounce.300ms="buscarCcf(1)"
                                       placeholder="Buscar por correlativo (ej. 1120), N.º de control, orden de compra o fecha…"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm disabled:bg-gray-100 disabled:text-gray-400">
                                <p class="mt-1 text-xs text-gray-400" x-show="clienteId === ''">Elegí primero el cliente y la sala del paso 2.</p>

                                <ul x-show="ccfAbierto && clienteId !== ''" x-cloak
                                    class="mt-1 w-full divide-y divide-gray-100 border border-gray-200 rounded-md bg-white text-sm">
                                    <li x-show="ccfCargando" class="px-3 py-3 text-gray-400">Buscando…</li>
                                    <template x-for="c in ccfResultados" :key="c.id">
                                        <li @click="seleccionarCcf(c)" class="px-3 py-3 cursor-pointer hover:bg-indigo-50">
                                            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                                <span class="font-medium text-gray-900">CCF <span x-text="c.numero"></span></span>
                                                <span class="font-mono font-semibold text-gray-900" x-text="'$' + c.total"></span>
                                            </div>
                                            <div class="mt-0.5 text-xs text-gray-500 font-mono break-all" x-text="c.numero_control"></div>
                                            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-gray-600">
                                                <span x-show="c.sala" class="text-indigo-600" x-text="c.sala"></span>
                                                <span x-text="c.fecha"></span>
                                                <span x-show="c.orden_compra">OC <span x-text="c.orden_compra"></span></span>
                                                <span x-show="c.serie" class="text-gray-400" x-text="c.serie"></span>
                                            </div>
                                        </li>
                                    </template>
                                    <li x-show="! ccfCargando && ccfResultados.length === 0" class="px-3 py-3 text-gray-400">
                                        Sin coincidencias en esta página.
                                    </li>

                                    {{-- Paginación: no se carga el histórico, se avanza. --}}
                                    <li class="flex items-center justify-between gap-2 bg-gray-50 px-3 py-2 text-xs">
                                        <button type="button" @click="buscarCcf(ccfPagina - 1)" :disabled="! ccfHayPrevia || ccfCargando"
                                                class="rounded-md border border-gray-300 bg-white px-3 py-1.5 font-medium text-gray-700 disabled:opacity-40 disabled:cursor-not-allowed">
                                            &larr; Anterior
                                        </button>
                                        <span class="text-gray-500">Página <span x-text="ccfPagina"></span></span>
                                        <button type="button" @click="buscarCcf(ccfPagina + 1)" :disabled="! ccfHayMas || ccfCargando"
                                                class="rounded-md border border-gray-300 bg-white px-3 py-1.5 font-medium text-gray-700 disabled:opacity-40 disabled:cursor-not-allowed">
                                            Siguiente &rarr;
                                        </button>
                                    </li>
                                </ul>
                                <p class="mt-1 text-xs text-gray-400">Los más recientes primero. Se muestran 10 por página.</p>
                            </div>
                        </template>

                        {{-- CCF ya elegido: ficha fija con los seis datos que hay que verificar. --}}
                        <template x-if="ccfId !== ''">
                            <div class="rounded-md border-2 border-indigo-300 bg-indigo-50/40 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <p class="font-semibold text-gray-900">CCF <span x-text="ccf?.numero"></span></p>
                                    <button type="button" @click="limpiarCcf()"
                                            class="shrink-0 text-xs font-medium text-indigo-700 hover:underline">Cambiar</button>
                                </div>
                                <dl class="mt-2 grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-2 text-xs">
                                    <div class="col-span-2 sm:col-span-3">
                                        <dt class="text-gray-500">N.º de control</dt>
                                        <dd class="font-mono text-gray-800 break-all" x-text="ccf?.numero_control ?? '—'"></dd>
                                    </div>
                                    <div><dt class="text-gray-500">Orden de compra</dt><dd class="text-gray-800" x-text="ccf?.orden_compra ?? '—'"></dd></div>
                                    <div><dt class="text-gray-500">Sala</dt><dd class="text-indigo-700" x-text="ccf?.sala ?? '—'"></dd></div>
                                    <div><dt class="text-gray-500">Fecha</dt><dd class="text-gray-800" x-text="ccf?.fecha ?? '—'"></dd></div>
                                    <div><dt class="text-gray-500">Total</dt><dd class="font-mono font-semibold text-gray-900" x-text="'$' + (ccf?.total ?? '')"></dd></div>
                                    <div><dt class="text-gray-500">Serie</dt><dd class="text-gray-800" x-text="ccf?.serie ?? '—'"></dd></div>
                                </dl>
                            </div>
                        </template>
                    </div>

                    {{-- RESPALDO SIN JAVASCRIPT y fuente del id que viaja al POST. Con Alpine se
                         oculta (sigue en el DOM, así que se envía igual) y pierde `required`,
                         que en un control display:none bloquearía el envío; con JS el botón
                         queda deshabilitado hasta elegir un CCF. Sin Alpine ninguna de las dos
                         cosas ocurre: select visible y obligatorio, como siempre. --}}
                    <div x-show="! jsListo">
                        <x-input-label for="dte_relacionado_id" value="CCF relacionado (aceptado) *" class="mt-3" />
                        <select id="dte_relacionado_id" name="dte_relacionado_id" x-ref="selectCcf"
                                x-model="ccfId" @change="onCcfChange()" :required="! jsListo"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                            <option value="">— Seleccione un CCF aceptado por Hacienda —</option>
                            @foreach ($opcionesCcf as $ccf)
                                <option value="{{ $ccf['id'] }}">{{ 'CCF '.($ccf['numero'] ?? $ccf['id']) }} · {{ $ccf['cliente_nombre'] ?? 'Cliente' }}{{ $ccf['sala'] ? ' — '.$ccf['sala'] : '' }} · {{ $ccf['fecha'] }} · ${{ $ccf['total'] }}{{ $ccf['numero_control'] ? ' · '.$ccf['numero_control'] : '' }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-400">Solo aparecen CCF ACEPTADOS por Hacienda: es obligatorio vincular uno.</p>
                    </div>

                    <x-input-error :messages="$errors->get('dte_relacionado_id')" class="mt-1" />
                </section>

                {{-- =============== 4. SALA RECEPTORA =============== --}}
                <section class="bg-white shadow sm:rounded-lg p-5" x-show="ccfId !== ''" x-cloak>
                    <h3 class="font-semibold text-gray-800">4 &middot; ¿A qué sala se emite?</h3>

                    {{-- Devolución / faltante / avería: atadas a la sala del CCF. --}}
                    <p class="mt-1 text-sm text-gray-500" x-show="! permiteOtraSalaReceptora">
                        Esta nota se emite a la misma sala del CCF relacionado<span x-show="nombreSalaCcf">:
                        <span class="font-medium text-gray-800" x-text="nombreSalaCcf"></span></span>.
                        Solo pronto pago y otro ajuste pueden usar una sala distinta.
                    </p>

                    <div x-show="permiteOtraSalaReceptora" x-cloak>
                        <p class="mt-1 text-sm text-gray-500">
                            Predeterminada: la sala del CCF<span x-show="nombreSalaCcf"> (<span x-text="nombreSalaCcf"></span>)</span>.
                            Para pronto pago podés emitir a una sala administrativa del mismo cliente
                            —«Bodega Oficina Central Calleja»— aunque nunca haya recibido un CCF.
                        </p>
                        <x-input-label for="sala_nc" value="Sala receptora de la Nota de Crédito" class="mt-3" />
                        <select id="sala_nc" x-model="salaNcId" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            <template x-for="s in salasCliente" :key="s.id">
                                <option :value="String(s.id)" x-text="s.nombre"></option>
                            </template>
                        </select>

                        <div class="mt-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
                             x-show="salaReceptoraCambiada" x-cloak role="status">
                            <p class="font-semibold">La nota se emitirá a una sala distinta a la del CCF.</p>
                            <p class="mt-1">
                                Cambia el establecimiento y la dirección impresos. <strong>El CCF relacionado,
                                el cliente fiscal (NIT/NRC) y el saldo acreditable no cambian.</strong>
                                El <strong>motivo pasa a ser obligatorio</strong> (paso 5).
                            </p>
                        </div>
                    </div>

                    <input type="hidden" name="cliente_sucursal_id" :value="salaEnviada">
                    <x-input-error :messages="$errors->get('cliente_sucursal_id')" class="mt-1" />

                    <div class="mt-3 rounded-md bg-gray-50 border border-gray-200 p-3 text-sm">
                        <template x-if="ordenCompra">
                            <span><span class="text-gray-500">Orden de compra vinculada:</span> <span class="font-medium" x-text="ordenCompra"></span></span>
                        </template>
                        <template x-if="! ordenCompra">
                            <span class="text-gray-500">El CCF relacionado no tiene orden de compra.</span>
                        </template>
                    </div>
                </section>

                {{-- =============== 5. MOTIVO =============== --}}
                <section class="bg-white shadow sm:rounded-lg p-5">
                    <h3 class="font-semibold text-gray-800">5 &middot; Motivo</h3>
                    <x-input-label for="motivo" class="mt-2">
                        <span>Motivo / observaciones</span>
                        <span x-show="motivoObligatorio" x-cloak class="text-amber-700 font-semibold"> — obligatorio</span>
                        <span x-show="! motivoObligatorio" class="text-gray-400 font-normal"> (opcional)</span>
                    </x-input-label>
                    <x-text-input id="motivo" name="motivo" type="text" class="mt-1 block w-full" :value="old('motivo')"
                                  x-model="motivo" ::required="motivoObligatorio"
                                  placeholder="Ej. Devolución parcial de mercadería, descuento por pronto pago…" />
                    <p class="mt-1 text-xs text-amber-700" x-show="motivoObligatorio" x-cloak>
                        Elegiste una sala distinta a la del CCF: explicá por qué. Queda en la auditoría del documento.
                    </p>
                    <x-input-error :messages="$errors->get('motivo')" class="mt-1" />
                </section>

                {{-- Emisor: SIEMPRE se recalcula en el servidor desde el CCF relacionado (la NC
                     debe compartir serie con su original). Lo de acá es informativo/UX. --}}
                @php
                    $estabUnico = $establecimientos->count() === 1 ? $establecimientos->first() : null;
                    $pvsEmisor = $estabUnico ? $puntosVenta->where('establecimiento_id', $estabUnico->id)->values() : $puntosVenta;
                    $pvUnico = $estabUnico ? \App\Support\Dte\ResuelveEmisorUnico::puntoVentaOculto($estabUnico->id) : null;
                @endphp

                @if ($estabUnico)
                    <input type="hidden" name="establecimiento_id" value="{{ $estabUnico->id }}">
                @endif
                @if ($pvUnico)
                    <input type="hidden" name="punto_venta_id" value="{{ $pvUnico->id }}">
                @endif

                {{-- Con emisor único los selects se ocultan, pero quién emite no puede quedar
                     invisible: se dice en texto. Son datos de Dulces La Negrita, no de la sala
                     del cliente, y confundirlos es un error caro. --}}
                @if ($estabUnico || $pvUnico)
                    <p class="px-1 text-sm text-gray-600">
                        @if ($estabUnico)Emisor: <span class="font-medium text-gray-800">{{ $estabUnico->nombre }}</span>@endif
                        @if ($estabUnico && $pvUnico) · @endif
                        @if ($pvUnico)Punto de venta: <span class="font-medium text-gray-800">{{ $pvUnico->nombre }}</span>@endif
                        <span class="block text-xs text-amber-600">Pertenecen a Dulces La Negrita, no a la sala del cliente. El correlativo se asigna al generar.</span>
                    </p>
                @endif

                @if (! $estabUnico || ! $pvUnico)
                    <section class="bg-white shadow sm:rounded-lg p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @unless ($estabUnico)
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
                        @endunless
                        @unless ($pvUnico)
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
                        @endunless
                    </section>
                @endif

                <div class="flex flex-wrap items-center gap-3 pb-4">
                    {{-- Con JS el `required` del select oculto no puede actuar, así que el botón
                         toma su lugar. Sin JS el atributo sigue vivo y esto no corre. --}}
                    <x-primary-button ::disabled="jsListo && ccfId === ''">Crear nota de crédito</x-primary-button>
                    <a href="{{ route('facturacion.index') }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                    <p class="w-full text-xs text-gray-400">
                        Se crea un <strong>borrador</strong>: no se emite, no se firma y no se transmite nada a Hacienda en este paso.
                    </p>
                </div>
            </form>
        </div>
    </div>

    {{--
        El componente vive en un <script> y NO dentro del atributo x-data. En el atributo,
        cualquier comilla doble lo cierra antes de tiempo y el resto del objeto cae al
        documento como TEXTO VISIBLE para el usuario (ya pasó una vez con un
        querySelector). Acá el atributo se reduce a una llamada de una línea y el código
        puede usar las comillas que necesite.

        Script clásico en el <body>: se ejecuta durante el parseo, o sea ANTES que el
        módulo diferido de Vite que hace Alpine.start(), así que el listener de
        `alpine:init` siempre llega a tiempo.
    --}}
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('ncFormulario', (d) => ({
                rutaBuscarCcf: '',
                opcionesCliente: d.opcionesCliente,
                salasPorCliente: d.salasPorCliente,
                submotivosPorModalidad: d.submotivosPorModalidad,
                origenPorModalidad: d.origenPorModalidad,
                otraSalaPorModalidad: d.otraSalaPorModalidad,
                ccfs: d.ccfs,

                jsListo: false,
                modalidad: d.modalidad,
                tipo: d.tipo,
                origenAveria: d.origenAveria,
                motivo: d.motivo,

                clienteId: d.clienteId,
                contextoSalaId: d.contextoSalaId,
                clienteBuscar: '',
                clienteAbierto: false,
                condicionLabel: '—',
                descuento: '0.00',

                otraSala: false,
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
                ordenCompra: d.ordenCompra,

                init() {
                    this.jsListo = true;
                    this.rutaBuscarCcf = this.$el.dataset.buscarCcf;
                    this.onModalidadChange();
                    if (this.ccfId !== '') { this.onCcfChange(); }
                    this.sincronizarCliente();
                },

                // ---- Modalidad ----
                get submotivos() { return this.submotivosPorModalidad[this.modalidad] ?? []; },
                get pideOrigenAveria() { return this.origenPorModalidad.includes(this.modalidad); },
                get permiteOtraSalaReceptora() { return this.otraSalaPorModalidad.includes(this.modalidad); },

                onModalidadChange() {
                    // El submotivo pertenece a UNA modalidad: al cambiar de modalidad se
                    // reinicia al primero válido (o se vacía) en vez de arrastrar uno ajeno,
                    // que el servidor rechazaría por contradictorio.
                    const subs = this.submotivos;
                    this.tipo = subs.length > 0
                        ? (subs.some((s) => s.valor === this.tipo) ? this.tipo : subs[0].valor)
                        : '';
                    if (!this.pideOrigenAveria) { this.origenAveria = ''; }
                    // Al pasar a una modalidad que no admite otra sala, se vuelve a la del CCF.
                    if (!this.permiteOtraSalaReceptora) { this.salaNcId = this.salaCcfId; }
                },

                // ---- Cliente / sala del contexto ----
                get clientesFiltrados() {
                    const q = this.clienteBuscar.trim().toLowerCase();
                    const base = q === ''
                        ? this.opcionesCliente
                        : this.opcionesCliente.filter((o) => [o.nombre, o.sucursal, o.num_documento, o.nrc]
                            .filter(Boolean).some((v) => String(v).toLowerCase().includes(q)));
                    return base.slice(0, 50);
                },
                esClienteActual(o) {
                    return String(o.cliente_id) === String(this.clienteId)
                        && String(o.cliente_sucursal_id ?? '') === String(this.contextoSalaId);
                },
                etiquetaCliente(o) {
                    return [o.nombre, o.sucursal, (o.num_documento || o.nrc)].filter(Boolean).join(' — ');
                },
                seleccionarCliente(o) {
                    this.clienteId = String(o.cliente_id);
                    this.contextoSalaId = o.cliente_sucursal_id ? String(o.cliente_sucursal_id) : '';
                    this.clienteBuscar = this.etiquetaCliente(o);
                    this.clienteAbierto = false;
                    this.condicionLabel = o.condicion_label ?? '—';
                    this.descuento = o.descuento_porcentaje ?? '0.00';
                    // Un CCF elegido para otro cliente o sala deja de tener sentido: se suelta
                    // en vez de quedar colgado y viajar al POST sin que nadie lo vuelva a mirar.
                    this.limpiarCcf();
                },
                limpiarCliente() {
                    this.clienteId = '';
                    this.contextoSalaId = '';
                    this.clienteBuscar = '';
                    this.condicionLabel = '—';
                    this.descuento = '0.00';
                    this.limpiarCcf();
                },
                sincronizarCliente() {
                    const actual = this.opcionesCliente.find((o) => this.esClienteActual(o));
                    if (actual) {
                        this.clienteBuscar = this.etiquetaCliente(actual);
                        this.condicionLabel = actual.condicion_label ?? '—';
                        this.descuento = actual.descuento_porcentaje ?? '0.00';
                    }
                },

                // ---- Buscador de CCF ----
                async buscarCcf(pagina) {
                    if (this.clienteId === '') { return; }
                    const destino = Math.max(1, pagina || 1);
                    this.ccfAbierto = true;
                    this.ccfCargando = true;
                    // Solo la última petición pinta: escribir rápido, o pulsar Siguiente dos
                    // veces, no debe dejar en pantalla el resultado de una consulta vieja.
                    const propia = ++this.ccfPeticion;

                    const params = new URLSearchParams({
                        q: this.ccfBuscar,
                        pagina: String(destino),
                        cliente_id: this.clienteId,
                    });
                    // Sin marcar "otra sala", la búsqueda se acota a la sala del contexto.
                    if (!this.otraSala && this.contextoSalaId !== '') {
                        params.set('cliente_sucursal_id', this.contextoSalaId);
                    }

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
                reiniciarBusqueda() { this.buscarCcf(1); },

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
                    if (c) {
                        this.clienteId = String(c.cliente_id ?? '');
                        this.salaNcId = c.cliente_sucursal_id ? String(c.cliente_sucursal_id) : '';
                        this.establecimientoId = String(c.establecimiento_id ?? '');
                        this.puntoVentaId = String(c.punto_venta_id ?? '');
                        this.ordenCompra = c.orden_compra ?? '';
                        if (this.contextoSalaId === '') { this.contextoSalaId = this.salaNcId; }
                        this.sincronizarCliente();
                    } else {
                        this.ordenCompra = '';
                        this.salaNcId = '';
                    }
                },

                // ---- Sala receptora y motivo ----
                get salaCcfId() { return this.ccf && this.ccf.cliente_sucursal_id ? String(this.ccf.cliente_sucursal_id) : ''; },
                get salasCliente() { return this.salasPorCliente[this.clienteId] ?? []; },
                get nombreSalaCcf() {
                    const s = this.salasCliente.find((x) => String(x.id) === this.salaCcfId);
                    return s ? s.nombre : '';
                },
                // Valor realmente enviado: la sala elegida solo cuenta cuando la modalidad lo
                // permite; si no, siempre la del CCF. El servidor vuelve a validarlo.
                get salaEnviada() { return this.permiteOtraSalaReceptora ? this.salaNcId : this.salaCcfId; },
                get salaReceptoraCambiada() {
                    return this.ccfId !== '' && this.salaEnviada !== '' && this.salaEnviada !== this.salaCcfId;
                },
                // El CCF elegido vino de una sala distinta a la del contexto de trabajo.
                get ccfDeOtraSala() {
                    return this.ccfId !== '' && this.contextoSalaId !== '' && this.salaCcfId !== ''
                        && this.contextoSalaId !== this.salaCcfId;
                },
                // Las dos formas de cruzar de sala cobran lo mismo: una explicación escrita.
                get motivoObligatorio() { return this.salaReceptoraCambiada || this.ccfDeOtraSala; },
            }));
        });
    </script>
</x-app-layout>
