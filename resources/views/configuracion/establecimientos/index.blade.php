<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Configuración &mdash; Establecimientos</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">
                @include('configuracion._nav')

                <div class="flex justify-end mb-4">
                    <a href="{{ route('configuracion.establecimientos.create') }}"
                       class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                        Nuevo establecimiento
                    </a>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-3 py-2">Código</th>
                                <th class="px-3 py-2">Nombre</th>
                                <th class="px-3 py-2">Tipo</th>
                                <th class="px-3 py-2">Activo</th>
                                <th class="px-3 py-2 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($establecimientos as $est)
                                <tr>
                                    <td class="px-3 py-2 font-mono">{{ $est->codigo }}</td>
                                    <td class="px-3 py-2">{{ $est->nombre }}</td>
                                    <td class="px-3 py-2">{{ $est->tipo_establecimiento?->label() ?? '—' }}</td>
                                    <td class="px-3 py-2">{{ $est->activo ? 'Sí' : 'No' }}</td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">
                                        <a href="{{ route('configuracion.establecimientos.edit', $est) }}" class="text-indigo-600 hover:underline">Editar</a>
                                        <form method="POST" action="{{ route('configuracion.establecimientos.destroy', $est) }}" class="inline"
                                              onsubmit="return confirm('¿Eliminar este establecimiento?');">
                                            @csrf @method('DELETE')
                                            <button class="text-red-600 hover:underline ml-2">Eliminar</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">No hay establecimientos registrados.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
