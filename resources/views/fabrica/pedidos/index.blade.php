{{-- «Pedidos» de la fábrica: lo que falta mandar a cada sala y lo hecho hoy. Pantalla
     táctil de fábrica (docs/GUIA_PANTALLAS.md, «Pantalla táctil»): botones de min-h-12 con
     py-4/py-5 y letra text-xl/2xl, más grandes que en la oficina. Funciona igual con el
     mouse.

     Se recarga sola cada 30 s, solo si nadie tocó la pantalla (ni el teclado) en los
     últimos 20 s y no hay un formulario enviándose: así no se corren las tarjetas bajo el
     dedo. Sin librerías: Alpine, que ya está. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Pedidos</h1>
        </div>
    </x-slot>

    @php($hoy = \App\Support\HoraNegocio::fechaHoy())

    <div class="py-6 sm:py-8"
         x-data="{ ultimo: Date.now(), enviando: false }"
         @pointerdown.window="ultimo = Date.now()"
         @keydown.window="ultimo = Date.now()"
         x-init="setInterval(() => { if (! enviando && Date.now() - ultimo > 20000) location.reload() }, 30000)">
        <div class="max-w-7xl mx-auto space-y-4 px-4 sm:px-6 lg:px-8">
            <x-rutas.avisos />

            {{-- Lo último se puede deshacer: sin confirmación al hacerlo, con este botón después. --}}
            @if (session('deshacer'))
                @php($deshacer = session('deshacer'))
                <form method="POST" @submit="enviando = true"
                      action="{{ $deshacer['tipo'] === 'envio'
                          ? route('fabrica.pedidos.anular-envio', [$deshacer['pedido'], $deshacer['envio']])
                          : route('fabrica.pedidos.reabrir', $deshacer['pedido']) }}">
                    @csrf @method('PATCH')
                    <button :disabled="enviando"
                            class="inline-flex min-h-12 items-center justify-center rounded-xl border border-gray-300 bg-white px-5 py-4 text-lg font-semibold text-gray-800 hover:bg-gray-50 disabled:opacity-50">Deshacer</button>
                </form>
            @endif

            <nav aria-label="Qué mirar" class="flex flex-wrap gap-3">
                @foreach (['por-mandar' => 'Por mandar ('.$cuantosPorMandar.')', 'hechos' => 'Hechos hoy'] as $valor => $texto)
                    @php($aqui = $ver === $valor)
                    <a href="{{ route('fabrica.pedidos.index', $valor === 'por-mandar' ? [] : ['ver' => $valor]) }}"
                       @if ($aqui) aria-current="page" @endif
                       class="inline-flex min-h-12 items-center justify-center rounded-full px-5 text-lg font-semibold {{ $aqui ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50' }}">{{ $texto }}</a>
                @endforeach
            </nav>

            @if ($ver === 'por-mandar')
                @if ($atrasados->isEmpty() && $deHoy->isEmpty())
                    <div class="rounded-lg border border-gray-200 bg-white px-4 py-10 text-center text-lg text-gray-500">No hay pedidos por mandar.</div>
                @endif

                @foreach (['De días anteriores' => [$atrasados, true], 'De hoy' => [$deHoy, false]] as $titulo => [$lista, $atrasado])
                    @if ($lista->isNotEmpty())
                        <section aria-label="{{ $titulo }}" class="space-y-3">
                            <h2 class="text-lg font-semibold text-gray-800">{{ $titulo }}</h2>
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                                @foreach ($lista as $pedido)
                                    @include('fabrica.pedidos._tarjeta', ['pedido' => $pedido, 'clave' => $claves[$pedido->id], 'atrasado' => $atrasado, 'hoy' => $hoy])
                                @endforeach
                            </div>
                        </section>
                    @endif
                @endforeach
            @else
                <section aria-labelledby="envios-hoy" class="space-y-3">
                    <h2 id="envios-hoy" class="text-lg font-semibold text-gray-800">Envíos de hoy</h2>
                    @if ($hechos['envios']->isEmpty())
                        <div class="rounded-lg border border-gray-200 bg-white px-4 py-10 text-center text-gray-500">Hoy todavía no salió nada.</div>
                    @else
                        @include('rutas.pedidos._envios', ['envios' => $hechos['envios'], 'conSala' => true, 'conDeshacer' => true])
                    @endif
                </section>

                <section aria-labelledby="cerrados" class="space-y-3">
                    <h2 id="cerrados" class="text-lg font-semibold text-gray-800">Cerrados con faltantes o que no se traen</h2>
                    <p class="text-sm text-gray-500">De los últimos {{ \App\Services\Rutas\PedidosFabrica::DIAS_CERRADOS }} días.</p>
                    @if ($hechos['cerrados']->isEmpty())
                        <div class="rounded-lg border border-gray-200 bg-white px-4 py-10 text-center text-gray-500">No hay pedidos cerrados con faltantes.</div>
                    @else
                        <ul class="space-y-3">
                            @foreach ($hechos['cerrados'] as $pedido)
                                <li class="rounded-lg border border-gray-200 bg-white p-4">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-lg font-semibold text-gray-800">{{ $pedido->sala?->nombre }}</p>
                                        <x-rutas.estado-badge :estado="$pedido->estado" />
                                    </div>
                                    <p class="mt-1 text-sm text-gray-600">Del {{ $pedido->fecha->format('d/m/Y') }} · {{ $pedido->ruta?->nombre ?? 'Sin ruta' }}</p>
                                    <ul class="mt-2 space-y-1 text-sm text-gray-700">
                                        @foreach ($pedido->lineas->filter->tienePendiente() as $linea)
                                            <li>Faltó <span class="font-semibold tabular-nums">{{ \App\Models\PedidoSalaLinea::legible($linea->pendiente()) }}</span> × {{ $linea->producto?->nombre }}{{ $linea->motivo_no_enviado ? ' · '.$linea->motivo_no_enviado : '' }}</li>
                                        @endforeach
                                    </ul>
                                    @can('reabrir', $pedido)
                                        <form method="POST" action="{{ route('fabrica.pedidos.reabrir', $pedido) }}" class="mt-3" @submit="enviando = true">
                                            @csrf @method('PATCH')
                                            @if ($pedido->estado === \App\Enums\EstadoPedidoSala::Enviado)
                                                <input type="hidden" name="abrir" value="1">
                                            @endif
                                            <button :disabled="enviando"
                                                    class="inline-flex min-h-12 items-center justify-center rounded-xl border border-gray-300 bg-white px-5 py-4 text-lg font-semibold text-gray-800 hover:bg-gray-50 disabled:opacity-50">
                                                {{ $pedido->estado === \App\Enums\EstadoPedidoSala::Enviado ? 'Mandar lo que faltó' : 'Volver a pendiente' }}
                                            </button>
                                        </form>
                                    @endcan
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif
        </div>
    </div>
</x-app-layout>
