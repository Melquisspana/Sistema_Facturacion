{{-- Web: cantidades fijas, sin borrador ni cierre. La factura cubre todo el pedido. --}}
<x-app-layout :pantalla-completa="true">
    @php
        $web = $pedido->pedidoWeb;
        $nombres = collect($web->lineas)->pluck('nombre', 'producto');
    @endphp
    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto space-y-4 px-4 sm:px-6 lg:px-8">
            <a href="{{ route('fabrica.pedidos.index') }}" class="inline-flex min-h-12 items-center rounded-xl px-5 py-4 text-lg font-semibold text-indigo-700">Volver a Pedidos</a>
            <x-rutas.avisos />
            <section class="rounded-lg border border-gray-200 bg-white p-5">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-bold text-gray-800">{{ $pedido->nombreSala() }}</h1>
                    @include('fabrica.pedidos._web-badges')
                </div>
                <p class="mt-3 text-base text-gray-700">
                    {{ $web->codigo }} · Pagado {{ $web->pagado_en->format('H:i') }} ·
                    {{ $web->entrega_tipo === 'recoger' ? 'Para recoger' : 'Con ruta: '.$pedido->zonaNombre() }} ·
                    {{ $web->facturaLista() ? 'Factura lista' : 'Esperando factura' }}
                </p>
                @if ($web->regalo)
                    <p class="mt-2 text-lg font-semibold text-gray-800">Regalo para {{ $web->regalo['nombre'] ?? '' }}</p>
                @endif
            </section>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach ($web->cajitas_detalle as $cajita)
                    @php
                        $clase = match ($cajita['clase'] ?? '') {
                            'gusto' => 'A su gusto',
                            'surtido' => is_string($cajita['surtido'] ?? null) ? $cajita['surtido'] : 'Surtido',
                            'dulce' => $nombres[$cajita['producto'] ?? ''] ?? 'Dulce',
                            default => 'Cajita',
                        };
                    @endphp
                    <section class="rounded-2xl border border-gray-200 bg-white p-5">
                        <h2 class="text-xl font-bold text-gray-800">Cajita {{ $cajita['n'] ?? $loop->iteration }} · {{ $clase }} ({{ $cajita['tam'] ?? '' }}){{ ($cajita['cantidad'] ?? 1) > 1 ? ' × '.$cajita['cantidad'] : '' }}</h2>
                        <ul class="mt-3 space-y-2 text-lg text-gray-700">
                            @foreach (($cajita['contenido'] ?? []) as $id => $cantidad)
                                <li><span class="font-bold tabular-nums">{{ $cantidad }}</span> {{ $nombres[$id] ?? 'Producto sin nombre' }}</li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
            <form method="POST" action="{{ route('fabrica.pedidos.mandar', $pedido) }}" x-data="{ enviando: false }" @submit="enviando = true">
                @csrf
                <input type="hidden" name="clave" value="{{ $clave }}">
                <input type="hidden" name="version" value="{{ $pedido->versionProduccion() }}">
                @foreach ($pedido->lineas as $linea)
                    <input type="hidden" name="cantidades[{{ $linea->id }}]" value="{{ $linea->cantidad }}">
                @endforeach
                <div class="flex flex-wrap items-center gap-3">
                    <a href="{{ route('fabrica.pedidos.index') }}" class="inline-flex min-h-12 items-center justify-center rounded-xl border border-gray-300 bg-white px-5 py-4 text-lg font-semibold text-gray-800 hover:bg-gray-50">Dejar para después</a>
                    <button @disabled(! $web->facturaLista()) :disabled="enviando || @js(! $web->facturaLista())" class="inline-flex min-h-12 items-center justify-center rounded-xl bg-indigo-600 px-5 py-5 text-xl font-bold text-white hover:bg-indigo-700 disabled:opacity-50">{{ $web->facturaLista() ? 'Imprimir y mandar' : 'Esperando factura' }}</button>
                </div>
                @unless ($web->facturaLista())
                    <p class="mt-2 text-sm text-gray-600">La factura sale sola en unos minutos</p>
                @endunless
            </form>
        </div>
    </div>
</x-app-layout>
