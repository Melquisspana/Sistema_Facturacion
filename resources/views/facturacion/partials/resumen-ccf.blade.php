{{--
    Panel lateral del editor de borrador (CCF / Factura / Exportación): productos
    agregados (editar cantidad / eliminar), totales compactos y botón Generar.

    SOLO presentación + reuso de rutas existentes (lineas.update, lineas.destroy,
    generar). No recalcula nada ni cambia lógica fiscal.

    Parámetros: $dte (requerido), $esAgenteRetencion (opcional).
--}}
@php
    $esFex = $dte->tipo_dte === \App\Enums\TipoDte::FacturaExportacion;
    // Productos agregados en el orden fijo de la orden de compra (barcode/nombre);
    // los que no están en la lista quedan al final por nombre. Solo afecta la
    // presentación del panel, no el numero_linea ni los cálculos.
    $lineasOrdenadas = $dte->lineas
        ->sortBy(fn ($l) => [\App\Support\Dte\OrdenProductosOc::rank($l->codigo_barra, $l->descripcion), mb_strtoupper((string) $l->descripcion)])
        ->values();
@endphp

<div class="space-y-6">
    {{-- Productos agregados --}}
    <div class="bg-white shadow sm:rounded-lg p-4">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-semibold text-gray-700">Productos agregados</h3>
            <span class="text-xs text-gray-400">{{ $dte->lineas->count() }} línea(s)</span>
        </div>

        <ul class="divide-y divide-gray-100 -mx-1">
            @forelse ($lineasOrdenadas as $linea)
                <li class="px-1 py-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-800 truncate" title="{{ $linea->descripcion }}">{{ $linea->descripcion }}</p>
                            <p class="text-xs text-gray-500 font-mono">${{ number_format($linea->precio_unitario, 2) }} c/u
                                @if (! $esFex && (float) $linea->iva_linea > 0)
                                    · IVA ${{ number_format($linea->iva_linea, 2) }}
                                @endif
                            </p>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="font-mono font-semibold text-gray-900">${{ number_format($linea->total_linea, 2) }}</p>
                        </div>
                    </div>

                    @can('update', $dte)
                        <div class="mt-2 flex items-center justify-between gap-2">
                            <form method="POST" action="{{ route('facturacion.lineas.update', [$dte, $linea]) }}"
                                  class="flex items-center gap-1.5">
                                @csrf @method('PATCH')
                                <label class="sr-only" for="cant-{{ $linea->id }}">Cantidad</label>
                                <input id="cant-{{ $linea->id }}" type="number" name="cantidad"
                                       value="{{ (int) $linea->cantidad }}" step="1" min="1" inputmode="numeric"
                                       class="block w-16 border-gray-300 rounded-md shadow-sm text-sm py-1" required>
                                <button class="text-indigo-600 hover:underline text-xs">Actualizar</button>
                            </form>
                            <form method="POST" action="{{ route('facturacion.lineas.destroy', [$dte, $linea]) }}"
                                  onsubmit="return confirm('¿Eliminar esta línea?');">
                                @csrf @method('DELETE')
                                <button class="text-red-600 hover:underline text-xs">Eliminar</button>
                            </form>
                        </div>
                    @endcan
                </li>
            @empty
                <li class="px-1 py-6 text-center text-sm text-gray-400">Primero agregue productos al borrador.</li>
            @endforelse
        </ul>
    </div>

    {{-- Totales (compacto: apilado vertical para el panel). Reusa el partial único. --}}
    @include('facturacion.partials.totales', ['dte' => $dte, 'esAgenteRetencion' => $esAgenteRetencion ?? null, 'compacto' => true])

    {{-- Generar: consume el correlativo y deja de ser editable. Deshabilitado sin líneas;
         el confirm con resumen viene de la vista (fallback genérico si no se pasa). --}}
    @can('update', $dte)
        @php
            $sinLineasPanel = $dte->lineas->isEmpty();
            $confirmPanel = $confirmGenerar ?? '¿Generar el documento? Ya no podrá editarse.';
        @endphp
        <div class="bg-white shadow sm:rounded-lg p-4">
            <form method="POST" action="{{ route('facturacion.generar', $dte) }}"
                  onsubmit="return confirm(@js($confirmPanel));">
                @csrf
                <button @disabled($sinLineasPanel)
                        @if ($sinLineasPanel) title="Agregá al menos un producto para generar." @endif
                        class="w-full inline-flex items-center justify-center px-4 py-2.5 text-white text-sm font-medium rounded-md {{ $sinLineasPanel ? 'bg-gray-300 cursor-not-allowed' : 'bg-green-600 hover:bg-green-700' }}">
                    Generar documento
                </button>
            </form>
            <p class="mt-2 text-xs text-gray-400">
                @if ($sinLineasPanel)
                    Agregá al menos un producto para generar.
                @else
                    Al generar se asigna el correlativo interno y el documento deja de ser editable. No firma ni transmite.
                @endif
            </p>
        </div>
    @endcan
</div>
