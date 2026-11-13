{{--
    Tarjeta «Líneas» de la ficha del DTE. SOLO LECTURA: muestra lo guardado, no recalcula.
    La usan la ficha de Facturación y la de Cobros.

    Parámetros: $dte (con lineas cargadas); $datosExportacion (opcional, solo FEX).

    Celular (debajo de md): la tabla no entra a 375 px, así que cada línea pasa a una
    tarjeta con los mismos datos. Desde md se ve la tabla de siempre.
--}}
            <div class="bg-white shadow sm:rounded-lg p-4 sm:p-6">
                <h3 class="font-semibold text-gray-700 mb-3">Líneas</h3>
                <div class="hidden md:block overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-3 py-2">#</th>
                                <th class="px-3 py-2">Descripción</th>
                                <th class="px-3 py-2 text-right">Precio</th>
                                <th class="px-3 py-2 text-right">Cantidad</th>
                                @if ($datosExportacion ?? null)<th class="px-3 py-2">Presentación</th>@endif
                                <th class="px-3 py-2 text-right">Descuento</th>
                                <th class="px-3 py-2 text-right">Gravado</th>
                                <th class="px-3 py-2 text-right">IVA</th>
                                <th class="px-3 py-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($dte->lineas as $linea)
                                <tr>
                                    <td class="px-3 py-2">{{ $linea->numero_linea }}</td>
                                    <td class="px-3 py-2 font-medium">{{ $linea->descripcion }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($linea->precio_unitario, 2) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ rtrim(rtrim($linea->cantidad, '0'), '.') }}</td>
                                    @if ($datosExportacion ?? null)<td class="px-3 py-2">{{ \App\Support\Dte\PresentacionUnidadLinea::etiqueta($linea, $dte) }}</td>@endif
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($linea->descuento_monto, 2) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($linea->venta_gravada, 2) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($linea->iva_linea, 2) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($linea->total_linea, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ ($datosExportacion ?? null) ? 9 : 8 }}" class="px-3 py-6 text-center text-gray-400">Sin líneas.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <ul class="md:hidden divide-y divide-gray-100">
                    @forelse ($dte->lineas as $linea)
                        <li class="py-3">
                            <div class="flex items-start justify-between gap-3">
                                <p class="min-w-0 break-words font-medium text-gray-800">
                                    <span class="text-gray-500">{{ $linea->numero_linea }}.</span> {{ $linea->descripcion }}
                                </p>
                                <p class="shrink-0 font-mono font-semibold text-gray-900">${{ number_format($linea->total_linea, 2) }}</p>
                            </div>
                            <dl class="mt-1 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-gray-600">
                                <div><dt class="inline text-gray-500">Precio:</dt> <dd class="inline font-mono">${{ number_format($linea->precio_unitario, 2) }}</dd></div>
                                <div><dt class="inline text-gray-500">Cantidad:</dt> <dd class="inline font-mono">{{ rtrim(rtrim($linea->cantidad, '0'), '.') }}</dd></div>
                                @if ($datosExportacion ?? null)
                                    <div class="col-span-2"><dt class="inline text-gray-500">Presentación:</dt> <dd class="inline">{{ \App\Support\Dte\PresentacionUnidadLinea::etiqueta($linea, $dte) }}</dd></div>
                                @endif
                                <div><dt class="inline text-gray-500">Descuento:</dt> <dd class="inline font-mono">${{ number_format($linea->descuento_monto, 2) }}</dd></div>
                                <div><dt class="inline text-gray-500">Gravado:</dt> <dd class="inline font-mono">${{ number_format($linea->venta_gravada, 2) }}</dd></div>
                                <div><dt class="inline text-gray-500">IVA:</dt> <dd class="inline font-mono">${{ number_format($linea->iva_linea, 2) }}</dd></div>
                            </dl>
                        </li>
                    @empty
                        <li class="py-6 text-center text-gray-400">Sin líneas.</li>
                    @endforelse
                </ul>
            </div>
