{{--
    ALBARÁN DE CRÉDITO de la nota + la comparación contra ella.

    Vive en su propio partial y dentro de #albaran-nc-panel porque tiene que poder
    REPINTARSE solo. Antes era HTML suelto en la pantalla de edición, así que la
    comparación —total de la nota, total del albarán, diferencia, color y avisos— se
    quedaba con los valores del último render completo: acreditar una línea más actualizaba
    el panel fiscal de la derecha y dejaba acá una diferencia que ya no existía, y había que
    apretar F5 para verla desaparecer. Ahora el editor AJAX reemplaza este bloque con el
    mismo contrato que usa para el resumen (ver ccf-editor.js).

    TODO lo que se muestra acá lo calculó el SERVIDOR: los dos totales, la diferencia y la
    tolerancia con la que se juzga si cuadran. En el navegador no se resta nada; si el
    guardado falla, este bloque no se toca y sigue mostrando lo último confirmado.

    Nada de acá cambia un valor fiscal. El albarán es el documento del CLIENTE y la nota es
    el de HACIENDA: cuando no coinciden se enseña la diferencia, no se mueve un total.

    Parámetros: $nc, $reglaAlbaran, $albaran, $comparacionAlbaran, $avisosAlbaran,
    $albaranPendiente (array de textos), $confirmGenerar.
--}}
@php
    $pendientes = $albaranPendiente ?? [];
    $salaSugerida = $albaran?->sala_codigo
        ?? ($nc->clienteSucursal?->codigo ?: \App\Support\OrdenCompra::salaDesde($nc->numero_orden_compra));
@endphp

