<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Buscar albarán por fecha</h2>
            <a href="{{ route('ppq.index', ['q' => $doc['q'] ?? null]) }}" class="text-sm text-indigo-600 hover:text-indigo-800">← Volver a la búsqueda</a>
        </div>
    </x-slot>

    @php
        $esNc = ($doc['tipo_dte'] ?? null) === '05';
        $montoNum = isset($doc['monto_dte']) && $doc['monto_dte'] !== '' ? (float) $doc['monto_dte'] : null;
        $montoDoc = $montoNum === null ? '—' : ($esNc ? '−$'.number_format(abs($montoNum), 2) : '$'.number_format($montoNum, 2));
        $sala = \App\Support\OrdenCompra::salaDesde($doc['numero_orden_compra'] ?? null);
        $salaEtq = \App\Support\Sala::etiqueta($sala);
        // Campos del documento a reenviar al agregar (sin 'q').
        $docPost = collect($doc)->except('q')->filter(fn ($v) => $v !== null && $v !== '')->all();
    @endphp

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ session('error') }}</div>
            @endif

            {{-- Documento que se está conciliando --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-block rounded-md {{ $esNc ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200' : 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200' }} px-2.5 py-0.5 text-xs font-semibold">{{ $esNc ? 'Nota de crédito' : 'CCF' }}</span>
                        <span class="font-mono text-xs text-gray-600">{{ $doc['numero_control'] ?? '—' }}</span>
                    </div>
                    <div class="text-right">
                        <div class="text-xl font-bold {{ $esNc ? 'text-rose-700' : 'text-gray-900' }}">{{ $montoDoc }}</div>
                        <div class="text-xs text-gray-400">{{ $esNc ? 'monto a restar' : 'monto del documento' }}</div>
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-400">Orden de compra</span>
                    <span class="inline-flex items-center rounded-md bg-gray-50 ring-1 ring-gray-200 px-2.5 py-1 font-mono text-xs text-gray-700">{{ $doc['numero_orden_compra'] ?? '—' }}</span>
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-400 sm:ml-3">Sala / CD</span>
                    <span class="inline-flex items-center rounded-md bg-indigo-50 ring-1 ring-indigo-200 px-3 py-1 font-mono text-sm font-bold text-indigo-700">{{ $salaEtq ?? '—' }}</span>
                </div>
            </div>

            {{-- Selección de fecha --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6">
                <form method="GET" action="{{ route('ppq.albaranes_por_fecha') }}" class="flex flex-wrap items-end gap-3">
                    @foreach ($doc as $name => $value)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    <div>
                        <label for="fecha" class="block text-sm font-medium text-gray-700 mb-1">Fecha de entrega / llegada del albarán</label>
                        <input id="fecha" type="date" name="fecha" value="{{ $fecha }}" class="rounded-md border-gray-300 text-sm">
                    </div>
                    <button type="submit" class="rounded-md bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700">Buscar albaranes</button>
                </form>
                <p class="mt-2 text-xs text-gray-400">Se buscan en Gmail (label Calleja_Albaranes) los albaranes recibidos ese día.</p>
            </div>

            {{-- Resultados --}}
            @if (! $gmailDisponible)
                <div class="bg-white shadow-sm ring-1 ring-amber-200 sm:rounded-xl p-6 text-sm text-amber-800">Gmail no está conectado; no se puede buscar el albarán por fecha. Podés agregar el documento sin albarán abajo.</div>
            @elseif (is_null($candidatos))
                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-8 text-center text-gray-400">Elegí una fecha y presioná <strong>Buscar albaranes</strong>.</div>
            @else
                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-700">Albaranes del {{ \Illuminate\Support\Carbon::parse($fecha)->format('d/m/Y') }}</h3>
                        <span class="text-xs text-gray-400">{{ count($candidatos) }} encontrado(s)</span>
                    </div>

                    @forelse ($candidatos as $c)
                        @php
                            $cSala = $c['sala'] ?? \App\Support\OrdenCompra::salaDesde($c['orden_compra'] ?? null);
                            $cSalaEtq = \App\Support\Sala::etiqueta($cSala);
                            $cMonto = $c['monto'] !== null ? '$'.number_format((float) $c['monto'], 2) : '—';
                            $cFecha = $c['fecha'] ? \App\Support\Albaran::fecha($c['fecha']) : null;
                            $mismaSala = $cSala && $sala && $cSala === $sala;
                        @endphp
                        <div class="px-5 py-4 border-b border-gray-100 {{ $mismaSala ? 'bg-indigo-50/40' : '' }}">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-sm font-semibold text-gray-800">{{ $c['numero_albaran'] ?: '(sin número)' }}</span>
                                        @if ($mismaSala)
                                            <span class="rounded bg-indigo-100 px-2 py-0.5 text-[11px] font-medium text-indigo-700">misma sala</span>
                                        @endif
                                    </div>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
                                        <span>Fecha: <span class="text-gray-700">{{ $cFecha ? \Illuminate\Support\Carbon::parse($cFecha)->format('d/m/Y') : '—' }}</span></span>
                                        <span>OC: <span class="font-mono text-gray-700">{{ $c['orden_compra'] ?: '—' }}</span></span>
                                        <span>Sala / CD: <span class="font-mono text-gray-700">{{ $cSalaEtq ?? '—' }}</span></span>
                                        <span>Monto: <span class="font-semibold text-gray-800">{{ $cMonto }}</span></span>
                                    </div>
                                    <p class="mt-1 text-xs text-gray-400 truncate" title="{{ $c['asunto'] }}">{{ $c['asunto'] ?: '(sin asunto)' }}</p>
                                </div>
                                <div class="shrink-0">
                                    @if ($lotesAbiertos->isEmpty())
                                        <a href="{{ route('ppq.lotes.create') }}" class="text-sm text-indigo-600 hover:underline">Crear PPQ →</a>
                                    @else
                                        <form method="POST" action="" class="flex flex-wrap items-center justify-end gap-2"
                                              onsubmit="this.action='{{ url('ppq/lotes') }}/'+this.lote.value+'/items'">
                                            @csrf
                                            @foreach ($docPost as $name => $value)
                                                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                                            @endforeach
                                            <input type="hidden" name="sin_albaran" value="0">
                                            <input type="hidden" name="numero_albaran" value="{{ $c['numero_albaran'] }}">
                                            <input type="hidden" name="fecha_albaran" value="{{ $cFecha }}">
                                            <input type="hidden" name="monto_albaran" value="{{ $c['monto'] }}">
                                            <select name="lote" class="rounded-md border-gray-300 text-sm py-1">
                                                @foreach ($lotesAbiertos as $l)
                                                    <option value="{{ $l->id }}">#{{ $l->id }} · {{ Str::limit($l->referencia, 20) }}</option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">Vincular y agregar</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="px-5 py-10 text-center text-gray-400">No se encontraron albaranes para esa fecha. Probá otra fecha o agregá el documento sin albarán.</div>
                    @endforelse
                </div>
            @endif

            {{-- Agregar sin albarán (siempre disponible) --}}
            @if (! $lotesAbiertos->isEmpty())
                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-5 flex flex-wrap items-center justify-between gap-3">
                    <div class="text-sm text-gray-600">¿No encontrás el albarán o todavía no lo tenés? Podés continuar sin él.</div>
                    <form method="POST" action="" class="flex flex-wrap items-center gap-2"
                          onsubmit="this.action='{{ url('ppq/lotes') }}/'+this.lote.value+'/items'">
                        @csrf
                        @foreach ($docPost as $name => $value)
                            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                        @endforeach
                        <input type="hidden" name="sin_albaran" value="1">
                        <label class="text-xs text-gray-500">Agregar a:</label>
                        <select name="lote" class="rounded-md border-gray-300 text-sm py-1">
                            @foreach ($lotesAbiertos as $l)
                                <option value="{{ $l->id }}">#{{ $l->id }} · {{ Str::limit($l->referencia, 20) }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="rounded-md bg-white ring-1 ring-gray-300 px-4 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100">Agregar sin albarán</button>
                    </form>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
