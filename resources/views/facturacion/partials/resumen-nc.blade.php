{{--
    Panel lateral del editor de NOTA DE CRÉDITO: lo acreditado hasta ahora (con su línea
    original a la vista), el resumen fiscal y el botón Generar.

    Es el gemelo de `resumen-ccf` y respeta su mismo contrato con el editor AJAX
    (`resumen_html` reemplaza el contenido de #resumen-panel, los formularios llevan
    data-ajax, el botón lleva data-generar-btn), así que ccf-editor.js sirve a los dos sin
    saber cuál está mirando.

    Lo que cambia es qué hace falta verificar. En un CCF alcanza con producto, cantidad y
    total; en una nota de crédito hay que poder responder «¿de qué línea del CCF salió
    esto y a qué precio?» sin abrir el original en otra pestaña. Por eso cada renglón
    muestra el precio congelado y, cuando acredita una línea del CCF, lo dice.

    SOLO presentación + reuso de rutas existentes (lineas.update, lineas.destroy,
    generar). No recalcula nada ni cambia lógica fiscal.

    Parámetros: $dte (la NC, requerido), $esAgenteRetencion (opcional), $confirmGenerar
    (opcional).
--}}
@php
    $modalidad = \App\Enums\ModalidadNotaCredito::desdeTipo($dte->tipo_nota_credito);
    $porMonto = $dte->tipo_nota_credito?->esPorMonto() ?? false;
    $sinLineasPanel = $dte->lineas->isEmpty();
    // Sin documento relacionado la nota NO puede generarse: el esquema del MH exige
    // `documentoRelacionado` en toda NC. Es un bloqueo distinto al de «sin líneas» y hay
    // que decirlo distinto, porque se resuelve de otra manera (vinculando un CCF).
    $faltaCcf = $dte->dte_relacionado_id === null;
    $titulo = match (true) {
        $porMonto => 'Conceptos de la nota',
        ($dte->tipo_nota_credito?->esPorAveria() ?? false) => 'Productos acreditados',
        default => 'Líneas acreditadas',
    };
@endphp

