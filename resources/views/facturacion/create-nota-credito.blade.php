@php
    // Emisor: si solo hay una opción real se auto-selecciona y los selects se ocultan.
    // Los IDs viajan igual en inputs ocultos y el backend los recalcula desde el CCF
    // relacionado cuando lo hay (la NC comparte serie con su original).
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

        Es un formulario administrativo completo, con todas sus secciones visibles desde
        que se abre: cliente y sala, tipo de nota, fecha, referencia documental y motivo.
        La captura de productos y el resumen fiscal viven en el paso siguiente, y no por
        gusto: las líneas necesitan un borrador ya guardado para que el motor fiscal
        calcule y persista los totales. Es el mismo recorrido del CCF (encabezado y después
        productos), no un camino aparte.

        Se entra por dos puertas y las dos terminan en el mismo editor: esta pantalla y
        «Revertir con nota de crédito» desde la ficha de un CCF, que crea el borrador con
        todas las líneas ya acreditadas. La INVALIDACIÓN oficial ante Hacienda es otro
        proceso, con su propia tarjeta; no se mezcla acá.

        Genérico a propósito: ningún cliente está cableado. Los códigos de albarán salen
        del perfil documental del cliente elegido y solo aparecen si ese cliente declaró
        uno; para el resto la nota se emite con las reglas fiscales generales.

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
                      class="divide-y divide-gray-100">
                    @csrf

                    {{-- =============== Cliente y sala =============== --}}
                    <section class="pb-5" @click.outside="clienteAbierto = false">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Cliente y sala</h3>

                        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="sm:col-span-2 relative">
                                <x-input-label for="cliente_buscar" value="Cliente (contribuyente) / sala *" />
                                <input id="cliente_buscar" type="text" x-model="clienteBuscar" autocomplete="off"
                                       @focus="clienteAbierto = true" @input="clienteAbierto = true"
                                       placeholder="Buscar por razón social, sala/sucursal, NIT o NRC…"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm pr-20">
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
                                <input type="hidden" name="cliente_id" :value="clienteId">
                                <input type="hidden" name="cliente_sucursal_id" :value="salaEnviada">
                                <x-input-error :messages="$errors->get('cliente_id')" class="mt-1" />
                                <x-input-error :messages="$errors->get('cliente_sucursal_id')" class="mt-1" />
                            </div>

                            <div class="rounded-md bg-gray-50 border border-gray-200 px-3 py-2 text-sm">
                                <span class="text-gray-500">Condición:</span>
                                <span class="font-medium text-gray-800" x-text="condicionLabel"></span>
                            </div>
                            <div class="rounded-md bg-gray-50 border border-gray-200 px-3 py-2 text-sm">
                                <span class="text-gray-500">Descuento del cliente:</span>
                                <span class="font-medium text-gray-800" x-text="descuento + '%'"></span>
                            </div>
                        </div>
                    </section>

                    {{-- =============== Tipo de nota =============== --}}
                    <section class="py-5">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Tipo de nota</h3>

                        <div class="mt-3 flex flex-wrap gap-2">
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

                        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-4">
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

                        </div>
                    </section>

                    {{-- =============== Avería: sala a la que corresponde =============== --}}
                    <section class="py-5" x-show="esAveria && clienteId !== ''" x-cloak>
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Avería</h3>

                        <div class="mt-3 sm:w-1/2">
                            {{-- Sala a la que CORRESPONDE la avería. Dato independiente: elegir
                                 después un CCF de otra sala no la reemplaza.
                                 La observación NO va acá: el formulario ya tiene un campo de
                                 motivo/observaciones más abajo, y tener dos obliga a preguntarse
                                 cuál de los dos vale. --}}
                            <x-input-label for="sucursal_averia_id" value="Sala a la que corresponde" />
                            {{-- El select NO lleva `name`: lo que viaja es el valor RESUELTO del
                                 hidden de abajo. Dejarlo vacío significa «la sala elegida arriba»,
                                 y esa sala tiene que quedar registrada igual; si el select
                                 posteara directo, no elegir nada guardaría un null y la avería
                                 quedaría sin sala. --}}
                            <select id="sucursal_averia_id" x-model="salaAveriaId"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                                <option value="">— La sala elegida arriba —</option>
                                <template x-for="s in salasCliente" :key="s.id">
                                    <option :value="String(s.id)" x-text="s.nombre"></option>
                                </template>
                            </select>
                            <input type="hidden" name="sucursal_averia_id" :value="salaBusquedaId">
                            <x-input-error :messages="$errors->get('sucursal_averia_id')" class="mt-1" />
                        </div>
                    </section>

                    {{-- =============== Referencia documental (CCF) =============== --}}
                    <section class="py-5" @click.outside="ccfAbierto = false">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Referencia documental</h3>
                            <span class="text-xs" :class="ccfRequerido ? 'text-gray-400' : 'text-amber-700 font-medium'"
                                  x-text="ccfRequerido ? 'CCF aceptado, obligatorio' : 'Excepción: se relaciona después'"></span>
                        </div>

                        <div class="mt-3">
                            {{-- Buscador paginado: oculto hasta que Alpine arranca. Sin JS queda el
                                 select de respaldo, con el mismo name del POST. --}}
                            <div x-show="jsListo" x-cloak>
                                <template x-if="ccfId === ''">
                                    <div class="relative">
                                        <x-input-label for="ccf_buscar">
                                            <span>Buscar CCF relacionado</span>
                                            <span x-show="ccfRequerido" class="text-gray-700">*</span>
                                        </x-input-label>
                                        <input id="ccf_buscar" type="text" x-model="ccfBuscar" autocomplete="off"
                                               @focus="buscarCcf(1)" @input.debounce.300ms="buscarCcf(1)"
                                               placeholder="Buscar por correlativo (ej. 1120), N.º de control u orden de compra…"
                                               class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">

                                        {{-- Alcance de la búsqueda. Por defecto SOLO la sala elegida:
                                             lo habitual es acreditar contra un CCF de la misma sala, y
                                             mezclar las demás obliga a distinguirlas a ojo. Salir de la
                                             sala es una decisión explícita, y se cobra con motivo. --}}
                                        <label class="mt-2 flex items-start gap-2 text-sm text-gray-700"
                                               x-show="salaBusquedaId !== ''" x-cloak>
                                            <input type="checkbox" x-model="otrasSalas" @change="buscarCcf(1)"
                                                   class="mt-0.5 rounded border-gray-300 text-amber-600">
                                            <span>Buscar también en <strong>otras salas</strong> del mismo cliente</span>
                                        </label>

                                        <p class="mt-1 text-xs text-gray-400">
                                            Solo CCF aceptados por Hacienda, del mismo cliente. Los más recientes primero.
                                            <span x-show="salaBusquedaId !== '' && ! otrasSalas" x-cloak>Acotado a la sala elegida.</span>
                                        </p>

                                        {{-- Salida de EXCEPCIÓN, discreta y desmarcada. Casi siempre
                                             hay un CCF, así que el flujo principal es elegirlo; esto
                                             es para el caso raro en que todavía no se sabe cuál. --}}
                                        <label class="mt-3 flex items-start gap-2 text-xs text-gray-500" x-show="esAveria" x-cloak>
                                            <input type="checkbox" name="sin_ccf_excepcional" value="1"
                                                   x-model="sinCcfExcepcional"
                                                   class="mt-0.5 rounded border-gray-300 text-amber-600">
                                            <span>Guardar excepcionalmente sin CCF por ahora</span>
                                        </label>

                                        <div x-show="sinCcfExcepcional" x-cloak role="status"
                                             class="mt-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                            Quedará <strong>bloqueada para generar, firmar y transmitir</strong> hasta
                                            relacionar un CCF aceptado. Explicá el motivo de la excepción abajo.
                                        </div>

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

                                {{-- Resumen COMPACTO del CCF elegido: lo que hay que verificar. --}}
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

                            {{-- RESPALDO SIN JAVASCRIPT y fuente del id que viaja al POST. Con
                                 Alpine se oculta (sigue en el DOM, así que se envía igual). --}}
                            <div x-show="! jsListo">
                                <x-input-label for="dte_relacionado_id" value="CCF relacionado (aceptado) *" />
                                <select id="dte_relacionado_id" name="dte_relacionado_id" x-ref="selectCcf"
                                        x-model="ccfId" @change="onCcfChange()"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                                    <option value="">— Seleccione un CCF aceptado por Hacienda —</option>
                                    @foreach ($opcionesCcf as $ccf)
                                        <option value="{{ $ccf['id'] }}">{{ 'CCF '.($ccf['numero'] ?? $ccf['id']) }} · {{ $ccf['cliente_nombre'] ?? 'Cliente' }}{{ $ccf['sala'] ? ' — '.$ccf['sala'] : '' }} · {{ $ccf['fecha'] }} · ${{ $ccf['total'] }}{{ $ccf['numero_control'] ? ' · '.$ccf['numero_control'] : '' }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <x-input-error :messages="$errors->get('dte_relacionado_id')" class="mt-1" />
                        </div>

                        {{-- Sala receptora: solo pronto pago y otro ajuste pueden cambiarla. --}}
                        <div class="mt-4" x-show="permiteOtraSalaReceptora && ccfId !== ''" x-cloak>
                            <x-input-label for="sala_nc" value="Sala receptora de la Nota de Crédito" />
                            <select id="sala_nc" x-model="salaNcId"
                                    class="mt-1 block w-full sm:w-1/2 border-gray-300 rounded-md shadow-sm text-sm">
                                <template x-for="s in salasCliente" :key="s.id">
                                    <option :value="String(s.id)" x-text="s.nombre"></option>
                                </template>
                            </select>
                            <p class="mt-1 text-xs text-gray-400">Predeterminada: la sala del CCF. Puede ser una sala administrativa del mismo cliente.</p>
                        </div>

                        {{-- Advertencia de sala cruzada: un solo cartel, con el porqué. --}}
                        <div x-show="salaCruzada" x-cloak role="status"
                             class="mt-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                            <span x-show="receptoraCruzada">
                                La nota se emitirá a una <strong>sala distinta</strong> a la del CCF: cambian el
                                establecimiento y la dirección impresos. El CCF relacionado, el cliente fiscal
                                (NIT/NRC) y el saldo acreditable <strong>no cambian</strong>.
                            </span>
                            <span x-show="averiaCruzada && ! receptoraCruzada">
                                El CCF elegido es de una <strong>sala distinta</strong> a la de la nota.
                                Es del mismo cliente, y la sala de la nota <strong>no cambia</strong>: solo se
                                acredita contra ese documento.
                            </span>
                            <span class="mt-0.5 block">Explicá el motivo: queda en la auditoría del documento.</span>
                        </div>
                    </section>

                    {{-- =============== Motivo y emisor =============== --}}
                    <section class="py-5">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Motivo</h3>
                        <div class="mt-3">
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

                        @if ($estabUnico)
                            <input type="hidden" name="establecimiento_id" value="{{ $estabUnico->id }}">
                        @endif
                        @if ($pvUnico)
                            <input type="hidden" name="punto_venta_id" value="{{ $pvUnico->id }}">
                        @endif

                        @if (! $estabUnico || ! $pvUnico)
                            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
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
                            <p class="mt-3 text-xs text-gray-500">
                                @if ($estabUnico)Emisor: <span class="font-medium text-gray-700">{{ $estabUnico->nombre }}</span>@endif
                                @if ($estabUnico && $pvUnico) · @endif
                                @if ($pvUnico)Punto de venta: <span class="font-medium text-gray-700">{{ $pvUnico->nombre }}</span>@endif
                                · El correlativo se asigna al generar.
                            </p>
                        @endif
                    </section>

                    {{-- =============== Acciones =============== --}}
                    <div class="flex flex-wrap items-center gap-3 pt-5">
                        <x-primary-button ::disabled="! puedeGuardar">Guardar borrador y capturar productos</x-primary-button>
                        <a href="{{ route('facturacion.index') }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                        <p class="w-full text-xs text-gray-400">
                            Se crea un <strong>borrador</strong>: en el paso siguiente se capturan los productos y se ve
                            el resumen fiscal. No se emite, firma ni transmite nada.
                        </p>
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
                opcionesCliente: d.opcionesCliente,
                salasPorCliente: d.salasPorCliente,
                submotivosPorModalidad: d.submotivosPorModalidad,
                sinCcfPorModalidad: d.sinCcfPorModalidad,
                otraSalaPorModalidad: d.otraSalaPorModalidad,
                codigosPorCliente: d.codigosPorCliente,
                ccfs: d.ccfs,

                jsListo: false,
                modalidad: d.modalidad,
                tipo: d.tipo,
                motivo: d.motivo,

                clienteId: d.clienteId,
                clienteSalaId: d.clienteSalaId,
                clienteBuscar: '',
                clienteAbierto: false,
                condicionLabel: '—',
                descuento: '0.00',

                salaAveriaId: d.salaAveriaId,
                // Alcance del buscador de CCF: por defecto, solo la sala elegida.
                otrasSalas: false,
                // Excepción para guardar sin CCF. Desmarcada: el CCF es obligatorio.
                sinCcfExcepcional: false,

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
                    this.sincronizarCliente();
                },

                // ---- Tipo de nota ----
                get submotivos() { return this.submotivosPorModalidad[this.modalidad] ?? []; },
                get permiteOtraSalaReceptora() { return this.otraSalaPorModalidad.includes(this.modalidad); },
                get esAveria() { return this.sinCcfPorModalidad.includes(this.modalidad); },

                // El CCF es obligatorio salvo que se active la excepción, y solo la avería
                // la admite. El servidor lo vuelve a exigir: esto decide qué se muestra y si
                // el botón deja enviar.
                get ccfRequerido() { return ! (this.esAveria && this.sinCcfExcepcional); },

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
                    if (!this.esAveria) { this.salaAveriaId = ''; this.sinCcfExcepcional = false; }
                    if (!this.permiteOtraSalaReceptora) { this.salaNcId = this.salaCcfId; }
                },

                // ---- Cliente y sala ----
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
                        && String(o.cliente_sucursal_id ?? '') === String(this.clienteSalaId);
                },
                etiquetaCliente(o) {
                    return [o.nombre, o.sucursal, (o.num_documento || o.nrc)].filter(Boolean).join(' — ');
                },
                seleccionarCliente(o) {
                    const cambioDeCliente = String(o.cliente_id) !== String(this.clienteId);
                    this.clienteId = String(o.cliente_id);
                    this.clienteSalaId = o.cliente_sucursal_id ? String(o.cliente_sucursal_id) : '';
                    this.clienteBuscar = this.etiquetaCliente(o);
                    this.clienteAbierto = false;
                    this.condicionLabel = o.condicion_label ?? '—';
                    this.descuento = o.descuento_porcentaje ?? '0.00';
                    // Un CCF elegido para otro cliente deja de tener sentido; y al cambiar de
                    // sala el alcance del buscador cambia, así que se vuelve a la sala propia.
                    if (cambioDeCliente) { this.limpiarCcf(); this.salaAveriaId = ''; }
                    this.otrasSalas = false;
                },
                limpiarCliente() {
                    this.clienteId = '';
                    this.clienteSalaId = '';
                    this.clienteBuscar = '';
                    this.condicionLabel = '—';
                    this.descuento = '0.00';
                    this.salaAveriaId = '';
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
                get salasCliente() { return this.salasPorCliente[this.clienteId] ?? []; },

                // ---- Buscador de CCF ----
                async buscarCcf(pagina) {
                    const destino = Math.max(1, pagina || 1);
                    this.ccfAbierto = true;
                    this.ccfCargando = true;
                    // Solo la última petición pinta: escribir rápido, o pulsar Siguiente dos
                    // veces, no debe dejar en pantalla el resultado de una consulta vieja.
                    const propia = ++this.ccfPeticion;

                    const params = new URLSearchParams({ q: this.ccfBuscar, pagina: String(destino) });
                    // El cliente SIEMPRE acota: una nota no puede cruzar de cliente, y el
                    // servidor lo vuelve a exigir al guardar.
                    if (this.clienteId !== '') { params.set('cliente_id', this.clienteId); }
                    // La sala acota salvo que se pida explícitamente mirar las demás.
                    if (!this.otrasSalas && this.salaBusquedaId !== '') {
                        params.set('cliente_sucursal_id', this.salaBusquedaId);
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

                // El id que viaja al POST sale del <select> de respaldo. Un CCF traído por el
                // buscador puede no estar entre las opciones precargadas, así que primero se
                // le agrega su <option> y solo después se fija ccfId.
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
                    // Con un CCF elegido la excepción sobra: se apaga sola para que el motivo
                    // no siga pidiéndose por una salida que ya no se está usando.
                    this.sinCcfExcepcional = false;
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
                        // El CCF fija el cliente y el emisor de la nota. Lo que NO toca es la
                        // sala elegida arriba: en una avería esa sala es a la que corresponde
                        // el producto dañado, y moverla al elegir un CCF de otra sala haría
                        // parecer que la avería ocurrió ahí. La sala del CCF vive aparte, en
                        // salaCcfId, y es la que recibe el documento.
                        this.clienteId = String(c.cliente_id ?? '');
                        this.salaNcId = this.salaCcfId;
                        this.establecimientoId = String(c.establecimiento_id ?? '');
                        this.puntoVentaId = String(c.punto_venta_id ?? '');
                    } else {
                        this.salaNcId = '';
                    }
                },

                // ---- Salas y motivo ----
                get salaCcfId() { return this.ccf && this.ccf.cliente_sucursal_id ? String(this.ccf.cliente_sucursal_id) : ''; },
                // Sala que acota el buscador: la de la avería si se declaró una distinta, y
                // si no la elegida arriba. Es la misma contra la que se juzga el cruce.
                get salaBusquedaId() { return this.salaAveriaId !== '' ? this.salaAveriaId : this.clienteSalaId; },
                // Sala RECEPTORA que viaja al POST. Con CCF manda la suya (salvo las
                // modalidades que admiten otra); sin CCF, la sala elegida arriba.
                get salaEnviada() {
                    if (this.ccfId === '') { return this.clienteSalaId; }
                    return this.permiteOtraSalaReceptora ? this.salaNcId : this.salaCcfId;
                },
                get receptoraCruzada() {
                    return this.ccfId !== '' && this.salaEnviada !== '' && this.salaEnviada !== this.salaCcfId;
                },
                // El CCF elegido es de otra sala que aquella a la que corresponde la nota.
                // Se permite —mismo cliente— pero con explicación escrita.
                get averiaCruzada() {
                    return this.ccfId !== '' && this.salaBusquedaId !== ''
                        && this.salaBusquedaId !== this.salaCcfId;
                },
                get salaCruzada() { return this.receptoraCruzada || this.averiaCruzada; },
                // Todo lo que se sale de lo normal cuesta una explicación escrita: cruzar de
                // sala, y guardar sin CCF.
                get motivoObligatorio() { return this.salaCruzada || this.sinCcfExcepcional; },

                // Con JS el `required` de los controles ocultos no puede actuar, así que el
                // botón toma su lugar: hace falta cliente, y el CCF solo cuando corresponde.
                get puedeGuardar() {
                    if (!this.jsListo) { return true; }
                    if (this.clienteId === '') { return false; }
                    return this.ccfRequerido ? this.ccfId !== '' : true;
                },
            }));
        });
    </script>
</x-app-layout>
