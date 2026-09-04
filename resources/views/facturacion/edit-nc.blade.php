<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $nc->tituloDocumento() }}
            <span class="ml-2 text-sm font-normal text-gray-500">N.º sistema: {{ $nc->etiquetaNumeroSistema() }}</span>
        </h2>
    </x-slot>

    {{--
        CAPTURA de la nota de crédito. Antes esta pantalla eran tres pantallas distintas
        una debajo de otra —tabla de líneas originales, catálogo entero de productos, o un
        formulario de concepto— y las líneas ya cargadas vivían al fondo, lejos de donde se
        trabajaba. Ahora es la MISMA disposición del editor de CCF: captura a la izquierda,
        panel pegajoso a la derecha con lo acreditado y el resumen fiscal, y el total
        siempre a la vista.

        Lo que cambia según la modalidad es solo el panel de captura:
          · devolución / faltante → líneas del CCF original, acotadas por su saldo;
          · avería                → catálogo libre de productos, con su precio del cliente;
          · pronto pago / otro    → conceptos por monto.

        Todo se guarda por AJAX contra las rutas de siempre (ccf-editor.js, el mismo que
        usa el CCF) con fallback a POST normal si no hay JavaScript. Ninguna regla fiscal
        vive acá: el servidor recalcula y revalida en cada cambio.
    --}}

    @php
        $modalidad = \App\Enums\ModalidadNotaCredito::desdeTipo($nc->tipo_nota_credito);
        $confirmGenerar = 'Generar la nota de crédito:'."\n\n"
            .'Modalidad: '.($modalidad?->label() ?? '—')."\n"
            .'Cliente: '.($nc->cliente?->nombre ?? '—')."\n"
            .'Sala: '.($nc->clienteSucursal?->nombre ?? '—')."\n"
            .'Líneas: '.$nc->lineas->count()."\n"
            .'Total: $'.number_format((float) $nc->total_pagar, 2)."\n\n"
            .'Se consume el correlativo interno y la nota ya no podrá editarse. ¿Continuar?';
    @endphp

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            {{-- Mensajes en vivo del editor AJAX (éxito/error sin recargar). Vacío por defecto.
                 Mismo id que usa el editor del CCF: ccf-editor.js lo busca por nombre. --}}
            <div id="ccf-flash" role="status" aria-live="polite" class="hidden rounded-md border p-3 text-sm"></div>

            {{-- Cabecera compacta: quién, contra qué y bajo qué modalidad. --}}
            <div class="bg-white shadow sm:rounded-lg p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center flex-wrap gap-x-2 gap-y-1">
                            <span class="font-semibold text-gray-800">{{ $nc->cliente?->nombre ?? 'Sin cliente' }}</span>
                            @if ($nc->clienteSucursal?->nombre)
                                <span class="text-indigo-600 text-sm">— {{ $nc->clienteSucursal->nombre }}</span>
                            @endif
                            <x-estado-dte-badge :estado="$nc->estado" />
                        </div>
                        <dl class="mt-1.5 flex flex-wrap gap-x-5 gap-y-1 text-xs text-gray-500">
                            <div>
                                <dt class="inline text-gray-400">Modalidad:</dt>
                                <dd class="inline font-medium text-gray-700">
                                    {{ $modalidad?->label() ?? '—' }}
                                    @if ($reglaAlbaran)
                                        {{-- Código del albarán de ESTE cliente, solo si declaró
                                             un perfil documental. Sin perfil no se rotula nada. --}}
                                        <span class="font-mono text-gray-500">· {{ $reglaAlbaran->codigo_externo }}</span>
                                    @endif
                                    @if ($modalidad && count($modalidad->submotivos()) > 0)
                                        <span class="text-gray-500">({{ $nc->tipo_nota_credito?->label() }})</span>
                                    @endif
                                </dd>
                            </div>
                            {{-- Sala a la que corresponde la avería. Solo aparece cuando difiere
                                 de la receptora tiene sentido mostrarla siempre: es el dato que el
                                 CCF relacionado no puede cambiar. --}}
                            @if ($nc->sucursalAveria)
                                <div>
                                    <dt class="inline text-gray-400">Sala de la avería:</dt>
                                    <dd class="inline font-medium text-gray-700">{{ $nc->sucursalAveria->nombre }}</dd>
                                </div>
                            @endif
                            <div>
                                <dt class="inline text-gray-400">CCF relacionado:</dt>
                                <dd class="inline">
                                    @if ($original)
                                        <a href="{{ route('facturacion.show', $original) }}" class="text-indigo-600 hover:underline font-mono">{{ $original->numero_interno ?? ('CCF #'.$original->id) }}</a>
                                    @else
                                        <span class="text-amber-600">Sin documento relacionado</span>
                                    @endif
                                </dd>
                            </div>
                            <div><dt class="inline text-gray-400">Orden de compra:</dt> <dd class="inline">{{ $nc->numero_orden_compra ?? '—' }}</dd></div>
                            <div><dt class="inline text-gray-400">Retención IVA:</dt> <dd class="inline">{{ $nc->aplica_retencion_iva ? 'Sí' : 'No' }}</dd></div>
                        </dl>
                        @if ($nc->motivo)
                            <p class="mt-1.5 text-xs text-gray-500"><span class="text-gray-400">Motivo:</span> {{ $nc->motivo }}</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- AVERÍA REGISTRADA SIN CCF. No es un aviso decorativo: mientras la nota no
                 tenga documento relacionado no puede generarse, porque el esquema oficial
                 del MH exige `documentoRelacionado` en toda NC (fe-nc-v3 y v4: está en el
                 `required` de la raíz y el array lleva minItems 1). Se dice acá, arriba de
                 todo, y se ofrece cómo resolverlo en el mismo lugar. --}}
            @if ($nc->dte_relacionado_id === null)
                <div class="rounded-md border border-amber-300 bg-amber-50 p-4" role="status">
                    <p class="text-sm font-semibold text-amber-900">Avería registrada; falta relacionar un CCF para emitir.</p>
                    <p class="mt-1 text-sm text-amber-800">
                        El borrador está <strong>incompleto</strong>: Hacienda exige un documento relacionado
                        en toda nota de crédito, así que <strong>no se puede generar, firmar ni transmitir</strong>
                        hasta vincular un CCF aceptado del mismo cliente.
                    </p>

                    @can('update', $nc)
                        <form method="POST" action="{{ route('facturacion.nota-credito.vincular-ccf', $nc) }}"
                              class="mt-3"
                              x-data="vincularCcf(@js(route('facturacion.nota-credito.buscar-ccf')), @js((string) $nc->cliente_id), @js((string) ($nc->sucursal_averia_id ?? $nc->cliente_sucursal_id)))">
                            @csrf
                            <div class="relative" @click.outside="abierto = false">
                                <x-input-label for="vincular_buscar" value="Buscar un CCF aceptado de este cliente" />
                                <input id="vincular_buscar" type="text" x-model="buscar" autocomplete="off"
                                       @focus="buscarCcf(1)" @input.debounce.300ms="buscarCcf(1)"
                                       placeholder="Correlativo, N.º de control u orden de compra…"
                                       class="mt-1 block w-full border-amber-300 rounded-md shadow-sm text-sm">

                                {{-- Por defecto solo la sala de la nota. Mirar las demás es
                                     una decisión explícita, y se cobra con motivo. --}}
                                <label class="mt-2 flex items-start gap-2 text-sm text-amber-900" x-show="salaNota !== ''" x-cloak>
                                    <input type="checkbox" x-model="otrasSalas" @change="buscarCcf(1)"
                                           class="mt-0.5 rounded border-amber-400 text-amber-600">
                                    <span>Buscar también en <strong>otras salas</strong> del mismo cliente</span>
                                </label>

                                <ul x-show="abierto" x-cloak
                                    class="absolute z-20 mt-1 w-full max-h-80 overflow-auto divide-y divide-gray-100 border border-gray-200 rounded-md bg-white shadow-lg text-sm">
                                    <li x-show="cargando" class="px-3 py-2.5 text-gray-400">Buscando…</li>
                                    <template x-for="c in resultados" :key="c.id">
                                        <li @click="elegir(c)" class="px-3 py-2.5 cursor-pointer hover:bg-indigo-50">
                                            <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                                <span class="font-medium text-gray-900">CCF <span x-text="c.numero"></span></span>
                                                <span class="font-mono font-semibold text-gray-900" x-text="'$' + c.total"></span>
                                            </div>
                                            <div class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-gray-500">
                                                <span x-show="c.sala" class="text-indigo-600" x-text="c.sala"></span>
                                                <span x-text="c.fecha"></span>
                                                <span x-show="c.orden_compra">OC <span x-text="c.orden_compra"></span></span>
                                            </div>
                                        </li>
                                    </template>
                                    <li x-show="! cargando && resultados.length === 0" class="px-3 py-2.5 text-gray-400">Sin coincidencias.</li>
                                    <li class="flex items-center justify-between gap-2 bg-gray-50 px-3 py-1.5 text-xs">
                                        <button type="button" @click="buscarCcf(pagina - 1)" :disabled="! hayPrevia || cargando"
                                                class="rounded border border-gray-300 bg-white px-2 py-1 font-medium text-gray-700 disabled:opacity-40 disabled:cursor-not-allowed">&larr; Anterior</button>
                                        <span class="text-gray-500">Página <span x-text="pagina"></span></span>
                                        <button type="button" @click="buscarCcf(pagina + 1)" :disabled="! hayMas || cargando"
                                                class="rounded border border-gray-300 bg-white px-2 py-1 font-medium text-gray-700 disabled:opacity-40 disabled:cursor-not-allowed">Siguiente &rarr;</button>
                                    </li>
                                </ul>
                            </div>

                            <input type="hidden" name="dte_relacionado_id" :value="elegidoId">

                            <div x-show="elegidoId !== ''" x-cloak class="mt-2 rounded-md border border-indigo-300 bg-white px-3 py-2 text-sm">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <span class="font-semibold text-gray-900">CCF <span x-text="elegido?.numero"></span>
                                        <span class="font-normal text-gray-600" x-text="elegido?.sala ? ('· ' + elegido.sala) : ''"></span>
                                    </span>
                                    {{-- `-m-2 p-2`: agranda el área táctil a 32 px sin mover el layout. --}}
                                    <button type="button" @click="limpiar()" class="-m-2 p-2 text-xs font-medium text-indigo-700 hover:underline">Cambiar</button>
                                </div>
                                <p class="mt-1 text-xs font-semibold text-amber-800" x-show="cruzaDeSala" x-cloak>
                                    Ese CCF es de otra sala del mismo cliente: el motivo es obligatorio.
                                    La sala de la avería no cambia.
                                </p>
                            </div>

                            <div class="mt-2">
                                <x-input-label for="motivo_vinculo">
                                    <span>Motivo</span>
                                    <span x-show="cruzaDeSala" x-cloak class="font-semibold text-amber-700"> — obligatorio</span>
                                    <span x-show="! cruzaDeSala" class="font-normal text-gray-400"> (opcional)</span>
                                </x-input-label>
                                <x-text-input id="motivo_vinculo" name="motivo" type="text" class="mt-1 block w-full text-sm"
                                              ::required="cruzaDeSala"
                                              placeholder="Por qué se acredita contra ese CCF" />
                                <x-input-error :messages="$errors->get('motivo')" class="mt-1" />
                            </div>

                            <x-input-error :messages="$errors->get('dte_relacionado_id')" class="mt-1" />

                            <button ::disabled="elegidoId === ''"
                                    class="mt-3 inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700 disabled:bg-gray-300 disabled:cursor-not-allowed">
                                Relacionar CCF
                            </button>
                        </form>
                    @endcan
                </div>
            @endif

            {{-- Avisos que exigen confirmación explícita antes de generar (retención o
                 diferencia contra el albarán). Nunca se ajusta un valor fiscal en silencio. --}}
            @can('update', $nc)
                @if (! empty($avisosAlbaran))
                    <div class="bg-white shadow sm:rounded-lg p-4">
                        <div class="rounded-md bg-amber-50 border border-amber-300 p-3">
                            <p class="text-sm font-semibold text-amber-800">Revisá antes de generar</p>
                            <ul class="mt-1 list-disc list-inside text-sm text-amber-800 space-y-1">
                                @foreach ($avisosAlbaran as $aviso)
                                    <li>{{ $aviso['texto'] }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <form method="POST" action="{{ route('facturacion.generar', $nc) }}"
                              onsubmit="return confirm(@js($confirmGenerar));"
                              class="mt-3 flex flex-wrap items-center gap-3">
                            @csrf
                            <label class="flex items-center gap-2 text-sm text-amber-800">
                                <input type="checkbox" name="confirmar_avisos_nc" value="1" class="rounded border-amber-400 text-amber-600">
                                Reviso y confirmo
                            </label>
                            <button class="inline-flex items-center px-4 py-2 bg-green-600 text-white text-sm rounded-md hover:bg-green-700">Generar</button>
                        </form>
                    </div>
                @endif
            @endcan

            {{-- Albarán del cliente. Solo se muestra si el cliente tiene un perfil que mapea
                 esta modalidad; en cualquier otro caso la pantalla no cambia. --}}
            @if ($reglaAlbaran)
                <div class="bg-white shadow sm:rounded-lg p-5">
                    <h3 class="font-semibold text-gray-700">Albarán del cliente</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Esta modalidad corresponde a un albarán
                        <span class="font-mono font-semibold">{{ $reglaAlbaran->codigo_externo }}</span>@if ($reglaAlbaran->etiqueta_externa) ({{ $reglaAlbaran->etiqueta_externa }})@endif.
                        Los datos van al archivo del día; <strong>no cambian los valores fiscales</strong> de la nota.
                    </p>

                    @if ($albaran)
                        <dl class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 text-sm mb-4">
                            <div class="col-span-2 sm:col-span-1"><dt class="text-gray-500">Número</dt><dd class="font-mono break-all">{{ $albaran->numero_canonico }}</dd></div>
                            <div><dt class="text-gray-500">Tipo</dt><dd class="font-mono">{{ $albaran->tipo_codigo }}</dd></div>
                            <div><dt class="text-gray-500">Sala</dt><dd class="font-mono">{{ $albaran->sala_codigo ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">Fecha</dt><dd>{{ $albaran->fecha?->format('d/m/Y') ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500">Total albarán</dt><dd class="font-mono">{{ number_format((float) $albaran->total, 2) }}</dd></div>
                        </dl>

                        @if ($comparacionAlbaran)
                            {{-- role=status y no alert: es información de contraste permanente, no
                                 una alarma que interrumpa. El aviso que sí exige acción vive arriba. --}}
                            <div role="status"
                                 class="rounded-md border p-3 text-sm mb-4 {{ $comparacionAlbaran['cuadra'] ? 'bg-green-50 border-green-200 text-green-800' : 'bg-amber-50 border-amber-300 text-amber-800' }}">
                                Total de la nota <span class="font-mono font-semibold">{{ $comparacionAlbaran['total_nc'] }}</span>
                                · total del albarán <span class="font-mono font-semibold">{{ $comparacionAlbaran['total_albaran'] }}</span>
                                · diferencia <span class="font-mono font-semibold">{{ $comparacionAlbaran['diferencia'] }}</span>
                                @if ($comparacionAlbaran['cuadra']) — coinciden. @else — revisá cuál de los dos es el correcto antes de generar. @endif
                            </div>
                        @endif
                    @else
                        <p class="text-sm text-amber-700 mb-4">Todavía no se registró el albarán de esta nota de crédito.</p>
                    @endif

                    @can('update', $nc)
                        <form method="POST" action="{{ route('facturacion.albaran.store', $nc) }}">
                            @csrf
                            <fieldset class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-start">
                                <legend class="sr-only">Datos del albarán de crédito</legend>

                                <div class="sm:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700" for="albaran_numero">Número de albarán</label>
                                    <input id="albaran_numero" name="numero" type="text" required maxlength="60"
                                           value="{{ old('numero', $albaran?->numero_canonico) }}"
                                           placeholder="{{ $reglaAlbaran->codigo_externo }}/0033/00/3209"
                                           @error('numero') aria-invalid="true" aria-describedby="albaran_numero_error" @else aria-describedby="albaran_numero_ayuda" @enderror
                                           class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono">
                                    <p id="albaran_numero_ayuda" class="mt-1 text-xs text-gray-500">
                                        Acepta el número completo, el nombre del PDF que manda el cliente, o solo el correlativo.
                                    </p>
                                    @error('numero')<p id="albaran_numero_error" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700" for="albaran_fecha">Fecha del albarán</label>
                                    <input id="albaran_fecha" name="fecha" type="date" required
                                           value="{{ old('fecha', $albaran?->fecha?->format('Y-m-d')) }}"
                                           @error('fecha') aria-invalid="true" aria-describedby="albaran_fecha_error" @enderror
                                           class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                    @error('fecha')<p id="albaran_fecha_error" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700" for="albaran_total">Total del albarán</label>
                                    <input id="albaran_total" name="total" type="number" step="0.01" min="0" required inputmode="decimal"
                                           value="{{ old('total', $albaran?->total) }}"
                                           @error('total') aria-invalid="true" aria-describedby="albaran_total_error" @else aria-describedby="albaran_total_ayuda" @enderror
                                           class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono">
                                    <p id="albaran_total_ayuda" class="mt-1 text-xs text-gray-500">En positivo, aunque el PDF lo imprima en negativo.</p>
                                    @error('total')<p id="albaran_total_error" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </div>
                            </fieldset>

                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                <button class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                                    {{ $albaran ? 'Actualizar albarán' : 'Registrar albarán' }}
                                </button>
                            </div>
                        </form>

                        @if ($albaran)
                            <form method="POST" action="{{ route('facturacion.albaran.destroy', $nc) }}" class="mt-3"
                                  onsubmit="return confirm('¿Quitar el albarán de esta nota de crédito? Quedará libre para otra nota.');">
                                @csrf
                                @method('DELETE')
                                <button class="text-sm text-red-600 hover:underline">Quitar albarán</button>
                            </form>
                        @endif
                    @endcan
                </div>
            @endif

            {{-- Área de trabajo: captura (principal, ancha) + panel pegajoso con lo acreditado
                 y el resumen fiscal. En móvil el panel va PRIMERO, para que el total quede a
                 la vista sin tener que bajar toda la tabla. Misma disposición que el CCF. --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

                @can('update', $nc)
                <div class="order-2 lg:order-1 lg:col-span-2">

                    {{-- ── Devolución / faltante: líneas del CCF original ───────────────── --}}
                    @if ($porProductos)
                        <div class="bg-white shadow sm:rounded-lg p-5" x-data="{ filtro: '' }">
                            <h3 class="font-semibold text-gray-700">Líneas del CCF original</h3>
                            <p class="mt-1 text-xs text-gray-500">
                                Escribí la cantidad a acreditar de cada línea. <strong>0 o vacío la retira.</strong>
                                No se puede acreditar más que el saldo disponible: lo que ya acreditaron otras
                                notas de crédito vigentes ya está descontado.
                            </p>

                            <div class="mt-4">
                                <x-input-label for="filtro-lineas" value="Filtrar líneas" class="sr-only" />
                                <input id="filtro-lineas" type="text" x-model="filtro" autocomplete="off"
                                       placeholder="Filtrar por producto o código…"
                                       class="block w-full sm:w-96 border-gray-300 rounded-lg shadow-sm py-2.5 text-base focus:border-indigo-500 focus:ring-indigo-500">
                            </div>

                            @if ($lineasOriginales->isEmpty())
                                <p class="mt-4 text-sm text-gray-400">El documento original no tiene líneas.</p>
                            @else
                                <div class="relative mt-4 overflow-x-auto max-h-[70vh] overflow-y-auto border border-gray-100 rounded-md">
                                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead class="bg-gray-50 sticky top-0 z-10">
                                            <tr class="text-left text-gray-500">
                                                <th class="px-3 py-2">Producto</th>
                                                <th class="px-3 py-2 text-right">Precio</th>
                                                <th class="px-3 py-2 text-right">Original</th>
                                                <th class="px-3 py-2 text-right">Acreditada</th>
                                                <th class="px-3 py-2 text-right">Disponible</th>
                                                <th class="px-3 py-2">Acreditar</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            @foreach ($lineasOriginales as $fila)
                                                @php
                                                    $lo = $fila['linea'];
                                                    $enEstaNc = $fila['en_esta_nc'];
                                                    $tope = $fila['tope'];
                                                    $filtro = mb_strtolower(trim(($lo->codigo ?? '').' '.($lo->codigo_barra ?? '').' '.$lo->descripcion));
                                                @endphp
                                                <tr x-show="filtro === '' || @js($filtro).includes(filtro.toLowerCase().trim())"
                                                    class="hover:bg-gray-50">
                                                    <td class="px-3 py-2">
                                                        <span class="font-medium">{{ $lo->descripcion }}</span>
                                                        @if ($lo->codigo)
                                                            <span class="block text-[11px] font-mono text-gray-400">{{ $lo->codigo }}</span>
                                                        @endif
                                                    </td>
                                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($lo->precio_unitario, 2) }}</td>
                                                    <td class="px-3 py-2 text-right font-mono">{{ rtrim(rtrim($lo->cantidad, '0'), '.') }}</td>
                                                    <td class="px-3 py-2 text-right font-mono">{{ rtrim(rtrim($fila['acreditado'], '0'), '.') ?: '0' }}</td>
                                                    <td class="px-3 py-2 text-right font-mono">{{ rtrim(rtrim($fila['disponible'], '0'), '.') ?: '0' }}</td>
                                                    <td class="px-3 py-2">
                                                        @if (\App\Support\Dinero::comparar($tope, '0') > 0)
                                                            {{-- Idempotente por línea original: escribir la cantidad la fija
                                                                 (no la suma), y 0/vacío la retira. El botón es el respaldo
                                                                 sin JavaScript. --}}
                                                            <form method="POST" action="{{ route('facturacion.acreditar.cantidad', [$nc, $lo]) }}"
                                                                  data-ajax="cantidad" data-linea="{{ $lo->id }}" class="flex items-end gap-2">
                                                                @csrf
                                                                <div>
                                                                    <label class="sr-only" for="acr-{{ $lo->id }}">Cantidad a acreditar</label>
                                                                    <input id="acr-{{ $lo->id }}" type="number" name="cantidad"
                                                                           value="{{ $enEstaNc !== null ? rtrim(rtrim($enEstaNc, '0'), '.') : '' }}"
                                                                           step="0.0001" min="0" max="{{ $tope }}" inputmode="decimal" placeholder="0"
                                                                           autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                                                                           onchange="this.form.requestSubmit()"
                                                                           class="block w-24 border-gray-300 rounded-md shadow-sm text-sm {{ $enEstaNc !== null ? 'ring-1 ring-indigo-300 bg-indigo-50/50' : '' }}">
                                                                </div>
                                                                <button class="px-3 py-2 {{ $enEstaNc !== null ? 'bg-gray-600 hover:bg-gray-700' : 'bg-indigo-600 hover:bg-indigo-700' }} text-white text-sm rounded-md">
                                                                    {{ $enEstaNc !== null ? 'Actualizar' : 'Acreditar' }}
                                                                </button>
                                                            </form>
                                                        @else
                                                            <span class="text-gray-400 text-xs">Sin saldo</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>

                    {{-- ── Avería: catálogo libre de productos ──────────────────────────── --}}
                    @elseif ($porAveria)
                        <div class="bg-white shadow sm:rounded-lg p-5" x-data="{ filtro: '' }">
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="font-semibold text-gray-700">Productos disponibles</h3>
                                <span class="text-xs text-gray-400">{{ count($productosDisponibles) }} producto(s) activos</span>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                La avería acredita <strong>cualquier producto</strong> del catálogo, no solo los del CCF
                                relacionado. El precio es el que aplica a este cliente y sala. <strong>0 o vacío retira</strong> el producto.
                            </p>

                            <div class="mt-4">
                                <x-input-label for="filtro-productos" value="Filtrar por nombre, código interno o código de barra" class="sr-only" />
                                <div class="relative">
                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
                                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd"/></svg>
                                    </span>
                                    <input id="filtro-productos" type="text" x-model="filtro" autocomplete="off"
                                           placeholder="Buscar por nombre, código interno o código de barra…"
                                           class="block w-full border-gray-300 rounded-lg shadow-sm pl-10 py-2.5 text-base focus:border-indigo-500 focus:ring-indigo-500">
                                </div>
                            </div>

                            @if (count($productosDisponibles) === 0)
                                <p class="mt-4 text-sm text-gray-400">No hay productos activos para agregar.</p>
                            @else
                                {{-- "relative" ancla acá los <label class="sr-only"> de cada fila para
                                     que este contenedor los recorte con su propio scroll, en vez de
                                     escaparse y estirar el alto real del documento. --}}
                                <div class="relative mt-4 overflow-x-auto max-h-[70vh] overflow-y-auto border border-gray-100 rounded-md">
                                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead class="bg-gray-50 sticky top-0 z-10">
                                            <tr class="text-left text-gray-500">
                                                <th class="px-3 py-2">Código</th>
                                                <th class="px-3 py-2">Código barra</th>
                                                <th class="px-3 py-2">Producto</th>
                                                <th class="px-3 py-2 text-right">Precio aplicado</th>
                                                <th class="px-3 py-2">Cantidad</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            @foreach ($productosDisponibles as $p)
                                                <tr x-show="filtro === '' || @js($p['filtro']).includes(filtro.toLowerCase().trim())"
                                                    class="hover:bg-gray-50">
                                                    <td class="px-3 py-2 font-mono">{{ $p['codigo'] ?? '—' }}</td>
                                                    <td class="px-3 py-2 font-mono text-gray-500">{{ $p['codigo_barra'] ?? '—' }}</td>
                                                    <td class="px-3 py-2 font-medium">{{ $p['nombre'] }}</td>
                                                    <td class="px-3 py-2 text-right">
                                                        @if ($p['sin_precio'])
                                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">Sin precio</span>
                                                        @else
                                                            <span class="font-mono">${{ $p['precio_fmt'] }}</span>
                                                            <span class="block text-[10px] {{ $p['es_especial'] ? 'text-indigo-600' : 'text-gray-400' }}">{{ $p['origen_label'] }}</span>
                                                        @endif
                                                    </td>
                                                    @if ($p['sin_precio'])
                                                        <td class="px-3 py-2 text-xs text-gray-400">No se puede agregar sin precio.</td>
                                                    @else
                                                        @php $qty = $cantidadesPorProducto[$p['id']] ?? null; @endphp
                                                        <td class="px-3 py-2">
                                                            {{-- Mismo endpoint idempotente del CCF: escribir la cantidad
                                                                 agrega/actualiza; 0 o vacío quita. Nunca duplica línea. --}}
                                                            <form method="POST" action="{{ route('facturacion.productos.cantidad', [$nc, $p['id']]) }}"
                                                                  data-ajax="cantidad" data-producto="{{ $p['id'] }}" class="flex items-end gap-2">
                                                                @csrf
                                                                <div>
                                                                    <label class="sr-only" for="cant-add-{{ $p['id'] }}">Cantidad</label>
                                                                    <input id="cant-add-{{ $p['id'] }}" type="number" name="cantidad"
                                                                           value="{{ $qty ?? '' }}" step="1" min="0" inputmode="numeric" placeholder="0"
                                                                           autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                                                                           onchange="this.form.requestSubmit()"
                                                                           class="block w-20 border-gray-300 rounded-md shadow-sm text-sm {{ $qty ? 'ring-1 ring-indigo-300 bg-indigo-50/50' : '' }}">
                                                                </div>
                                                                <button class="px-3 py-2 {{ $qty ? 'bg-gray-600 hover:bg-gray-700' : 'bg-indigo-600 hover:bg-indigo-700' }} text-white text-sm rounded-md">
                                                                    {{ $qty ? 'Actualizar' : 'Agregar' }}
                                                                </button>
                                                            </form>
                                                        </td>
                                                    @endif
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>

                    {{-- ── Pronto pago / otro ajuste: conceptos por monto ───────────────── --}}
                    @else
                        <div class="bg-white shadow sm:rounded-lg p-5">
                            <h3 class="font-semibold text-gray-700">Agregar concepto</h3>
                            <p class="mt-1 text-xs text-gray-500">
                                Estas líneas son <strong>conceptos de ajuste</strong>, no productos físicos: no afectan
                                inventario. Una nota por monto no mezcla conceptos con líneas de producto.
                            </p>
                            <form method="POST" action="{{ route('facturacion.conceptos.store', $nc) }}"
                                  class="mt-4 grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                                @csrf
                                <div class="sm:col-span-2">
                                    <x-input-label for="descripcion" value="Concepto / descripción *" />
                                    <x-text-input id="descripcion" name="descripcion" type="text" class="mt-1 block w-full"
                                                  placeholder="Ej. Descuento por pronto pago" required />
                                </div>
                                <div>
                                    <x-input-label for="monto" value="Monto *" />
                                    <input id="monto" name="monto" type="number" step="0.01" min="0.01" inputmode="decimal"
                                           class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm" required>
                                </div>
                                <div>
                                    <x-input-label for="tipo_impuesto" value="Tratamiento" />
                                    <select id="tipo_impuesto" name="tipo_impuesto" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                                        @foreach ($tiposImpuesto as $valor => $label)
                                            <option value="{{ $valor }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="sm:col-span-4">
                                    <button class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">Agregar concepto</button>
                                </div>
                            </form>

                            {{-- Referencia del CCF: para un pronto pago hay que poder mirar el total
                                 que se está descontando sin abrir el original en otra pestaña. --}}
                            @if ($original)
                                <dl class="mt-5 grid grid-cols-2 sm:grid-cols-4 gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-xs">
                                    <div class="col-span-2"><dt class="text-gray-500">CCF relacionado</dt><dd class="font-mono text-gray-800 break-all">{{ $original->numero_control ?? $original->numero_interno }}</dd></div>
                                    <div><dt class="text-gray-500">Fecha</dt><dd class="text-gray-800">{{ $original->fecha_emision?->format('d/m/Y') ?? '—' }}</dd></div>
                                    <div><dt class="text-gray-500">Total del CCF</dt><dd class="font-mono font-semibold text-gray-900">${{ number_format((float) $original->total_pagar, 2) }}</dd></div>
                                </dl>
                            @endif
                        </div>
                    @endif
                </div>
                @endcan

                {{-- Panel pegajoso: lo acreditado + resumen fiscal + Generar. --}}
                <div class="order-1 lg:order-2 @cannot('update', $nc) lg:col-span-3 @endcannot">
                    <div class="lg:sticky lg:top-6" id="resumen-panel">
                        @include('facturacion.partials.resumen-nc', [
                            'dte' => $nc,
                            'esAgenteRetencion' => $esAgenteRetencion ?? null,
                            'confirmGenerar' => $confirmGenerar,
                        ])
                    </div>
                </div>
            </div>

            <div>
                <a href="{{ route('facturacion.index') }}" class="text-sm text-gray-500 hover:underline">← Volver al listado</a>
            </div>
        </div>
    </div>

    {{-- Buscador del CCF a vincular. Va en un <script> y no dentro del atributo x-data:
         una comilla doble ahí adentro cierra el atributo antes de tiempo y vuelca el resto
         del componente al documento como texto visible. --}}
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('vincularCcf', (ruta, clienteId, salaAveria) => ({
                ruta,
                clienteId: String(clienteId ?? ''),
                // Sala a la que corresponde la nota. Acota la búsqueda y es contra la que
                // se juzga el cruce; vincular un CCF de otra sala NO la cambia.
                salaNota: String(salaAveria ?? ''),
                otrasSalas: false,
                buscar: '',
                abierto: false,
                cargando: false,
                resultados: [],
                pagina: 1,
                hayMas: false,
                hayPrevia: false,
                peticion: 0,
                elegidoId: '',
                elegido: null,

                async buscarCcf(pagina) {
                    const destino = Math.max(1, pagina || 1);
                    this.abierto = true;
                    this.cargando = true;
                    // Solo la última petición pinta: escribir rápido no debe dejar en
                    // pantalla el resultado de una consulta ya vieja.
                    const propia = ++this.peticion;
                    // Acotado al cliente de la nota: una NC nunca puede cruzar de cliente,
                    // y el servidor lo vuelve a exigir al vincular. La sala acota además,
                    // salvo que se pida explícitamente mirar las demás.
                    const params = new URLSearchParams({
                        q: this.buscar,
                        pagina: String(destino),
                        cliente_id: this.clienteId,
                    });
                    if (!this.otrasSalas && this.salaNota !== '') {
                        params.set('cliente_sucursal_id', this.salaNota);
                    }
                    try {
                        const r = await fetch(this.ruta + '?' + params.toString(), {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                        });
                        const data = await r.json();
                        if (propia !== this.peticion) { return; }
                        this.resultados = data.resultados ?? [];
                        this.pagina = data.pagina ?? destino;
                        this.hayMas = !!data.hay_mas;
                        this.hayPrevia = !!data.hay_previa;
                    } catch (e) {
                        if (propia === this.peticion) {
                            this.resultados = [];
                            this.hayMas = false;
                            this.hayPrevia = false;
                        }
                    } finally {
                        if (propia === this.peticion) { this.cargando = false; }
                    }
                },
                elegir(c) { this.elegido = c; this.elegidoId = String(c.id); this.abierto = false; this.buscar = ''; },
                limpiar() { this.elegido = null; this.elegidoId = ''; },
                // El CCF es de otra sala que aquella a la que corresponde la nota: se
                // permite —es el mismo cliente— pero con explicación escrita, y el servidor
                // la exige igual. La sala de la nota no se mueve por esto.
                get cruzaDeSala() {
                    return this.elegidoId !== '' && this.salaNota !== ''
                        && String(this.elegido?.cliente_sucursal_id ?? '') !== this.salaNota;
                },
            }));
        });
    </script>
</x-app-layout>
