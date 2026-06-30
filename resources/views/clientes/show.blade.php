<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Cliente: {{ $cliente->nombre }}</h2>
            <a href="{{ route('clientes.index') }}" class="text-sm text-gray-500 hover:underline">← Volver al listado</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif

            <div class="bg-white shadow sm:rounded-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $cliente->activo ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">
                        {{ $cliente->activo ? 'Activo' : 'Inactivo' }}
                    </span>
                    <div class="flex items-center gap-3">
                        @can('update', $cliente)
                            <a href="{{ route('clientes.edit', $cliente) }}" class="text-indigo-600 hover:underline text-sm">Editar</a>
                            <form method="POST" action="{{ route('clientes.toggle-activo', $cliente) }}">
                                @csrf @method('PATCH')
                                <button class="text-amber-600 hover:underline text-sm">{{ $cliente->activo ? 'Inactivar' : 'Activar' }}</button>
                            </form>
                        @endcan
                    </div>
                </div>

                <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3 text-sm">
                    <div><dt class="text-gray-500">Tipo de cliente</dt><dd>{{ $cliente->tipo_cliente?->label() ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Tipo de persona</dt><dd>{{ $cliente->tipo_persona?->label() ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Documento</dt><dd>{{ $cliente->tipo_documento?->label() }} <span class="font-mono">{{ $cliente->num_documento }}</span></dd></div>
                    <div><dt class="text-gray-500">NRC</dt><dd class="font-mono">{{ $cliente->nrc ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Tamaño de contribuyente</dt><dd>{{ $cliente->tamanio_contribuyente?->label() ?? '—' }}</dd></div>
                    <div>
                        <dt class="text-gray-500">Agente de retención</dt>
                        <dd>
                            @if ($cliente->es_agente_retencion)
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-indigo-100 text-indigo-700">Sí</span>
                            @else
                                No
                            @endif
                        </dd>
                    </div>
                    <div><dt class="text-gray-500">Descuento global (%)</dt><dd class="font-mono">{{ number_format($cliente->descuento_global_default ?? 0, 2) }}%</dd></div>
                    <div><dt class="text-gray-500">Nombre comercial</dt><dd>{{ $cliente->nombre_comercial ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Actividad económica</dt><dd>{{ $cliente->actividadEconomica?->nombre ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">País</dt><dd>{{ $cliente->pais?->nombre ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Departamento</dt><dd>{{ $cliente->departamento?->nombre ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Municipio</dt><dd>{{ $cliente->municipio?->nombre ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Dirección</dt><dd>{{ $cliente->direccion ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Complemento</dt><dd>{{ $cliente->complemento_direccion ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Correo</dt><dd>{{ $cliente->correo ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Teléfono</dt><dd>{{ $cliente->telefono ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Contacto principal</dt><dd>{{ $cliente->contacto_principal ?? '—' }}</dd></div>
                    <div class="md:col-span-2"><dt class="text-gray-500">Observaciones</dt><dd>{{ $cliente->observaciones ?? '—' }}</dd></div>
                    <div>
                        <dt class="text-gray-500">Requiere orden de compra (CCF)</dt>
                        <dd>
                            @if ($cliente->requiere_orden_compra)
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-indigo-100 text-indigo-700">Sí</span>
                                <span class="text-gray-500">— etiqueta: "{{ $cliente->etiqueta_orden_compra ?? 'Orden de compra' }}"</span>
                            @else
                                No
                            @endif
                        </dd>
                    </div>
                    <div><dt class="text-gray-500">Observaciones de facturación</dt><dd>{{ $cliente->observaciones_facturacion ?? '—' }}</dd></div>
                </dl>
            </div>

            {{-- Sucursales / salas comerciales --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-medium text-gray-700">Sucursales / salas</h3>
                    @can('update', $cliente)
                        <a href="{{ route('clientes.sucursales.create', $cliente) }}"
                           class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                            Agregar sucursal
                        </a>
                    @endcan
                </div>

                <p class="text-xs text-gray-400 mb-3">
                    Mismo cliente fiscal (NIT/NRC), varias salas. La sala es referencia comercial; el receptor fiscal sigue siendo el cliente.
                </p>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-3 py-2">Código</th>
                                <th class="px-3 py-2">Sala / nombre comercial</th>
                                <th class="px-3 py-2">Ubicación</th>
                                <th class="px-3 py-2">Orden de compra</th>
                                <th class="px-3 py-2">Estado</th>
                                <th class="px-3 py-2 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($cliente->sucursales as $sucursal)
                                <tr>
                                    <td class="px-3 py-2 font-mono">{{ $sucursal->codigo ?? '—' }}</td>
                                    <td class="px-3 py-2 font-medium">{{ $sucursal->nombre }}</td>
                                    <td class="px-3 py-2">{{ $sucursal->direccion ?? '—' }}</td>
                                    <td class="px-3 py-2">
                                        @if (is_null($sucursal->requiere_orden_compra))
                                            <span class="text-gray-400">Hereda del cliente</span>
                                        @elseif ($sucursal->requiere_orden_compra)
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-indigo-100 text-indigo-700">Sí</span>
                                        @else
                                            No
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $sucursal->activo ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">
                                            {{ $sucursal->activo ? 'Activa' : 'Inactiva' }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">
                                        @can('update', $cliente)
                                            <a href="{{ route('clientes.sucursales.edit', [$cliente, $sucursal]) }}" class="text-indigo-600 hover:underline">Editar</a>
                                            <form method="POST" action="{{ route('clientes.sucursales.toggle-activo', [$cliente, $sucursal]) }}" class="inline">
                                                @csrf @method('PATCH')
                                                <button class="text-amber-600 hover:underline ml-2">{{ $sucursal->activo ? 'Inactivar' : 'Activar' }}</button>
                                            </form>
                                            <form method="POST" action="{{ route('clientes.sucursales.destroy', [$cliente, $sucursal]) }}" class="inline"
                                                  onsubmit="return confirm('¿Eliminar esta sucursal?');">
                                                @csrf @method('DELETE')
                                                <button class="text-red-600 hover:underline ml-2">Eliminar</button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Sin sucursales registradas.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-700 mb-3">Historial de auditoría</h3>
                @forelse ($actividades as $actividad)
                    <div class="flex items-start gap-3 py-2 border-b border-gray-100 last:border-0 text-sm">
                        <div class="text-gray-400 whitespace-nowrap">{{ $actividad->created_at->format('d/m/Y H:i') }}</div>
                        <div>
                            <span class="font-medium">{{ $actividad->causer?->name ?? 'Sistema' }}</span>
                            {{ $actividad->description }}
                            @if ($actividad->properties->has('attributes'))
                                <span class="text-gray-400">
                                    ({{ collect($actividad->properties->get('attributes'))->keys()->implode(', ') }})
                                </span>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">Sin actividad registrada.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
