@php
    $money = fn ($v) => ((float) $v < 0 ? '−$' : '$').number_format(abs((float) $v), 2);
    $t = $informe['totales'];
    $lotesPpq = $lotesPpq ?? [];
@endphp
<section class="rounded-lg border border-gray-200 bg-white p-4 sm:p-6 space-y-4 text-sm text-gray-700">
    {{-- ---------- El PPQ que paga este TXT ---------- --}}
    <div>
        @if ($lotesPpq !== [])
            {{-- En una sola línea: el texto que se lee es «Este TXT paga el PPQ X (queda pagado)». --}}
            <h2 class="text-lg font-semibold text-gray-800">{{ count($lotesPpq) === 1 ? 'Este TXT paga el PPQ' : 'Este TXT paga los PPQ' }}@foreach ($lotesPpq as $l){{ $loop->first ? ' ' : ', ' }}<a href="{{ route('ppq.lotes.show', $l['lote']) }}" class="text-indigo-600 hover:text-indigo-800 hover:underline">{{ $l['lote']->referencia }}</a>{{ $l['pagado'] ? ' (queda pagado)' : '' }}@endforeach</h2>
        @else
            <div class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800" role="status">
                Este TXT no corresponde a ningún PPQ del sistema: revisalo.
            </div>
        @endif
        <p class="mt-1 text-xs text-gray-500">
            <span class="font-mono">{{ $informe['archivo'] }}</span> ·
            @if ($informe['fecha_pago'])
                Fecha de pago: {{ $informe['fecha_pago']->format('d/m/Y') }}
            @else
                Sin fecha de pago: quedan cobrados sin fecha hasta tener evidencia.
            @endif
        </p>
    </div>

    @if ($informe['proveedor']['ajenos'] !== [])
        <div class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">
            <strong>El archivo trae códigos de proveedor ajenos:</strong>
            {{ implode(', ', $informe['proveedor']['ajenos']) }} (se esperaba {{ $informe['proveedor']['esperado'] }}).
            Revisá que sea el archivo de este proveedor antes de dar por buenos los totales.
        </div>
    @endif

    {{-- El MISMO archivo (misma huella) ya se concilió en PPQ. Solo se avisa: lo de acá
         no cambió por eso, ni se copió nada de allá. --}}
    @if (! empty($informe['en_ppq']))
        <div class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800" role="status">
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
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-xs text-gray-500">Facturas (CF) · {{ $t['cantidad_cf'] }}</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-green-700">{{ $money($t['total_cf']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-xs text-gray-500">Notas de crédito (NC) · {{ $t['cantidad_nc'] }}</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-rose-700">{{ $money($t['total_nc']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-xs text-gray-500">Ajustes (QD) · {{ $t['cantidad_qd'] }}</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-amber-600">{{ $money($t['total_qd']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-xs text-gray-500">Neto del archivo</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-gray-800">{{ $money($t['neto_archivo']) }}</div>
        </div>
    </div>

    {{-- ---------- Ajuste de pronto pago: de acá sale la nota de crédito ---------- --}}
    @if ($informe['ajustes'] !== [])
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
            <h3 class="font-medium text-gray-800">Ajuste de pronto pago (QD)</h3>
            <ul class="mt-1">
                @foreach ($informe['ajustes'] as $entrada)
                    <li>
                        {{-- Sin el motivo interno («no se encontró ninguna solicitud…»): en el TXT confunde. --}}
                        <x-cobros.ajuste :ajuste="$entrada['ajuste']" :solo-revisar="$soloRevisar" :con-motivo="false" />
                        {{-- La nota se hace desde la revisión, sin aplicar el TXT (Melqui, 06/11/2026):
                             se registra solo este ajuste y, al aplicar después, ya tiene su nota. --}}
                        @if ($soloRevisar && $entrada['ajuste']->estadoEfectivo() === 'pendiente_nc' && isset($cliente))
                            @can('ppq.gestionar')
                                @can('create', \App\Models\Dte::class)
                                    <form method="POST" action="{{ route('cobros.pagos.ajuste-nc', $cliente) }}" class="pb-2">
                                        @csrf
                                        <input type="hidden" name="hash" value="{{ $informe['hash'] }}">
                                        <input type="hidden" name="linea" value="{{ $entrada['fila']['linea'] }}">
                                        <button type="submit" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">Hacer nota de crédito</button>
                                    </form>
                                @endcan
                            @endcan
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ---------- Lo que no cuadra en el PPQ que paga ---------- --}}
    @if ($lotesPpq !== [])
        @include('cobros.partials.pendientes-txt')
    @endif

    {{-- ---------- Pagos que no se aplicaron solos ---------- --}}
    @if ($informe['en_revision'] !== [])
        <div class="rounded-lg border border-amber-200 bg-white p-4">
            <h3 class="font-medium text-gray-800 mb-1">
                Pagos que quedaron EN REVISIÓN ({{ count($informe['en_revision']) }})
            </h3>
            <p class="text-sm text-gray-500 mb-4">
                Estos documentos <strong>ya tenían un pago informado por otro archivo</strong>. Desde el archivo
                no se distingue si el cliente lo repitió o si pagó en dos abonos, así que
                <strong>este importe se registró pero no se sumó</strong>. Se resuelve en la ficha de cada
                documento, con motivo.
            </p>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">Pagos en revisión</caption>
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th scope="col" class="p-2 text-left font-medium">Documento</th>
                            <th scope="col" class="p-2 text-left font-medium">Línea</th>
                            <th scope="col" class="p-2 text-right font-medium">Informado ahora</th>
                            <th scope="col" class="p-2 text-right font-medium">Cobrado que sí cuenta</th>
                            <th scope="col" class="p-2 text-left font-medium">Por qué no se aplicó</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($informe['en_revision'] as $fila)
                            <tr>
                                <td class="p-2 font-mono whitespace-nowrap">
                                    @if ($soloRevisar)
                                        {{ $fila['documento']->numero_control }}
                                    @else
                                        <a href="{{ route('cobros.documentos.show', $fila['documento']) }}"
                                           class="text-indigo-600 hover:underline">{{ $fila['documento']->numero_control }}</a>
                                    @endif
                                </td>
                                <td class="p-2 font-mono text-xs">{{ $fila['fila']['linea'] }} · {{ $fila['fila']['tipo'] }}</td>
                                <td class="p-2 text-right font-mono text-amber-700">{{ $money($fila['monto_archivo']) }}</td>
                                <td class="p-2 text-right font-mono">{{ $money($fila['documento']->monto_pagado) }}</td>
                                <td class="p-2 text-xs text-gray-600">{{ $fila['evento']->estado_motivo }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ---------- Lo que el archivo no resolvió ---------- --}}
    @if (! empty($informe['ambiguos']) || $informe['no_identificados'] !== [] || $informe['invalidas'] !== [] || $informe['otros_tipos'] !== [] || $informe['repetidas'] !== [])
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <h3 class="font-medium text-gray-800 mb-1">Filas que hay que mirar</h3>
            <p class="text-sm text-gray-500 mb-4">
                No se descartan en silencio: un número que no reconocemos puede ser de otro proveedor, un error
                de tecleo del cliente o un documento que falta dar de alta.
            </p>

            <div class="space-y-4 text-sm">
                @if (! empty($informe['ambiguos']))
                    <div class="text-amber-800">
                        <h4 class="font-medium">Números que existen en más de un año</h4>
                        <p class="text-xs">el archivo no trae la fecha del documento: existe en más de un año, revisar a mano</p>
                        <ul class="mt-1 text-xs space-y-1">
                            @foreach ($informe['ambiguos'] as $ambiguo)
                                <li>línea {{ $ambiguo['fila']['linea'] }} · {{ $ambiguo['fila']['tipo'] }} · {{ $ambiguo['fila']['numero'] }} · {{ $money($ambiguo['fila']['valor']) }} · {{ $ambiguo['candidatos']->pluck('ejercicio_control')->unique()->sort()->implode(' · ') }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
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
                        <p class="text-xs text-gray-500">Sin número o sin importe: no identifican ni informan nada.</p>
                        <ul class="mt-1 font-mono text-xs space-y-0.5">
                            @foreach ($informe['invalidas'] as $fila)
                                <li>línea {{ $fila['linea'] }} · {{ \Illuminate\Support\Str::limit($fila['raw'], 90) }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($informe['otros_tipos'] !== [])
                    <div>
                        <h4 class="font-medium text-gray-700">Tipos no reconocidos ({{ count($informe['otros_tipos']) }})</h4>
                        <ul class="mt-1 font-mono text-xs space-y-0.5">
                            @foreach ($informe['otros_tipos'] as $fila)
                                <li>línea {{ $fila['linea'] }} · «{{ $fila['tipo'] }}» · {{ $fila['numero'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($informe['repetidas'] !== [])
                    <div>
                        <h4 class="font-medium text-gray-700">Filas repetidas ({{ count($informe['repetidas']) }})</h4>
                        <p class="text-xs text-gray-500">
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

    {{-- ---------- Detalle, cerrado: casi nunca hace falta ---------- --}}
    <details class="rounded-lg border border-gray-200 bg-white p-4">
        <summary class="cursor-pointer font-medium text-gray-800">Ver documento por documento</summary>
        <div class="mt-4 space-y-4">
            <div>
                <h3 class="font-medium text-gray-800 mb-1">Documentos actualizados ({{ $t['cantidad_aplicados'] }})</h3>
                <p class="text-sm text-gray-500 mb-4">
                    @if ($t['cantidad_sin_cambio'] > 0)
                        Otros {{ $t['cantidad_sin_cambio'] }} ya estaban exactamente así: este archivo no los cambió.
                        Recargar el mismo archivo no vuelve a cobrar nada.
                    @else
                        Cada uno con el importe que informó el archivo.
                    @endif
                </p>

                @if ($informe['aplicados'] === [])
                    <p class="text-sm text-gray-500">Ningún documento cambió con este archivo.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Documentos actualizados</caption>
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th scope="col" class="p-2 text-left font-medium">Documento</th>
                                    <th scope="col" class="p-2 text-left font-medium">Línea</th>
                                    <th scope="col" class="p-2 text-right font-medium">Monto del documento</th>
                                    <th scope="col" class="p-2 text-right font-medium">Informado</th>
                                    <th scope="col" class="p-2 text-right font-medium">Saldo</th>
                                    <th scope="col" class="p-2 text-left font-medium">Estado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
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

            @if ($informe['conservados']->isNotEmpty())
                <div>
                    <h3 class="font-medium text-gray-800 mb-1">
                        Cobros anteriores conservados ({{ $informe['conservados']->count() }})
                    </h3>
                    <p class="text-sm text-gray-500 mb-4">
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
    </details>
</section>
