<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Pronto pago — Buscar CCF / NC</h2>
            <div class="flex items-center gap-3">
                @role('administrador')
                    <a href="{{ route('ppq.gmail.debug') }}" class="text-xs text-gray-400 hover:text-gray-600">Diagnóstico Gmail</a>
                @endrole
                <a href="{{ route('ppq.lotes.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Historial PPQ →</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ session('error') }}</div>
            @endif

            {{-- Contexto: agregando a un lote concreto (se llegó desde el lote con ?lote=ID) --}}
            @if ($loteActivo)
                <div class="rounded-md bg-indigo-50 border border-indigo-200 p-3 text-sm text-indigo-800 flex items-center justify-between">
                    <span>Estás agregando documentos al lote <span class="font-semibold">#{{ $loteActivo->id }}</span>@if ($loteActivo->referencia) · {{ $loteActivo->referencia }}@endif. Lo que busqués acá se sumará a este PPQ.</span>
                    <a href="{{ route('ppq.lotes.show', $loteActivo) }}" class="ml-3 shrink-0 rounded bg-indigo-600 px-3 py-1 text-xs text-white hover:bg-indigo-700">Volver al lote →</a>
                </div>
            @elseif ($lotesAbiertos->isEmpty())
                <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800 flex items-center justify-between">
                    <span>No hay ningún PPQ abierto. Creá uno primero para poder agregarle los CCF/NC que busqués.</span>
                    @can('ppq.gestionar')
                        <a href="{{ route('ppq.lotes.create') }}" class="ml-3 shrink-0 rounded bg-amber-600 px-3 py-1 text-xs text-white hover:bg-amber-700">Crear PPQ →</a>
                    @endcan
                </div>
            @endif

            {{-- Búsqueda principal: por TIPO de documento (CCF por defecto; luego NC) --}}
            @php
                $esNcModo = $tipo === '05';
                $ctxLink = array_filter(['q' => $filtros['q'] ?? null, 'lote' => $loteActivo?->id], fn ($v) => filled($v));
            @endphp
            <div class="bg-white shadow sm:rounded-lg p-6">
                {{-- Selector de tipo de documento --}}
                <div class="mb-4">
                    <span class="block text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1.5">Tipo de documento</span>
                    <div class="inline-flex rounded-lg ring-1 ring-gray-300 p-0.5 bg-gray-50">
                        <a href="{{ route('ppq.index', $ctxLink + ['tipo' => '03']) }}"
                           class="px-4 py-1.5 text-sm font-medium rounded-md {{ ! $esNcModo ? 'bg-indigo-600 text-white shadow' : 'text-gray-600 hover:text-gray-900' }}">CCF</a>
                        <a href="{{ route('ppq.index', $ctxLink + ['tipo' => '05']) }}"
                           class="px-4 py-1.5 text-sm font-medium rounded-md {{ $esNcModo ? 'bg-rose-600 text-white shadow' : 'text-gray-600 hover:text-gray-900' }}">Nota de crédito</a>
                    </div>
                    <p class="mt-1.5 text-xs text-gray-400">
                        @if ($esNcModo)
                            Buscando solo <span class="font-medium text-rose-600">Notas de crédito</span>. Agregalas después de los CCF.
                        @else
                            Buscando solo <span class="font-medium text-indigo-600">CCF</span>. Cuando termines, cambiá a “Nota de crédito”.
                        @endif
                    </p>
                </div>

                <form method="GET" action="{{ route('ppq.index') }}">
                    @if ($loteActivo)
                        <input type="hidden" name="lote" value="{{ $loteActivo->id }}">
                    @endif
                    <input type="hidden" name="tipo" value="{{ $tipo }}">
                    <label for="q" class="block text-sm font-medium text-gray-700 mb-1">{{ $esNcModo ? 'Buscar Nota de crédito' : 'Buscar CCF' }}</label>
                    <div class="flex gap-2">
                        <input id="q" type="text" name="q" value="{{ $filtros['q'] ?? '' }}" autofocus placeholder="Ej. 0986"
                               class="flex-1 rounded-md border-gray-300 text-base py-3">
                        <button type="submit" class="rounded-md {{ $esNcModo ? 'bg-rose-600 hover:bg-rose-700' : 'bg-indigo-600 hover:bg-indigo-700' }} px-6 text-sm font-medium text-white">Buscar</button>
                    </div>
                    <p class="mt-1 text-xs text-gray-400">
                        Escribí el número del {{ $esNcModo ? 'NC' : 'CCF' }}: los últimos dígitos (ej. 0986) o el número de
                        control completo. Devuelve <span class="font-medium">ese</span> documento, no parecidos.
                        Agregarlo al PPQ <span class="font-medium">no</span> lo marca como pagado.
                    </p>
                </form>

                {{-- BÚSQUEDA AVANZADA: plegada y aparte. Acá sí tiene sentido devolver
                     varios resultados —se piden a propósito, combinando criterios—, y por
                     eso no puede compartir caja con el buscador exacto: mezclarlas fue lo
                     que hizo que teclear un número devolviera documentos ajenos. --}}
                <details class="mt-4 border-t border-gray-100 pt-3" @if ($hayAvanzados) open @endif>
                    <summary class="cursor-pointer text-sm font-medium text-gray-600 hover:text-gray-800">
                        Búsqueda avanzada
                        <span class="font-normal text-gray-400">— cuando no tenés el número a mano</span>
                    </summary>

                    <form method="GET" action="{{ route('ppq.index') }}" class="mt-3">
                        @if ($loteActivo)
                            <input type="hidden" name="lote" value="{{ $loteActivo->id }}">
                        @endif
                        <input type="hidden" name="tipo" value="{{ $tipo }}">

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            <div>
                                <label for="adv_oc" class="block text-xs font-medium text-gray-600">Orden de compra</label>
                                <input id="adv_oc" type="text" name="oc" value="{{ $filtros['oc'] ?? '' }}"
                                       class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            </div>
                            <div>
                                <label for="adv_cliente" class="block text-xs font-medium text-gray-600">Cliente</label>
                                <input id="adv_cliente" type="text" name="cliente" value="{{ $filtros['cliente'] ?? '' }}"
                                       placeholder="Razón social, nombre comercial o NIT"
                                       class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            </div>
                            <div>
                                <label for="adv_sala" class="block text-xs font-medium text-gray-600">Sala</label>
                                <input id="adv_sala" type="text" name="sala" value="{{ $filtros['sala'] ?? '' }}"
                                       class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            </div>
                            <div>
                                <label for="adv_desde" class="block text-xs font-medium text-gray-600">Desde</label>
                                <input id="adv_desde" type="date" name="fecha_desde" value="{{ $filtros['fecha_desde'] ?? '' }}"
                                       class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            </div>
                            <div>
                                <label for="adv_hasta" class="block text-xs font-medium text-gray-600">Hasta</label>
                                <input id="adv_hasta" type="date" name="fecha_hasta" value="{{ $filtros['fecha_hasta'] ?? '' }}"
                                       class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            </div>
                            <div>
                                <label for="adv_monto" class="block text-xs font-medium text-gray-600">Monto exacto</label>
                                <input id="adv_monto" type="number" step="0.01" name="monto" value="{{ $filtros['monto'] ?? '' }}"
                                       class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            </div>
                            <div>
                                <label for="adv_control" class="block text-xs font-medium text-gray-600">Número de control</label>
                                <input id="adv_control" type="text" name="numero_control" value="{{ $filtros['numero_control'] ?? '' }}"
                                       class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono">
                            </div>
                            <div class="sm:col-span-2">
                                <label for="adv_codgen" class="block text-xs font-medium text-gray-600">Código de generación</label>
                                <input id="adv_codgen" type="text" name="codigo_generacion" value="{{ $filtros['codigo_generacion'] ?? '' }}"
                                       placeholder="UUID completo" class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono">
                            </div>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <button type="submit" class="rounded-md bg-gray-700 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                                Buscar avanzado
                            </button>
                            <a href="{{ route('ppq.index', array_filter(['tipo' => $tipo, 'lote' => $loteActivo?->id])) }}"
                               class="text-xs text-gray-500 hover:underline">Limpiar filtros</a>
                            <span class="text-xs text-gray-400">Devuelve varios resultados, paginados y por fecha reciente.</span>
                        </div>
                    </form>
                </details>
            </div>

            {{--
                FUENTE DE LA BÚSQUEDA. La base local es la fuente normal; Gmail es la
                excepción, reservada a los históricos de Conta/P001 que este sistema
                nunca emitió. El aviso de Gmail desconectado solo aparece cuando de
                verdad hizo falta: si el documento se resolvió localmente, que Gmail
                esté caído no afecta nada y decirlo solo alarmaría de gratis.
            --}}
            @if ($resueltoLocalmente)
                <p class="text-xs text-green-600">● Resuelto con la base local del sistema. No hizo falta consultar Gmail.</p>
            @elseif ($gmailError)
                <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800 flex items-center justify-between">
                    <span>
                        {{ $gmailError }}
                        Los documentos emitidos por este sistema se siguen buscando normalmente; lo único que no se puede consultar
                        ahora son los <span class="font-medium">históricos de Conta (P001)</span>.
                    </span>
                    @role('administrador')
                        <a href="{{ route('ppq.gmail.conectar') }}" class="ml-3 shrink-0 rounded bg-amber-600 px-3 py-1 text-xs text-white hover:bg-amber-700">Reconectar Gmail</a>
                    @endrole
                </div>
            @elseif ($gmailConsultado)
                <p class="text-xs text-amber-600">● Sin coincidencia local: consulta excepcional a Gmail (históricos de Conta / P001).</p>
            @elseif (! $gmailDisponible && $gmailConfigurado)
                <p class="text-xs text-gray-400">Buscando en la base local del sistema. Gmail no está conectado: solo faltarían los históricos de Conta (P001).</p>
            @elseif (! $gmailDisponible)
                <p class="text-xs text-gray-400">Buscando en la base local del sistema. Gmail no está configurado: solo faltarían los históricos de Conta (P001).</p>
            @else
                <p class="text-xs text-gray-400">Buscando en la base local del sistema; Gmail queda como respaldo para los históricos de Conta (P001).</p>
            @endif

            {{-- ─────────────── RESULTADO EXACTO ───────────────
                 Un número, un documento. Si ya está en un lote se muestra SOLO él, con su
                 estado y su enlace: ofrecer alternativas ahí sería invitar a cobrar dos
                 veces el mismo papel. --}}
            @if ($buscoExacto && $exacto && ! in_array($exacto->id, $localesOcultos, true))
                @if ($loteDelExacto)
                    <div class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3" role="status">
                        <p class="text-sm font-semibold text-amber-900">
                            Ya está en PPQ — lote {{ $loteDelExacto->referencia ?? ('#'.$loteDelExacto->id) }}
                        </p>
                        <p class="mt-1 text-sm text-amber-800">
                            Este documento ya fue agregado a un lote; no se puede agregar otra vez.
                            <a href="{{ route('ppq.lotes.show', $loteDelExacto) }}" class="font-medium underline">Ver el lote</a>
                        </p>
                    </div>
                @endif

                @include('ppq.partials.fila-local', [
                    'dte' => $exacto,
                    'albaranesPorDte' => $albaranesPorDte,
                    'albaranesPorOc' => $albaranesPorOc,
                    'yaUsados' => $yaUsados,
                ])
            @endif

            {{-- CCF no encontrado: ni local ni por correo. Se dice claramente, y no se
                 rellena la pantalla con documentos que no son el que se pidió. --}}
            @if ($buscoExacto && ! $exacto && (is_null($fichasGmail) || $fichasGmail === []))
                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-8 text-center">
                    <p class="text-sm font-medium text-gray-700">{{ $esNcModo ? 'Nota de crédito' : 'CCF' }} no encontrado</p>
                    <p class="mt-1 text-xs text-gray-500">
                        No hay ningún documento con el número <span class="font-mono">{{ $filtros['q'] }}</span>
                        en el ambiente actual.
                        @if ($gmailError)
                            El correo no se pudo consultar, así que un histórico de Conta podría existir y no verse.
                        @elseif (! $gmailDisponible)
                            El correo no está disponible, así que un histórico de Conta podría existir y no verse.
                        @endif
                    </p>
                    <p class="mt-2 text-xs text-gray-400">Revisá el número, o probá la <strong>búsqueda avanzada</strong> por orden de compra, cliente o fecha.</p>
                </div>
            @endif

            {{-- Resultados desde Gmail: FALLBACK, solo si la base local no resolvió --}}
            @if (! is_null($fichasGmail))
                @forelse ($fichasGmail as $f)
                    @php
                        $ccf = $f['ccf'];
                        $alb = $f['albaran'];
                        $albMonto = $alb['monto'] ?? null;
                        $r = [
                            'origen' => 'gmail',
                            'esNc' => ($ccf['tipoDte'] ?? '') === '05',
                            // Los históricos de Conta/P001 NO pasan por el candado fiscal:
                            // los emitió otro sistema y no hay DTE local que evaluar.
                            // Aplicárselo los bloquearía a todos.
                            'motivoNoElegible' => null,
                            // Un control P001 es del sistema viejo (ContaPortable): ese es el
                            // caso legítimo del correo. Cualquier otro llegando por Gmail es
                            // una consulta excepcional, y conviene que se vea como tal.
                            'fuente' => str_contains((string) ($ccf['numeroControl'] ?? ''), 'M001P001-')
                                ? 'Histórico Conta'
                                : 'Consulta excepcional a Gmail',
                            'albaranFuente' => match ($f['albaran_fuente'] ?? null) {
                                \App\Services\Ppq\PpqGmailService::ALBARAN_LOCAL => 'Albarán sincronizado',
                                \App\Services\Ppq\PpqGmailService::ALBARAN_GMAIL => 'Consulta excepcional a Gmail',
                                default => null,
                            },
                            'tipoDte' => $ccf['tipoDte'] ?? null,
                            'numeroControl' => $ccf['numeroControl'] ?? null,
                            'codigoGeneracion' => $ccf['codigoGeneracion'] ?? null,
                            'sello' => $ccf['sello'] ?? null,
                            'fecha' => $ccf['fecha'] ?? null,
                            'monto' => $ccf['monto'] ?? null,
                            'ordenCompra' => $ccf['ordenCompra'] ?? null,
                            'sala' => $ccf['sala'] ?? \App\Support\OrdenCompra::salaDesde($ccf['ordenCompra'] ?? null),
                            'salaNombre' => $ccf['salaNombre'] ?? null, // nombre comercial vía el DTE local

                            'albaranNumero' => \App\Support\Albaran::numeroLimpio($alb['numero_albaran'] ?? null),
                            'albaranFecha' => $alb['fecha'] ?? null,
                            'albaranMonto' => $albMonto,
                            'salaAlbaran' => \App\Support\Albaran::salaDesdeNumero($alb['numero_albaran'] ?? null),
                            'diferencia' => $f['diferencia'] ?? null,
                            'estado' => \App\Support\PpqConciliacion::estado($ccf['monto'] ?? null, $albMonto, filled($alb['numero_albaran'] ?? null)),
                            'gmailMessageId' => $f['gmail_message_id'] ?? null,
                            'ccfRelacionado' => $f['ccfRelacionado'] ?? null,
                            'yaEn' => null,
                        ];
                    @endphp
                    @include('ppq.partials.resultado', ['r' => $r])
                @empty
                    @if (($gmailDebug['correos'] ?? 0) > 0)
                        <div class="bg-white shadow-sm ring-1 ring-amber-200 sm:rounded-xl p-6 border-l-4 border-amber-400">
                            <p class="text-sm font-semibold text-amber-800">Correo encontrado, pero no se pudo leer el documento.</p>
                            <p class="text-xs text-gray-500 mt-1">Se encontraron {{ $gmailDebug['correos'] }} correo(s) pero no se pudo extraer el CCF/NC.@role('administrador') Revisá el <a href="{{ route('ppq.gmail.debug') }}" class="text-indigo-600 hover:underline">Diagnóstico Gmail</a>.@endrole</p>
                        </div>
                    @else
                        <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-8 text-center text-gray-400">No se encontró ningún {{ $esNcModo ? 'Nota de crédito' : 'CCF' }} para esa búsqueda.</div>
                    @endif
                @endforelse
            @endif

            {{-- Resultados locales: la fuente NORMAL, se consulta siempre y va primero --}}
            @php
                // Documentos locales que ya representó una ficha de Gmail: no se repiten.
                // Solo pueden ser locales NO elegibles —los elegibles descartan la copia
                // de Gmail, no al revés—. Ver PpqBusquedaController::completarFichasGmail().
                $totalLocal = is_null($resultados) ? null : max(0, $resultados->total() - count($localesOcultos));
                // El bloque de abajo es el de la BÚSQUEDA AVANZADA. El resultado exacto ya
                // se dibujó arriba y no se repite acá.
                // El bloque local se dibuja si tiene algo que mostrar, o si fue la ÚNICA
                // fuente consultada: ahí hace falta para poder decir «sin resultados».
                $mostrarLocales = ! is_null($resultados) && ($totalLocal > 0 || is_null($fichasGmail));
            @endphp
            @if ($mostrarLocales)
                <p class="text-sm text-gray-500">
                    Búsqueda avanzada: {{ $totalLocal }} documento(s) encontrado(s) en el sistema.
                </p>
                @forelse ($resultados as $dte)
                    @continue (in_array($dte->id, $localesOcultos, true))
                    @include('ppq.partials.fila-local', [
                        'dte' => $dte,
                        'albaranesPorDte' => $albaranesPorDte,
                        'albaranesPorOc' => $albaranesPorOc,
                        'yaUsados' => $yaUsados,
                    ])
                @empty
                    <div class="bg-white shadow sm:rounded-lg p-8 text-center text-gray-400">Sin resultados para esos filtros.</div>
                @endforelse
                <div>{{ $resultados->links() }}</div>
            @elseif (is_null($fichasGmail) && is_null($resultados) && ! $buscoExacto)
                <div class="bg-white shadow sm:rounded-lg p-8 text-center text-sm text-gray-500">
                    Escribí el número del CCF/NC (ej. <span class="font-mono">0986</span>) y presioná <strong>Buscar</strong>.
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
