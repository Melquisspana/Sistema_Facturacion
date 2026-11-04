<x-configuracion-layout titulo="Auditoría · Órdenes de compra para revisar">
    {{-- Marco de Configuración (04/11/2026): esta pantalla salió de la barra de
         Facturación y vive en el índice de Configuración › Administración. Misma URL y mismo
         permiso de siempre. --}}
    <div>

                <div class="mb-4">
                    <a href="{{ route('auditoria.index') }}" class="text-sm text-gray-500 hover:underline">← Volver a Auditoría</a>
                </div>

                <div class="mb-4 rounded-md bg-amber-50 border border-amber-200 p-3 text-xs text-amber-800">
                    CCF emitidos a un cliente o sala que exige orden de compra, cuya orden no tiene la forma esperada:
                    <strong>14 números</strong>, año y mes (AAMM), un 0 y 9 números más, del mismo mes del CCF, del anterior
                    o del siguiente. Solo lectura: esta lista no cambia nada.
                </div>

                <form method="GET" action="{{ route('auditoria.ordenes_compra') }}" class="mb-5 flex flex-wrap items-end gap-3">
                    <div>
                        <x-input-label for="desde" value="Desde" />
                        <x-text-input id="desde" name="desde" type="date" class="mt-1 block" :value="$filtros['desde']" />
                        <x-input-error :messages="$errors->get('desde')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="hasta" value="Hasta" />
                        <x-text-input id="hasta" name="hasta" type="date" class="mt-1 block" :value="$filtros['hasta']" />
                        <x-input-error :messages="$errors->get('hasta')" class="mt-1" />
                    </div>
                    <div class="flex gap-2">
                        <x-primary-button>Filtrar</x-primary-button>
                        <a href="{{ route('auditoria.ordenes_compra') }}" class="px-3 py-2 text-sm text-gray-500 hover:underline">Limpiar</a>
                    </div>
                </form>

                <p class="mb-3 text-sm text-gray-600">{{ count($revisar) }} CCF para revisar.</p>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="px-3 py-2">Fecha</th>
                                <th scope="col" class="px-3 py-2">CCF</th>
                                <th scope="col" class="px-3 py-2">Cliente / sala</th>
                                <th scope="col" class="px-3 py-2">Orden de compra</th>
                                <th scope="col" class="px-3 py-2">Qué no cuadra</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($revisar as $fila)
                                @php($dte = $fila['dte'])
                                <tr class="align-top">
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $dte->fecha_emision?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <a href="{{ route('facturacion.show', $dte) }}" class="text-indigo-600 hover:underline font-mono text-xs">
                                            {{ $dte->numero_control ?? $dte->numero_interno ?? ('#'.$dte->id) }}</a>
                                    </td>
                                    <td class="px-3 py-2">
                                        {{ $dte->cliente?->nombre ?? '—' }}
                                        @if ($dte->clienteSucursal)
                                            <span class="block text-xs text-gray-500">{{ $dte->clienteSucursal->nombre }}@if (filled($dte->clienteSucursal->codigo)) <span class="font-mono">({{ $dte->clienteSucursal->codigo }})</span>@endif</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 font-mono text-xs break-all">{{ $dte->numero_orden_compra ?? '—' }}</td>
                                    <td class="px-3 py-2 text-amber-800">
                                        <ul class="list-disc list-inside">
                                            @foreach ($fila['problemas'] as $problema)
                                                <li>{{ $problema }}</li>
                                            @endforeach
                                        </ul>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-3 py-6 text-center text-gray-400">No hay CCF con la orden de compra para revisar.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
    </div>
</x-configuracion-layout>