<div id="albaran-nc-panel" class="space-y-4">

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
                      onsubmit="return confirm(@js($confirmGenerar ?? '¿Generar la nota de crédito? Ya no podrá editarse.'));"
                      class="mt-3 flex flex-wrap items-center gap-3">
                    @csrf
                    <label class="flex items-center gap-2 text-sm text-amber-800">
                        <input type="checkbox" name="confirmar_avisos_nc" value="1" class="rounded border-amber-400 text-amber-600">
                        Reviso y confirmo
                    </label>
                    {{-- data-generar-btn: el mismo marcador del botón del panel fiscal, para
                         que el editor AJAX los mantenga a los dos con el mismo estado. --}}
                    <button data-generar-btn @disabled($pendientes !== [])
                            @if ($pendientes !== []) title="Faltan datos del albarán que este cliente exige." @endif
                            class="inline-flex items-center px-4 py-2 text-white text-sm rounded-md {{ $pendientes !== [] ? 'bg-gray-300 cursor-not-allowed' : 'bg-green-600 hover:bg-green-700' }}">Generar</button>
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
                    <div><dt class="text-gray-500">Total albarán</dt><dd class="font-mono">{{ $albaran->total !== null ? number_format((float) $albaran->total, 2) : '—' }}</dd></div>
                </dl>

                @if ($comparacionAlbaran)
                    {{-- role=status y no alert: es información de contraste permanente, no
                         una alarma que interrumpa. El aviso que sí exige acción vive arriba.
                         Los tres números y el veredicto los calculó el servidor. --}}
                    <div role="status"
                         class="rounded-md border p-3 text-sm mb-4 {{ $comparacionAlbaran['cuadra'] ? 'bg-green-50 border-green-200 text-green-800' : 'bg-amber-50 border-amber-300 text-amber-800' }}">
                        <p class="font-semibold">Nota de crédito contra albarán del cliente</p>
                        <p class="mt-0.5">
                            Total de la nota de crédito <span class="font-mono font-semibold">{{ $comparacionAlbaran['total_nc'] }}</span>
                            · total del albarán <span class="font-mono font-semibold">{{ $comparacionAlbaran['total_albaran'] }}</span>
                            · diferencia <span class="font-mono font-semibold">{{ $comparacionAlbaran['diferencia'] }}</span>
                            @if ($comparacionAlbaran['cuadra'])
                                — <strong>coinciden</strong>.
                            @else
                                — <strong>no coinciden</strong>: revisá cuál de los dos es el correcto antes de generar.
                            @endif
                        </p>
                        <p class="mt-0.5 text-xs opacity-80">
                            Tolerancia declarada por el cliente: {{ $comparacionAlbaran['tolerancia'] }}.
                        </p>
                    </div>
                @elseif ($albaran->total === null)
                    <p class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600 mb-4" role="status">
                        Sin el total del albarán no hay con qué comparar el de la nota de crédito
                        (<span class="font-mono">{{ number_format((float) $nc->total_pagar, 2) }}</span>).
                    </p>
                @endif
            @else
                <p class="text-sm text-amber-700 mb-4">Todavía no se registró el albarán de esta nota de crédito.</p>
            @endif

            {{-- Qué falta para poder generar. Solo aparece cuando el cliente lo exige. --}}
            @if ($pendientes !== [])
                <p class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 mb-4" role="status">
                    <strong>No se puede generar todavía:</strong> falta {{ implode(', ', $pendientes) }}.
                    Podés guardar lo que ya tengas e ir completando; el borrador se conserva.
                </p>
            @endif

            @can('update', $nc)
                {{-- data-ajax="albaran": el mismo canal del editor de líneas. Al volver, el
                     servidor manda este bloque y el panel fiscal ya repintados, así que la
                     comparación no necesita F5. Sin JavaScript sigue siendo un POST normal. --}}
                <form method="POST" action="{{ route('facturacion.albaran.store', $nc) }}" data-ajax="albaran">
                    @csrf
                    <fieldset class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4 items-start">
                        <legend class="sr-only">Datos del albarán de crédito</legend>

                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium text-gray-700" for="albaran_numero">Número de albarán *</label>
                            <input id="albaran_numero" name="numero" type="text" required maxlength="60"
                                   value="{{ old('numero', $albaran?->numero_canonico) }}"
                                   placeholder="{{ $reglaAlbaran->codigo_externo }}/0033/00/3209"
                                   @error('numero') aria-invalid="true" aria-describedby="albaran_numero_error" @else aria-describedby="albaran_numero_ayuda" @enderror
                                   class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono">
                            <p id="albaran_numero_ayuda" class="mt-1 text-xs text-gray-500">
                                Acepta el número completo, el nombre del PDF que manda el cliente, o solo el correlativo.
                                Si lleva la sala adentro, esa manda.
                            </p>
                            @error('numero')<p id="albaran_numero_error" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>

                        {{-- Tipo: lo decide la MODALIDAD de la nota, no el operador. Se
                             muestra porque va al archivo del cliente y porque capturar un
                             AC02 en una devolución es un error que conviene ver antes de
                             guardar; viaja en el POST y el servidor lo vuelve a comprobar. --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="albaran_tipo">Tipo de albarán</label>
                            <input id="albaran_tipo" name="tipo_codigo" type="text" readonly maxlength="10"
                                   value="{{ old('tipo_codigo', $albaran?->tipo_codigo ?? $reglaAlbaran->codigo_externo) }}"
                                   aria-describedby="albaran_tipo_ayuda"
                                   class="mt-1 w-full rounded-md border-gray-300 bg-gray-50 text-sm font-mono text-gray-700">
                            <p id="albaran_tipo_ayuda" class="mt-1 text-xs text-gray-500">Lo fija la modalidad de la nota.</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="albaran_sala">Sala del albarán</label>
                            <input id="albaran_sala" name="sala_codigo" type="text" maxlength="10"
                                   value="{{ old('sala_codigo', $salaSugerida) }}"
                                   placeholder="0207"
                                   @error('sala_codigo') aria-invalid="true" aria-describedby="albaran_sala_error" @else aria-describedby="albaran_sala_ayuda" @enderror
                                   class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono">
                            <p id="albaran_sala_ayuda" class="mt-1 text-xs text-gray-500">Se sugiere la de la nota.</p>
                            @error('sala_codigo')<p id="albaran_sala_error" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="albaran_fecha">Fecha del albarán</label>
                            <input id="albaran_fecha" name="fecha" type="date"
                                   value="{{ old('fecha', $albaran?->fecha?->format('Y-m-d')) }}"
                                   @error('fecha') aria-invalid="true" aria-describedby="albaran_fecha_error" @enderror
                                   class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            @error('fecha')<p id="albaran_fecha_error" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="albaran_total">Total del albarán</label>
                            <input id="albaran_total" name="total" type="number" step="0.01" min="0" inputmode="decimal"
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
                        <span class="text-xs text-gray-500">
                            Solo el número es obligatorio para guardar. Fecha y total se pueden completar después,
                            pero hacen falta para generar.
                        </span>
                    </div>
                </form>

                @if ($albaran)
                    <form method="POST" action="{{ route('facturacion.albaran.destroy', $nc) }}" class="mt-3" data-ajax="albaran"
                          onsubmit="return confirm('¿Quitar el albarán de esta nota de crédito? Quedará libre para otra nota.');">
                        @csrf
                        @method('DELETE')
                        <button class="text-sm text-red-600 hover:underline">Quitar albarán</button>
                    </form>
                @endif
            @endcan
        </div>
    @endif
</div>
