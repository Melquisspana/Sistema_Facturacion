<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-paper-100 leading-tight">
                Auditoría de vinculación de albaranes
            </h2>
            <a href="{{ route('cobros.index', ['cliente_id' => $cliente->id]) }}"
               class="text-sm font-medium text-indigo-600 hover:text-indigo-800">← Cobros Calleja</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-full xl:max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Qué es esto y por qué va antes de automatizar nada. --}}
            <div class="rounded-md bg-blue-50 border border-blue-200 p-4 text-sm text-blue-700">
                <p>
                    Esto es un <strong>ensayo en seco</strong>: no se ha escrito nada. Muestra, sobre los datos reales,
                    qué vínculos saldrían solos y cuáles no, <em>antes</em> de dejar que el sistema los aplique.
                </p>
                <p class="mt-2">
                    Un vínculo solo se hace cuando hay <strong>una única coincidencia por orden de compra o por vínculo
                    explícito</strong> y nada la contradice. El importe, la fecha y el correlativo
                    <strong>nunca crean un vínculo</strong>: solo pueden impedirlo.
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-paper-300">Se vincularían solos</div>
                    <div class="mt-1 text-2xl font-bold text-green-700">{{ $auditoria['resumen']['vinculado'] }}</div>
                </div>
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-paper-300">Quedarían para revisar</div>
                    <div class="mt-1 text-2xl font-bold text-amber-700">{{ $auditoria['resumen']['revisar'] }}</div>
                </div>
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-paper-300">Sin albarán</div>
                    <div class="mt-1 text-2xl font-bold text-gray-700">{{ $auditoria['resumen']['sin_albaran'] }}</div>
                </div>
            </div>

            @can('ppq.gestionar')
                <form method="POST" action="{{ route('cobros.vinculacion.aplicar', $cliente) }}">
                    @csrf
                    <input type="hidden" name="aplicar" value="1">
                    <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        Aplicar esta vinculación
                    </button>
                    <span class="ml-2 text-xs text-gray-500 dark:text-paper-300">
                        Se escribirán solo los vínculos únicos. Los ambiguos quedan marcados «revisar» con su motivo:
                        ninguno se vincula por parecido.
                    </span>
                </form>
            @endcan

            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-4">Detalle</h3>

                @if ($auditoria['detalle'] === [])
                    <p class="text-sm text-gray-500 dark:text-paper-300">No hay documentos sin albarán para auditar.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Auditoría de vinculación</caption>
                            <thead class="bg-gray-50 text-gray-600 dark:text-paper-300">
                                <tr>
                                    <th scope="col" class="p-2 text-left font-medium">Documento</th>
                                    <th scope="col" class="p-2 text-left font-medium">Emitida</th>
                                    <th scope="col" class="p-2 text-left font-medium">Veredicto</th>
                                    <th scope="col" class="p-2 text-left font-medium">Motivo</th>
                                    <th scope="col" class="p-2 text-left font-medium">Candidatos</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                                @foreach ($auditoria['detalle'] as $fila)
                                    <tr>
                                        <td class="p-2 font-mono whitespace-nowrap">
                                            <a href="{{ route('cobros.documentos.show', $fila['documento']) }}"
                                               class="text-indigo-600 hover:underline">{{ $fila['documento']->numero_control }}</a>
                                        </td>
                                        <td class="p-2 whitespace-nowrap">{{ $fila['documento']->fecha_emision?->format('d/m/Y') ?? '—' }}</td>
                                        <td class="p-2">
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] {{ $fila['estado']->clase() }}">{{ $fila['estado']->label() }}</span>
                                        </td>
                                        <td class="p-2 text-xs text-gray-600 dark:text-paper-300">{{ $fila['motivo'] }}</td>
                                        <td class="p-2 text-xs font-mono">
                                            @forelse ($fila['candidatos'] as $candidato)
                                                <span class="block">
                                                    {{ $candidato['numero'] }}
                                                    @if (! empty($candidato['tomado_por']))
                                                        <span class="font-sans text-rose-700">(ya en {{ $candidato['tomado_por'] }})</span>
                                                    @endif
                                                </span>
                                            @empty
                                                <span class="text-gray-400">—</span>
                                            @endforelse
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
