<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $cliente->nombre }}
                <span class="ms-2 inline-block rounded-full px-2.5 py-0.5 text-xs font-medium align-middle {{ $cliente->activo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">{{ $cliente->activo ? 'Activo' : 'Inactivo' }}</span>
            </h2>
            <div class="flex gap-2">
                <a href="{{ route('exportaciones.clientes.index') }}" class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Volver</a>
                <a href="{{ route('exportaciones.clientes.edit', $cliente) }}" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Editar cliente</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif

            {{-- Datos del cliente --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6">
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-3 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">Dirección</dt>
                        <dd class="mt-0.5 text-gray-800">{{ $cliente->direccion ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">FDA reg. number</dt>
                        <dd class="mt-0.5 text-gray-800">{{ $cliente->fda_reg_number ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">Contacto</dt>
                        <dd class="mt-0.5 text-gray-800">{{ $cliente->contacto ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Cliente DTE vinculado --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 mb-4">Cliente DTE vinculado</h3>

                @if ($cliente->cliente)
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-sm text-gray-800">
                                <span class="inline-block rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700 align-middle">Vinculado</span>
                                <span class="ms-2 font-medium">{{ $cliente->cliente->nombre }}</span>
                            </p>
                            <p class="mt-1 text-xs text-gray-500">Doc.: {{ $cliente->cliente->num_documento ?? '—' }} · <a href="{{ route('clientes.edit', $cliente->cliente) }}" class="text-indigo-600 hover:underline">ver/editar cliente DTE</a></p>
                        </div>
                        <form method="POST" action="{{ route('exportaciones.clientes.desvincular-cliente-dte', $cliente) }}"
                              onsubmit="return confirm('¿Quitar el vínculo con el Cliente DTE? Esto no borra ningún cliente ni factura.');">
                            @csrf @method('DELETE')
                            <button class="rounded-md bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200">Quitar vínculo</button>
                        </form>
                    </div>
                @else
                    <p class="mb-3 text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                        Cliente DTE no vinculado. Sin este vínculo todavía no se podrá crear una Factura de exportación (FEX) para este cliente.
                    </p>
                    <form method="POST" action="{{ route('exportaciones.clientes.vincular-cliente-dte', $cliente) }}" class="flex flex-wrap items-end gap-3">
                        @csrf @method('PATCH')
                        <div class="grow max-w-md">
                            <label class="block text-xs font-medium text-gray-500">Cliente DTE (tipo exportación)</label>
                            <select name="cliente_id" required class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                <option value="">— buscar y elegir un cliente DTE —</option>
                                @foreach ($clientesDte as $c)
                                    <option value="{{ $c->id }}" @selected((string) old('cliente_id') === (string) $c->id)>
                                        {{ $c->nombre }}{{ $c->num_documento ? ' ('.$c->num_documento.')' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @error('cliente_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            @if ($clientesDte->isEmpty())
                                <p class="mt-1 text-xs text-gray-400">No hay clientes DTE de tipo exportación todavía.</p>
                            @endif
                        </div>
                        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Vincular</button>
                        <a href="{{ route('clientes.create') }}" class="text-sm text-indigo-600 hover:underline">Crear cliente DTE</a>
                    </form>
                @endif
            </div>

            {{-- Lista de precios del cliente --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 border-b border-gray-100">
                    <div>
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Productos, presentaciones y precios del cliente</h3>
                        <label class="mt-1 inline-flex items-center gap-2 text-xs text-gray-500">
                            <input type="checkbox" class="rounded border-gray-300"
                                   onchange="window.location = '{{ route('exportaciones.clientes.show', $cliente) }}' + (this.checked ? '?habilitados=1' : '')"
                                   @checked($soloHabilitados)>
                            Ver solo lo habilitado para este cliente
                        </label>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($otrosClientes->isNotEmpty())
                            <button type="button" onclick="document.getElementById('copiar-precios').classList.toggle('hidden')"
                                    class="rounded-md bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200">
                                Copiar precios desde otro cliente
                            </button>
                        @endif
                        @if ($disponibles->isNotEmpty())
                            <form method="POST" action="{{ route('exportaciones.clientes.productos.asignar-catalogo', $cliente) }}"
                                  onsubmit="return confirm('¿Asignar todos los productos activos del catálogo que faltan, con su precio base? Los que no tienen precio base (o está en $0) quedan fuera. Después podés ajustar los precios uno por uno.');">
                                @csrf
                                <button class="rounded-md bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200"
                                        title="Agrega todo el catálogo activo que falte usando el precio base como punto de partida">
                                    Asignar todo el catálogo (precio base)
                                </button>
                            </form>
                        @endif
                    </div>
                </div>

                {{-- Copiar precios desde otro cliente (oculto hasta pulsar el botón). --}}
                @if ($otrosClientes->isNotEmpty())
                    <form id="copiar-precios" method="POST" action="{{ route('exportaciones.clientes.productos.copiar', $cliente) }}"
                          class="{{ $errors->has('origen_id') || $errors->has('modo') ? '' : 'hidden' }} px-6 py-4 bg-indigo-50 border-b border-indigo-100"
                          onsubmit="return confirm('¿Copiar los productos/precios activos del cliente origen hacia «{{ $cliente->nombre }}»? Las exportaciones ya creadas no cambian.');">
                        @csrf
                        <div class="flex flex-wrap items-end gap-4">
                        <div class="grow max-w-md">
                            <label class="block text-xs font-medium text-gray-600">Cliente origen</label>
                            <select name="origen_id" required class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                <option value="">— elegí el cliente origen —</option>
                                @foreach ($otrosClientes as $otro)
                                    <option value="{{ $otro->id }}" @selected((string) old('origen_id') === (string) $otro->id)>
                                        {{ $otro->nombre }} ({{ $otro->productos_count }} productos)
                                    </option>
                                @endforeach
                            </select>
                            @error('origen_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="space-y-1 text-sm text-gray-700">
                            <label class="flex items-center gap-2">
                                <input type="radio" name="modo" value="conservar" class="border-gray-300" @checked(old('modo', 'conservar') === 'conservar')>
                                No sobrescribir existentes (solo agrega los que faltan)
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="radio" name="modo" value="sobrescribir" class="border-gray-300" @checked(old('modo') === 'sobrescribir')>
                                Sobrescribir precios existentes
                            </label>
                            @error('modo') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Copiar precios</button>
                        </div>
                    </form>
                @endif

                {{-- Agregar producto --}}
                <form method="POST" action="{{ route('exportaciones.clientes.productos.store', $cliente) }}"
                      class="flex flex-wrap items-end gap-3 px-6 py-4 bg-gray-50 border-b border-gray-100"
                      onsubmit="return confirmarPrecioCero(this);">
                    @csrf
                    <div class="grow max-w-md">
                        <label class="block text-xs font-medium text-gray-500">Producto del catálogo</label>
                        <select name="exportacion_producto_id" required class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            <option value="">— elegí un producto —</option>
                            @foreach ($disponibles as $producto)
                                <option value="{{ $producto->id }}" @selected((string) old('exportacion_producto_id') === (string) $producto->id)>
                                    {{ $producto->nombre_es }}{{ $producto->precio_caja !== null ? ' (base $'.number_format((float) $producto->precio_caja, 2).($producto->precioPorUnidad() !== null ? ' · $'.number_format($producto->precioPorUnidad(), 2).'/unid.' : '').')' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('exportacion_producto_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500">Precio por caja ($)</label>
                        <input type="number" name="precio_caja" value="{{ old('precio_caja') }}" required min="0" step="0.01"
                               class="mt-1 w-32 rounded-md border-gray-300 text-sm text-right">
                        @error('precio_caja') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <input type="hidden" name="confirmar_cero" value="0">
                    <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Asignar</button>
                    @if ($disponibles->isEmpty())
                        <p class="text-xs text-gray-400">Todo el catálogo activo ya está asignado a este cliente.</p>
                    @endif
                </form>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200">
                                <th class="py-3 px-4">Producto (es / en)</th>
                                <th class="py-3 px-4">Unidad / empaque</th>
                                <th class="py-3 px-4 text-right">Unid./caja</th>
                                <th class="py-3 px-4 text-right">g/unid.</th>
                                <th class="py-3 px-4 text-right">oz/unid.</th>
                                <th class="py-3 px-4 text-right">Precio base</th>
                                <th class="py-3 px-4 text-right">Precio cliente</th>
                                <th class="py-3 px-4 text-right">Precio unidad</th>
                                <th class="py-3 px-4 text-center">Activo</th>
                                <th class="py-3 px-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($cliente->productos->sortBy(fn ($a) => $a->producto?->nombre_es) as $asignacion)
                                <tr class="hover:bg-gray-50 {{ $asignacion->activo ? '' : 'opacity-60' }}">
                                    <td class="py-3 px-4">
                                        <div class="font-medium text-gray-800">{{ $asignacion->producto?->nombre_es ?? '(producto eliminado)' }}</div>
                                        <div class="text-xs text-gray-500">{{ $asignacion->producto?->nombre_en }}</div>
                                    </td>
                                    <td class="py-3 px-4 text-gray-600">{{ $asignacion->producto?->unidad ?? '—' }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">{{ $asignacion->producto?->unidades_por_caja }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">{{ $asignacion->producto?->gramos_por_unidad !== null ? number_format((float) $asignacion->producto->gramos_por_unidad, 2) : '—' }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">{{ $asignacion->producto?->onzas_por_unidad !== null ? number_format((float) $asignacion->producto->onzas_por_unidad, 2) : '—' }}</td>
                                    <td class="py-3 px-4 text-right text-gray-400">
                                        {{ $asignacion->producto?->precio_caja !== null ? '$'.number_format((float) $asignacion->producto->precio_caja, 2) : '—' }}
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        <form method="POST" action="{{ route('exportaciones.clientes.productos.update', [$cliente, $asignacion]) }}"
                                              class="flex items-center justify-end gap-2" onsubmit="return confirmarPrecioCero(this);">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="confirmar_cero" value="0">
                                            <input type="number" name="precio_caja" value="{{ $asignacion->precio_caja }}" required min="0" step="0.01"
                                                   class="w-28 rounded-md border-gray-300 text-sm text-right font-semibold">
                                            <button class="rounded-md bg-gray-100 px-2 py-1 text-xs text-gray-700 hover:bg-gray-200" title="Guardar precio">Guardar</button>
                                        </form>
                                    </td>
                                    <td class="py-3 px-4 text-right text-gray-600"
                                        title="Precio del cliente ÷ unidades por caja (calculado)">
                                        {{ $asignacion->precioPorUnidad() !== null ? '$'.number_format($asignacion->precioPorUnidad(), 2) : '—' }}
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <form method="POST" action="{{ route('exportaciones.clientes.productos.update', [$cliente, $asignacion]) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="toggle_activo" value="1">
                                            <button class="inline-block rounded-full px-2.5 py-0.5 text-xs font-medium {{ $asignacion->activo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                                {{ $asignacion->activo ? 'Sí' : 'No' }}
                                            </button>
                                        </form>
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        <form method="POST" action="{{ route('exportaciones.clientes.productos.destroy', [$cliente, $asignacion]) }}"
                                              onsubmit="return confirm('¿Quitar este producto de la lista del cliente?');">
                                            @csrf @method('DELETE')
                                            <button class="text-red-600 hover:underline text-xs">Quitar</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="10" class="py-10 text-center text-gray-400">{{ $soloHabilitados ? 'Este cliente no tiene productos habilitados.' : 'Este cliente no tiene productos asignados. Asignale productos con su precio, usá "Asignar todo el catálogo" o copiá los precios de otro cliente.' }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="text-xs text-gray-400">
                Regla: si a otro cliente solo le cambia el PRECIO, es el mismo producto con precio específico aquí.
                Si cambia empaque, presentación, unidades por caja, gramos o pesos, crealo como otra presentación en el
                <a href="{{ route('exportaciones.productos.index') }}" class="text-indigo-600 hover:underline">catálogo maestro</a>.
                El precio por unidad es calculado (precio caja ÷ unidades por caja); no se guarda.
            </p>
        </div>
    </div>

    <script>
        // Precio $0.00 solo pasa si el usuario lo confirma (el servidor también lo exige).
        function confirmarPrecioCero(form) {
            const precio = parseFloat(form.precio_caja.value);
            if (precio === 0) {
                if (!confirm('El precio quedó en $0.00. ¿Confirmás que es intencional?')) return false;
                form.confirmar_cero.value = '1';
            } else {
                form.confirmar_cero.value = '0';
            }
            return true;
        }
    </script>
</x-app-layout>
