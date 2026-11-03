{{--
    Tarjeta «Líneas» de la ficha del DTE. SOLO LECTURA: muestra lo guardado, no recalcula.
    La usan la ficha de Facturación y la de Cobros.

    Parámetros: $dte (con lineas cargadas); $datosExportacion (opcional, solo FEX).
--}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-700 mb-3">Líneas</h3>
                <div class="overflow-x-auto">
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
            </div>
