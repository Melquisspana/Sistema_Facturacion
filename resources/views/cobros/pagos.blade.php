<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-paper-100 leading-tight">
                Archivo de pagos aplicado
            </h2>
            <a href="{{ route('cobros.index', ['cliente_id' => $cliente->id]) }}"
               class="text-sm font-medium text-indigo-600 hover:text-indigo-800">← Cobros Calleja</a>
        </div>
    </x-slot>

    @php
        $money = fn ($v) => ((float) $v < 0 ? '−$' : '$').number_format(abs((float) $v), 2);
        $t = $informe['totales'];
    @endphp

    <div class="py-8">
        <div class="max-w-full xl:max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Qué dice este archivo, y qué NO dice. --}}
            <div class="rounded-md bg-blue-50 border border-blue-200 p-4 text-sm text-blue-700">
                <p>
                    Archivo <span class="font-mono">{{ $informe['archivo'] }}</span>, cargado el
                    {{ $informe['fecha_carga']->format('d/m/Y H:i') }}.
                    Huella <span class="font-mono text-xs">{{ substr($informe['hash'], 0, 16) }}…</span>
                </p>
                <p class="mt-2">
                    Un archivo <strong>solo habla de lo que trae dentro</strong>: los documentos que no menciona
                    conservan lo que ya tenían. Y la fecha de la columna del archivo es la
                    <strong>del documento, no la del pago</strong>:
                    @if ($informe['fecha_pago'])
                        la fecha de pago aplicada es la que se capturó, {{ $informe['fecha_pago']->format('d/m/Y') }}.
                    @else
                        como no se capturó ninguna fecha de pago, los documentos quedan cobrados <strong>sin fecha de
                        pago</strong> hasta que exista evidencia de cuándo se pagó.
                    @endif
                </p>
            </div>

            @if (($lotesPpq ?? []) !== [])
                <div class="rounded-md bg-green-50 border border-green-200 p-4 text-sm text-green-800 dark:bg-green-900/30 dark:border-green-800 dark:text-green-200">
                    También se actualizaron los PPQ:
                    @foreach ($lotesPpq as $l)
                        <a href="{{ route('ppq.lotes.show', $l['lote']) }}" class="font-medium underline">{{ $l['lote']->referencia }}</a>{{ $l['pagado'] ? ' (pagado)' : '' }}{{ $loop->last ? '.' : ',' }}
                    @endforeach
                </div>
            @endif

            @if ($informe['proveedor']['ajenos'] !== [])
                <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700" role="alert">
                    <strong>El archivo trae códigos de proveedor ajenos:</strong>
                    {{ implode(', ', $informe['proveedor']['ajenos']) }} (se esperaba {{ $informe['proveedor']['esperado'] }}).
                    Revisá que sea el archivo de este proveedor antes de dar por buenos los totales.
                </div>
            @endif

            {{-- El MISMO archivo (misma huella) ya se concilió en PPQ. Solo se avisa: lo de acá
                 no cambió por eso, ni se copió nada de allá. --}}
            @if (! empty($informe['en_ppq']))
                <div class="rounded-md bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800" role="status">
                    <p><strong>Este mismo archivo ya se concilió en PPQ.</strong></p>
                    <ul class="mt-1 list-disc list-inside">
                        @foreach ($informe['en_ppq'] as $corrida)
                            <li>
                                Lote
                                @if ($corrida['lote_disponible'])
                                    <a href="{{ route('ppq.lotes.show', $corrida['lote_id']) }}" class="underline">{{ $corrida['referencia'] ?? '#'.$corrida['lote_id'] }}</a>,
                                @else
                                    #{{ $corrida['lote_id'] }} (ya no disponible),
                                @endif
                                el {{ $corrida['fecha']?->format('d/m/Y H:i') ?? 'fecha no registrada' }}.
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-1">Los pagos de este seguimiento y los del lote son registros separados: revisá que no se estén reclamando o dando por cobrados dos veces.</p>
                </div>
            @endif

            {{-- ---------- Totales del archivo ---------- --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-paper-300">Facturas (CF) · {{ $t['cantidad_cf'] }}</div>
                    <div class="mt-1 text-2xl font-bold text-green-700">{{ $money($t['total_cf']) }}</div>
                </div>
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-paper-300">Notas de crédito (NC) · {{ $t['cantidad_nc'] }}</div>
                    <div class="mt-1 text-2xl font-bold text-rose-700">{{ $money($t['total_nc']) }}</div>
                </div>
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-paper-300">Ajustes (QD) · {{ $t['cantidad_qd'] }}</div>
                    <div class="mt-1 text-2xl font-bold text-amber-600">{{ $money($t['total_qd']) }}</div>
                </div>
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-paper-300">Neto del archivo</div>
                    <div class="mt-1 text-2xl font-bold text-gray-800 dark:text-paper-100">{{ $money($t['neto_archivo']) }}</div>
                </div>
            </div>

            {{-- ---------- Aplicados ---------- --}}
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-1">
                    Documentos actualizados ({{ $t['cantidad_aplicados'] }})
                </h3>
                <p class="text-sm text-gray-500 dark:text-paper-300 mb-4">
                    @if ($t['cantidad_sin_cambio'] > 0)
                        Otros {{ $t['cantidad_sin_cambio'] }} ya estaban exactamente así: este archivo no los cambió.
                        Recargar el mismo archivo no vuelve a cobrar nada.
                    @else
                        Cada uno con el importe que informó el archivo.
                    @endif
                </p>

                @if ($informe['aplicados'] === [])
                    <p class="text-sm text-gray-500 dark:text-paper-300">Ningún documento cambió con este archivo.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Documentos actualizados</caption>
                            <thead class="bg-gray-50 text-gray-600 dark:text-paper-300">
                                <tr>
                                    <th scope="col" class="p-2 text-left font-medium">Documento</th>
                                    <th scope="col" class="p-2 text-left font-medium">Línea</th>
                                    <th scope="col" class="p-2 text-right font-medium">Monto del documento</th>
                                    <th scope="col" class="p-2 text-right font-medium">Informado</th>
                                    <th scope="col" class="p-2 text-right font-medium">Saldo</th>
                                    <th scope="col" class="p-2 text-left font-medium">Estado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                                @foreach ($informe['aplicados'] as $fila)
                                    <tr>
                                        <td class="p-2 font-mono whitespace-nowrap">
                                            <a href="{{ route('cobros.documentos.show', $fila['documento']) }}"
                                               class="text-indigo-600 hover:underline">{{ $fila['documento']->numero_control }}</a>
                                        </td>
                                        <td class="p-2 font-mono text-xs">{{ $fila['fila']['linea'] }} · {{ $fila['fila']['tipo'] }}</td>
                                        <td class="p-2 text-right font-mono">{{ $money($fila['documento']->monto) }}</td>
                                        <td class="p-2 text-right font-mono">{{ $money($fila['monto_archivo']) }}</td>
                                        <td class="p-2 text-right font-mono {{ (float) $fila['diferencia'] != 0.0 ? 'text-rose-700 font-semibold' : 'text-gray-500' }}">
                                            {{ $money($fila['diferencia']) }}
                                        </td>
                                        <td class="p-2">
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] {{ $fila['documento']->pago_estado->clase() }}">
                                                {{ $fila['documento']->pago_estado->label() }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ---------- Pagos que no se aplicaron solos ---------- --}}
            @if ($informe['en_revision'] !== [])
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6 border-l-4 border-amber-400">
                    <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-1">
                        Pagos que quedaron EN REVISIÓN ({{ count($informe['en_revision']) }})
                    </h3>
                    <p class="text-sm text-gray-500 dark:text-paper-300 mb-4">
                        Estos documentos <strong>ya tenían un pago informado por otro archivo</strong>. Desde el archivo
                        no se distingue si el cliente lo repitió o si pagó en dos abonos, así que
                        <strong>este importe se registró pero no se sumó</strong>. Se resuelve en la ficha de cada
                        documento, con motivo.
                    </p>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Pagos en revisión</caption>
                            <thead class="bg-gray-50 text-gray-600 dark:text-paper-300">
                                <tr>
                                    <th scope="col" class="p-2 text-left font-medium">Documento</th>
                                    <th scope="col" class="p-2 text-left font-medium">Línea</th>
                                    <th scope="col" class="p-2 text-right font-medium">Informado ahora</th>
                                    <th scope="col" class="p-2 text-right font-medium">Cobrado que sí cuenta</th>
                                    <th scope="col" class="p-2 text-left font-medium">Por qué no se aplicó</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                                @foreach ($informe['en_revision'] as $fila)
                                    <tr>
                                        <td class="p-2 font-mono whitespace-nowrap">
                                            <a href="{{ route('cobros.documentos.show', $fila['documento']) }}"
                                               class="text-indigo-600 hover:underline">{{ $fila['documento']->numero_control }}</a>
                                        </td>
                                        <td class="p-2 font-mono text-xs">{{ $fila['fila']['linea'] }} · {{ $fila['fila']['tipo'] }}</td>
                                        <td class="p-2 text-right font-mono text-amber-700">{{ $money($fila['monto_archivo']) }}</td>
                                        <td class="p-2 text-right font-mono">{{ $money($fila['documento']->monto_pagado) }}</td>
                                        <td class="p-2 text-xs text-gray-600 dark:text-paper-300">{{ $fila['evento']->estado_motivo }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- ---------- Lo que el archivo no resolvió ---------- --}}
            @if ($informe['no_identificados'] !== [] || $informe['invalidas'] !== [] || $informe['otros_tipos'] !== [] || $informe['repetidas'] !== [])
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                    <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-1">Filas que hay que mirar</h3>
                    <p class="text-sm text-gray-500 dark:text-paper-300 mb-4">
                        No se descartan en silencio: un número que no reconocemos puede ser de otro proveedor, un error
                        de tecleo del cliente o un documento que falta dar de alta.
                    </p>

                    <div class="space-y-4 text-sm">
                        @if ($informe['no_identificados'] !== [])
                            <div>
                                <h4 class="font-medium text-amber-800">
                                    Documentos que no están en el seguimiento ({{ count($informe['no_identificados']) }})
                                </h4>
                                <ul class="mt-1 font-mono text-xs space-y-0.5">
                                    @foreach ($informe['no_identificados'] as $fila)
                                        <li>línea {{ $fila['linea'] }} · {{ $fila['tipo'] }} · {{ $fila['numero'] }} · {{ $money($fila['valor']) }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if ($informe['invalidas'] !== [])
                            <div>
                                <h4 class="font-medium text-rose-800">Filas incompletas ({{ count($informe['invalidas']) }})</h4>
                                <p class="text-xs text-gray-500 dark:text-paper-300">Sin número o sin importe: no identifican ni informan nada.</p>
                                <ul class="mt-1 font-mono text-xs space-y-0.5">
                                    @foreach ($informe['invalidas'] as $fila)
                                        <li>línea {{ $fila['linea'] }} · {{ \Illuminate\Support\Str::limit($fila['raw'], 90) }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if ($informe['otros_tipos'] !== [])
                            <div>
                                <h4 class="font-medium text-gray-700 dark:text-paper-100">Tipos no reconocidos ({{ count($informe['otros_tipos']) }})</h4>
                                <ul class="mt-1 font-mono text-xs space-y-0.5">
                                    @foreach ($informe['otros_tipos'] as $fila)
                                        <li>línea {{ $fila['linea'] }} · «{{ $fila['tipo'] }}» · {{ $fila['numero'] }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if ($informe['repetidas'] !== [])
                            <div>
                                <h4 class="font-medium text-gray-700 dark:text-paper-100">Filas repetidas ({{ count($informe['repetidas']) }})</h4>
                                <p class="text-xs text-gray-500 dark:text-paper-300">
                                    Venían más de una vez con datos idénticos: se aplicó una sola. Un archivo que repite
                                    filas suele venir mal armado.
                                </p>
                                <ul class="mt-1 font-mono text-xs space-y-0.5">
                                    @foreach ($informe['repetidas'] as $rep)
                                        <li>{{ $rep['fila']['numero'] }} · {{ $rep['veces'] }} veces</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- ---------- Ajustes ---------- --}}
            @if ($informe['ajustes'] !== [])
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                    <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-1">Ajustes informados (QD)</h3>
                    <p class="text-sm text-gray-500 dark:text-paper-300 mb-4">
                        <strong>No se reparten entre las facturas.</strong> Podés crear o vincular una nota de crédito
                        por el importe neto del TXT. El ajuste se resuelve cuando Hacienda acepta la nota.
                    </p>
                    <ul class="text-sm space-y-2">
                        @foreach ($informe['ajustes'] as $entrada)
                            <li><x-cobros.ajuste :ajuste="$entrada['ajuste']" /></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- ---------- Conservados ---------- --}}
            @if ($informe['conservados']->isNotEmpty())
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                    <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-1">
                        Cobros anteriores conservados ({{ $informe['conservados']->count() }})
                    </h3>
                    <p class="text-sm text-gray-500 dark:text-paper-300 mb-4">
                        Estos ya tenían un cobro informado y este archivo no los menciona. <strong>No se les tocó
                        nada</strong>: que un archivo no hable de un documento no significa que no esté pagado.
                    </p>
                    <ul class="font-mono text-xs space-y-0.5">
                        @foreach ($informe['conservados'] as $doc)
                            <li>
                                <a href="{{ route('cobros.documentos.show', $doc) }}" class="text-indigo-600 hover:underline">{{ $doc->numero_control }}</a>
                                · {{ $money($doc->monto_pagado) }} · {{ $doc->pago_estado->label() }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
