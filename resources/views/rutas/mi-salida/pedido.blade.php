{{-- «Sugerido» de una sala (docs/PLAN_TABLERO_RUTAS.md, PR 9 y 10). Así le dicen al formato
     de la empresa donde el vendedor apunta el pedido y que firma el gerente de la sala.

     Los productos de facturación activos, con buscador (sin JavaScript se ven todos), la OC
     solo si la sala la usa (casi siempre llega después por EDI: se agrega desde la oficina) y
     notas. El sugerido de papel no se sube al sistema (decisión de Melqui, 05/11/2026). --}}
@extends('layouts.vendedor')

@section('content')
    @php
        $caja = 'rounded-2xl bg-white p-4 ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
        $gris = 'text-sm text-gray-600 dark:text-paper-300';
        $campo = 'min-h-11 w-full rounded-lg border-gray-300 dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100';
        $accion = $pedido ? route('rutas.mi-salida.pedidos.update', [$salida, $pedido]) : route('rutas.mi-salida.pedidos.store', [$salida, $sala]);
    @endphp

    <p class="text-sm font-medium text-gray-600 dark:text-paper-300">{{ $salida->ruta->nombre }}</p>
    <h1 class="text-3xl font-bold">Sugerido</h1>
    <p class="mt-1 text-base">{{ $sala->nombre }}</p>

    <form method="POST" action="{{ $accion }}" class="mt-5 space-y-5">
        @csrf
        @if ($pedido)
            @method('PUT')
        @endif

        {{-- Productos: la misma lista de facturación. Las cantidades vacías no se piden. --}}
        <section class="{{ $caja }}" aria-labelledby="productos-titulo" x-data="{ q: '' }">
            <h2 id="productos-titulo" class="text-lg font-bold">Productos</h2>
            <p class="mt-1 {{ $gris }}">Anotá la cantidad de lo que pide la sala. Lo que dejés vacío no se pide.</p>
            <label for="buscar-producto" class="sr-only">Buscar producto</label>
            <input id="buscar-producto" type="search" x-model="q" autocomplete="off" placeholder="Buscar producto…"
                   class="mt-3 {{ $campo }}">
            @error('lineas')<p class="mt-1 text-sm text-red-700 dark:text-red-300" role="alert">{{ $message }}</p>@enderror
            @if ($productos->isEmpty())
                <p class="mt-3 {{ $gris }}">No hay productos activos. Pedile a la oficina que los active en Productos.</p>
            @endif
            <ul class="mt-2 divide-y divide-gray-100 dark:divide-ink-700">
                @foreach ($productos as $producto)
                    <li class="flex items-center justify-between gap-3 py-2"
                        data-buscar="{{ mb_strtolower($producto->nombre.' '.$producto->codigo) }}"
                        x-show="q === '' || $el.dataset.buscar.includes(q.toLowerCase())">
                        <label for="cantidad-{{ $producto->id }}" class="min-w-0">
                            <span class="block text-sm font-medium">{{ $producto->nombre }}</span>
                            <span class="block text-xs text-gray-500 dark:text-paper-300">{{ $producto->codigo }}</span>
                        </label>
                        <input id="cantidad-{{ $producto->id }}" name="cantidades[{{ $producto->id }}]" type="number" inputmode="decimal" min="0" step="any"
                               value="{{ old('cantidades.'.$producto->id, $cantidades[$producto->id] ?? '') }}"
                               class="min-h-11 w-24 shrink-0 rounded-lg border-gray-300 text-right text-lg tabular-nums dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100">
                    </li>
                @endforeach
            </ul>
        </section>

        @if ($conOc)
            <div class="{{ $caja }}">
                <label for="pedido-oc" class="block font-semibold">Número de orden de compra <span class="font-normal text-gray-600 dark:text-paper-300">(opcional)</span></label>
                <input id="pedido-oc" name="numero_orden_compra" inputmode="numeric" maxlength="30" autocomplete="off" aria-describedby="pedido-oc-ayuda"
                       value="{{ old('numero_orden_compra', $pedido?->numero_orden_compra) }}" class="mt-2 font-mono {{ $campo }}">
                <p id="pedido-oc-ayuda" class="mt-1 {{ $gris }}">Casi siempre llega después y la agrega la oficina. Si ya la tenés, son de 13 a 15 números.</p>
                @foreach ($pedido?->avisosOrdenCompra() ?? [] as $aviso)
                    <p class="mt-1 text-sm text-amber-800 dark:text-amber-300">{{ $aviso }} Se guarda igual.</p>
                @endforeach
                @error('numero_orden_compra')<p class="mt-1 text-sm text-red-700 dark:text-red-300" role="alert">{{ $message }}</p>@enderror
            </div>
        @endif

        <div class="{{ $caja }}">
            <label for="pedido-notas" class="block font-semibold">Notas <span class="font-normal text-gray-600 dark:text-paper-300">(opcional)</span></label>
            <textarea id="pedido-notas" name="notas" rows="3" maxlength="500" class="mt-2 w-full rounded-lg border-gray-300 dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100">{{ old('notas', $pedido?->notas) }}</textarea>
            @error('notas')<p class="mt-1 text-sm text-red-700 dark:text-red-300" role="alert">{{ $message }}</p>@enderror
        </div>

        {{-- Cancelar arriba y el principal abajo en el celular; en fila en la computadora. --}}
        <div class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:justify-end">
            <a href="{{ route('rutas.mi-salida.show', $salida) }}#sala-{{ $sala->id }}"
               class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</a>
            <button class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">{{ $pedido ? 'Guardar cambios' : 'Guardar pedido' }}</button>
        </div>
    </form>
@endsection
