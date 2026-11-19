<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Pedidos web</h1>
        </div>
    </x-slot>
    <div class="py-6">
        <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6 lg:px-8">
            <x-rutas.avisos />
            <p class="text-sm text-gray-600">@if ($atencion !== null)Arriba, lo que requiere atención: alertas, avisos rechazados, pedidos para revisar y los que llevan más de 30 minutos facturando. Debajo, los pedidos pagados en los últimos 30 días. @endif Si un pedido ya tiene factura, corregila en Facturación.</p>
            <x-filtros-celular :activos="$estado !== '' ? 1 : 0" id="filtros-pedidos-web">
                <form method="GET" action="{{ route('facturacion.pedidos-web.index') }}" class="flex flex-wrap items-end gap-3">
                    <div>
                        <label for="estado" class="block text-sm font-medium text-gray-700">Estado</label>
                        <select id="estado" name="estado" class="min-h-11 rounded-md border-gray-300">
                            @foreach (['' => 'Atención y últimos 30 días', 'todos' => 'Todos', 'importado' => 'Importado', 'facturando' => 'Facturando', 'facturado' => 'Facturado', 'para_revisar' => 'Para revisar'] as $valor => $texto)
                                <option value="{{ $valor }}" @selected($estado === $valor)>{{ $texto }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-secondary-button class="min-h-11" type="submit">Filtrar</x-secondary-button>
                    @if ($estado !== '')
                        <a href="{{ route('facturacion.pedidos-web.index') }}" class="inline-flex min-h-11 items-center text-sm text-indigo-700">Limpiar</a>
                    @endif
                </form>
            </x-filtros-celular>
            <div class="rounded-lg border border-gray-200 bg-white">
                {{-- Filas apiladas: cada acción tiene Alpine y un único modal, también en celular. --}}
                <table class="block w-full md:table">
                    <thead class="hidden bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500 md:table-header-group">
                        <tr>@foreach (['Código', 'Pago', 'Cliente', 'Entrega', 'Total', 'Estado', 'Motivo', 'Acciones'] as $titulo)<th scope="col" class="px-4 py-3">{{ $titulo }}</th>@endforeach</tr>
                    </thead>
                    @php
                        // Sin filtro, dos partes en la misma tabla: arriba lo que requiere atención y
                        // debajo los últimos 30 días. Con un filtro, la lista de siempre, sin títulos.
                        $secciones = $atencion !== null ? [
                            ['titulo' => 'Requieren atención', 'filas' => $atencion, 'vacio' => 'Nada requiere atención.', 'enlace' => null],
                            ['titulo' => 'Últimos 30 días', 'filas' => $pedidos, 'vacio' => 'No entró ningún pedido de la página en los últimos 30 días.',
                                'enlace' => ['Ver todos', route('facturacion.pedidos-web.index', ['estado' => 'todos'])]],
                        ] : [
                            $estado === 'todos'
                                ? ['titulo' => null, 'filas' => $pedidos, 'vacio' => 'Todavía no entró ningún pedido de la página.', 'enlace' => null]
                                : ['titulo' => null, 'filas' => $pedidos, 'vacio' => 'Nada coincide con estos filtros.', 'enlace' => ['Limpiar filtros', route('facturacion.pedidos-web.index')]],
                        ];
                    @endphp
                    @foreach ($secciones as $seccion)
                    <tbody class="block md:table-row-group">
                        @if ($seccion['titulo'])
                            <tr class="block border-t border-gray-200 bg-gray-50 md:table-row">
                                <th colspan="8" scope="rowgroup" class="px-4 py-2 text-left text-sm font-semibold text-gray-700">{{ $seccion['titulo'] }}</th>
                            </tr>
                        @endif
                        @forelse ($seccion['filas'] as $web)
                            <tr class="grid grid-cols-2 border-t border-gray-100 md:table-row">
                                <td class="col-span-2 px-4 py-3 text-sm font-semibold text-gray-800">{{ $web->codigo }}</td>
                                <td class="px-4 py-3 text-sm tabular-nums"><span class="block text-xs text-gray-500 md:hidden">Pago</span>{{ $web->pagado_en->format('d/m/Y H:i') }}</td>
                                <td class="col-span-2 px-4 py-3 text-sm"><span class="block text-xs text-gray-500 md:hidden">Cliente</span>{{ $web->cliente_nombre }}</td>
                                <td class="px-4 py-3 text-sm"><span class="block text-xs text-gray-500 md:hidden">Entrega</span>{{ $web->entrega_tipo === 'recoger' ? 'Recoger' : 'Ruta · '.($web->pedidoSala?->ruta?->nombre ?? $web->pedidoSala?->salida?->ruta?->nombre ?? 'Por asignar') }}</td>
                                <td class="px-4 py-3 text-right text-sm tabular-nums"><span class="block text-xs text-gray-500 md:hidden">Total</span>${{ \App\Services\Gastos\Dinero::mostrar($web->total_centavos) }}</td>
                                <td class="px-4 py-3"><x-planta.badge :color="$web->estado->color()">{{ $web->estado->etiqueta() }}</x-planta.badge></td>
                                <td class="col-span-2 px-4 py-3 text-sm">{{ $web->motivo_revision }}
                                    @if ($web->alerta)<p class="text-sm text-gray-600">Pago doble: revisar si hay que devolver el segundo cobro</p>@endif</td>
                                <td class="col-span-2 px-4 py-3 text-right text-sm">
                                    @if ($web->alerta)
                                        @can('dte.gestionar')
                                            <x-secondary-button x-on:click="$dispatch('open-modal', 'revisado-{{ $web->id }}')">Ya lo revisé</x-secondary-button>
                                            <x-modal name="revisado-{{ $web->id }}" maxWidth="md" focusable>
                                                <form method="POST" action="{{ route('facturacion.pedidos-web.revisado', $web) }}" class="p-6 text-left" role="dialog" aria-modal="true" aria-labelledby="revisado-titulo-{{ $web->id }}">
                                                    @csrf
                                                    <h2 id="revisado-titulo-{{ $web->id }}" class="text-lg font-semibold text-gray-800">¿Confirmar revisión de {{ $web->codigo }}?</h2>
                                                    <label for="nota-{{ $web->id }}" class="mt-3 block text-sm font-medium text-gray-700">Nota de revisión (sin datos de tarjeta)</label>
                                                    <x-text-input id="nota-{{ $web->id }}" name="nota" required maxlength="300" class="mt-2 block w-full" />
                                                    <x-input-error :messages="$errors->get('nota')" />
                                                    <label class="mt-3 block text-sm text-gray-700"><input type="checkbox" name="confirmacion" value="1" required> Confirmo que revisé el segundo cobro.</label>
                                                    <x-input-error :messages="$errors->get('confirmacion')" />
                                                    <div class="mt-6 flex flex-wrap justify-end gap-3">
                                                        <x-secondary-button x-on:click="$dispatch('close-modal', 'revisado-{{ $web->id }}')">Cancelar</x-secondary-button>
                                                        <x-primary-button>Ya lo revisé</x-primary-button>
                                                    </div>
                                                </form>
                                            </x-modal>
                                        @endcan
                                    @endif
                                    @if ($web->estado !== \App\Enums\EstadoPedidoWeb::ParaRevisar && filled($web->motivo_revision))
                                        @can('dte.gestionar')
                                            <x-secondary-button x-on:click="$dispatch('open-modal', 'volver-a-avisar-{{ $web->id }}')">Volver a avisar</x-secondary-button>
                                            <x-modal name="volver-a-avisar-{{ $web->id }}" maxWidth="md" focusable>
                                                <form method="POST" action="{{ route('facturacion.pedidos-web.volver-a-avisar', $web) }}" class="p-6 text-left" role="dialog" aria-modal="true" aria-labelledby="volver-a-avisar-titulo-{{ $web->id }}">
                                                    @csrf
                                                    <h2 id="volver-a-avisar-titulo-{{ $web->id }}" class="text-lg font-semibold text-gray-800">¿Volver a avisar {{ $web->codigo }}?</h2>
                                                    <p class="mt-3 text-sm text-gray-600">Después de corregir el problema en la página, la próxima pasada volverá a mandar los avisos pendientes.</p>
                                                    <label class="mt-3 block text-sm text-gray-700"><input type="checkbox" name="confirmacion" value="1" required> Confirmo que quiero volver a avisar.</label>
                                                    <x-input-error :messages="$errors->get('confirmacion')" />
                                                    <div class="mt-6 flex flex-wrap justify-end gap-3">
                                                        <x-secondary-button x-on:click="$dispatch('close-modal', 'volver-a-avisar-{{ $web->id }}')">Cancelar</x-secondary-button>
                                                        <x-primary-button>Volver a avisar</x-primary-button>
                                                    </div>
                                                </form>
                                            </x-modal>
                                        @endcan
                                    @endif
                                    @if ($web->dte_id)
                                        <a href="{{ route('facturacion.show', $web->dte_id) }}" class="inline-flex min-h-11 items-center text-indigo-700">Ver factura</a>
                                        @if ($web->estado === \App\Enums\EstadoPedidoWeb::ParaRevisar)<p class="text-sm text-gray-600">Corregí la factura en Facturación.</p>@endif
                                    @elseif ($web->estado === \App\Enums\EstadoPedidoWeb::ParaRevisar)
                                        @can('dte.gestionar')
                                            <x-secondary-button class="min-h-11" x-on:click="$dispatch('open-modal', 'reintentar-{{ $web->id }}')">Reintentar</x-secondary-button>
                                            <x-modal name="reintentar-{{ $web->id }}" maxWidth="md" focusable>
                                                <form method="POST" action="{{ route('facturacion.pedidos-web.reintentar', $web) }}" class="p-6 text-left" role="dialog" aria-modal="true" aria-labelledby="reintentar-titulo-{{ $web->id }}">
                                                    @csrf
                                                    <h2 id="reintentar-titulo-{{ $web->id }}" class="text-lg font-semibold text-gray-800">¿Reintentar {{ $web->codigo }}?</h2>
                                                    <p class="mt-3 text-sm text-gray-600">Después de corregir el motivo, el próximo intento volverá a preparar la factura.</p>
                                                    <div class="mt-6 flex flex-wrap justify-end gap-3">
                                                        <x-secondary-button class="min-h-11" x-on:click="$dispatch('close-modal', 'reintentar-{{ $web->id }}')">Cancelar</x-secondary-button>
                                                        <x-primary-button class="min-h-11">Reintentar</x-primary-button>
                                                    </div>
                                                </form>
                                            </x-modal>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr class="block md:table-row"><td colspan="8" class="py-10 text-center text-gray-500">{{ $seccion['vacio'] }}
                                @if ($seccion['enlace'])<a href="{{ $seccion['enlace'][1] }}" class="text-indigo-700">{{ $seccion['enlace'][0] }}</a>@endif</td></tr>
                        @endforelse
                        @if ($seccion['filas'] === $atencion && $atencion->hasPages())
                            <tr class="block md:table-row"><td colspan="8" class="border-t border-gray-100 px-4 py-3">{{ $atencion->links() }}</td></tr>
                        @endif
                    </tbody>
                    @endforeach
                </table>
                <div class="border-t border-gray-100 px-4 py-3">{{ $pedidos->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>