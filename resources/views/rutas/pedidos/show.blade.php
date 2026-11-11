{{-- Detalle de un pedido de sala para la oficina (docs/GUIA_PANTALLAS.md, «Fichas y
     detalles»): estado y datos arriba, la OC (que suele llegar después por EDI y se agrega
     acá), los productos y lo que mandó la fábrica, con cómo va la facturación de cada envío.

     Variables: $pedido (con envios.dte) y $regresa [producto_id => llego, facturado,
     regresa] (FacturarPedido::regresa): la columna «Regresa» sale solo si ya se facturó algo.
     «Facturar» (solo quien factura) abre el formulario del CCF con lo que sigue sin facturar;
     con un borrador abierto no sale y se dice por qué: lo nuevo va en ese mismo CCF. El enlace
     lleva `desde=pedido` para que «Cancelar» vuelva a esta ficha. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Pedido de {{ $pedido->nombreSala() }}</h1>
            <a href="{{ route('rutas.pedidos.index') }}"
               class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver a pedidos</a>
        </div>
    </x-slot>

    @php
        $caja = 'rounded-lg border border-gray-200 bg-white p-4 sm:p-5';
        $dt = 'text-xs font-medium text-gray-500';
        $dd = 'mt-0.5 text-sm text-gray-800';
        $avisos = $pedido->avisosOrdenCompra();
        // Sin consultas: los envíos ya traen su CCF.
        $facturacion = \App\Services\Rutas\FacturarPedido::estado($pedido);
        $puedeFacturar = $pedido->estaVigente() && $facturacion['por_facturar']->isNotEmpty() && ! $facturacion['borrador'];
        $llegoDespues = $pedido->estaVigente() && $facturacion['por_facturar']->isNotEmpty() && $facturacion['borrador'] !== null;
    @endphp

    <div class="py-6 sm:py-8">
        <div class="max-w-3xl mx-auto space-y-4 px-4 sm:px-6 lg:px-8">
            <x-rutas.avisos />

            <section class="{{ $caja }}">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <x-rutas.estado-badge :estado="$pedido->estado" />
                    {{-- Barra de acciones de la ficha (x-secondary-button es solo <button>). La hoja,
                         solo si salió algo o ya se cerró: pendiente o anulado no dice nada. --}}
                    <div class="flex flex-wrap gap-2">
                        @if ($pedido->estado->tieneHoja())
                            <a href="{{ route('rutas.pedidos.hoja', $pedido) }}" target="_blank" rel="noopener"
                               class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Imprimir hoja</a>
                        @endif
                        @if ($pedido->esTiendaNueva() && $pedido->estaVigente())
                            {{-- Tienda nueva: antes de facturar se da de alta y se enlaza a su sala. --}}
                            @can('enlazarSala', $pedido)
                                <a href="{{ route('facturacion.pedidos.tienda', $pedido) }}"
                                   class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Dar de alta la tienda</a>
                            @endcan
                        @elseif ($puedeFacturar)
                            @can('create', App\Models\Dte::class)
                                <a href="{{ route('facturacion.create-ccf', ['pedido' => $pedido->id, 'desde' => 'pedido']) }}"
                                   class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Facturar</a>
                            @endcan
                        @endif
                    </div>
                </div>
                @if ($llegoDespues)
                    <p class="mt-3 text-sm text-amber-800">{{ \App\Services\Rutas\FacturarPedido::LLEGO_DESPUES_DEL_BORRADOR }}</p>
                @endif
                <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div><dt class="{{ $dt }}">Sala</dt><dd class="{{ $dd }}">{{ $pedido->nombreSala() }}{{ $pedido->sala?->codigo ? ' · '.$pedido->sala->codigo : '' }}</dd></div>
                    <div><dt class="{{ $dt }}">Fecha</dt><dd class="{{ $dd }}">{{ $pedido->fecha->format('d/m/Y') }}</dd></div>
                    <div><dt class="{{ $dt }}">Zona</dt><dd class="{{ $dd }}">{{ $pedido->ruta?->nombre ?? 'Sin zona' }}</dd></div>
                    {{-- El viaje, por su nombre («Lunes · San Miguel»); una salida armada a mano de antes, por su fecha. --}}
                    <div><dt class="{{ $dt }}">{{ $pedido->salida && ! $pedido->salida->esViaje() ? 'Salida' : 'Viaje' }}</dt><dd class="{{ $dd }}">
                        @if ($pedido->salida)
                            <a href="{{ route('rutas.salidas.show', $pedido->salida) }}" class="text-indigo-600 underline">{{ $pedido->salida->esViaje() ? $pedido->salida->nombreViaje() : $pedido->salida->fecha_inicio->format('d/m/Y') }}</a>
                        @else
                            —
                        @endif
                    </dd></div>
                    <div><dt class="{{ $dt }}">Lo sacó</dt><dd class="{{ $dd }}">{{ $pedido->sacadoPor?->nombre ?? '—' }}</dd></div>
                    <div><dt class="{{ $dt }}">Guardado</dt><dd class="{{ $dd }}">{{ $pedido->created_at->format('d/m/Y H:i') }} · {{ $pedido->registradoPor?->name }}</dd></div>
                    @if ($pedido->notas)
                        <div class="sm:col-span-2"><dt class="{{ $dt }}">Notas</dt><dd class="{{ $dd }} whitespace-pre-line">{{ $pedido->notas }}</dd></div>
                    @endif
                </dl>
            </section>

            {{-- La OC: casi nunca se sabe en la sala, llega después por EDI. --}}
            <section class="{{ $caja }}" aria-labelledby="oc-titulo">
                <h2 id="oc-titulo" class="text-base font-semibold text-gray-800">Orden de compra</h2>
                <p class="mt-1 font-mono text-sm text-gray-800">{{ $pedido->numero_orden_compra ?? 'Todavía sin OC' }}</p>
                @foreach ($avisos as $aviso)
                    <p class="mt-1 text-sm text-amber-800">{{ $aviso }}</p>
                @endforeach
                @can('cambiarOrdenCompra', $pedido)
                    <form method="POST" action="{{ route('rutas.pedidos.orden-compra', $pedido) }}" class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end">
                        @csrf @method('PATCH')
                        <div class="min-w-0 flex-1">
                            <label for="oc" class="block text-sm font-medium text-gray-700">{{ $pedido->numero_orden_compra ? 'Corregir la OC' : 'Agregar la OC' }}</label>
                            <input id="oc" name="numero_orden_compra" inputmode="numeric" maxlength="30" autocomplete="off"
                                   value="{{ old('numero_orden_compra', $pedido->numero_orden_compra) }}"
                                   class="mt-1 min-h-11 w-full rounded-md border-gray-300 font-mono text-sm">
                            <p class="mt-1 text-xs text-gray-500">De 13 a 15 números, del portal EDI. Si la forma no cuadra, avisa y se guarda igual. Vacía, se quita.</p>
                            <x-input-error :messages="$errors->get('numero_orden_compra')" class="mt-1" />
                        </div>
                        <button class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Guardar OC</button>
                    </form>
                @endcan
            </section>

            <section class="rounded-lg border border-gray-200 bg-white" aria-labelledby="productos-titulo">
                <h2 id="productos-titulo" class="px-4 pt-4 text-base font-semibold text-gray-800 sm:px-5">Productos</h2>
                <div class="overflow-x-auto">
                <table class="mt-2 min-w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3 sm:px-5">Producto</th>
                            <th class="px-4 py-3 text-right sm:px-5">Pidió</th>
                            <th class="px-4 py-3 text-right sm:px-5">Mandado</th>
                            @if ($regresa !== [])
                                {{-- Llegó − lo que quedó en los CCF (lo que la OC no pidió y se bajó en el editor). --}}
                                <th class="px-4 py-3 text-right sm:px-5">Regresa</th>
                            @endif
                            <th class="px-4 py-3 text-right sm:px-5">Falta</th>
                            <th class="px-4 py-3 sm:px-5">Motivo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($pedido->lineas as $linea)
                            <tr>
                                <td class="px-4 py-3 sm:px-5">
                                    <span class="block text-gray-800">{{ $linea->producto?->nombre }}</span>
                                    <span class="block text-xs text-gray-500">{{ $linea->producto?->codigo }}</span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums sm:px-5">{{ $linea->cantidadLegible() }}</td>
                                <td class="px-4 py-3 text-right tabular-nums sm:px-5">{{ \App\Models\PedidoSalaLinea::legible($linea->mandado()) }}</td>
                                @if ($regresa !== [])
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums sm:px-5">{{ isset($regresa[$linea->producto_id]) ? \App\Models\PedidoSalaLinea::legible($regresa[$linea->producto_id]['regresa']) : '—' }}</td>
                                @endif
                                <td class="px-4 py-3 text-right tabular-nums sm:px-5">{{ \App\Models\PedidoSalaLinea::legible($linea->pendiente()) }}</td>
                                <td class="px-4 py-3 text-gray-600 sm:px-5">{{ $linea->motivo_no_enviado ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </section>

            {{-- Cada envío por separado, para compararlo con el sugerido y la OC. --}}
            <section class="{{ $caja }}" aria-labelledby="envios-titulo">
                <h2 id="envios-titulo" class="text-base font-semibold text-gray-800">Lo que mandó la fábrica</h2>
                @if ($pedido->envios->isEmpty())
                    <p class="mt-1 text-sm text-gray-500">La fábrica todavía no mandó nada.</p>
                @else
                    <div class="mt-3">@include('rutas.pedidos._envios', ['envios' => $pedido->envios, 'conFacturacion' => true])</div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
