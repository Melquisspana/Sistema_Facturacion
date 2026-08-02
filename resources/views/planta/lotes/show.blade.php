{{-- Ficha de un lote: su identidad, dónde está su saldo y qué le ha pasado.

     PANTALLA DE CONSULTA. La única escritura es el botón de estado, que retira o
     reincorpora el lote y no toca ni el saldo ni el libro mayor.

     Los totales van SEPARADOS por estado, igual que en Existencias: `disponible`,
     `retenido` y `rechazado` no se suman entre sí ni siquiera dentro de un mismo
     lote, porque solo el primero puede trasladarse o utilizarse. Sí llevan unidad
     y eso no mezcla nada: un lote pertenece a UN insumo, así que todo su saldo
     está expresado en la misma unidad base. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">
                Lote {{ $lote->codigo_interno }}
                <x-planta.badge :color="$lote->activo ? 'green' : 'gray'" class="ms-2">
                    {{ $lote->activo ? 'Activo' : 'Retirado' }}
                </x-planta.badge>
            </h2>
            <a href="{{ route('planta.lotes.index') }}"
               class="text-sm text-gray-500 hover:underline dark:text-paper-400">Volver al listado</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-planta.avisos />

            @php
                $dt = 'text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-paper-400';
                $dd = 'mt-0.5 text-gray-800 dark:text-paper-100';
                $unidad = $lote->insumo?->unidad_base->abreviatura();
                $vencido = $lote->fecha_vencimiento !== null
                    && $lote->fecha_vencimiento->lt(\Illuminate\Support\Carbon::today());
            @endphp

            @if (! $lote->activo)
                <div class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600 dark:border-ink-600 dark:bg-ink-700 dark:text-paper-300">
                    Este lote está <strong>retirado</strong> de la operación: no se ofrece para entradas
                    nuevas. Su saldo y su historial se conservan intactos.
                </div>
            @endif

            @if ($vencido)
                <div class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                    Este lote venció el {{ $lote->fecha_vencimiento->format('d/m/Y') }}. Retirar el saldo
                    vencido del inventario se hace con un <strong>ajuste</strong> con motivo, no desde esta pantalla.
                </div>
            @endif

            {{-- Identidad --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 dark:bg-ink-800 dark:ring-ink-600">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4 text-sm">
                    <div>
                        <dt class="{{ $dt }}">Código interno</dt>
                        <dd class="{{ $dd }} font-mono">{{ $lote->codigo_interno }}</dd>
                    </div>
                    <div>
                        <dt class="{{ $dt }}">Código del proveedor</dt>
                        <dd class="{{ $dd }}">{{ $lote->codigo_proveedor ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="{{ $dt }}">Insumo</dt>
                        <dd class="{{ $dd }}">
                            {{ $lote->insumo?->codigo }} — {{ $lote->insumo?->nombre ?? '—' }}
                            @if ($lote->insumo)
                                <x-planta.badge :color="$lote->insumo->tipo->color()" class="ms-1">{{ $lote->insumo->tipo->label() }}</x-planta.badge>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="{{ $dt }}">Proveedor</dt>
                        <dd class="{{ $dd }}">{{ $lote->proveedor?->nombre ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="{{ $dt }}">Fecha de recepción</dt>
                        <dd class="{{ $dd }}">{{ $lote->fecha_recepcion?->format('d/m/Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="{{ $dt }}">Fecha de elaboración</dt>
                        <dd class="{{ $dd }}">{{ $lote->fecha_elaboracion?->format('d/m/Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="{{ $dt }}">Fecha de vencimiento</dt>
                        <dd class="{{ $dd }} {{ $vencido ? 'font-semibold text-red-700 dark:text-red-300' : '' }}">
                            {{ $lote->fecha_vencimiento?->format('d/m/Y') ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="{{ $dt }}">Estado</dt>
                        <dd class="{{ $dd }}">
                            <x-planta.toggle-activo :activo="$lote->activo"
                                                    :accion="route('planta.lotes.toggle-activo', $lote)" />
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- Saldos por estado. Sin gran total: ver la cabecera de esta vista. --}}
            <div>
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-400 dark:text-paper-500">Saldo de este lote</h3>
                <div class="grid gap-3 sm:grid-cols-3">
                    @forelse ($totales as $t)
                        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                            <div class="flex items-center justify-between">
                                <x-planta.badge :color="$t['estado']->color()">{{ $t['estado']->label() }}</x-planta.badge>
                                <span class="text-xs text-gray-400 dark:text-paper-500">{{ $t['buckets'] }} {{ $t['buckets'] === 1 ? 'saldo' : 'saldos' }}</span>
                            </div>
                            <p class="mt-2 text-2xl font-semibold tabular-nums text-gray-800 dark:text-paper-100">
                                {{ $t['total'] }}
                                <span class="text-sm font-normal text-gray-400 dark:text-paper-500">{{ $unidad }}</span>
                            </p>
                        </div>
                    @empty
                        <div class="sm:col-span-3 rounded-xl bg-white p-4 text-sm text-gray-500 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:text-paper-400 dark:ring-ink-600">
                            Este lote no tiene saldo en ninguna ubicación. Si tuvo movimientos, siguen abajo:
                            el historial no desaparece al agotarse el saldo.
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Dónde está ese saldo. Un lote tiene pocos buckets con saldo
                 —ubicación × estado × traslado en curso—, así que la página casi
                 nunca aparece; existe para que un caso raro no rompa la ficha. --}}
            <div class="overflow-x-auto bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl dark:bg-ink-800 dark:ring-ink-600">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-ink-600">
                    <thead class="bg-gray-50 dark:bg-ink-900">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-paper-400">
                            <th class="px-4 py-3">Ubicación</th>
                            <th class="px-4 py-3">Estado</th>
                            <th class="px-4 py-3">Traslado</th>
                            <th class="px-4 py-3 text-right">Cantidad</th>
                            <th class="px-4 py-3">Unidad</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm dark:divide-ink-700">
                        @forelse ($saldos as $saldo)
                            <tr class="text-gray-700 dark:text-paper-200">
                                <td class="px-4 py-3">{{ $saldo->ubicacion?->codigo ?? '—' }} — {{ $saldo->ubicacion?->nombre }}</td>
                                <td class="px-4 py-3">
                                    <x-planta.badge :color="$saldo->estado->color()">{{ $saldo->estado->label() }}</x-planta.badge>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($saldo->planta_traslado_id > 0)
                                        @can('planta.traslados.ver')
                                            <a href="{{ route('planta.traslados.show', $saldo->planta_traslado_id) }}"
                                               class="text-indigo-600 hover:underline dark:text-indigo-300">#{{ $saldo->traslado?->numero ?? $saldo->planta_traslado_id }}</a>
                                        @else
                                            #{{ $saldo->traslado?->numero ?? $saldo->planta_traslado_id }}
                                        @endcan
                                    @else
                                        <span class="text-gray-400 dark:text-paper-500">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-medium tabular-nums">{{ $saldo->cantidad }}</td>
                                <td class="px-4 py-3">{{ $unidad }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-paper-400">
                                    Sin saldo en ninguna ubicación.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                @if ($saldos->hasPages())
                    <div class="px-4 py-3 border-t border-gray-100 dark:border-ink-700">{{ $saldos->links() }}</div>
                @endif
            </div>

            @canany(['planta.existencias.ver', 'planta.movimientos.ver'])
                <div class="flex flex-wrap gap-4 text-sm">
                    @can('planta.existencias.ver')
                        <a href="{{ route('planta.existencias.index', ['lote' => $lote->id]) }}"
                           class="text-indigo-600 hover:underline dark:text-indigo-300">Ver existencias de este lote</a>
                    @endcan
                    @can('planta.movimientos.ver')
                        <a href="{{ route('planta.movimientos.index', ['lote' => $lote->id]) }}"
                           class="text-indigo-600 hover:underline dark:text-indigo-300">Ver historial completo ({{ $totalMovimientos }})</a>
                    @endcan
                </div>
            @endcanany

            {{-- Últimos movimientos. Es un resumen, no el historial: el historial
                 entero vive en la pantalla de movimientos, ya filtrada por lote. --}}
            <div>
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-400 dark:text-paper-500">
                    Últimos movimientos
                    @if ($totalMovimientos > $movimientos->count())
                        <span class="ms-1 font-normal normal-case tracking-normal text-gray-400 dark:text-paper-500">
                            ({{ $movimientos->count() }} de {{ $totalMovimientos }})
                        </span>
                    @endif
                </h3>
                <div class="overflow-x-auto bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl dark:bg-ink-800 dark:ring-ink-600">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-ink-600">
                        <thead class="bg-gray-50 dark:bg-ink-900">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-paper-400">
                                <th class="px-4 py-3">Fecha</th>
                                <th class="px-4 py-3">Tipo</th>
                                <th class="px-4 py-3">Ubicación</th>
                                <th class="px-4 py-3">Estado</th>
                                <th class="px-4 py-3 text-right">Cantidad</th>
                                <th class="px-4 py-3">Documento</th>
                                <th class="px-4 py-3">Usuario</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-sm dark:divide-ink-700">
                            @forelse ($movimientos as $movimiento)
                                @php
                                    $rutaDocumento = \App\Support\Planta\DocumentoOrigen::ruta($movimiento->documento_type);
                                    $permisoDocumento = \App\Support\Planta\DocumentoOrigen::permiso($movimiento->documento_type);
                                    $claveDocumento = ltrim((string) $movimiento->documento_type, '\\').'#'.$movimiento->documento_id;
                                    $numeroDocumento = $numerosDocumento[$claveDocumento] ?? null;
                                @endphp
                                <tr class="text-gray-700 dark:text-paper-200">
                                    <td class="px-4 py-3 whitespace-nowrap">{{ $movimiento->fecha_efectiva?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <x-planta.badge :color="$movimiento->tipo->color()">{{ $movimiento->tipo->label() }}</x-planta.badge>
                                        @if ($movimiento->tipo->esReversion())
                                            <span class="ms-1 text-[11px] text-rose-600 dark:text-rose-300">(reversión)</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">{{ $movimiento->ubicacion?->codigo ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <x-planta.badge :color="$movimiento->estado->color()">{{ $movimiento->estado->label() }}</x-planta.badge>
                                    </td>
                                    <td class="px-4 py-3 text-right font-medium tabular-nums {{ $movimiento->esEntrada() ? 'text-green-700 dark:text-green-300' : 'text-red-700 dark:text-red-300' }}">
                                        {{ $movimiento->esEntrada() ? '+' : '' }}{{ $movimiento->cantidad }}
                                        <span class="ms-1 text-[11px] font-normal text-gray-400 dark:text-paper-500">{{ $movimiento->unidad_base?->abreviatura() }}</span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        @if ($rutaDocumento && $permisoDocumento && auth()->user()?->can($permisoDocumento))
                                            <a href="{{ route($rutaDocumento, $movimiento->documento_id) }}"
                                               class="text-indigo-600 hover:underline dark:text-indigo-300">
                                                {{ \App\Support\Planta\DocumentoOrigen::etiqueta($movimiento->documento_type) }}
                                                @if ($numeroDocumento) #{{ $numeroDocumento }} @endif
                                            </a>
                                        @else
                                            <span>
                                                {{ \App\Support\Planta\DocumentoOrigen::etiqueta($movimiento->documento_type) }}
                                                @if ($numeroDocumento) #{{ $numeroDocumento }} @endif
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">{{ $movimiento->user?->name ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-paper-400">
                                        Este lote todavía no tiene movimientos.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
