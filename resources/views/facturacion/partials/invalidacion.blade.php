{{--
    Invalidación oficial (evento anulardte) — dentro de la ficha del documento.

    Dos caminos:
      1) Transmisión REAL a Hacienda (facturacion.invalidacion.transmitir): reutiliza
         DteInvalidacionService::transmitir(). Fuertemente candada: en el entorno actual
         (modo seguro) queda BLOQUEADA y se muestran las razones. Exige la frase exacta
         INVALIDAR DTE (revalidada en servidor). Solo marca Invalidado si Hacienda ACEPTA;
         si rechaza o falla, conserva el estado.
      2) Modo prueba (MOCK) + dry-run: diagnóstico avanzado, no transmite nada.

    Espera $invalidacion (ver DteController::show) y $dte.
--}}
@php
    $inv = $invalidacion;
    $selloInval = $dte->sello_invalidacion;
    $selloMock = \Illuminate\Support\Str::startsWith((string) $selloInval, 'MOCK');
    // La transmisión real está bloqueada si los candados del entorno la bloquean O si el
    // documento no es candidato (p. ej. aceptación MOCK): en ambos casos el botón va
    // deshabilitado y se muestran las razones, sin ocultar la tarjeta.
    $bloqueadoReal = ($inv['candados']['bloqueado'] ?? true) || ! ($inv['puede_transmitir'] ?? false);
@endphp

