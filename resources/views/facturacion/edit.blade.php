<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Editar borrador {{ $dte->tipo_dte->label() }} #{{ $dte->id }}
        </h2>
    </x-slot>

    @php $esFex = $dte->tipo_dte === \App\Enums\TipoDte::FacturaExportacion; @endphp
    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Encabezado compacto: solo información clave del documento + Generar. --}}
            <div class="bg-white shadow sm:rounded-lg p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center flex-wrap gap-x-2 gap-y-1">
                            <span class="font-semibold text-gray-800">{{ $dte->cliente?->nombre ?? 'Sin cliente' }}</span>
                            @if ($dte->clienteSucursal?->nombre)
                                <span class="text-indigo-600 text-sm">— {{ $dte->clienteSucursal->nombre }}</span>
                            @endif
                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">
                                {{ $dte->estado->label() }}
                            </span>
                        </div>
                        <dl class="mt-1.5 flex flex-wrap gap-x-5 gap-y-1 text-xs text-gray-500">
                            <div><dt class="inline text-gray-400">Fecha:</dt> <dd class="inline">{{ $dte->fecha_emision?->format('d/m/Y') ?? '—' }}</dd></div>
                            <div><dt class="inline text-gray-400">Orden de compra:</dt> <dd class="inline">{{ $dte->numero_orden_compra ?? '—' }}</dd></div>
                            <div><dt class="inline text-gray-400">Emisor:</dt> <dd class="inline">{{ $dte->establecimiento?->nombre ?? '—' }} / {{ $dte->puntoVenta?->nombre ?? '—' }}</dd></div>
                            <div><dt class="inline text-gray-400">Retención IVA:</dt> <dd class="inline">{{ $dte->aplica_retencion_iva ? 'Sí' : 'No' }}</dd></div>
                        </dl>
                    </div>
                    @can('update', $dte)
                        <form method="POST" action="{{ route('facturacion.generar', $dte) }}"
                              onsubmit="return confirm('¿Generar el documento? Ya no podrá editarse.');">
                            @csrf
                            <button class="inline-flex items-center px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-md hover:bg-green-700">
                                Generar
                            </button>
                        </form>
                    @endcan
                </div>
            </div>

            {{-- Área de trabajo: productos (principal, ancho) + resumen (panel sticky).
                 En móvil el resumen va primero para que el total quede visible sin bajar. --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

                {{-- Principal: catálogo de productos disponibles con buscador grande. --}}
                @can('update', $dte)
                <div class="order-2 lg:order-1 lg:col-span-2">
                    <div class="bg-white shadow sm:rounded-lg p-5" x-data="{ filtro: '' }">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-semibold text-gray-700">Productos disponibles</h3>
                            <span class="text-xs text-gray-400">{{ count($productosDisponibles) }} producto(s) activos</span>
                        </div>

                        {{-- Buscador grande y prominente (el listado ya está visible; filtrar es opcional). --}}
                        <div class="mb-4">
                            <x-input-label for="filtro-productos" value="Filtrar por nombre, código interno o código de barra" class="sr-only" />
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
                                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd"/></svg>
                                </span>
                                <input id="filtro-productos" type="text" x-model="filtro" autocomplete="off"
                                       placeholder="Buscar por nombre, código interno o código de barra…"
                                       class="block w-full border-gray-300 rounded-lg shadow-sm pl-10 py-2.5 text-base focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                        </div>

                        @if (count($productosDisponibles) === 0)
                            <p class="text-sm text-gray-400">No hay productos activos para agregar.</p>
                        @else
                            <div class="overflow-x-auto max-h-[70vh] overflow-y-auto border border-gray-100 rounded-md">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50 sticky top-0 z-10">
                                        <tr class="text-left text-gray-500">
                                            <th class="px-3 py-2">Código</th>
                                            <th class="px-3 py-2">Código barra</th>
                                            <th class="px-3 py-2">Producto</th>
                                            <th class="px-3 py-2 text-right">Precio aplicado</th>
                                            <th class="px-3 py-2">Cantidad</th>
                                            <th class="px-3 py-2">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($productosDisponibles as $p)
                                            <tr x-show="filtro === '' || @js($p['filtro']).includes(filtro.toLowerCase().trim())"
                                                class="hover:bg-gray-50">
                                                <td class="px-3 py-2 font-mono">{{ $p['codigo'] ?? '—' }}</td>
                                                <td class="px-3 py-2 font-mono text-gray-500">{{ $p['codigo_barra'] ?? '—' }}</td>
                                                <td class="px-3 py-2 font-medium">{{ $p['nombre'] }}</td>
                                                <td class="px-3 py-2 text-right">
                                                    @if ($p['sin_precio'])
                                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">Sin precio</span>
                                                    @else
                                                        <span class="font-mono">${{ $p['precio_fmt'] }}</span>
                                                        <span class="block text-[10px] {{ $p['es_especial'] ? 'text-indigo-600' : 'text-gray-400' }}">{{ $p['origen_label'] }}</span>
                                                    @endif
                                                </td>
                                                @if ($p['sin_precio'])
                                                    <td colspan="2" class="px-3 py-2 text-xs text-gray-400">No se puede agregar sin precio.</td>
                                                @else
                                                    @php $qty = $cantidadesPorProducto[$p['id']] ?? null; @endphp
                                                    <td colspan="2" class="px-3 py-2">
                                                        {{-- Auto-agregar: al escribir una cantidad (>0) se agrega/actualiza la línea;
                                                             0 o vacío la quita. Idempotente por producto (no duplica). El botón es respaldo. --}}
                                                        <form method="POST" action="{{ route('facturacion.productos.cantidad', [$dte, $p['id']]) }}"
                                                              class="flex items-end gap-2">
                                                            @csrf
                                                            <div>
                                                                <label class="sr-only" for="cant-add-{{ $p['id'] }}">Cantidad</label>
                                                                {{-- Cantidad entera: step 1, min 0 (0/vacío quita la línea). --}}
                                                                <input id="cant-add-{{ $p['id'] }}" type="number" name="cantidad"
                                                                       value="{{ $qty ?? '' }}" step="1" min="0" inputmode="numeric" placeholder="0"
                                                                       onchange="this.form.requestSubmit()"
                                                                       class="block w-20 border-gray-300 rounded-md shadow-sm text-sm {{ $qty ? 'ring-1 ring-indigo-300 bg-indigo-50/50' : '' }}">
                                                            </div>
                                                            <button class="px-3 py-2 {{ $qty ? 'bg-gray-600 hover:bg-gray-700' : 'bg-indigo-600 hover:bg-indigo-700' }} text-white text-sm rounded-md">
                                                                {{ $qty ? 'Actualizar' : 'Agregar' }}
                                                            </button>
                                                        </form>
                                                    </td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
                @endcan

                {{-- Panel lateral: resumen del CCF (productos agregados + totales + Generar), sticky. --}}
                <div class="order-1 lg:order-2 @cannot('update', $dte) lg:col-span-3 @endcannot">
                    <div class="lg:sticky lg:top-6">
                        @include('facturacion.partials.resumen-ccf', ['dte' => $dte, 'esAgenteRetencion' => $esAgenteRetencion ?? null])
                    </div>
                </div>
            </div>

            <div>
                <a href="{{ route('facturacion.index') }}" class="text-sm text-gray-500 hover:underline">← Volver al listado</a>
            </div>
        </div>
    </div>
</x-app-layout>
