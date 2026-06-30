<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">PPQ — Diagnóstico de búsqueda en Gmail</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white shadow sm:rounded-lg p-6 text-sm">
                <p>Estado: Gmail
                    @if ($disponible) <span class="text-green-600 font-medium">conectado</span>
                    @elseif ($configurado) <span class="text-amber-600 font-medium">configurado, no conectado</span>
                    @else <span class="text-red-600 font-medium">no configurado</span> @endif
                </p>
                <p class="text-xs text-gray-500 mt-1">Query base de enviados: <span class="font-mono">{{ $enviadosQuery }}</span> · Label albaranes: <span class="font-mono">{{ $labelAlbaranes }}</span></p>

                <form method="GET" action="{{ route('ppq.gmail.debug') }}" class="mt-4 flex gap-2">
                    <input type="text" name="numero" value="{{ $numero }}" placeholder="Ej. 0999"
                           class="flex-1 rounded-md border-gray-300 text-sm">
                    <button class="rounded-md bg-indigo-600 px-5 text-sm font-medium text-white hover:bg-indigo-700">Diagnosticar</button>
                </form>
            </div>

            @if ($error)
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ $error }}</div>
            @endif

            @if ($diag)
                <div class="bg-white shadow sm:rounded-lg p-4 text-sm">
                    <p>Buscando: <span class="font-mono font-semibold">{{ $diag['numero'] }}</span></p>
                    <p class="text-xs text-gray-500 mt-1">Variantes probadas: @foreach ($diag['variantes'] as $v)<span class="font-mono bg-gray-100 rounded px-1.5 py-0.5 mr-1">{{ $v }}</span>@endforeach</p>
                </div>

                @foreach ($diag['consultas'] as $c)
                    <div class="bg-white shadow sm:rounded-lg p-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-semibold text-gray-800">{{ $c['etiqueta'] }}</p>
                                <p class="text-xs text-gray-500 font-mono mt-0.5">q = {{ $c['query'] }}</p>
                            </div>
                            <div class="text-right">
                                @isset($c['error'])
                                    <span class="text-xs text-red-600">error: {{ $c['error'] }}</span>
                                @else
                                    <span class="text-lg font-semibold {{ ($c['estimado'] ?? 0) > 0 ? 'text-green-600' : 'text-gray-400' }}">~{{ $c['estimado'] ?? 0 }}</span>
                                    <span class="text-xs text-gray-400 block">resultados (estimado)</span>
                                @endisset
                            </div>
                        </div>

                        @if (! empty($c['resultados']))
                            <div class="mt-3 overflow-x-auto">
                                <table class="min-w-full text-xs">
                                    <thead>
                                        <tr class="text-left text-gray-500 border-b">
                                            <th class="py-1 pr-3">Fecha</th>
                                            <th class="py-1 pr-3">Asunto</th>
                                            <th class="py-1 pr-3">Adjuntos</th>
                                            <th class="py-1 pr-3">Snippet</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($c['resultados'] as $r)
                                            <tr>
                                                <td class="py-1 pr-3 whitespace-nowrap">{{ $r['fecha'] }}</td>
                                                <td class="py-1 pr-3">{{ $r['asunto'] ?: '(sin asunto)' }}</td>
                                                <td class="py-1 pr-3">
                                                    @forelse ($r['adjuntos'] as $a)
                                                        <span class="inline-block rounded bg-gray-100 px-1.5 py-0.5 mr-1 mb-0.5">{{ $a['filename'] }} <span class="text-gray-400">({{ $a['mime'] }})</span></span>
                                                    @empty
                                                        <span class="text-gray-400">—</span>
                                                    @endforelse
                                                </td>
                                                <td class="py-1 pr-3 text-gray-500">{{ $r['snippet'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @elseif (! isset($c['error']))
                            <p class="mt-3 text-xs text-gray-400">Sin resultados para este query.</p>
                        @endif
                    </div>
                @endforeach
            @endif

            <p class="text-xs text-gray-400">
                Nota: Gmail tokeniza por palabra completa. Si el control es <span class="font-mono">…0000000000000999</span>,
                buscar <span class="font-mono">0999</span> suelto puede NO matchear; mirá qué variante (o el control completo)
                sí devuelve el correo, y con qué adjunto (JSON/PDF) viene el DTE.
            </p>

        </div>
    </div>
</x-app-layout>
