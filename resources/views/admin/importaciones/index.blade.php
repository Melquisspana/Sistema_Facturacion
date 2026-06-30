<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Importaciones / Exportaciones</h2>
    </x-slot>

    <div class="py-8" x-data="{ clienteId: @js((string) ($clientes->first()->id ?? '')) }">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if ($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                    <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            {{-- Resumen + detalle de la última importación --}}
            @if (session('resumen'))
                @php
                    $r = session('resumen');
                    // Clases literales para que el JIT de Tailwind las incluya.
                    $accionClases = [
                        'creado' => 'bg-green-100 text-green-700',
                        'actualizado' => 'bg-indigo-100 text-indigo-700',
                        'ignorado' => 'bg-amber-100 text-amber-700',
                        'advertencia' => 'bg-yellow-100 text-yellow-800',
                        'error' => 'bg-red-100 text-red-700',
                    ];
                @endphp
                <div class="bg-white shadow sm:rounded-lg p-6">
                    <h3 class="font-semibold text-gray-700 mb-3">{{ session('resumen_titulo', 'Resumen de importación') }}</h3>

                    <div class="flex flex-wrap gap-3 text-sm mb-4">
                        <span class="px-2 py-1 rounded bg-gray-100 text-gray-700">Leídas: <strong>{{ $r['leidas'] }}</strong></span>
                        <span class="px-2 py-1 rounded bg-green-100 text-green-700">Creadas: <strong>{{ $r['creadas'] }}</strong></span>
                        <span class="px-2 py-1 rounded bg-indigo-100 text-indigo-700">Actualizadas: <strong>{{ $r['actualizadas'] }}</strong></span>
                        <span class="px-2 py-1 rounded bg-amber-100 text-amber-700">Ignoradas: <strong>{{ $r['ignoradas'] }}</strong></span>
                        <span class="px-2 py-1 rounded bg-yellow-100 text-yellow-800">Advertencias: <strong>{{ $r['advertencias'] }}</strong></span>
                        <span class="px-2 py-1 rounded bg-red-100 text-red-700">Errores: <strong>{{ $r['errores'] }}</strong></span>
                    </div>

                    @if (! empty($r['detalles']))
                        <div class="overflow-x-auto max-h-96">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="sticky top-0 bg-white">
                                    <tr class="text-left text-gray-500">
                                        <th class="px-3 py-2">Fila</th>
                                        <th class="px-3 py-2">Acción</th>
                                        <th class="px-3 py-2">Nombre / producto</th>
                                        <th class="px-3 py-2">Detalle</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($r['detalles'] as $d)
                                        <tr>
                                            <td class="px-3 py-2 text-gray-500">{{ $d['fila'] }}</td>
                                            <td class="px-3 py-2">
                                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $accionClases[$d['accion']] ?? 'bg-gray-100 text-gray-700' }}">
                                                    {{ ucfirst($d['accion']) }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 font-medium">{{ $d['nombre'] }}</td>
                                            <td class="px-3 py-2 text-gray-600">{{ $d['detalle'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif

            <div class="bg-white shadow sm:rounded-lg p-6">
                <label class="block text-sm text-gray-600 mb-1">Cliente</label>
                <select x-model="clienteId" class="block w-full md:w-1/2 border-gray-300 rounded-md shadow-sm text-sm">
                    @foreach ($clientes as $c)
                        <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                    @endforeach
                </select>
                @if ($clientes->isEmpty())
                    <p class="mt-2 text-xs text-amber-600">No hay clientes contribuyentes activos.</p>
                @endif
                <p class="mt-2 text-xs text-gray-400">Las acciones de abajo usan el cliente seleccionado. Formato: <strong>CSV</strong> (.csv).</p>
            </div>

            {{-- Instrucciones --}}
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-sm text-blue-800">
                <p class="font-medium mb-1">Cómo preparar el archivo</p>
                <ul class="list-disc list-inside space-y-0.5 text-xs">
                    <li>Guardar desde Excel como <strong>CSV UTF‑8</strong>.</li>
                    <li>Las direcciones con comas deben ir <strong>entre comillas</strong>.</li>
                    <li>Se acepta <strong>coma</strong> o <strong>punto y coma</strong> como separador.</li>
                    <li>Solo se importan productos con <strong>precio numérico</strong>.</li>
                    <li>Importar dos veces <strong>no duplica</strong>.</li>
                    <li>Usá las <strong>plantillas</strong> de cada sección para evitar errores de formato.</li>
                </ul>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                {{-- Salas --}}
                <div class="bg-white shadow sm:rounded-lg p-6 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="font-semibold text-gray-700">Salas / sucursales</h3>
                        <a href="{{ route('importaciones.salas.plantilla') }}" class="text-xs text-indigo-600 hover:underline">Descargar plantilla</a>
                    </div>

                    <form method="POST" action="{{ route('importaciones.salas.importar') }}" enctype="multipart/form-data" class="space-y-2">
                        @csrf
                        <input type="hidden" name="cliente_id" :value="clienteId">
                        <input type="file" name="archivo" accept=".csv,.txt" required
                               class="block w-full text-sm border border-gray-300 rounded-md">
                        <p class="text-xs text-gray-400">Columnas: Nombre comercial, Dirección, Distrito, Municipio, Departamento, (Requiere orden compra).</p>
                        <button class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">Importar salas</button>
                    </form>

                    <form method="GET" action="{{ route('importaciones.salas.exportar') }}">
                        <input type="hidden" name="cliente_id" :value="clienteId">
                        <button class="px-4 py-2 bg-gray-600 text-white text-sm rounded-md hover:bg-gray-700">Exportar salas</button>
                    </form>
                </div>

                {{-- Precios/productos --}}
                <div class="bg-white shadow sm:rounded-lg p-6 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="font-semibold text-gray-700">Precios / productos</h3>
                        <a href="{{ route('importaciones.precios.plantilla') }}" class="text-xs text-indigo-600 hover:underline">Descargar plantilla</a>
                    </div>

                    <form method="POST" action="{{ route('importaciones.precios.importar') }}" enctype="multipart/form-data" class="space-y-2">
                        @csrf
                        <input type="hidden" name="cliente_id" :value="clienteId">
                        <input type="file" name="archivo" accept=".csv,.txt" required
                               class="block w-full text-sm border border-gray-300 rounded-md">
                        <p class="text-xs text-gray-400">Columnas: Código interno, Código de barra, Descripción de producto, Factor de empaque, Fecha de inicio, Precio.</p>
                        <button class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">Importar precios/productos</button>
                    </form>

                    <form method="GET" action="{{ route('importaciones.precios.exportar') }}">
                        <input type="hidden" name="cliente_id" :value="clienteId">
                        <button class="px-4 py-2 bg-gray-600 text-white text-sm rounded-md hover:bg-gray-700">Exportar precios/productos</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
