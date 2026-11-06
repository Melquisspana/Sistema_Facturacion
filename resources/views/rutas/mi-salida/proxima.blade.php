{{-- «Tu próxima salida» (docs/PLAN_TABLERO_RUTAS.md, PR 8): la salida planificada, solo
     para mirar hasta que el vendedor toca «Empezar ruta». Mismas cajas que el resto de
     «Mi salida» y los colores del tablero para las salas. --}}
@extends('layouts.vendedor')

@section('content')
    @php
        $S = \App\Services\Rutas\Ritmo::class;
        // Colores de la guía: rojo atrasada, ámbar toca pronto. Clases completas para que Tailwind las vea.
        $insignias = [
            $S::ATRASADA => ['Atrasada', 'bg-red-100 text-red-700'],
            $S::PRONTO => ['Toca pronto', 'bg-amber-100 text-amber-800'],
        ];
        $caja = 'rounded-2xl bg-white p-4 ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
        $titulo = 'text-xl font-semibold';
        $gris = 'text-sm text-gray-600 dark:text-paper-300';
        $fecha = fn ($f) => $f->copy()->locale('es')->dayName.' '.$f->format('d/m/Y');
        $dias = fn (int $n) => $n.' '.($n === 1 ? 'día' : 'días');
    @endphp

    <p class="text-sm font-medium text-gray-600 dark:text-paper-300">Tu próxima salida</p>
    <h1 class="text-3xl font-bold">{{ $salida->ruta->nombre }}</h1>
    <p class="mt-1 text-base">
        <time datetime="{{ $salida->fecha_inicio->toDateString() }}">{{ ucfirst($fecha($salida->fecha_inicio)) }}</time>
        · {{ $salida->proposito->descripcion() }}
    </p>
    <p class="mt-2 text-base">{{ $companeros !== '' ? 'Vas con '.$companeros : 'Nadie más va en esta salida.' }}</p>

    {{-- Empezar: solo el día de la salida o después. Antes, solo se mira. --}}
    <div class="mt-5 {{ $caja }}">
        @if ($puedeEmpezar)
            <form method="POST" action="{{ route('rutas.mi-salida.empezar', $salida) }}">
                @csrf
                <button class="inline-flex min-h-11 w-full items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Empezar ruta</button>
            </form>
            <p class="mt-2 {{ $gris }}">Al empezarla vas a poder marcar las entregas.</p>
        @else
            <p class="text-base">La podés empezar el {{ $fecha($salida->fecha_inicio) }}.</p>
            <p class="mt-1 {{ $gris }}">Hasta entonces es solo para mirar: no se marca nada.</p>
        @endif
    </div>

    {{-- Salas que tocan sacar pedido, con el ritmo del tablero calculado al día de la salida. --}}
    <section class="mt-6" aria-labelledby="salas-titulo">
        <h2 id="salas-titulo" class="{{ $titulo }}">Salas que tocan sacar pedido</h2>
        <p class="mt-1 {{ $gris }}">Para el día de la salida. Las atrasadas primero.</p>
        @if ($tocan->isEmpty())
            <p class="mt-3 {{ $caja }}">Ninguna sala de esta ruta está atrasada ni toca pronto.</p>
        @else
            <ul class="mt-3 space-y-3">
                @foreach ($tocan as $fila)
                    @php
                        [$estadoTexto, $estadoClase] = $insignias[$fila['estado']];
                        $ultimo = $fila['ritmo']->ultima();
                    @endphp
                    <li class="{{ $caja }}">
                        <div class="flex items-start justify-between gap-3">
                            <p class="min-w-0 font-medium">{{ $fila['sala']->nombre }}</p>
                            <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $estadoClase }}">{{ $estadoTexto }}</span>
                        </div>
                        <p class="mt-1 {{ $gris }}">
                            @if ($ultimo)
                                Último pedido el <time datetime="{{ $ultimo->toDateString() }}">{{ $ultimo->format('d/m/Y') }}</time> ·
                            @endif
                            Compra cada {{ $dias($fila['ritmo']->dias) }}{{ $fila['ritmo']->confianza() === $S::APROXIMADO ? ' (aprox.)' : '' }}
                        </p>
                        @if ($fila['dejoDeComprar'])
                            <p class="mt-1 text-sm font-medium text-red-700">¿Dejó de comprar?</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($otrasSalas > 0)
            <p class="mt-3 {{ $gris }}">{{ $otrasSalas === 1 ? 'La otra sala de la ruta está al día o todavía sin ritmo.' : 'Las otras '.$otrasSalas.' salas de la ruta están al día o todavía sin ritmo.' }}</p>
        @endif
    </section>

    {{-- CCF que lleva la salida: la cantidad y, si son pocos, cuáles. La que solo saca pedido no lleva. --}}
    @if ($salida->proposito->entrega())
    <section class="mt-6" aria-labelledby="ccf-titulo">
        <h2 id="ccf-titulo" class="{{ $titulo }}">CCF por entregar</h2>
        <div class="mt-3 {{ $caja }}">
            @if ($entregas->isEmpty())
                <p class="text-base">Esta salida todavía no lleva CCF.</p>
            @else
                <p class="text-base font-semibold">{{ $entregas->count() }} CCF · ${{ number_format($totalCcf, 2) }}</p>
                @if ($listarCcf)
                    <ul class="mt-2 divide-y divide-gray-100">
                        @foreach ($entregas as $entrega)
                            <li class="flex flex-wrap items-baseline justify-between gap-x-2 py-2">
                                <span class="min-w-0 truncate text-sm">{{ $entrega->sala?->nombre ?? 'Sala sin identificar' }}</span>
                                <span class="text-sm text-gray-600 dark:text-paper-300">
                                    <span class="font-mono" title="{{ $entrega->dte?->numero_control }}">…{{ \App\Support\OrdenCompra::ultimosDigitos($entrega->dte?->numero_control) }}</span>
                                    · ${{ number_format((float) $entrega->dte?->total_pagar, 2) }}
                                    @if (isset($noVigentes[$entrega->dte_id]))
                                        <span class="rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $noVigentes[$entrega->dte_id] }} · no entregar</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-1 {{ $gris }}">La lista completa aparece al empezar la ruta.</p>
                @endif
            @endif
        </div>
    </section>
    @endif

    @if ($despues->isNotEmpty())
        <section class="mt-6" aria-labelledby="despues-titulo">
            <h2 id="despues-titulo" class="{{ $titulo }}">Después</h2>
            <ul class="mt-3 space-y-3">
                @foreach ($despues as $otra)
                    <li>
                        <a href="{{ route('rutas.mi-salida.show', $otra) }}" class="block min-h-11 rounded-2xl bg-white p-5 ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                            <span class="block text-xl font-semibold">{{ $otra->ruta->nombre }}</span>
                            <span class="{{ $gris }}">{{ ucfirst($fecha($otra->fecha_inicio)) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
