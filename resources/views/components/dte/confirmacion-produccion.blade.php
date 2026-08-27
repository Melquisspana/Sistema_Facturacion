@props(['dte', 'produccion'])

{{--
    «EMITIR EN PRODUCCIÓN» — tarjeta + confirmación en modal, ÚNICA para FCF (01),
    CCF (03), NC (05) y FEX (11).

    Antes había dos formularios distintos y una asimetría por tipo:
      · 01/03/11 tenían un modal propio («Emitir a Hacienda») contra
        `generar-transmitir-produccion`, cuyo resumen leía claves del preflight que
        solo el CCF producía —en Factura y FEX salían vacías—, y que en el CCF
        mostraba además el último correlativo de un sistema externo;
      · la NC 05 no tenía ese modal (no está en DtePolicy::TIPOS_EMISION_PRODUCCION),
        así que su única vía era el panel «Avanzado» colapsado, sin resumen alguno.

    Ahora los cuatro tipos ven ESTA tarjeta. Lo que cambia entre ellos es solo la RUTA
    destino, que ya existía y que resuelve el controlador (ver DteController::show):
    los tipos con acción de producción van a `generar-transmitir-produccion`; la NC va
    a `firmar-transmitir`. Ninguna policy, correlativo ni payload cambia.

    ── El resumen viene del DTE, no del preflight ────────────────────────────────
    Todos los datos que se muestran salen de `$dte`, la fuente de verdad del documento
    (número de control, código de generación, receptor, fecha, total, emisor, ambiente).
    Así el resumen es idéntico en los cuatro tipos y no depende de que cada preflight
    devuelva las mismas claves. Del preflight solo se consumen los CANDADOS (si se puede
    emitir y por qué no), que es justamente lo que no se debe duplicar.

    Espera $produccion (ver DteController::show) y $dte.
--}}
@php
    $p = $produccion;
    $puede = (bool) ($p['puede'] ?? false);
    $razones = array_values(array_filter((array) ($p['razones'] ?? [])));
    $checks = (array) ($p['checks'] ?? []);
    $requiereBarrera = (bool) ($p['requiere_barrera'] ?? false);
    $etiqueta = (string) ($p['etiqueta'] ?? 'Firmar y transmitir a Hacienda');

    // Un error devuelto por el servidor debe REABRIR la confirmación, no dejar al
    // usuario frente a un modal cerrado sin saber qué pasó.
    $huboError = filled(session('error'));

    // El documento ya generado conserva su número; el borrador todavía no lo tiene y
    // se le asigna al emitir. Se dice así, sin inventar un flujo distinto.
    $yaGenerado = filled($dte->numero_control);
@endphp

