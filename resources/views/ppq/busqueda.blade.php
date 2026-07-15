<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Prontos Pagos — Buscar CCF / NC</h2>
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
                    <a href="{{ route('ppq.lotes.create') }}" class="ml-3 shrink-0 rounded bg-amber-600 px-3 py-1 text-xs text-white hover:bg-amber-700">Crear PPQ →</a>
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
                    <p class="mt-1 text-xs text-gray-400">Escribí solo los últimos 4 dígitos del {{ $esNcModo ? 'NC' : 'CCF' }} (ej. 0986). El sistema lo busca y agrega los documentos al PPQ; <span class="font-medium">no</span> los marca como pagados.</p>
                </form>
            </div>

            {{-- Estado de la conexión Gmail --}}
            @if ($gmailDisponible)
                <p class="text-xs text-green-600">● Buscando en Gmail (correos enviados + Calleja_Albaranes).</p>
            @elseif ($gmailConfigurado)
                <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800 flex items-center justify-between">
                    <span>{{ $gmailError ?? 'Gmail está configurado pero no conectado.' }} Mostrando datos locales (respaldo).</span>
                    @role('administrador')
                        <a href="{{ route('ppq.gmail.conectar') }}" class="rounded bg-amber-600 px-3 py-1 text-xs text-white hover:bg-amber-700">Reconectar Gmail</a>
                    @endrole
                </div>
            @else
                <p class="text-xs text-gray-400">Gmail no configurado — mostrando datos locales (respaldo). Configurá las credenciales en .env para usar Gmail como fuente principal.</p>
            @endif

            {{-- Resultados desde Gmail (fuente principal) --}}
            @if (! is_null($fichasGmail))
                @forelse ($fichasGmail as $f)
                    @php
                        $ccf = $f['ccf'];
                        $alb = $f['albaran'];
                        $albMonto = $alb['monto'] ?? null;
                        $r = [
                            'origen' => 'gmail',
                            'esNc' => ($ccf['tipoDte'] ?? '') === '05',
                            'fuente' => 'Gmail',
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

            {{-- Resultados locales (respaldo cuando Gmail no está conectado) --}}
            @if (! is_null($resultados))
                <p class="text-sm text-gray-500">{{ $resultados->total() }} documento(s) encontrado(s) localmente.</p>
                @forelse ($resultados as $dte)
                    @php
                        $esNcLocal = $dte->tipo_dte->value === '05';
                        // En NC no se auto-vincula albarán (comparte OC con el CCF; es manual).
                        $alb = $esNcLocal ? null : ($albaranesPorDte[$dte->id] ?? ($albaranesPorOc[$dte->numero_orden_compra] ?? null));
                        $albMonto = $alb?->monto_albaran;
                        $r = [
                            'origen' => 'local',
                            'esNc' => $esNcLocal,
                            'fuente' => null,
                            'tipoDte' => $dte->tipo_dte->value,
                            'numeroControl' => $dte->numero_control,
                            'codigoGeneracion' => $dte->codigo_generacion,
                            'sello' => $dte->sello_recepcion,
                            'fecha' => optional($dte->fecha_emision)->format('Y-m-d'),
                            'monto' => $dte->total_pagar,
                            'ordenCompra' => $dte->numero_orden_compra,
                            'sala' => \App\Support\OrdenCompra::salaDesde($dte->numero_orden_compra),
                            'salaNombre' => $dte->clienteSucursal?->nombre, // nombre comercial vía la relación del CCF

                            'albaranNumero' => \App\Support\Albaran::numeroLimpio($alb?->numero_albaran),
                            'albaranFecha' => optional($alb?->fecha_albaran)->format('Y-m-d'),
                            'albaranMonto' => $albMonto,
                            'salaAlbaran' => \App\Support\Albaran::salaDesdeNumero($alb?->numero_albaran),
                            'diferencia' => $albMonto !== null ? round((float) $dte->total_pagar - (float) $albMonto, 2) : null,
                            'estado' => \App\Support\PpqConciliacion::estado($dte->total_pagar, $albMonto, $alb !== null),
                            'dteId' => $dte->id,
                            'albaranId' => $alb?->id,
                            'ccfRelacionado' => null,
                            'yaEn' => $yaUsados[$dte->id] ?? null,
                        ];
                    @endphp
                    @include('ppq.partials.resultado', ['r' => $r])
                @empty
                    <div class="bg-white shadow sm:rounded-lg p-8 text-center text-gray-400">Sin resultados para esa búsqueda.</div>
                @endforelse
                <div>{{ $resultados->links() }}</div>
            @elseif (is_null($fichasGmail))
                <div class="bg-white shadow sm:rounded-lg p-8 text-center text-sm text-gray-500">
                    Escribí el número del CCF/NC (ej. <span class="font-mono">0986</span>) y presioná <strong>Buscar</strong>.
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
