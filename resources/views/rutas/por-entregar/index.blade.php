{{-- «Por entregar» (06/11/2026): todos los CCF que faltan entregar, por la ruta de su sala,
     para el celular del vendedor. Desde el 10/11/2026, con su viaje en curso, arriba elige
     «Este viaje», «Toda la zona» o «Todas»; al entregar dice cómo le pagaron. Arriba, pastillas para mirar una ruta (?ruta=, no guarda
     nada). Cada tarjeta descarga el PDF y marca «Entregado» o «No se pudo entregar»: el
     sistema elige o arma la salida (App\Services\Rutas\PorEntregar). Lo marcado hoy queda
     abajo, con «Deshacer». --}}
@extends('layouts.vendedor')

@section('titulo', 'Por entregar')

@section('content')
    @php
        $caja = 'rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none';
        $pastilla = 'inline-flex min-h-11 shrink-0 items-center rounded-full px-4 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600';
        $corto = fn ($dte) => $dte?->numero_control ? '…'.\App\Support\OrdenCompra::ultimosDigitos($dte->numero_control) : '—';
    @endphp

    <h1 class="text-3xl font-bold">Por entregar</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-paper-300">{{ $total }} CCF de los últimos {{ \App\Services\Rutas\EntregasCcf::DIAS_POR_ENTREGAR }} días. Los más viejos van primero.</p>
    @if ($cobroHoy)
        <p class="mt-1 text-xs text-gray-500 dark:text-paper-300">Al terminar, la oficina recibe el efectivo de hoy.</p>
    @endif

    @if (! $persona && auth()->user()->can('rutas.entregas.registrar'))
        <p class="mt-4 rounded-2xl bg-white p-5 dark:bg-ink-800">Tu usuario no está enlazado a una persona de Rutas. Pedile a la oficina que lo enlace.</p>
    @endif

    {{-- Con su viaje en curso: «Este viaje», «Toda la zona» y «Todas» (?ver=, no guarda nada). --}}
    @if ($vistas !== [])
        @php
            $nombres = ['viaje' => 'Este viaje', 'zona' => 'Toda la zona', 'todas' => 'Todas'];
        @endphp
        <nav aria-label="Qué mostrar" class="mt-4 flex flex-wrap gap-2">
            @foreach ($vistas as $clave => $cuantos)
                <a href="{{ route('rutas.por-entregar.index', ['ver' => $clave]) }}" @if ($ver === $clave) aria-current="page" @endif
                   class="{{ $pastilla }} {{ $ver === $clave ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-ink-800 dark:text-paper-100 dark:ring-ink-500' }}">{{ $nombres[$clave] }} {{ $cuantos }}</a>
            @endforeach
        </nav>
    @endif

    @if ($grupos->isNotEmpty() && $ver === 'todas')
        <nav aria-label="Qué ruta mostrar" class="mt-4 flex flex-wrap gap-2">
            <a href="{{ request()->fullUrlWithQuery(['ruta' => null]) }}" @if ($ruta === null) aria-current="page" @endif
               class="{{ $pastilla }} {{ $ruta === null ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-ink-800 dark:text-paper-100 dark:ring-ink-500' }}">Todas {{ $total }}</a>
            @foreach ($grupos as $grupo)
                <a href="{{ request()->fullUrlWithQuery(['ruta' => $grupo['clave']]) }}" @if ($ruta === $grupo['clave']) aria-current="page" @endif
                   class="{{ $pastilla }} {{ $ruta === $grupo['clave'] ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-ink-800 dark:text-paper-100 dark:ring-ink-500' }}">{{ $grupo['nombre'] }} {{ $grupo['dtes']->count() }}</a>
            @endforeach
        </nav>
    @endif

    @if ($filtroInvalido)
        <div class="{{ $caja }} mt-4 px-5 py-8 text-center">
            <p class="text-base font-medium text-gray-800 dark:text-paper-100">Esa ruta no tiene CCF por entregar.</p>
            <a href="{{ request()->fullUrlWithQuery(['ruta' => null]) }}" class="mt-2 inline-flex min-h-11 items-center text-sm text-indigo-600 underline dark:text-indigo-300">Ver todas</a>
        </div>
    @elseif ($grupos->isEmpty())
        <div class="{{ $caja }} mt-4 px-5 py-10 text-center">
            <p class="text-base font-medium text-gray-800 dark:text-paper-100">No hay CCF por entregar</p>
            <p class="mt-1 text-sm text-gray-500 dark:text-paper-300">Lo que se facture aparece acá solo.</p>
        </div>
    @endif

    @foreach ($visibles as $grupo)
        <section aria-labelledby="ruta-{{ $grupo['clave'] }}">
            <h2 id="ruta-{{ $grupo['clave'] }}" class="mt-6 text-xl font-semibold">{{ $grupo['nombre'] }} <span class="text-sm font-normal text-gray-500 dark:text-paper-300">· {{ $grupo['dtes']->count() }} CCF</span></h2>
            @foreach ($grupo['dtes'] as $dte)
                @php
                    $camino = $enCamino->get($dte->id);
                    $planificada = $camino?->salida->estado === \App\Enums\EstadoSalidaRuta::Planificada;
                @endphp
                <div class="{{ $caja }} mt-3 px-4 py-3">
                    <p class="truncate text-xs font-medium text-gray-500 dark:text-paper-300">{{ $dte->clienteSucursal?->nombre ?? 'Sala sin identificar' }}</p>
                    <p class="truncate text-sm text-gray-800 dark:text-paper-100">{{ $dte->cliente?->nombre_comercial ?: $dte->cliente?->nombre }}</p>
                    @php
                        $pedidoDte = $pedidos->get($dte->id);
                        $quien = $pedidoDte?->sacadoPor;
                        // Lo que la fábrica no mandó, con el pedido ya cerrado: solo para avisar.
                        $noVino = $pedidoDte && $pedidoDte->estado->cerrado() ? $pedidoDte->lineas->filter->tienePendiente() : collect();
                    @endphp
                    @if ($quien && $quien->id !== $persona?->id)
                        <p class="text-xs text-gray-500 dark:text-paper-300">Lo sacó {{ $quien->nombre }}</p>
                    @endif
                    @foreach ($noVino as $linea)
                        @php
                            $cajas = \App\Models\PedidoSalaLinea::legible($linea->pendiente());
                        @endphp
                        <p class="mt-1 text-sm text-amber-800 dark:text-amber-300">No vino: {{ $cajas }} {{ $cajas === '1' ? 'caja' : 'cajas' }} de {{ $linea->producto?->nombre }}</p>
                    @endforeach
                    @if (($fallas[$dte->id] ?? 0) >= \App\Services\Rutas\EntregasCcf::FALLAS_AVISO)
                        <p class="mt-1 text-sm font-medium text-red-700 dark:text-red-300">No se pudo entregar {{ $fallas[$dte->id] }} veces: avisale al jefe.</p>
                    @endif
                    <div class="mt-1 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1">
                            <p class="font-mono text-sm text-gray-600 dark:text-paper-300" title="{{ $dte->numero_control }}">
                                <span aria-hidden="true">{{ $corto($dte) }}</span>
                                <span class="sr-only">{{ $dte->numero_control }}</span>
                            </p>
                            <p class="text-sm text-gray-600 dark:text-paper-300">{{ $dte->fecha_emision?->format('d/m') }}</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-paper-100">${{ number_format((float) $dte->total_pagar, 2) }}</p>
                        </div>
                        @if ($pedidoDte)
                            <a href="{{ route('rutas.por-entregar.hoja', $dte) }}" target="_blank" rel="noopener"
                               class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-ink-500 dark:bg-ink-800 dark:text-paper-100">
                                Hoja para firmar<span class="sr-only"> del CCF {{ $dte->numero_control }}</span>
                            </a>
                        @endif
                        <a href="{{ route('rutas.por-entregar.pdf', $dte) }}" download
                           class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-ink-500 dark:bg-ink-800 dark:text-paper-100">
                            Descargar PDF<span class="sr-only"> del CCF {{ $dte->numero_control }}</span>
                        </a>
                    </div>

                    @if ($grupo['clave'] === $sinRuta && $vistas === [])
                        <p class="mt-3 text-sm text-amber-800 dark:text-amber-300">{{ \App\Services\Rutas\PorEntregar::SIN_RUTA }}</p>
                    @elseif ($planificada)
                        <p class="mt-3 text-sm text-gray-600 dark:text-paper-300">Va en la salida {{ $camino->salida->descripcionCorta() }}, que todavía no empezó: se marca cuando la empiecen.</p>
                    @elseif ($trabados->has($dte->id))
                        {{-- Día cerrado, o una salida formal donde no va: los botones siempre fallarían. --}}
                        <p class="mt-3 text-sm text-amber-800 dark:text-amber-300">{{ $trabados->get($dte->id) }}</p>
                    @elseif ($puedeMarcar)
                        @if ($camino)
                            <p class="mt-2 text-xs text-gray-500 dark:text-paper-300">Va en la salida {{ $camino->salida->descripcionCorta() }}: se marca ahí.</p>
                        @endif
                        @include('rutas.salidas._marcar', [
                            'accion' => route('rutas.por-entregar.marcar', $dte),
                            'metodo' => 'POST',
                            'clave' => 'dte-'.$dte->id,
                            'habitual' => $dte->cliente?->cobro_en_entrega,
                            'textoNo' => 'No se pudo entregar',
                            'pidePago' => true,
                            'portal' => $portal !== null && (int) $dte->cliente_id === $portal,
                        ])
                    @endif
                </div>
            @endforeach
        </section>
    @endforeach

    @if ($marcados->isNotEmpty())
        <section aria-labelledby="marcados-hoy">
            <h2 id="marcados-hoy" class="mt-8 text-xl font-semibold">Marcados hoy</h2>
            @foreach ($marcados as $entrega)
                <div class="{{ $caja }} mt-3 px-4 py-3">
                    <p class="truncate text-xs font-medium text-gray-500 dark:text-paper-300">{{ $entrega->sala?->nombre ?? 'Sala sin identificar' }} · {{ $entrega->salida->descripcionCorta() }}</p>
                    <div class="mt-1 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1">
                            <p class="font-mono text-sm text-gray-600 dark:text-paper-300" title="{{ $entrega->dte?->numero_control }}">
                                <span aria-hidden="true">{{ $corto($entrega->dte) }}</span>
                                <span class="sr-only">{{ $entrega->dte?->numero_control }}</span>
                            </p>
                            <p class="text-sm font-medium text-gray-800 dark:text-paper-100">${{ number_format((float) $entrega->dte?->total_pagar, 2) }}</p>
                        </div>
                        <div class="text-right">
                            @if ($entrega->resultado === \App\Enums\ResultadoEntrega::Entregado)
                                <p class="text-sm font-medium text-green-700 dark:text-green-400">✓ Entregado</p>
                                <p class="text-xs text-gray-500 dark:text-paper-300">{{ $entrega->fecha_resultado?->format('H:i') }}{{ $entrega->trae_nota_averia ? ' · con nota de avería' : '' }}</p>
                            @else
                                <p class="text-sm font-medium text-red-700 dark:text-red-400">✗ No se pudo entregar</p>
                                <p class="text-xs text-gray-500 dark:text-paper-300">{{ $entrega->motivo_no_entrega?->label() }}{{ $entrega->nota ? ' · '.$entrega->nota : '' }}</p>
                            @endif
                            @can('deshacer', [$entrega->salida, $entrega])
                                <form method="POST" action="{{ route('rutas.mi-salida.deshacer', [$entrega->salida, $entrega]) }}" class="mt-1">
                                    @csrf @method('PATCH')
                                    <button class="min-h-11 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600 text-xs text-gray-400 hover:underline dark:text-paper-500">Deshacer</button>
                                </form>
                            @endcan
                        </div>
                    </div>
                </div>
            @endforeach
        </section>
    @endif
@endsection
