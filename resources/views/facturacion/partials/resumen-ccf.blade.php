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

        {{-- Lista con scroll propio: con muchos productos, el bloque de totales y el botón de
             abajo quedan siempre visibles (el panel es sticky) sin tener que bajar. --}}
        <ul class="divide-y divide-gray-100 -mx-1 max-h-[45vh] overflow-y-auto pr-1">
            @forelse ($lineasOrdenadas as $linea)
                @php
                    $esLibreFex = $esFex && $linea->producto_id === null;
                    // Etiqueta de presentación visible (Caja/Cajas para unidad_codigo="99";
                    // el nombre real de la unidad en cualquier otro caso). Solo presentación:
                    // no cambia unidad_codigo guardado ni el JSON oficial.
                    $presentacionLinea = $esFex ? \App\Support\Dte\PresentacionUnidadLinea::etiqueta($linea, $dte) : null;
                @endphp
                <li class="px-1 py-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-800 truncate" title="{{ $linea->descripcion }}">{{ $linea->descripcion }}</p>
                            <p class="text-xs text-gray-500 font-mono">${{ number_format($linea->precio_unitario, 2) }}
                                {{ $esFex ? '/ caja' : 'c/u' }}
                                @if ($esFex)
                                    · <span class="font-sans">{{ (int) $linea->cantidad }} {{ $presentacionLinea }}</span>
                                @endif
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
                        {{-- Línea libre de FEX (sin producto de catálogo): además de cantidad, se
                             puede editar descripción y precio por caja (no hay snapshot que proteger). --}}
                        @if ($esLibreFex)
                            <form method="POST" action="{{ route('facturacion.lineas.update', [$dte, $linea]) }}"
                                  data-ajax="update" class="mt-2 space-y-1.5">
                                @csrf @method('PATCH')
                                <input type="text" name="descripcion" value="{{ $linea->descripcion }}" required maxlength="1000"
                                       title="Descripción" class="block w-full border-gray-300 rounded-md shadow-sm text-xs py-1">
                                <div class="flex items-center gap-1.5">
                                    <label class="text-xs text-gray-500" for="cant-{{ $linea->id }}">{{ $presentacionLinea }}</label>
                                    <input id="cant-{{ $linea->id }}" type="number" name="cantidad"
                                           value="{{ (int) $linea->cantidad }}" step="1" min="1" inputmode="numeric"
                                           title="{{ $presentacionLinea }}" class="block w-16 border-gray-300 rounded-md shadow-sm text-sm py-1" required>
                                    <label class="sr-only" for="precio-{{ $linea->id }}">Precio por caja</label>
                                    <input id="precio-{{ $linea->id }}" type="number" name="precio_unitario"
                                           value="{{ number_format((float) $linea->precio_unitario, 2, '.', '') }}" step="0.01" min="0"
                                           title="Precio por caja" class="block w-24 border-gray-300 rounded-md shadow-sm text-sm py-1" required>
                                    <button class="text-gray-400 hover:text-gray-600 hover:underline text-xs">Guardar</button>
                                </div>
                            </form>
                            <form method="POST" action="{{ route('facturacion.lineas.destroy', [$dte, $linea]) }}"
                                  data-ajax="destroy" onsubmit="return confirm('¿Eliminar esta línea?');" class="mt-1">
                                @csrf @method('DELETE')
                                <button class="text-red-600 hover:underline text-xs">Eliminar</button>
                            </form>
                        @else
                        <div class="mt-2 flex items-center justify-between gap-2">
                            <form method="POST" action="{{ route('facturacion.lineas.update', [$dte, $linea]) }}"
                                  data-ajax="update" class="flex items-center gap-1.5">
                                @csrf @method('PATCH')
                                <label class="sr-only" for="cant-{{ $linea->id }}">{{ $esFex ? 'Cajas' : 'Cantidad' }}</label>
                                <input id="cant-{{ $linea->id }}" type="number" name="cantidad"
                                       value="{{ (int) $linea->cantidad }}" step="1" min="1" inputmode="numeric"
                                       autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                                       title="Se guarda solo al cambiar la cantidad"
                                       class="block w-16 border-gray-300 rounded-md shadow-sm text-sm py-1" required>
                                {{-- Fallback discreto: con JS se guarda solo; sin JS este botón sigue funcionando. --}}
                                <button class="text-gray-400 hover:text-gray-600 hover:underline text-xs" title="También se guarda solo al cambiar la cantidad">Actualizar</button>
                            </form>
                            <form method="POST" action="{{ route('facturacion.lineas.destroy', [$dte, $linea]) }}"
                                  data-ajax="destroy" onsubmit="return confirm('¿Eliminar esta línea?');">
                                @csrf @method('DELETE')
                                <button class="text-red-600 hover:underline text-xs">Eliminar</button>
                            </form>
                        </div>
                        @endif
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
                <button data-generar-btn @disabled($sinLineasPanel)
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
