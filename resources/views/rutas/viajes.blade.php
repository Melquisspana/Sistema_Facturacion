<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Rutas</h1>
        </div>
    </x-slot>

    @php
        $T = \App\Services\Rutas\TableroViajes::class;
        $caja = 'rounded-lg border border-gray-200 bg-white';
        $titulo = 'text-xs font-semibold uppercase tracking-wide text-gray-500';
        $insignia = 'inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-medium';
        // Un color por paso, los de la guía: gris sacado, ámbar en fábrica (falta algo),
        // celeste facturado (en camino), verde entregado. Clases completas para Tailwind.
        $pasos = [
            $T::SACADO => ['Sacados', 'Sacado', 'border border-gray-200 bg-gray-50 text-gray-700', 'bg-gray-100 text-gray-700'],
            $T::EN_FABRICA => ['En fábrica', 'En fábrica', 'bg-amber-50 text-amber-800', 'bg-amber-100 text-amber-800'],
            $T::FACTURADO => ['Facturados', 'Facturado', 'bg-sky-50 text-sky-700', 'bg-sky-100 text-sky-700'],
            $T::ENTREGADO => ['Entregados', 'Entregado', 'bg-green-50 text-green-700', 'bg-green-100 text-green-700'],
            $T::NO_SE_TRAE => ['No se trae', 'No se trae', '', 'bg-gray-100 text-gray-600'],
        ];
        $plural = fn (int $n, string $uno, string $varios) => $n.' '.($n === 1 ? $uno : $varios);
        $hace = fn (int $n) => match ($n) { 0 => 'hoy', 1 => 'ayer', default => 'hace '.$n.' días' };
        $verPedido = auth()->user()->can('rutas.pedidos.ver');
    @endphp

    <div class="py-6 sm:py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">

            @include('rutas._pestanas', ['activa' => 'viajes'])

            <x-rutas.avisos />

            {{-- ===================== Viajes abiertos ===================== --}}
            <section aria-labelledby="abiertos-titulo">
                <h2 id="abiertos-titulo" class="{{ $titulo }}">Viajes abiertos</h2>
                <p class="mt-1 text-sm text-gray-600">En qué paso va cada pedido. Lo trabado sale en rojo. Tocá un viaje para ver sus pedidos.</p>

                @if ($abiertos->isEmpty())
                    <div class="{{ $caja }} mt-3 px-6 py-10 text-center text-gray-500">
                        <p class="font-medium text-gray-800">No hay viajes abiertos.</p>
                        <p class="mt-1 text-sm">El viaje se abre solo cuando un vendedor saca el primer pedido.</p>
                    </div>
                @else
                    <ul class="mt-3 space-y-3">
                        @foreach ($abiertos as $viaje)
                            @php $salida = $viaje['salida']; @endphp
                            <li>
                                <details class="{{ $caja }}">
                                    <summary class="block cursor-pointer list-none space-y-3 p-4 hover:bg-gray-50">
                                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                                            <p class="text-base font-semibold text-gray-800">{{ $viaje['nombre'] }}</p>
                                            <p class="text-sm text-gray-600">
                                                Día {{ $viaje['dia'] }} de viaje
                                                · {{ $plural(count($viaje['vendedores']), 'vendedor', 'vendedores') }}
                                                · {{ $plural($viaje['salas'], 'sala', 'salas') }}
                                            </p>
                                        </div>
                                        <p class="text-sm text-gray-600">
                                            {{ implode(', ', $viaje['vendedores']) ?: 'Sin vendedores' }}
                                            · {{ $salida->suma_pedidos ? 'Sigue sacando pedidos' : 'Ya no saca pedidos: falta entregar' }}
                                        </p>
                                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                            @foreach ($T::PASOS as $paso)
                                                <div class="rounded-md p-2 text-center text-sm font-medium {{ $pasos[$paso][2] }}">
                                                    <span class="block text-2xl font-semibold tabular-nums">{{ $viaje['conteo'][$paso] }}</span>
                                                    {{ $pasos[$paso][0] }}
                                                </div>
                                            @endforeach
                                        </div>
                                        @if ($viaje['noSeTrae'] > 0)
                                            <p class="text-xs text-gray-500">Y {{ $plural($viaje['noSeTrae'], 'pedido que la fábrica no trae', 'pedidos que la fábrica no trae') }}.</p>
                                        @endif
                                        {{-- Lo que también lo deja abierto: CCF marcados en el viaje que no son de sus pedidos. --}}
                                        @if ($viaje['sueltos'] > 0)
                                            <p class="text-xs text-gray-500">Además, {{ $viaje['sueltos'] === 1 ? '1 factura de la zona, que no salió de estos pedidos, sigue sin entregar.' : $viaje['sueltos'].' facturas de la zona, que no salieron de estos pedidos, siguen sin entregar.' }}</p>
                                        @endif
                                        @if ($viaje['todoEntregado'] && ! $salida->suma_pedidos)
                                            <p class="text-sm font-medium text-green-700">Todo entregado: se cierra solo mañana temprano.</p>
                                        @endif
                                        @foreach ($viaje['pedidos']->whereNotNull('trabado') as $p)
                                            <p class="text-sm text-gray-700">
                                                <span class="{{ $insignia }} bg-red-100 text-red-700">Trabado</span>
                                                {{ $p['pedido']->sala?->nombre ?? 'Sala' }}: {{ \Illuminate\Support\Str::lcfirst($p['trabado']) }}
                                            </p>
                                        @endforeach
                                        {{-- Sin `group-open:`: no está en el CSS de producción. --}}
                                        <p class="text-sm font-medium text-indigo-600">{{ $viaje['pedidos']->count() === 1 ? 'Ver su pedido' : 'Ver sus '.$viaje['pedidos']->count().' pedidos' }}</p>
                                    </summary>

                                    {{-- El viaje: sus entregas y el PDF de cada día. --}}
                                    <p class="border-t border-gray-100 px-4">
                                        <a href="{{ route('rutas.salidas.show', $salida) }}" class="inline-flex min-h-11 items-center text-sm font-medium text-indigo-600 hover:underline">Ver viaje<span class="sr-only">: {{ $viaje['nombre'] }}</span></a>
                                    </p>
                                    {{-- Los pedidos del viaje: lista de tarjetas, se lee igual en el celular. --}}
                                    <ul class="divide-y divide-gray-100 border-t border-gray-100">
                                        @foreach ($viaje['pedidos'] as $p)
                                            @php $pedido = $p['pedido']; @endphp
                                            <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                                                <div class="min-w-0">
                                                    <p class="font-medium text-gray-800">
                                                        @if ($verPedido)
                                                            <a href="{{ route('rutas.pedidos.show', $pedido) }}" class="text-indigo-600 hover:underline">{{ $pedido->sala?->nombre ?? 'Sala' }}</a>
                                                        @else
                                                            {{ $pedido->sala?->nombre ?? 'Sala' }}
                                                        @endif
                                                    </p>
                                                    <p class="text-xs text-gray-500">
                                                        Sacado el {{ $pedido->fecha->format('d/m/Y') }}{{ $pedido->sacadoPor ? ' por '.$pedido->sacadoPor->nombre : '' }}
                                                        @if ($p['detalle']) · {{ $p['detalle'] }} @endif
                                                    </p>
                                                    @if ($p['trabado'])
                                                        <p class="mt-1 text-sm text-red-700">{{ $p['trabado'] }}</p>
                                                    @endif
                                                </div>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    @if ($p['trabado'])
                                                        <span class="{{ $insignia }} bg-red-100 text-red-700">Trabado</span>
                                                    @endif
                                                    <span class="{{ $insignia }} {{ $pasos[$p['paso']][3] }}">{{ $pasos[$p['paso']][1] }}</span>
                                                    <span class="text-xs text-gray-500">{{ $hace($p['dias']) }}</span>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                </details>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- ===================== Lo que no se pudo entregar dos veces =====================
                 El mismo aviso de «Por entregar», para el jefe: siguen por entregar. --}}
            @if ($fallas->isNotEmpty())
                <section aria-labelledby="fallas-titulo">
                    <h2 id="fallas-titulo" class="{{ $titulo }}">No se pudieron entregar</h2>
                    <p class="mt-1 text-sm text-gray-600">Facturas que ya fallaron {{ \App\Services\Rutas\EntregasCcf::FALLAS_AVISO }} veces o más y siguen por entregar.</p>
                    <ul class="{{ $caja }} mt-3 divide-y divide-gray-100">
                        @foreach ($fallas as $falla)
                            <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="font-medium text-gray-800">{{ $falla['dte']->clienteSucursal?->nombre ?? 'Sala sin identificar' }}</p>
                                    <p class="text-xs text-gray-500"><span class="font-mono">{{ $falla['dte']->numero_control }}</span> · del {{ $falla['dte']->fecha_emision?->format('d/m/Y') }}</p>
                                </div>
                                <span class="{{ $insignia }} bg-red-100 text-red-700">No se pudo {{ $falla['veces'] }} veces</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- ===================== Quedan de las tiendas =====================
                 Lo que el vendedor anotó al entregar: número de quedan y fecha de pago. --}}
            <section aria-labelledby="quedan-titulo">
                <h2 id="quedan-titulo" class="{{ $titulo }}">Quedan por cobrar</h2>
                @if ($quedan->isEmpty())
                    <p class="mt-1 text-sm text-gray-600">No hay quedan de tiendas por cobrar.</p>
                @else
                    <p class="mt-1 text-sm text-gray-600">Los quedan que dejaron las tiendas al entregar: primero los que no tienen fecha de pago, después el pago más próximo.</p>
                    <ul class="{{ $caja }} mt-3 divide-y divide-gray-100">
                        @foreach ($quedan as $entrega)
                            @php $vencido = $entrega->quedan_fecha_pago !== null && $entrega->quedan_fecha_pago->toDateString() < \App\Support\HoraNegocio::fechaHoy(); @endphp
                            <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="font-medium text-gray-800">{{ $entrega->sala?->nombre ?? 'Sala sin identificar' }}</p>
                                    <p class="text-xs text-gray-500">
                                        <span class="font-mono">{{ $entrega->dte?->numero_control }}</span>
                                        · {{ $entrega->quedan_numero ? 'Quedan n.º '.$entrega->quedan_numero : 'Quedan sin número' }}
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="font-semibold tabular-nums text-gray-800">${{ number_format((float) $entrega->dte?->total_pagar, 2) }}</p>
                                    @if ($entrega->quedan_fecha_pago === null)
                                        <p class="text-xs font-medium text-amber-800">Sin fecha de pago</p>
                                    @else
                                        <p class="text-xs {{ $vencido ? 'font-medium text-red-700' : 'text-gray-500' }}">{{ $vencido ? 'Venció el' : 'Paga el' }} {{ $entrega->quedan_fecha_pago->format('d/m/Y') }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- ===================== Viajes cerrados, colapsados ===================== --}}
            <section aria-labelledby="cerrados-titulo">
                <details class="{{ $caja }}">
                    <summary class="flex min-h-11 cursor-pointer items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <span id="cerrados-titulo">Viajes cerrados en los últimos {{ $T::DIAS_CERRADOS }} días ({{ $cerrados->count() }})</span>
                    </summary>
                    @if ($cerrados->isEmpty())
                        <p class="border-t border-gray-100 px-4 py-6 text-center text-sm text-gray-500">No se cerró ningún viaje en estos días.</p>
                    @else
                        <ul class="divide-y divide-gray-100 border-t border-gray-100">
                            @foreach ($cerrados as $viaje)
                                <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                                    <div class="min-w-0">
                                        <p class="font-medium text-gray-800">
                                            <a href="{{ route('rutas.salidas.show', $viaje['salida']) }}" class="text-indigo-600 hover:underline">{{ $viaje['nombre'] }}</a>
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            Del {{ $viaje['salida']->fecha_inicio->format('d/m/Y') }} al {{ $viaje['salida']->fecha_fin_real?->format('d/m/Y') }}
                                            · {{ implode(', ', $viaje['vendedores']) ?: 'Sin vendedores' }}
                                            · {{ $plural($viaje['salas'], 'sala', 'salas') }}
                                        </p>
                                    </div>
                                    <p class="flex flex-wrap gap-2 text-xs">
                                        @foreach ($T::PASOS as $paso)
                                            <span class="{{ $insignia }} {{ $pasos[$paso][3] }}">{{ $plural($viaje['conteo'][$paso], \Illuminate\Support\Str::lower($pasos[$paso][1]), \Illuminate\Support\Str::lower($pasos[$paso][0])) }}</span>
                                        @endforeach
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </details>
            </section>
        </div>
    </div>
</x-app-layout>