<div class="bg-white shadow sm:rounded-lg p-6 border-l-4 border-amber-400 h-full">
    <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
        <h3 class="font-semibold text-gray-700">Invalidación oficial (evento anulardte)</h3>
        @if ($inv['ya_invalidado'] ?? false)
            <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">Con evento registrado</span>
        @elseif (($inv['puede_transmitir'] ?? false) && ! $bloqueadoReal)
            <span class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-medium text-rose-800">Transmisión habilitada</span>
        @else
            <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">Bloqueada (modo seguro)</span>
        @endif
    </div>

    <p class="mb-4 text-sm text-gray-500">
        Invalida el documento ante Hacienda enviando el evento al ambiente del propio documento.
        Solo se marca como <strong>Invalidado</strong> si Hacienda ACEPTA; si rechaza o falla, el
        documento conserva su estado y podés revertirlo con una nota de crédito. No se deshace.
    </p>

    @if ($inv['protegido'] ?? false)
        <div class="mb-4 bg-rose-50 border border-rose-300 rounded-md p-3 text-xs text-rose-800 font-semibold">
            DOCUMENTO PROTEGIDO COMO EVIDENCIA APITEST
            <p class="mt-1 font-normal">
                Este DTE está en la lista de protección
                (<span class="font-mono">dte.invalidacion.protegidos_numero_control</span> /
                <span class="font-mono">protegidos_codigo_generacion</span>) y <strong>no puede invalidarse</strong>
                por esta vía —ni mock ni real— mientras la protección siga activa.
            </p>
        </div>
    @endif

    @if ($inv['tiene_nc_relacionada'] ?? false)
        <div class="mb-4 bg-orange-50 border border-orange-300 rounded-md p-3 text-xs text-orange-800 font-semibold">
            ESTE DOCUMENTO YA TIENE UNA NOTA DE CRÉDITO RELACIONADA
            <p class="mt-1 font-normal">
                Invalidar oficialmente un documento que ya tiene una Nota de Crédito emitida en su contra puede
                producir una <strong>doble corrección fiscal</strong> (la NC y el evento de invalidación cubriendo
                la misma operación).
                @if (($inv['notas_credito_relacionadas'] ?? collect())->isNotEmpty())
                    NC relacionada(s): <span class="font-mono">{{ $inv['notas_credito_relacionadas']->pluck('numero_control')->implode(', ') }}</span>.
                @endif
                Requiere confirmación explícita para continuar.
            </p>
        </div>
    @endif

    {{-- Evidencia del evento ya firmado (mock o real). Solo lectura. --}}
    @if ($inv['ya_invalidado'] && filled($selloInval))
        <div class="bg-gray-50 border border-gray-200 rounded-md p-4 mb-4">
            <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                <h4 class="font-semibold text-gray-700">Evento de invalidación registrado</h4>
                <span class="inline-block rounded-full px-3 py-1 text-xs font-medium {{ $selloMock ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-700' }}">
                    {{ $selloMock ? 'Invalidación SIMULADA (MOCK)' : 'Invalidación aceptada por Hacienda' }}
                </span>
            </div>
            <dl class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div class="sm:col-span-2"><dt class="text-gray-500">Sello de invalidación</dt><dd class="font-mono break-all text-gray-800">{{ $selloInval }}</dd></div>
                <div><dt class="text-gray-500">Tipo de anulación (CAT-024)</dt><dd>{{ $dte->tipo_anulacion?->value }} — {{ $dte->tipo_anulacion?->label() ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Fecha del evento</dt><dd>{{ optional($dte->fecha_invalidacion)->format('d/m/Y H:i:s') ?? '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-gray-500">Código de generación (evento)</dt><dd class="font-mono break-all">{{ $dte->codigo_generacion_invalidacion ?? '—' }}</dd></div>
            </dl>

            @if ($dte->respuesta_mh_invalidacion)
                @php $ri = $dte->respuesta_mh_invalidacion; @endphp
                <details class="mt-4 group">
                    <summary class="cursor-pointer text-sm font-medium text-indigo-600 hover:text-indigo-800">Ver respuesta del evento</summary>
                    <dl class="mt-3 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                        <div><dt class="text-gray-500">Estado</dt><dd>{{ $ri['estado'] ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Código</dt><dd class="font-mono">{{ $ri['codigoMsg'] ?? '—' }}</dd></div>
                        <div class="md:col-span-2"><dt class="text-gray-500">Descripción</dt><dd>{{ $ri['descripcionMsg'] ?? '—' }}</dd></div>
                    </dl>
                    @if (! empty($ri['observaciones']))
                        <ul class="mt-3 list-disc ml-5 text-sm text-gray-700">
                            @foreach ($ri['observaciones'] as $o)<li>{{ $o }}</li>@endforeach
                        </ul>
                    @endif
                    @if ($dte->respuesta_mh_invalidacion_path)
                        <p class="mt-3 text-xs text-gray-400 font-mono break-all">Respuesta guardada: {{ $dte->respuesta_mh_invalidacion_path }}</p>
                    @endif
                </details>
            @endif
        </div>
    @endif

    {{-- === Transmisión REAL a Hacienda (candado-gated). Se muestra para cualquier documento
         aceptado sin evento previo; si está bloqueada (candados del entorno o documento no
         candidato, p. ej. MOCK) el botón va deshabilitado y se listan las razones. === --}}
    @if (! ($inv['ya_invalidado'] ?? false))
        <div class="mb-2 bg-rose-50 border border-rose-300 rounded-md p-3 text-xs text-rose-800 font-semibold">
            TRANSMITE A HACIENDA DE VERDAD (evento anulardte)
            <p class="mt-1 font-normal">
                Esta acción envía la invalidación al ambiente del propio documento. El servidor revalida
                todos los candados (aceptación real por el MH, flags, firma real, ambiente, frase exacta,
                doble invalidación, evidencia protegida y NC relacionada) antes de transmitir.
            </p>
        </div>

        @if ($bloqueadoReal)
            <div class="mb-3 bg-gray-50 border border-gray-200 rounded-md p-3">
                <p class="text-xs font-semibold text-gray-600 mb-1">Bloqueada en este entorno. Razones:</p>
                <ul class="text-xs text-gray-500 list-disc list-inside space-y-1">
                    @foreach ($inv['candados']['razones'] as $razon)
                        <li>{{ $razon }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('facturacion.invalidacion.transmitir', $dte) }}"
              class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end"
              onsubmit="return dteConfirmarInvalidacionReal(this);">
            @csrf
            <div>
                <x-input-label for="inval_real_tipo" value="Tipo de anulación (CAT-024) *" />
                <select id="inval_real_tipo" name="tipo" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm" required>
                    @foreach ($inv['tipos'] as $valor => $label)
                        <option value="{{ $valor }}" @selected((int) old('tipo', \App\Enums\TipoAnulacionMh::RescindirOperacion->value) === $valor)>{{ $valor }} — {{ $label }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('tipo')" class="mt-1" />
            </div>
            <div class="md:col-span-2">
                <x-input-label for="inval_real_motivo" value="Motivo en texto (obligatorio si tipo = 3)" />
                <x-text-input id="inval_real_motivo" name="motivo" type="text" class="mt-1 block w-full" :value="old('motivo')" />
                <x-input-error :messages="$errors->get('motivo')" class="mt-1" />
            </div>
            <div class="md:col-span-2">
                <x-input-label for="inval_real_reemplazo" value="Código de generación de reemplazo (obligatorio si tipo = 1)" />
                <x-text-input id="inval_real_reemplazo" name="reemplazo" type="text" class="mt-1 block w-full font-mono" :value="old('reemplazo')" />
                <x-input-error :messages="$errors->get('reemplazo')" class="mt-1" />
            </div>
            @if ($inv['tiene_nc_relacionada'] ?? false)
                <div class="md:col-span-3">
                    <label class="inline-flex items-center gap-2 text-xs text-orange-700 font-semibold">
                        <input type="checkbox" name="confirmar_nc_relacionada" value="1" class="rounded border-orange-400 text-orange-600 focus:ring-orange-500">
                        Entiendo el riesgo de doble corrección fiscal y confirmo invalidar de todas formas
                    </label>
                </div>
            @endif
            <div class="md:col-span-3">
                <label class="block text-xs font-semibold text-rose-700 mb-1">Escribí exactamente: <span class="font-mono">INVALIDAR DTE</span></label>
                <input type="text" name="confirmacion_invalidacion" autocomplete="off" spellcheck="false" placeholder="INVALIDAR DTE"
                       class="w-72 rounded-md border-rose-300 text-sm font-mono focus:border-rose-500 focus:ring-rose-500">
                <x-input-error :messages="$errors->get('confirmacion_invalidacion')" class="mt-1" />
            </div>
            <div class="md:col-span-3">
                <button type="submit" {{ $bloqueadoReal ? 'disabled' : '' }}
                        class="inline-flex items-center px-4 py-2 text-white text-sm rounded-md font-medium {{ $bloqueadoReal ? 'bg-gray-300 cursor-not-allowed' : 'bg-rose-600 hover:bg-rose-700' }}">
                    Transmitir invalidación a Hacienda
                </button>
                @if ($bloqueadoReal)
                    <p class="mt-1 text-xs text-gray-400">Botón deshabilitado: revisá las razones de arriba. La transmisión real está candada en este entorno.</p>
                @endif
            </div>
        </form>

        <script>
            function dteConfirmarInvalidacionReal(form) {
                var val = ((form.confirmacion_invalidacion && form.confirmacion_invalidacion.value) || '').trim();
                if (val !== 'INVALIDAR DTE') {
                    alert('Para transmitir la invalidación debés escribir exactamente: INVALIDAR DTE');
                    return false;
                }
                return confirm('¿TRANSMITIR la invalidación a Hacienda? Solo se marcará Invalidado si Hacienda la acepta. No se deshace.');
            }
        </script>
    @endif

    {{-- === Modo prueba (MOCK) + dry-run: diagnóstico avanzado, no transmite nada. === --}}
    @if (($inv['puede_mock'] ?? false) || $inv['dry_run'])
        <details class="mt-6 group rounded-lg border border-dashed border-gray-300 bg-gray-50/60">
            <summary class="cursor-pointer select-none px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-800">
                Avanzado · modo prueba (MOCK) y dry-run
                <span class="ml-1 font-normal text-gray-400">(diagnóstico, no transmite a Hacienda)</span>
            </summary>
            <div class="px-4 pb-4 pt-1 space-y-4">
                <div class="bg-amber-50 border border-amber-300 rounded-md p-3 text-xs text-amber-800 font-semibold">
                    SIMULACIÓN — NO TRANSMITE A HACIENDA
                    <p class="mt-1 font-normal">
                        El modo prueba firma el evento con un sello ficticio (<span class="font-mono">MOCK-INVAL-…</span>) y el
                        dry-run solo prepara/valida el evento. Ninguno transmite ni cambia el estado del documento.
                    </p>
                </div>

                @if ($inv['puede_mock'])
                    <form method="POST" action="{{ route('facturacion.invalidacion.mock', $dte) }}"
                          class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end"
                          onsubmit="return confirm('¿Firmar el evento de invalidación en MODO PRUEBA (MOCK)? No se transmite nada a Hacienda ni cambia el estado del documento.');">
                        @csrf
                        <div>
                            <x-input-label for="inval_tipo" value="Tipo de anulación (CAT-024) *" />
                            <select id="inval_tipo" name="tipo" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm" required>
                                @foreach ($inv['tipos'] as $valor => $label)
                                    <option value="{{ $valor }}" @selected((int) old('tipo', \App\Enums\TipoAnulacionMh::RescindirOperacion->value) === $valor)>{{ $valor }} — {{ $label }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('tipo')" class="mt-1" />
                        </div>
                        <div class="md:col-span-2">
                            <x-input-label for="inval_motivo" value="Motivo en texto (obligatorio si tipo = 3)" />
                            <x-text-input id="inval_motivo" name="motivo" type="text" class="mt-1 block w-full" :value="old('motivo')" />
                            <x-input-error :messages="$errors->get('motivo')" class="mt-1" />
                        </div>
                        <div class="md:col-span-2">
                            <x-input-label for="inval_reemplazo" value="Código de generación de reemplazo (obligatorio si tipo = 1)" />
                            <x-text-input id="inval_reemplazo" name="reemplazo" type="text" class="mt-1 block w-full font-mono" :value="old('reemplazo')" />
                            <x-input-error :messages="$errors->get('reemplazo')" class="mt-1" />
                        </div>
                        <div class="flex flex-col gap-2">
                            @unless ($inv['mock_activo'])
                                <label class="inline-flex items-center gap-2 text-xs text-gray-600">
                                    <input type="checkbox" name="confirmar_sin_flag" value="1" class="rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                                    Ejecutar aunque el mock esté apagado (no transmite nada)
                                </label>
                            @endunless
                            @if ($inv['tiene_nc_relacionada'] ?? false)
                                <label class="inline-flex items-center gap-2 text-xs text-orange-700 font-semibold">
                                    <input type="checkbox" id="inval_confirmar_nc" name="confirmar_nc_relacionada" value="1" required class="rounded border-orange-400 text-orange-600 focus:ring-orange-500">
                                    Entiendo el riesgo de doble corrección fiscal y confirmo invalidar de todas formas
                                </label>
                            @endif
                        </div>
                        <div class="md:col-span-3 flex flex-wrap gap-3">
                            <button class="inline-flex items-center px-4 py-2 bg-amber-600 text-white text-sm rounded-md hover:bg-amber-700">
                                Firmar invalidación (MOCK)
                            </button>
                            <button type="submit" form="inval_dry_run_form"
                                    class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                                Dry-run visual
                            </button>
                        </div>
                    </form>

                    {{-- Form separado del dry-run (reusa los mismos campos vía JS al enviar). --}}
                    <form method="POST" action="{{ route('facturacion.invalidacion.dry-run', $dte) }}" id="inval_dry_run_form"
                          onsubmit="this.querySelector('[name=tipo]').value = document.getElementById('inval_tipo').value;
                                    this.querySelector('[name=motivo]').value = document.getElementById('inval_motivo').value;
                                    this.querySelector('[name=reemplazo]').value = document.getElementById('inval_reemplazo').value;
                                    var ncChk = document.getElementById('inval_confirmar_nc');
                                    this.querySelector('[name=confirmar_nc_relacionada]').value = (ncChk && ncChk.checked) ? '1' : '';">
                        @csrf
                        <input type="hidden" name="tipo">
                        <input type="hidden" name="motivo">
                        <input type="hidden" name="reemplazo">
                        <input type="hidden" name="confirmar_nc_relacionada">
                    </form>
                @endif

                {{-- Resultado del último dry-run visual (seguro: sin token/contraseña; el JWS va como marcador). --}}
                @if ($inv['dry_run'])
                    @php $dr = $inv['dry_run']; @endphp
                    <div class="bg-white border border-gray-200 rounded-md p-4 text-sm">
                        <h4 class="font-semibold text-gray-700 mb-2">Resultado del dry-run de invalidación (no transmitido)</h4>
                        <dl class="grid grid-cols-2 md:grid-cols-3 gap-3">
                            <div><dt class="text-gray-500">¿Transmitiría?</dt><dd>{{ $dr['transmitiria'] ? 'sí' : 'no' }}</dd></div>
                            <div><dt class="text-gray-500">ambiente</dt><dd>{{ $dr['ambiente'] }}</dd></div>
                            <div><dt class="text-gray-500">schema válido</dt><dd>{{ $dr['schema']['valido'] ? 'sí' : 'no' }} ({{ $dr['schema']['estado'] }})</dd></div>
                            <div class="md:col-span-3"><dt class="text-gray-500">endpoint</dt><dd class="font-mono break-all">{{ $dr['endpoint'] }}</dd></div>
                        </dl>
                        @if (! empty($dr['schema']['errores']))
                            <ul class="mt-2 list-disc ml-5 text-xs text-rose-600">
                                @foreach (array_slice($dr['schema']['errores'], 0, 8) as $e)<li>{{ $e }}</li>@endforeach
                            </ul>
                        @endif
                        <details class="mt-3 group">
                            <summary class="cursor-pointer text-sm font-medium text-indigo-600 hover:text-indigo-800">Ver evento serializado</summary>
                            <pre class="mt-2 bg-gray-50 border border-gray-200 rounded p-3 text-xs overflow-x-auto">{{ json_encode($dr['evento'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                        <p class="mt-2 text-xs text-gray-500">El documento firmado (JWS) se genera solo al transmitir; en dry-run va como marcador. El token y la contraseña nunca se muestran.</p>
                    </div>
                @endif
            </div>
        </details>
    @endif
</div>
