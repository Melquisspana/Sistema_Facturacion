<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Pedidos web</h1>
        </div>
    </x-slot>
    <div class="py-6">
        <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6 lg:px-8">
            <x-rutas.avisos />
            <p class="text-sm text-gray-600">Primero los pedidos para revisar y los que llevan más de 30 minutos facturando. Si un pedido ya tiene factura, corregila en Facturación.</p>
            <x-filtros-celular :activos="$estado !== '' ? 1 : 0" id="filtros-pedidos-web">
                <form method="GET" action="{{ route('facturacion.pedidos-web.index') }}" class="flex flex-wrap items-end gap-3">
                    <div>
                        <label for="estado" class="block text-sm font-medium text-gray-700">Estado</label>
                        <select id="estado" name="estado" class="min-h-11 rounded-md border-gray-300">
                            @foreach (['' => 'Requieren atención', 'todos' => 'Todos', 'importado' => 'Importado', 'facturando' => 'Facturando', 'facturado' => 'Facturado', 'para_revisar' => 'Para revisar'] as $valor => $texto)
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
                    <tbody class="block md:table-row-group">
                        @forelse ($pedidos as $web)
                            <tr class="grid grid-cols-2 border-t border-gray-100 md:table-row">
                                <td class="col-span-2 px-4 py-3 text-sm font-semibold text-gray-800">{{ $web->codigo }}</td>
                                <td class="px-4 py-3 text-sm tabular-nums"><span class="block text-xs text-gray-500 md:hidden">Pago</span>{{ $web->pagado_en->format('d/m/Y H:i') }}</td>
                                <td class="col-span-2 px-4 py-3 text-sm"><span class="block text-xs text-gray-500 md:hidden">Cliente</span>{{ $web->cliente_nombre }}</td>
                                <td class="px-4 py-3 text-sm"><span class="block text-xs text-gray-500 md:hidden">Entrega</span>{{ $web->entrega_tipo === 'recoger' ? 'Recoger' : 'Ruta · '.($web->pedidoSala?->ruta?->nombre ?? $web->pedidoSala?->salida?->ruta?->nombre ?? 'Por asignar') }}</td>
                                <td class="px-4 py-3 text-right text-sm tabular-nums"><span class="block text-xs text-gray-500 md:hidden">Total</span>${{ \App\Services\Gastos\Dinero::mostrar($web->total_centavos) }}</td>
                                <td class="px-4 py-3"><x-planta.badge :color="$web->estado->color()">{{ $web->estado->etiqueta() }}</x-planta.badge></td>
                                <td class="col-span-2 px-4 py-3 text-sm">{{ $web->estado === \App\Enums\EstadoPedidoWeb::ParaRevisar ? $web->motivo_revision : '' }}</td>
                                <td class="col-span-2 px-4 py-3 text-right text-sm">
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
                            <tr class="block md:table-row"><td colspan="8" class="py-10 text-center text-gray-500">Nada coincide con estos filtros. <a href="{{ route('facturacion.pedidos-web.index', ['estado' => 'todos']) }}" class="text-indigo-700">Ver todos</a></td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="border-t border-gray-100 px-4 py-3">{{ $pedidos->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>