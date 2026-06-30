<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Lote PPQ #{{ $lote->id }} — {{ $lote->referencia }}</h2>
            <div class="flex gap-2">
                <a href="{{ route('ppq.lotes.index') }}" class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Historial</a>
                @if ($lote->esEditable())
                    <a href="{{ route('ppq.index', ['lote' => $lote->id]) }}" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Buscar / agregar CCF</a>
                @else
                    <a href="{{ route('ppq.index') }}" class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Buscar CCF</a>
                @endif
                @if ($lote->items->isNotEmpty())
                    <a href="{{ route('ppq.lotes.excel', $lote) }}" class="inline-flex items-center gap-1.5 rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 2.75a.75.75 0 00-1.5 0v8.614L6.295 8.235a.75.75 0 10-1.09 1.03l4.25 4.5a.75.75 0 001.09 0l4.25-4.5a.75.75 0 00-1.09-1.03l-2.955 3.129V2.75z"/><path d="M3.5 12.75a.75.75 0 00-1.5 0v2.5A2.75 2.75 0 004.75 18h10.5A2.75 2.75 0 0018 15.25v-2.5a.75.75 0 00-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5z"/></svg>
                        Generar Excel Calleja
                    </a>
                @endif
                @if ($lote->esEditable())
                    <a href="{{ route('ppq.lotes.edit', $lote) }}" class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Editar</a>
                @endif
            </div>
        </div>
    </x-slot>

    @php
        $badge = [
            'borrador' => 'bg-gray-100 text-gray-700', 'listo' => 'bg-blue-100 text-blue-700',
            'enviado' => 'bg-amber-100 text-amber-700', 'pagado' => 'bg-green-100 text-green-700',
            'observado' => 'bg-red-100 text-red-700',
        ];

        $totalCcf = $lote->totalMontoDte();
        $totalAlb = $lote->totalMontoAlbaran();
        $difTotal = $lote->diferenciaTotal();
        $sinAlb = $lote->cantidadSinAlbaran();
        $conDif = $lote->cantidadConDiferencia();
        $estadoLote = \App\Support\PpqConciliacion::estadoLote($sinAlb, $conDif);
        $money = fn ($v) => ((float) $v < 0 ? '−$' : '$').number_format(abs((float) $v), 2);
        $difBox = match ($estadoLote['key']) {
            'cuadra' => 'bg-green-50 ring-green-200',
            'incompleto' => 'bg-amber-50 ring-amber-200',
            default => 'bg-red-50 ring-red-200',
        };
    @endphp

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ session('error') }}</div>
            @endif

            {{-- Resumen superior --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white shadow-sm ring-1 ring-gray-200 rounded-xl p-4">
                    <div class="text-xs text-gray-500">Documentos</div>
                    <div class="mt-1 text-2xl font-bold text-gray-900">{{ $lote->items->count() }}</div>
                    @if ($sinAlb > 0)
                        <div class="mt-1 text-xs text-amber-600">{{ $sinAlb }} sin albarán</div>
                    @endif
                </div>
                <div class="bg-white shadow-sm ring-1 ring-gray-200 rounded-xl p-4">
                    <div class="text-xs text-gray-500">Total CCF/NC <span class="text-gray-400">(neto)</span></div>
                    <div class="mt-1 text-2xl font-bold text-gray-900">{{ $money($totalCcf) }}</div>
                </div>
                <div class="bg-white shadow-sm ring-1 ring-gray-200 rounded-xl p-4">
                    <div class="text-xs text-gray-500">Total albarán <span class="text-gray-400">(neto)</span></div>
                    <div class="mt-1 text-2xl font-bold text-gray-900">{{ $money($totalAlb) }}</div>
                </div>
                <div class="shadow-sm ring-1 {{ $difBox }} rounded-xl p-4">
                    <div class="flex items-center gap-1.5 text-xs text-gray-500">
                        Diferencia total
                        @if ($estadoLote['alerta'])
                            <span class="cursor-help {{ $estadoLote['clase'] }}" title="{{ $estadoLote['motivo'] }}">
                                <svg class="h-4 w-4 inline" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                            </span>
                        @endif
                    </div>
                    <div class="mt-1 text-2xl font-bold {{ $estadoLote['clase'] }}">{{ $money($difTotal) }}</div>
                    <div class="mt-0.5 text-xs {{ $estadoLote['clase'] }}">{{ $estadoLote['alerta'] ? $estadoLote['motivo'] : 'Todo cuadra' }}</div>
                </div>
            </div>

            {{-- Metadatos del lote --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-5 grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                <div><dt class="text-xs text-gray-500">Estado</dt><dd class="mt-0.5"><span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badge[$lote->estado->value] ?? 'bg-gray-100 text-gray-700' }}">{{ $lote->estado->label() }}</span></dd></div>
                <div><dt class="text-xs text-gray-500">Fecha</dt><dd class="mt-0.5 text-gray-700">{{ $lote->fecha->format('d/m/Y') }}</dd></div>
                <div><dt class="text-xs text-gray-500">Cliente</dt><dd class="mt-0.5 text-gray-700">{{ $lote->cliente?->nombre ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Creado</dt><dd class="mt-0.5 text-gray-700">{{ $lote->created_at?->format('d/m/Y') ?? '—' }}</dd></div>
                @if ($lote->observaciones)
                    <div class="col-span-2 sm:col-span-4"><dt class="text-xs text-gray-500">Observaciones</dt><dd class="mt-0.5 text-gray-700">{{ $lote->observaciones }}</dd></div>
                @endif
            </div>

            {{-- Conciliación contra el TXT de pagos de Calleja (solo lectura) --}}
            @if ($lote->items->isNotEmpty())
                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-5">
                    <h3 class="text-sm font-semibold text-gray-700">Conciliar pagos (archivo TXT de Calleja)</h3>
                    <p class="mt-1 text-xs text-gray-500">Subí el archivo <span class="font-mono">.txt</span> que manda Calleja. Marca cada CCF como <span class="font-medium text-green-700">pagado/conciliado</span> solo si aparece en el TXT (tipo CF) y las NC como aplicadas; el resto queda pendiente. No modifica el Excel oficial.</p>
                    <form method="POST" action="{{ route('ppq.lotes.conciliar', $lote) }}" enctype="multipart/form-data" class="mt-3 flex flex-wrap items-center gap-3">
                        @csrf
                        <input type="file" name="archivo" accept=".txt,text/plain" required
                               class="text-sm file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100">
                        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">Conciliar</button>
                    </form>
                    @error('archivo')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            @endif

            {{-- Documentos del lote (tabla en el orden del Excel de Calleja) --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700">Documentos del lote</h3>
                    <span class="text-xs text-gray-400">Mismo orden de columnas que el Excel de Calleja</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-600 bg-gray-50 border-b border-gray-200">
                                <th class="py-2.5 px-3">N° orden de compra</th>
                                <th class="py-2.5 px-3">N° albarán</th>
                                <th class="py-2.5 px-3">Fecha albarán</th>
                                <th class="py-2.5 px-3 text-right">Monto albarán</th>
                                <th class="py-2.5 px-3">Código de generación</th>
                                <th class="py-2.5 px-3">N° de control</th>
                                <th class="py-2.5 px-3 text-right">Monto CCF/NC</th>
                                <th class="py-2.5 px-3">Sello de recepción</th>
                                <th class="py-2.5 px-3">Sala / CD</th>
                                <th class="py-2.5 px-3 text-center">Estado</th>
                                <th class="py-2.5 px-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($lote->itemsOrdenados() as $item)
                                @php
                                    $tipo = $item->tipo_dte ?? $item->dte?->tipo_dte?->value;
                                    $control = $item->numero_control ?? $item->dte?->numero_control;
                                    $sello = $item->sello_recepcion ?? $item->dte?->sello_recepcion;
                                    $codigo = $item->codigo_generacion ?? $item->dte?->codigo_generacion;
                                    $numAlb = \App\Support\Albaran::numeroLimpio($item->albaran?->numero_albaran);
                                    $sala = $item->salaCodigo();
                                    $salaNombre = $item->salaNombre();
                                    $salaDescripcion = $item->salaDescripcion();
                                    $estado = \App\Support\PpqConciliacion::estado($item->monto_dte, $item->monto_albaran);
                                    $alerta = in_array($estado['key'], ['pequena', 'posible_nc'], true);
                                    $tip = match ($estado['key']) {
                                        'coincide' => 'El monto del albarán coincide con el del CCF/NC.',
                                        'pequena' => 'Diferencia pequeña entre el albarán y el CCF/NC ($'.number_format((float) $item->diferencia, 2).').',
                                        'posible_nc' => 'El monto difiere ($'.number_format((float) $item->diferencia, 2).'): posible nota de crédito o devolución.',
                                        default => 'Documento sin albarán vinculado.',
                                    };
                                @endphp
                                @php
                                    $montoAlbSigno = $item->montoAlbaranConSigno();
                                    $montoDteSigno = $item->montoDteConSigno();
                                    $fmt = fn ($v) => ($v < 0 ? '−$' : '$').number_format(abs((float) $v), 2);
                                @endphp
                                <tr class="hover:bg-gray-50 even:bg-gray-50/40">
                                    <td class="py-2 px-3 font-mono text-xs text-gray-700">{{ $item->numero_orden_compra ?: '—' }}</td>
                                    <td class="py-2 px-3 font-mono text-xs">
                                        @if ($item->sin_albaran)
                                            <span class="inline-block rounded bg-gray-100 px-2 py-0.5 text-[11px] text-gray-500">sin albarán</span>
                                        @else
                                            {{ $numAlb ?: '—' }}
                                        @endif
                                        @if ($item->observaciones)
                                            <span class="cursor-help text-gray-400" title="{{ $item->observaciones }}">ⓘ</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-3 text-xs text-gray-600">{{ optional($item->albaran?->fecha_albaran)->format('d/m/Y') ?: '—' }}</td>
                                    <td class="py-2 px-3 text-right {{ $item->esNc() ? 'text-rose-600' : 'text-gray-700' }}">{{ $montoAlbSigno !== null ? $fmt($montoAlbSigno) : '—' }}</td>
                                    <td class="py-2 px-3 font-mono text-xs text-gray-700">{{ $codigo ?: '—' }}</td>
                                    <td class="py-2 px-3 font-mono text-xs text-gray-700">{{ $control ?: '—' }} <span class="{{ $item->esNc() ? 'text-rose-500 font-medium' : 'text-gray-400' }}">{{ $tipo === '05' ? '(NC)' : '(CCF)' }}</span></td>
                                    <td class="py-2 px-3 text-right font-medium {{ $item->esNc() ? 'text-rose-600' : 'text-gray-800' }}">{{ $fmt($montoDteSigno) }}</td>
                                    <td class="py-2 px-3 font-mono text-xs text-gray-600">{{ $sello ? Str::limit($sello, 16) : '—' }}</td>
                                    <td class="py-2 px-3 text-gray-700">
                                        <span class="block {{ $salaNombre ? 'font-medium text-gray-800' : 'text-amber-600' }}">{{ $salaDescripcion }}</span>
                                        @if ($sala)
                                            <span class="block font-mono text-[11px] text-gray-400">{{ $sala }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-3 text-center whitespace-nowrap">
                                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $estado['clase'] }}">
                                            @if ($alerta)
                                                <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                                            @endif
                                            <span class="cursor-help" title="{{ $tip }}">{{ $estado['label'] }}</span>
                                        </span>
                                        @if (! $item->sin_albaran && $item->monto_albaran !== null)
                                            <span class="mt-1 block text-[11px] {{ abs((float) $item->diferencia) >= 0.01 ? 'text-red-600 font-medium' : 'text-gray-400' }}">Dif {{ $money($item->diferencia) }}</span>
                                        @endif
                                        {{-- Estado de pago: solo "pagado" si el TXT de Calleja lo confirma --}}
                                        <span class="mt-1 inline-block rounded-full px-2 py-0.5 text-[11px] font-medium {{ $item->estadoPagoClase() }}" @if ($item->fecha_pago) title="Pago {{ \App\Support\Fecha::dmy($item->fecha_pago) }}" @endif>{{ $item->estadoPagoLabel() }}</span>
                                    </td>
                                    <td class="py-2 px-3 text-right">
                                        @if ($lote->esEditable())
                                            <form method="POST" action="{{ route('ppq.lotes.items.destroy', [$lote, $item]) }}" onsubmit="return confirm('¿Quitar este documento del lote?')">
                                                @csrf @method('DELETE')
                                                <button class="text-red-600 hover:underline text-xs">quitar</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="11" class="py-10 text-center text-gray-400">El lote no tiene documentos. <a href="{{ route('ppq.index', $lote->esEditable() ? ['lote' => $lote->id] : []) }}" class="text-indigo-600 hover:underline">Agregá CCF/NC desde la búsqueda</a>.</td></tr>
                            @endforelse
                        </tbody>
                        @if ($lote->items->isNotEmpty())
                            <tfoot>
                                <tr class="bg-gray-50 border-t-2 border-gray-200 font-semibold text-gray-800">
                                    <td class="py-2.5 px-3 text-xs uppercase text-gray-500" colspan="3">Totales (neto)</td>
                                    <td class="py-2.5 px-3 text-right">{{ $money($totalAlb) }}</td>
                                    <td colspan="2"></td>
                                    <td class="py-2.5 px-3 text-right">{{ $money($totalCcf) }}</td>
                                    <td colspan="2"></td>
                                    <td class="py-2.5 px-3 text-center text-xs {{ $estadoLote['clase'] }}">Dif {{ $money($difTotal) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>

            @if ($lote->esEditable())
                <div class="flex justify-end">
                    <form method="POST" action="{{ route('ppq.lotes.destroy', $lote) }}" onsubmit="return confirm('¿Eliminar todo el lote?')">
                        @csrf @method('DELETE')
                        <button class="text-sm text-red-600 hover:underline">Eliminar lote</button>
                    </form>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