<div class="space-y-4">
    <div class="bg-white shadow sm:rounded-lg p-4">
        <div class="flex items-center justify-between gap-2 mb-3">
            <h3 class="font-semibold text-gray-700">{{ $titulo }}</h3>
            <span class="text-xs text-gray-400">{{ $dte->lineas->count() }} línea(s)</span>
        </div>

        {{-- Lista con scroll propio: con muchas líneas, los totales y el botón de abajo
             quedan siempre visibles (el panel es sticky) sin tener que bajar.
             "relative" ancla acá los <label class="sr-only"> de cada renglón para que este
             contenedor los recorte con su propio scroll, en vez de escaparse y estirar el
             alto real del documento. --}}
        <ul class="relative divide-y divide-gray-100 -mx-1 max-h-[45vh] overflow-y-auto pr-1">
            @forelse ($dte->lineas->sortBy('numero_linea') as $linea)
                <li class="px-1 py-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-800 truncate" title="{{ $linea->descripcion }}">{{ $linea->descripcion }}</p>
                            <p class="text-xs text-gray-500 font-mono">
                                ${{ number_format($linea->precio_unitario, 2) }} c/u
                                @if ((float) $linea->iva_linea > 0)
                                    · IVA ${{ number_format($linea->iva_linea, 2) }}
                                @endif
                            </p>
                            {{-- Trazabilidad al CCF: de qué línea del original salió esto.
                                 Sin este dato, verificar una NC obliga a abrir el CCF aparte. --}}
                            @if ($linea->dte_linea_original_id)
                                <p class="mt-0.5 text-[11px] text-indigo-600">
                                    Acredita la línea #{{ $linea->lineaOriginal?->numero_linea ?? $linea->dte_linea_original_id }} del CCF
                                    @if ($linea->lineaOriginal)
                                        (cantidad original {{ rtrim(rtrim($linea->lineaOriginal->cantidad, '0'), '.') }})
                                    @endif
                                </p>
                            @endif
                        </div>
                        <div class="text-right shrink-0">
                            <p class="font-mono font-semibold text-gray-900">${{ number_format($linea->total_linea, 2) }}</p>
                        </div>
                    </div>

                    @can('update', $dte)
                        <div class="mt-2 flex items-center justify-between gap-2">
                            <form method="POST" action="{{ route('facturacion.lineas.update', [$dte, $linea]) }}"
                                  data-ajax="update" class="flex items-center gap-1.5">
                                @csrf @method('PATCH')
                                <label class="sr-only" for="cant-{{ $linea->id }}">Cantidad</label>
                                <input id="cant-{{ $linea->id }}" type="number" name="cantidad"
                                       value="{{ rtrim(rtrim($linea->cantidad, '0'), '.') }}"
                                       step="{{ $porMonto ? '1' : '0.0001' }}" min="0.0001" inputmode="decimal"
                                       autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                                       title="Se guarda solo al cambiar la cantidad"
                                       class="block w-20 border-gray-300 rounded-md shadow-sm text-sm py-1" required>
                                {{-- Fallback discreto: con JS se guarda solo; sin JS este botón sigue funcionando. --}}
                                <button class="text-gray-400 hover:text-gray-600 hover:underline text-xs"
                                        title="También se guarda solo al cambiar la cantidad">Actualizar</button>
                            </form>
                            <form method="POST" action="{{ route('facturacion.lineas.destroy', [$dte, $linea]) }}"
                                  data-ajax="destroy" onsubmit="return confirm('¿Quitar esta línea de la nota de crédito?');">
                                @csrf @method('DELETE')
                                <button class="text-red-600 hover:underline text-xs">Quitar</button>
                            </form>
                        </div>
                    @endcan
                </li>
            @empty
                <li class="px-1 py-6 text-center text-sm text-gray-400">
                    @if ($porMonto)
                        Todavía no hay conceptos. Agregá uno desde el panel de la izquierda.
                    @else
                        Todavía no acreditaste nada. Poné una cantidad en el panel de la izquierda.
                    @endif
                </li>
            @endforelse
        </ul>
    </div>

    {{-- Resumen fiscal: el MISMO partial de totales que usan CCF, factura y exportación.
         No recalcula: muestra lo que el motor ya persistió. --}}
    @include('facturacion.partials.totales', ['dte' => $dte, 'esAgenteRetencion' => $esAgenteRetencion ?? null, 'compacto' => true])

    @can('update', $dte)
        <div class="bg-white shadow sm:rounded-lg p-4">
            @php $bloqueado = $sinLineasPanel || $faltaCcf; @endphp
            <form method="POST" action="{{ route('facturacion.generar', $dte) }}"
                  onsubmit="return confirm(@js($confirmGenerar ?? '¿Generar la nota de crédito? Ya no podrá editarse.'))">
                @csrf
                <button data-generar-btn @disabled($bloqueado)
                        @if ($faltaCcf) title="Falta relacionar un CCF aceptado para poder emitir."
                        @elseif ($sinLineasPanel) title="Agregá al menos una línea para generar." @endif
                        class="w-full inline-flex items-center justify-center px-4 py-2.5 text-white text-sm font-medium rounded-md {{ $bloqueado ? 'bg-gray-300 cursor-not-allowed' : 'bg-green-600 hover:bg-green-700' }}">
                    Generar nota de crédito
                </button>
            </form>
            <p class="mt-2 text-xs {{ $faltaCcf ? 'text-amber-700' : 'text-gray-400' }}">
                @if ($faltaCcf)
                    <strong>Avería registrada; falta relacionar un CCF para emitir.</strong>
                    Hacienda exige un documento relacionado en toda nota de crédito.
                @elseif ($sinLineasPanel)
                    Agregá al menos una línea para generar.
                @else
                    Al generar se asigna el correlativo interno y la nota deja de ser editable. No firma ni transmite.
                @endif
            </p>
        </div>
    @endcan
</div>
