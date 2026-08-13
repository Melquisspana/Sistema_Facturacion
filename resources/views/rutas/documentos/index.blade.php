<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Documentos</h2>
            <p class="text-xs text-gray-500 dark:text-paper-400">Todas las salidas · {{ $desde->translatedFormat('d M Y') }} → {{ $hasta->translatedFormat('d M Y') }}</p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-rutas.avisos />

            {{-- ===================== Filtros =====================
                 Dos grupos separados por una línea, y no por capricho: los de arriba
                 son columnas reales y los resuelve la base; los de abajo son estados
                 DERIVADOS (albarán, cobro) que se resuelven al mirar. Quien mantenga
                 esto tiene que saber cuál es cuál antes de mover nada. --}}
            @php
                $campo = 'mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
                $etiqueta = 'block text-xs font-medium text-gray-500 dark:text-paper-400';
                $hayFiltros = collect($filtros)->filter(fn ($v) => filled($v))->isNotEmpty();
            @endphp

            <form method="GET" class="mb-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <div>
                        <label for="desde" class="{{ $etiqueta }}">Salidas desde</label>
                        <input id="desde" type="date" name="desde" value="{{ $desde->toDateString() }}" class="{{ $campo }}">
                    </div>
                    <div>
                        <label for="hasta" class="{{ $etiqueta }}">Hasta</label>
                        <input id="hasta" type="date" name="hasta" value="{{ $hasta->toDateString() }}" class="{{ $campo }}">
                    </div>
                    <div>
                        <label for="ruta_id" class="{{ $etiqueta }}">Ruta</label>
                        <select id="ruta_id" name="ruta_id" class="{{ $campo }}">
                            <option value="">Todas</option>
                            @foreach ($rutas as $r)
                                <option value="{{ $r->id }}" @selected(($filtros['ruta_id'] ?? '') == $r->id)>{{ $r->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="salida_id" class="{{ $etiqueta }}">Salida</label>
                        <select id="salida_id" name="salida_id" class="{{ $campo }}">
                            <option value="">Todas</option>
                            @foreach ($salidas as $s)
                                <option value="{{ $s->id }}" @selected(($filtros['salida_id'] ?? '') == $s->id)>
                                    {{ $s->ruta->nombre }} · {{ $s->fecha_inicio->translatedFormat('d M Y') }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="sucursal_id" class="{{ $etiqueta }}">Sala</label>
                        <select id="sucursal_id" name="sucursal_id" class="{{ $campo }}">
                            <option value="">Todas</option>
                            @foreach ($sucursales as $s)
                                <option value="{{ $s->id }}" @selected(($filtros['sucursal_id'] ?? '') == $s->id)>{{ $s->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="mt-3 grid grid-cols-2 gap-3 border-t border-gray-100 pt-3 sm:grid-cols-3 lg:grid-cols-5 dark:border-ink-700">
                    <div>
                        <label for="entrega" class="{{ $etiqueta }}">Entrega</label>
                        <select id="entrega" name="entrega" class="{{ $campo }}">
                            <option value="">Todos</option>
                            <option value="entregado" @selected(($filtros['entrega'] ?? '') === 'entregado')>Entregados</option>
                            <option value="sin_albaran" @selected(($filtros['entrega'] ?? '') === 'sin_albaran')>Sin albarán</option>
                        </select>
                    </div>
                    <div>
                        <label for="papel" class="{{ $etiqueta }}">Documentación</label>
                        <select id="papel" name="papel" class="{{ $campo }}">
                            <option value="">Todos</option>
                            <option value="recibido" @selected(($filtros['papel'] ?? '') === 'recibido')>Papel recibido</option>
                            <option value="pendiente" @selected(($filtros['papel'] ?? '') === 'pendiente')>Papel pendiente</option>
                        </select>
                    </div>
                    <div>
                        <label for="requiere_nc" class="{{ $etiqueta }}">Requiere NC</label>
                        <select id="requiere_nc" name="requiere_nc" class="{{ $campo }}">
                            <option value="">Todos</option>
                            <option value="1" @selected(($filtros['requiere_nc'] ?? '') === '1')>Marcados</option>
                            <option value="0" @selected(($filtros['requiere_nc'] ?? '') === '0')>Sin marcar</option>
                        </select>
                    </div>
                    <div>
                        <label for="ppq" class="{{ $etiqueta }}">Cobro / PPQ</label>
                        <select id="ppq" name="ppq" class="{{ $campo }}">
                            <option value="">Todos</option>
                            <option value="fuera" @selected(($filtros['ppq'] ?? '') === 'fuera')>Pendiente de ingresar a PPQ</option>
                            <option value="pendiente" @selected(($filtros['ppq'] ?? '') === 'pendiente')>En PPQ, pendiente de pago</option>
                            <option value="pagado" @selected(($filtros['ppq'] ?? '') === 'pagado')>Pagados</option>
                        </select>
                    </div>
                    <div class="flex items-end gap-3">
                        <button class="rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 dark:bg-paper-100 dark:text-ink-900 dark:hover:bg-white">Filtrar</button>
                        @if ($hayFiltros)
                            <a href="{{ route('rutas.documentos.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Limpiar</a>
                        @endif
                    </div>
                </div>
            </form>

            {{-- ===================== Resumen =====================
                 Contado sobre TODO el resultado filtrado, no sobre la página que se
                 ve. Son los mismos contadores del detalle de una salida, calculados
                 por el mismo servicio: no hay una segunda forma de contar.

                 Los dos estados de cobro van SEPARADOS a propósito. «No entró al PPQ»
                 y «está en el PPQ y no lo pagaron» son dos problemas distintos, con
                 dueños distintos: el primero es trabajo nuestro, el segundo es plata
                 que hay que ir a buscar. Sumarlos escondería cuál de los dos crece. --}}
            @php
                $caja = 'rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none';
                $rotulo = 'text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-paper-400';
                $numero = 'mt-1 text-2xl font-semibold tabular-nums text-gray-800 dark:text-paper-100';
                $pie = 'mt-0.5 text-[11px] text-gray-400 dark:text-paper-500';
                $enPpqPendiente = $resumen['en_ppq'] - $resumen['pagados'];
            @endphp

            <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div class="{{ $caja }}">
                    <p class="{{ $rotulo }}">Documentos</p>
                    <p class="{{ $numero }}">{{ $resumen['total'] }}</p>
                    <p class="{{ $pie }}">en el filtro</p>
                </div>
                <div class="{{ $caja }}">
                    <p class="{{ $rotulo }}">Sin albarán</p>
                    <p class="{{ $numero }} @if ($resumen['sin_albaran'] > 0) !text-amber-600 dark:!text-amber-400 @endif">{{ $resumen['sin_albaran'] }}</p>
                    <p class="{{ $pie }}">esperando entrega</p>
                </div>
                <div class="{{ $caja }}">
                    <p class="{{ $rotulo }}">Papel pendiente</p>
                    <p class="{{ $numero }}">{{ $resumen['total'] - $resumen['documentacion_fisica'] }}</p>
                    <p class="{{ $pie }}">no volvió firmado</p>
                </div>
                <div class="{{ $caja }}">
                    <p class="{{ $rotulo }}">Fuera de PPQ</p>
                    <p class="{{ $numero }} @if ($resumen['sin_ppq'] > 0) !text-amber-600 dark:!text-amber-400 @endif">{{ $resumen['sin_ppq'] }}</p>
                    <p class="{{ $pie }}">falta ingresarlos</p>
                </div>
                <div class="{{ $caja }}">
                    <p class="{{ $rotulo }}">En PPQ sin pagar</p>
                    <p class="{{ $numero }}">{{ $enPpqPendiente }}</p>
                    <p class="{{ $pie }}">ya en un lote</p>
                </div>
                <div class="{{ $caja }}">
                    <p class="{{ $rotulo }}">Pagados</p>
                    <p class="{{ $numero }} @if ($resumen['pagados'] > 0) !text-green-600 dark:!text-green-400 @endif">{{ $resumen['pagados'] }}</p>
                    <p class="{{ $pie }}">conciliados</p>
                </div>
            </div>

            {{-- ===================== Tabla ===================== --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200 dark:bg-ink-700 dark:text-paper-400 dark:border-ink-600">
                                <th class="py-3 px-4">Ruta / salida</th>
                                <th class="py-3 px-4">Sala</th>
                                <th class="py-3 px-4">Documento</th>
                                <th class="py-3 px-4">Fecha</th>
                                <th class="py-3 px-4 text-right">Monto</th>
                                <th class="py-3 px-4">Entrega</th>
                                <th class="py-3 px-4">Papel</th>
                                <th class="py-3 px-4">NC</th>
                                <th class="py-3 px-4">Cobro / PPQ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                            @forelse ($documentos as $documento)
                                @php
                                    $nc = $documento->notaCredito();
                                    $ppq = $documento->ppqItem();
                                @endphp
                                <tr class="hover:bg-gray-50 dark:hover:bg-ink-700">
                                    <td class="py-3 px-4">
                                        <a href="{{ route('rutas.salidas.show', $documento->salida) }}" class="font-medium text-gray-800 hover:underline dark:text-paper-100">
                                            {{ $documento->salida->ruta->nombre }}
                                        </a>
                                        <span class="block text-xs text-gray-500 dark:text-paper-400">
                                            {{ $documento->salida->fecha_inicio->translatedFormat('d M Y') }}
                                            <x-rutas.estado-badge :estado="$documento->salida->estado" />
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-gray-700 dark:text-paper-200">{{ $documento->salaNombre() ?? '—' }}</td>
                                    <td class="py-3 px-4">
                                        <span class="font-mono font-semibold text-gray-800 dark:text-paper-100">{{ $documento->ultimos4() }}</span>
                                        @if ($documento->esHistorico())
                                            <span class="ml-1 rounded bg-gray-100 px-1 py-0.5 text-[10px] font-semibold uppercase text-gray-500 dark:bg-ink-700 dark:text-paper-400" title="Histórico P001">P001</span>
                                        @endif
                                        <span class="block truncate font-mono text-[10px] text-gray-400 dark:text-paper-500">{{ $documento->numeroLegible() }}</span>
                                    </td>
                                    <td class="py-3 px-4 text-gray-600 dark:text-paper-300">{{ $documento->fecha()?->translatedFormat('d M Y') ?? '—' }}</td>
                                    <td class="py-3 px-4 text-right tabular-nums text-gray-800 dark:text-paper-100">
                                        {{ $documento->monto() !== null ? '$'.number_format($documento->monto(), 2) : '—' }}
                                    </td>

                                    <td class="py-3 px-4">
                                        @if ($documento->entregado())
                                            <span class="text-green-700 dark:text-green-400">✓ Entregado</span>
                                            <span class="block text-[11px] text-gray-400 dark:text-paper-500">{{ $documento->fechaEntrega()?->translatedFormat('d M Y') ?? 'Sin fecha' }}</span>
                                        @else
                                            <span class="text-gray-500 dark:text-paper-400">○ Sin albarán</span>
                                        @endif
                                    </td>

                                    <td class="py-3 px-4">
                                        @if ($documento->documentacionFisicaRecibida())
                                            <span class="text-green-700 dark:text-green-400">✓ Recibido</span>
                                        @else
                                            <span class="text-gray-500 dark:text-paper-400">○ Pendiente</span>
                                        @endif
                                    </td>

                                    <td class="py-3 px-4">
                                        @if ($nc)
                                            <span class="text-gray-700 dark:text-paper-200">NC emitida</span>
                                            <span class="block truncate font-mono text-[10px] text-gray-400 dark:text-paper-500">{{ $nc->numero_control }}</span>
                                        @elseif ($documento->requiere_nc)
                                            <span class="text-amber-700 dark:text-amber-400">⚠ Requiere NC</span>
                                        @else
                                            <span class="text-gray-300 dark:text-ink-500">—</span>
                                        @endif
                                    </td>

                                    <td class="py-3 px-4">
                                        @if (! $ppq)
                                            <span class="text-gray-500 dark:text-paper-400">○ No está en PPQ</span>
                                        @elseif ($documento->pagado())
                                            <span class="text-green-700 dark:text-green-400">✓ Pagado</span>
                                            <span class="block text-[11px] text-gray-400 dark:text-paper-500">
                                                {{ $documento->fechaPago()?->translatedFormat('d M Y') }}
                                                @if ($documento->montoPagado() !== null) · ${{ number_format($documento->montoPagado(), 2) }} @endif
                                            </span>
                                        @elseif ($documento->ncAplicada())
                                            <span class="text-indigo-700 dark:text-indigo-400">✓ Aplicada</span>
                                        @else
                                            <span class="text-amber-700 dark:text-amber-400">◐ En PPQ</span>
                                            <span class="block truncate text-[11px] text-gray-400 dark:text-paper-500">{{ $ppq->lote?->referencia }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="py-8 px-4 text-center text-gray-500 dark:text-paper-400">
                                        No hay documentos que coincidan con el filtro.
                                        <span class="block mt-1 text-xs">La ventana actual es {{ $desde->translatedFormat('d M Y') }} → {{ $hasta->translatedFormat('d M Y') }}; ampliala si buscás algo más viejo.</span>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4">{{ $documentos->links() }}</div>

        </div>
    </div>
</x-app-layout>
