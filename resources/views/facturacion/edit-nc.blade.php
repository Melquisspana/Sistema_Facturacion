<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $nc->tituloDocumento() }}
            <span class="ml-2 text-sm font-normal text-gray-500">N.º sistema: {{ $nc->etiquetaNumeroSistema() }}</span>
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Acción: generar. Si hay avisos (retención o diferencia contra el albarán)
                 se exige marcar la confirmación; nunca se ajusta un valor fiscal en silencio. --}}
            @can('update', $nc)
                <div class="bg-white shadow sm:rounded-lg p-4">
                    @if (! empty($avisosAlbaran))
                        <div class="mb-4 rounded-md bg-amber-50 border border-amber-300 p-3">
                            <p class="text-sm font-semibold text-amber-800">Revisá antes de generar</p>
                            <ul class="mt-1 list-disc list-inside text-sm text-amber-800 space-y-1">
                                @foreach ($avisosAlbaran as $aviso)
                                    <li>{{ $aviso['texto'] }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <div class="flex items-center justify-between gap-4">
                        <p class="text-sm text-gray-600">Cuando termines de acreditar líneas, generá la nota de crédito (consume el correlativo y deja de ser editable).</p>
                        <form method="POST" action="{{ route('facturacion.generar', $nc) }}"
                              onsubmit="return confirm('¿Generar la nota de crédito? Ya no podrá editarse.');"
                              class="flex items-center gap-3 shrink-0">
                            @csrf
                            @if (! empty($avisosAlbaran))
                                <label class="flex items-center gap-2 text-sm text-amber-800">
                                    <input type="checkbox" name="confirmar_avisos_nc" value="1" class="rounded border-amber-400 text-amber-600">
                                    Reviso y confirmo
                                </label>
                            @endif
                            <button class="inline-flex items-center px-4 py-2 bg-green-600 text-white text-sm rounded-md hover:bg-green-700">Generar</button>
                        </form>
                    </div>
                </div>
            @endcan

            {{-- Albarán de crédito del cliente. Solo se muestra si el cliente tiene un perfil
                 que mapea esta modalidad; en cualquier otro caso la pantalla no cambia. --}}
            @if ($reglaAlbaran)
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                    <h3 class="font-semibold text-gray-700 dark:text-paper-100">Albarán del cliente</h3>
                    <p class="text-sm text-gray-500 dark:text-paper-300 mb-4">
                        Esta modalidad corresponde a un albarán
                        <span class="font-mono font-semibold">{{ $reglaAlbaran->codigo_externo }}</span>@if ($reglaAlbaran->etiqueta_externa) ({{ $reglaAlbaran->etiqueta_externa }})@endif.
                        Los datos van al archivo del día; <strong>no cambian los valores fiscales</strong> de la nota.
                    </p>

                    @if ($albaran)
                        <dl class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 text-sm mb-4">
                            <div class="col-span-2 sm:col-span-1"><dt class="text-gray-500 dark:text-paper-300">Número</dt><dd class="font-mono break-all">{{ $albaran->numero_canonico }}</dd></div>
                            <div><dt class="text-gray-500 dark:text-paper-300">Tipo</dt><dd class="font-mono">{{ $albaran->tipo_codigo }}</dd></div>
                            <div><dt class="text-gray-500 dark:text-paper-300">Sala</dt><dd class="font-mono">{{ $albaran->sala_codigo ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500 dark:text-paper-300">Fecha</dt><dd>{{ $albaran->fecha?->format('d/m/Y') ?? '—' }}</dd></div>
                            <div><dt class="text-gray-500 dark:text-paper-300">Total albarán</dt><dd class="font-mono">{{ number_format((float) $albaran->total, 2) }}</dd></div>
                        </dl>

                        @if ($comparacionAlbaran)
                            {{-- role=status y no alert: es información de contraste permanente,
                                 no una alarma que interrumpa. El aviso que sí exige acción vive
                                 arriba, junto al botón de generar. --}}
                            <div role="status"
                                 class="rounded-md border p-3 text-sm mb-4 {{ $comparacionAlbaran['cuadra'] ? 'bg-green-50 border-green-200 text-green-800' : 'bg-amber-50 border-amber-300 text-amber-800' }}">
                                Total de la nota <span class="font-mono font-semibold">{{ $comparacionAlbaran['total_nc'] }}</span>
                                · total del albarán <span class="font-mono font-semibold">{{ $comparacionAlbaran['total_albaran'] }}</span>
                                · diferencia <span class="font-mono font-semibold">{{ $comparacionAlbaran['diferencia'] }}</span>
                                @if ($comparacionAlbaran['cuadra']) — coinciden. @else — revisá cuál de los dos es el correcto antes de generar. @endif
                            </div>
                        @endif
                    @else
                        <p class="text-sm text-amber-700 mb-4">Todavía no se registró el albarán de esta nota de crédito.</p>
                    @endif

                    @can('update', $nc)
                        <form method="POST" action="{{ route('facturacion.albaran.store', $nc) }}">
                            @csrf
                            <fieldset class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-start">
                                <legend class="sr-only">Datos del albarán de crédito</legend>

                                <div class="sm:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-paper-100" for="albaran_numero">Número de albarán</label>
                                    <input id="albaran_numero" name="numero" type="text" required maxlength="60"
                                           value="{{ old('numero', $albaran?->numero_canonico) }}"
                                           placeholder="{{ $reglaAlbaran->codigo_externo }}/0033/00/3209"
                                           @error('numero') aria-invalid="true" aria-describedby="albaran_numero_error" @else aria-describedby="albaran_numero_ayuda" @enderror
                                           class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono">
                                    <p id="albaran_numero_ayuda" class="mt-1 text-xs text-gray-500 dark:text-paper-300">
                                        Acepta el número completo, el nombre del PDF que manda el cliente, o solo el correlativo.
                                    </p>
                                    @error('numero')<p id="albaran_numero_error" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-paper-100" for="albaran_fecha">Fecha del albarán</label>
                                    <input id="albaran_fecha" name="fecha" type="date" required
                                           value="{{ old('fecha', $albaran?->fecha?->format('Y-m-d')) }}"
                                           @error('fecha') aria-invalid="true" aria-describedby="albaran_fecha_error" @enderror
                                           class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                    @error('fecha')<p id="albaran_fecha_error" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-paper-100" for="albaran_total">Total del albarán</label>
                                    <input id="albaran_total" name="total" type="number" step="0.01" min="0" required inputmode="decimal"
                                           value="{{ old('total', $albaran?->total) }}"
                                           @error('total') aria-invalid="true" aria-describedby="albaran_total_error" @else aria-describedby="albaran_total_ayuda" @enderror
                                           class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono">
                                    <p id="albaran_total_ayuda" class="mt-1 text-xs text-gray-500 dark:text-paper-300">En positivo, aunque el PDF lo imprima en negativo.</p>
                                    @error('total')<p id="albaran_total_error" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </div>
                            </fieldset>

                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                <button class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                                    {{ $albaran ? 'Actualizar albarán' : 'Registrar albarán' }}
                                </button>
                            </div>
                        </form>

                        @if ($albaran)
                            <form method="POST" action="{{ route('facturacion.albaran.destroy', $nc) }}" class="mt-3"
                                  onsubmit="return confirm('¿Quitar el albarán de esta nota de crédito? Quedará libre para otra nota.');">
                                @csrf
                                @method('DELETE')
                                <button class="text-sm text-red-600 hover:underline">Quitar albarán</button>
                            </form>
                        @endif
                    @endcan
                </div>
            @endif

            {{-- Cabecera --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-700 mb-3">Datos de la nota de crédito</h3>
                <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div><dt class="text-gray-500">Tipo</dt><dd>{{ $nc->tipo_nota_credito?->label() ?? '—' }}</dd></div>
                    <div>
                        <dt class="text-gray-500">Documento original</dt>
                        <dd>
                            @if ($original)
                                <a href="{{ route('facturacion.show', $original) }}" class="text-indigo-600 hover:underline font-mono">{{ $original->numero_interno ?? ('CCF #'.$original->id) }}</a>
                            @else
                                <span class="text-amber-600">Sin documento relacionado</span>
                            @endif
                        </dd>
                    </div>
                    <div><dt class="text-gray-500">Cliente</dt><dd>{{ $nc->cliente?->nombre ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Estado</dt><dd><x-estado-dte-badge :estado="$nc->estado" /></dd></div>
                    <div class="md:col-span-4"><dt class="text-gray-500">Motivo</dt><dd>{{ $nc->motivo ?? '—' }}</dd></div>
                </dl>
            </div>

            @if ($porProductos)
            {{-- Flujo 1: devolución/faltante → acreditar líneas del CCF original --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <p class="text-xs text-gray-500 mb-3">Use esta opción cuando la nota afecta productos o cantidades del CCF original.</p>
                <h3 class="font-semibold text-gray-700 mb-3">Líneas del documento original</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-3 py-2">Descripción</th>
                                <th class="px-3 py-2 text-right">Precio</th>
                                <th class="px-3 py-2 text-right">Cant. original</th>
                                <th class="px-3 py-2 text-right">Acreditada</th>
                                <th class="px-3 py-2 text-right">Disponible</th>
                                <th class="px-3 py-2">Acreditar</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($lineasOriginales as $fila)
                                @php $lo = $fila['linea']; $disponible = $fila['disponible']; @endphp
                                <tr>
                                    <td class="px-3 py-2 font-medium">{{ $lo->descripcion }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($lo->precio_unitario, 2) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ rtrim(rtrim($lo->cantidad, '0'), '.') }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ rtrim(rtrim($fila['acreditado'], '0'), '.') ?: '0' }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ rtrim(rtrim($disponible, '0'), '.') ?: '0' }}</td>
                                    <td class="px-3 py-2">
                                        @if (\App\Support\Dinero::comparar($disponible, '0') > 0)
                                            <form method="POST" action="{{ route('facturacion.acreditar', [$nc, $lo]) }}" class="flex items-end gap-2">
                                                @csrf
                                                <input type="number" name="cantidad" step="0.0001" min="0.0001" max="{{ $disponible }}"
                                                       value="{{ rtrim(rtrim($disponible, '0'), '.') }}" inputmode="decimal"
                                                       autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                                                       class="block w-24 border-gray-300 rounded-md shadow-sm text-sm" required>
                                                <button class="px-3 py-2 bg-indigo-600 text-white text-xs rounded-md hover:bg-indigo-700">Acreditar</button>
                                            </form>
                                        @else
                                            <span class="text-gray-400 text-xs">Sin saldo</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">El documento original no tiene líneas.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @elseif ($porAveria)
            {{-- Flujo "avería": cualquier producto del catálogo (no limitado al CCF original) --}}
            <div class="bg-white shadow sm:rounded-lg p-6" x-data="{ filtro: '' }">
                <h3 class="font-semibold text-gray-700 mb-1">Productos para nota de crédito por avería</h3>
                <p class="text-xs text-gray-500 mb-3">La avería puede acreditar <strong>cualquier producto</strong> del catálogo, no solo los del CCF relacionado. El precio se aplica por cliente/sucursal.</p>

                <div class="mb-4">
                    <x-input-label for="filtro-averia" value="Filtrar por nombre, código interno o código de barra" />
                    <input id="filtro-averia" type="text" x-model="filtro"
                           placeholder="Escribe para filtrar… (el listado ya está visible)"
                           class="mt-1 block w-full md:w-96 border-gray-300 rounded-md shadow-sm text-sm">
                </div>

                @if (count($productosDisponibles) === 0)
                    <p class="text-sm text-gray-400">No hay productos activos para agregar.</p>
                @else
                    <div class="overflow-x-auto max-h-96 overflow-y-auto border border-gray-100 rounded-md">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr class="text-left text-gray-500">
                                    <th class="px-3 py-2">Código</th>
                                    <th class="px-3 py-2">Código barra</th>
                                    <th class="px-3 py-2">Producto</th>
                                    <th class="px-3 py-2 text-right">Precio aplicado</th>
                                    <th class="px-3 py-2">Cantidad</th>
                                    <th class="px-3 py-2">Acción</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($productosDisponibles as $p)
                                    <tr x-show="filtro === '' || @js($p['filtro']).includes(filtro.toLowerCase().trim())">
                                        <td class="px-3 py-2 font-mono">{{ $p['codigo'] ?? '—' }}</td>
                                        <td class="px-3 py-2 font-mono text-gray-500">{{ $p['codigo_barra'] ?? '—' }}</td>
                                        <td class="px-3 py-2 font-medium">{{ $p['nombre'] }}</td>
                                        <td class="px-3 py-2 text-right">
                                            @if ($p['sin_precio'])
                                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">Sin precio</span>
                                            @else
                                                <span class="font-mono">${{ $p['precio_fmt'] }}</span>
                                                <span class="block text-[10px] {{ $p['es_especial'] ? 'text-indigo-600' : 'text-gray-400' }}">{{ $p['origen_label'] }}</span>
                                            @endif
                                        </td>
                                        @if ($p['sin_precio'])
                                            <td colspan="2" class="px-3 py-2 text-xs text-gray-400">No se puede agregar sin precio.</td>
                                        @else
                                            <td colspan="2" class="px-3 py-2">
                                                <form method="POST" action="{{ route('facturacion.averia.store', $nc) }}"
                                                      class="flex items-end gap-2">
                                                    @csrf
                                                    <input type="hidden" name="producto_id" value="{{ $p['id'] }}">
                                                    <div>
                                                        <label class="text-xs text-gray-500">Cantidad</label>
                                                        <input type="number" name="cantidad" value="1" step="1" min="1"
                                                               inputmode="numeric"
                                                               autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                                                               class="block w-20 border-gray-300 rounded-md shadow-sm text-sm" required>
                                                    </div>
                                                    <button class="px-3 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                                                        Agregar a nota de crédito
                                                    </button>
                                                </form>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
            @else
            {{-- Flujo 2: pronto pago / descuento / ajuste → conceptos por monto --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-700 mb-1">Agregar concepto de ajuste</h3>
                <p class="text-xs text-gray-500 mb-3">Estas líneas son <strong>conceptos de ajuste</strong>, no productos físicos. No afectan inventario.</p>
                <form method="POST" action="{{ route('facturacion.conceptos.store', $nc) }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                    @csrf
                    <div class="md:col-span-2">
                        <x-input-label for="descripcion" value="Concepto / descripción *" />
                        <x-text-input id="descripcion" name="descripcion" type="text" class="mt-1 block w-full"
                                      placeholder="Ej. Descuento por pronto pago" required />
                    </div>
                    <div>
                        <x-input-label for="monto" value="Monto *" />
                        <input id="monto" name="monto" type="number" step="0.01" min="0.01"
                               class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm" required>
                    </div>
                    <div>
                        <x-input-label for="tipo_impuesto" value="Tratamiento" />
                        <select id="tipo_impuesto" name="tipo_impuesto" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                            @foreach ($tiposImpuesto as $valor => $label)
                                <option value="{{ $valor }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-4">
                        <button class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">Agregar concepto</button>
                    </div>
                </form>
            </div>
            @endif

            {{-- Líneas/conceptos de esta NC --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-700 mb-3">{{ $porProductos ? 'Líneas acreditadas' : ($porAveria ? 'Productos acreditados por avería' : 'Conceptos') }}</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-3 py-2">#</th>
                                <th class="px-3 py-2">Descripción</th>
                                <th class="px-3 py-2 text-right">Cantidad</th>
                                <th class="px-3 py-2 text-right">Gravado</th>
                                <th class="px-3 py-2 text-right">IVA</th>
                                <th class="px-3 py-2 text-right">Total</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($nc->lineas as $linea)
                                <tr>
                                    <td class="px-3 py-2">{{ $linea->numero_linea }}</td>
                                    <td class="px-3 py-2 font-medium">{{ $linea->descripcion }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ rtrim(rtrim($linea->cantidad, '0'), '.') }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($linea->venta_gravada, 2) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($linea->iva_linea, 2) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($linea->total_linea, 2) }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <form method="POST" action="{{ route('facturacion.lineas.destroy', [$nc, $linea]) }}"
                                              onsubmit="return confirm('¿Quitar esta línea acreditada?');">
                                            @csrf @method('DELETE')
                                            <button class="text-red-600 hover:underline text-xs">Quitar</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">{{ $porProductos ? 'Aún no se acreditó ninguna línea.' : ($porAveria ? 'Aún no hay productos. Agrega uno desde el catálogo de arriba.' : 'Aún no hay conceptos. Agrega uno arriba.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Totales: partial único de presentación (no recalcula nada). --}}
            @include('facturacion.partials.totales', ['dte' => $nc])

            <div>
                <a href="{{ route('facturacion.index') }}" class="text-sm text-gray-500 hover:underline">← Volver al listado</a>
            </div>
        </div>
    </div>
</x-app-layout>
