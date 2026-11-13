{{-- El pedido de una sala, en el celular (docs/PLAN_TABLERO_RUTAS.md, PR 9 y 10). «Sugerido»
     le dicen a la HOJA de la empresa que firma el gerente de la sala (rutas.pedidos.hoja);
     esta pantalla no sugiere cantidades, así que se titula «Pedido de <sala>». Desde el
     10/11/2026 no depende de una salida: el pedido se suma solo al viaje del vendedor.

     Los productos de facturación activos, con buscador (sin JavaScript se ven todos), la OC
     solo si la sala la usa (casi siempre llega después por EDI: se agrega desde la oficina) y
     notas. El sugerido de papel no se sube al sistema (decisión de Melqui, 05/11/2026).

     Sin $sala es una tienda nueva: arriba va su nombre ($nombreNueva) y se guarda solo eso.

     Con $sala, arriba va su historia ($historia, de HistoriaSala, sobre la misma fuente del
     tablero): último pedido y sus cajas, última entrega y cómo pagó, cada cuánto compra,
     cuándo toca volver y cuántas facturas tiene por cobrar. Sin precios. --}}
@extends('layouts.vendedor')

@section('content')
    @php
        $caja = 'rounded-2xl bg-white p-4 ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
        $gris = 'text-sm text-gray-600 dark:text-paper-300';
        $campo = 'min-h-11 w-full rounded-lg border-gray-300 dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100';
        $accion = match (true) {
            $pedido !== null => route('rutas.sacar-pedido.update', $pedido),
            $sala !== null => route('rutas.sacar-pedido.store', $sala),
            default => route('rutas.sacar-pedido.tienda-nueva.store'),
        };
    @endphp

    <p class="text-sm font-medium text-gray-600 dark:text-paper-300">{{ $viaje ? 'Tu viaje: '.$viaje->nombreViaje() : 'Tu primer pedido abre tu viaje.' }}</p>
    {{-- No se llama «Sugerido»: el sugerido es la hoja que firma el gerente, y acá no se sugiere nada. --}}
    <h1 class="text-3xl font-bold">Pedido de {{ $sala?->nombre ?? 'una tienda nueva' }}</h1>
    <p class="mt-1 text-base">Anotá las cantidades. Lo que quede vacío no se pide.</p>

    @if ($historia ?? null)
        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 rounded-2xl bg-white p-4 text-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600" aria-label="Historia de la sala">
            <dt class="{{ $gris }}">Último pedido</dt>
            <dd class="text-right font-medium">{{ $historia['ultimoPedido']?->format('d/m/Y') ?? 'Ninguno todavía' }}{{ $historia['cajas'] !== null ? ' · '.$historia['cajas'].' '.($historia['cajas'] === '1' ? 'caja' : 'cajas') : '' }}</dd>
            <dt class="{{ $gris }}">Última entrega</dt>
            <dd class="text-right font-medium">{{ $historia['ultimaEntrega']?->format('d/m/Y') ?? 'Sin entregas' }}{{ $historia['pago'] !== null ? ' · '.$historia['pago'] : '' }}</dd>
            <dt class="{{ $gris }}">Compra cada</dt>
            <dd class="text-right font-medium">{{ $historia['ritmo']->dias !== null ? 'unos '.$historia['ritmo']->dias.' días' : 'Todavía sin ritmo' }}</dd>
            @if ($historia['proxima'])
                <dt class="{{ $gris }}">Próxima visita</dt>
                <dd class="text-right font-medium">
                    {{ $historia['proxima']->format('d/m/Y') }}
                    @if (\App\Services\Rutas\RitmoRutas::leToca($historia))
                        <span class="ml-1 inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">Le toca</span>
                    @endif
                </dd>
            @endif
            {{-- Cuántas, no cuánto: al sacar pedido no se ven precios ni montos. --}}
            <dt class="{{ $gris }}">Facturas por cobrar</dt>
            <dd class="text-right font-medium">{{ $historia['porCobrar'] === 0 ? 'Ninguna' : ($historia['porCobrar'] === 1 ? '1 factura' : $historia['porCobrar'].' facturas') }}</dd>
        </dl>
    @endif

    <form method="POST" action="{{ $accion }}" class="mt-5 space-y-5">
        @csrf
        @if ($pedido)
            @method('PUT')
        @endif

        {{-- Tienda nueva (10/11/2026): solo el nombre. La oficina carga sus datos al facturar. --}}
        @if (! $sala && ! $pedido && ($parecidas ?? collect())->isNotEmpty())
            {{-- Antes, las que ya existen con un nombre parecido: si es una de estas, se saca ahí. --}}
            <section class="{{ $caja }}" aria-labelledby="parecidas-titulo">
                <h2 id="parecidas-titulo" class="text-lg font-bold">¿Es alguna de estas?</h2>
                <ul class="mt-2 divide-y divide-gray-100 dark:divide-ink-700">
                    @foreach ($parecidas as $parecida)
                        <li>
                            <a href="{{ route('rutas.sacar-pedido.create', $parecida) }}" class="flex min-h-11 items-center justify-between gap-3 py-2">
                                <span class="min-w-0">
                                    <span class="block font-semibold">{{ $parecida->nombre }}</span>
                                    <span class="block {{ $gris }}">{{ $parecida->detalleBuscador() }}</span>
                                </span>
                                <span class="shrink-0 text-sm font-medium text-indigo-600 underline dark:text-indigo-300">Es esta</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 {{ $gris }}">Si no es ninguna, seguí abajo: se guarda como tienda nueva.</p>
            </section>
        @endif
        @unless ($sala)
            <div class="{{ $caja }}">
                <label for="sala-nueva-nombre" class="block font-semibold">Nombre de la tienda</label>
                <input id="sala-nueva-nombre" name="sala_nueva_nombre" type="text" required minlength="3" maxlength="100" autocomplete="off"
                       aria-describedby="sala-nueva-ayuda" value="{{ old('sala_nueva_nombre', $nombreNueva) }}" class="mt-2 {{ $campo }}">
                <p id="sala-nueva-ayuda" class="mt-1 {{ $gris }}">Solo el nombre. Sus datos se cargan en la oficina, al facturar.</p>
                @error('sala_nueva_nombre')<p class="mt-1 text-sm text-red-700 dark:text-red-300" role="alert">{{ $message }}</p>@enderror
            </div>
        @endunless

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
            <a href="{{ route('rutas.sacar-pedido.index') }}"
               class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</a>
            <button class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">{{ $pedido ? 'Guardar cambios' : 'Guardar pedido' }}</button>
        </div>
    </form>
@endsection