<div id="emitir-produccion" class="scroll-mt-6 bg-white shadow sm:rounded-lg p-6 border border-gray-200">
    <div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
            <h3 class="font-semibold text-gray-700">Emitir en producción</h3>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">
                @if ($yaGenerado)
                    El documento ya está generado con su número definitivo. Esta acción lo
                    <strong>firma y lo transmite</strong> al Ministerio de Hacienda.
                @else
                    Esta acción genera el documento, lo <strong>firma y lo transmite</strong> al
                    Ministerio de Hacienda.
                @endif
                El correo al cliente va aparte, después de que Hacienda acepte.
            </p>
        </div>
        @if ($puede)
            <span class="inline-flex shrink-0 items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-medium text-rose-800">Emisión real habilitada</span>
        @else
            <span class="inline-flex shrink-0 items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">Bloqueada</span>
        @endif
    </div>

    {{-- ── Bloqueada: nada que parezca utilizable, y las razones QUE YA DA el sistema.
         `razones` viene tal cual del preflight del tipo o de
         DteTransmisionService::evaluarCandados(); acá no se inventa ninguna. --}}
    @unless ($puede)
        <div class="mt-4 rounded-md bg-gray-50 border border-gray-200 p-3">
            <p class="text-sm font-semibold text-gray-700">Emisión real bloqueada</p>
            @if ($razones !== [])
                <ul class="mt-2 text-sm text-gray-600 list-disc list-inside space-y-1">
                    @foreach ($razones as $razon)<li>{{ $razon }}</li>@endforeach
                </ul>
            @else
                <p class="mt-1 text-sm text-gray-600">La emisión real no está disponible en este momento.</p>
            @endif
        </div>
    @endunless

    {{-- ── Checklist del preflight, cuando el tipo tiene uno. Se muestra igual esté o no
         habilitada la emisión: es el diagnóstico que explica el estado. --}}
    @if ($checks !== [])
        <details class="mt-4 group rounded-lg border border-gray-200" @if (! $puede) open @endif>
            <summary class="cursor-pointer select-none px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-800">
                Verificaciones previas
                <span class="ml-1 font-normal text-gray-400">({{ collect($checks)->where('ok', true)->count() }}/{{ count($checks) }} correctas)</span>
            </summary>
            <ul class="space-y-1 px-4 pb-4 pt-1 text-sm">
                @foreach ($checks as $c)
                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 inline-block h-4 w-4 shrink-0 rounded-full text-center text-xs leading-4 {{ $c['ok'] ? 'bg-green-100 text-green-700' : 'bg-rose-100 text-rose-700' }}">{{ $c['ok'] ? '✓' : '✗' }}</span>
                        <span class="{{ $c['ok'] ? 'text-gray-600' : 'text-rose-700 font-medium' }}">
                            {{ $c['label'] }}
                            <span class="block text-xs text-gray-400">{{ $c['detalle'] }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    <div class="mt-4">
        <button type="button"
                @if ($puede) x-data="" x-on:click="$dispatch('open-modal', 'emitir-produccion')" @else disabled @endif
                class="inline-flex items-center gap-2 px-4 py-2 text-sm rounded-md font-medium {{ $puede ? 'bg-rose-600 text-white hover:bg-rose-700' : 'bg-gray-100 text-gray-400 cursor-not-allowed' }}">
            {{ $etiqueta }}
        </button>
        @if ($puede)
            <p class="mt-1 text-xs text-gray-500">Se abre una confirmación con el resumen. Nada se transmite hasta el último paso.</p>
        @else
            <p class="mt-1 text-xs text-gray-400">Botón deshabilitado: la emisión real no está habilitada (ver razones arriba).</p>
        @endif
    </div>

    {{-- ── CONFIRMACIÓN (modal) ==================================================
         Solo se monta cuando la emisión es posible: sin candados abiertos no hay
         formulario que rellenar. El servidor revalida la frase y los candados en cada
         intento (DteController + preflight del tipo + DteTransmisionService). ===== --}}
    @if ($puede)
        <x-modal name="emitir-produccion" maxWidth="2xl" :show="$huboError" focusable>
            <div class="p-6" x-data="{ frase: '', barrera: {{ $requiereBarrera ? 'false' : 'true' }} }">
                <div class="flex items-start justify-between gap-3">
                    <h2 class="text-lg font-semibold text-gray-800">Emitir en producción</h2>
                    <button type="button" x-on:click="$dispatch('close')" class="text-gray-400 hover:text-gray-600 text-sm">Cerrar</button>
                </div>

                {{-- ── Resumen: TODO desde $dte. Lo que no aplica al tipo simplemente no
                     se pinta, sin dejar filas vacías ni guiones sueltos. --}}
                <dl class="mt-4 rounded-lg border border-gray-200 divide-y divide-gray-100 text-sm">
                    <div class="flex justify-between gap-4 px-3 py-2">
                        <dt class="text-gray-500">Tipo de documento</dt>
                        <dd class="text-right font-medium text-gray-800">{{ $dte->tipo_dte?->label() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-3 py-2">
                        <dt class="text-gray-500">Número de control</dt>
                        <dd class="text-right font-mono text-xs text-gray-800">
                            {{ $dte->numero_control ?? 'se asigna al emitir' }}
                        </dd>
                    </div>
                    @if (filled($dte->codigo_generacion))
                        <div class="flex justify-between gap-4 px-3 py-2">
                            <dt class="text-gray-500">Código de generación</dt>
                            <dd class="text-right font-mono text-xs break-all text-gray-800">{{ $dte->codigo_generacion }}</dd>
                        </div>
                    @endif
                    @if (filled($dte->cliente?->nombre))
                        <div class="flex justify-between gap-4 px-3 py-2">
                            <dt class="text-gray-500">Cliente / receptor</dt>
                            <dd class="text-right font-medium text-gray-800">{{ $dte->cliente->nombre }}</dd>
                        </div>
                    @endif
                    @if ($dte->clienteSucursal?->nombre)
                        <div class="flex justify-between gap-4 px-3 py-2">
                            <dt class="text-gray-500">Sala</dt>
                            <dd class="text-right font-medium text-gray-800">{{ $dte->clienteSucursal->nombre }}</dd>
                        </div>
                    @endif
                    @if ($dte->fecha_emision)
                        <div class="flex justify-between gap-4 px-3 py-2">
                            <dt class="text-gray-500">Fecha</dt>
                            <dd class="text-right font-medium text-gray-800">{{ $dte->fecha_emision->format('d/m/Y') }}</dd>
                        </div>
                    @endif
                    @if ($dte->total_pagar !== null)
                        <div class="flex justify-between gap-4 px-3 py-2">
                            <dt class="text-gray-500">Total</dt>
                            <dd class="text-right font-semibold text-gray-800">${{ number_format((float) $dte->total_pagar, 2) }}</dd>
                        </div>
                    @endif
                    @if ($dte->establecimiento)
                        <div class="flex justify-between gap-4 px-3 py-2">
                            <dt class="text-gray-500">Establecimiento</dt>
                            <dd class="text-right font-medium text-gray-800">
                                {{ $dte->establecimiento->nombre }}
                                <span class="block text-xs font-normal text-gray-400">{{ $dte->establecimiento->codigo }}</span>
                            </dd>
                        </div>
                    @endif
                    @if ($dte->puntoVenta)
                        <div class="flex justify-between gap-4 px-3 py-2">
                            <dt class="text-gray-500">Punto de venta</dt>
                            <dd class="text-right font-medium text-gray-800">
                                {{ $dte->puntoVenta->nombre }}
                                <span class="block text-xs font-normal text-gray-400">{{ $dte->puntoVenta->codigo }}</span>
                            </dd>
                        </div>
                    @endif
                    <div class="flex justify-between gap-4 px-3 py-2">
                        <dt class="text-gray-500">Ambiente</dt>
                        <dd class="text-right font-semibold text-gray-800">PRODUCCIÓN</dd>
                    </div>
                </dl>

                {{-- ── Advertencia, en lenguaje llano. --}}
                <div class="mt-4 rounded-md border border-rose-300 bg-rose-50 p-3 text-sm text-rose-800">
                    <p class="font-semibold">Este documento será firmado y transmitido realmente al Ministerio de Hacienda.</p>
                    <p class="mt-1">
                        @if ($yaGenerado)
                            El documento ya tiene su número definitivo: esta acción no lo vuelve a numerar, lo envía.
                        @else
                            Al continuar se le asigna su número definitivo y se envía.
                        @endif
                        No se deshace.
                    </p>
                </div>

                <form method="POST" action="{{ $p['accion'] }}" class="mt-4"
                      x-data="{ enviando: false }"
                      x-on:submit="if (! $data.enviando) { enviando = true; } else { $event.preventDefault(); }">
                    @csrf

                    {{-- Casilla de revisión. `barrera_conta` es el NOMBRE del campo que el
                         controlador ya espera (DteController::generarTransmitirProduccion):
                         se conserva intacto para no cambiar el payload. Su texto en pantalla
                         no menciona ningún sistema externo. --}}
                    @if ($requiereBarrera)
                        <label class="flex items-start gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="barrera_conta" value="1" x-model="barrera"
                                   class="mt-0.5 rounded border-gray-300">
                            <span>Confirmo que revisé este documento —datos, totales y receptor— y que corresponde emitirlo ahora.</span>
                        </label>
                    @endif

                    <div class="mt-3">
                        <label for="emision_frase" class="block text-xs font-semibold text-rose-700 mb-1">
                            Para continuar, escribí exactamente: <span class="font-mono">EMITIR PRODUCCION</span>
                        </label>
                        <input id="emision_frase" type="text" name="confirmacion_emision" autocomplete="off" spellcheck="false"
                               placeholder="EMITIR PRODUCCION" x-model="frase"
                               class="w-72 rounded-md border-rose-300 text-sm font-mono focus:border-rose-500 focus:ring-rose-500">
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-3 border-t border-gray-100 pt-4">
                        <button type="button" x-on:click="$dispatch('close')"
                                class="inline-flex items-center px-4 py-2 text-sm rounded-md font-medium bg-white text-gray-600 hover:bg-gray-50 border border-gray-200">
                            Cancelar
                        </button>
                        {{-- Único botón rojo del flujo, y solo en el paso final. --}}
                        <button type="submit" :disabled="enviando || frase.trim() !== 'EMITIR PRODUCCION' || ! barrera"
                                class="inline-flex items-center px-4 py-2 text-sm rounded-md font-medium"
                                :class="(! enviando && frase.trim() === 'EMITIR PRODUCCION' && barrera)
                                    ? 'bg-rose-600 text-white hover:bg-rose-700'
                                    : 'bg-gray-100 text-gray-400 cursor-not-allowed'">
                            <span x-show="! enviando">{{ $etiqueta }}</span>
                            <span x-show="enviando" x-cloak>Procesando…</span>
                        </button>
                    </div>
                </form>
            </div>
        </x-modal>
    @endif
</div>
