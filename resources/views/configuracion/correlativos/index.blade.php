<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Configuración &mdash; Correlativos</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">
                @include('configuracion._nav')

                <p class="text-xs text-gray-500 mb-4">
                    Estructura de control interno. La asignación real del número de control para Hacienda
                    se implementará en la fase del motor DTE.
                </p>

                <div class="flex justify-end mb-4">
                    <a href="{{ route('configuracion.correlativos.create') }}"
                       class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                        Nuevo correlativo
                    </a>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-3 py-2">Tipo DTE</th>
                                <th class="px-3 py-2">Establecimiento</th>
                                <th class="px-3 py-2">Punto de venta</th>
                                <th class="px-3 py-2">Ambiente</th>
                                <th class="px-3 py-2">Serie</th>
                                <th class="px-3 py-2 text-right">Último</th>
                                <th class="px-3 py-2 text-right">Siguiente</th>
                                <th class="px-3 py-2">Activo</th>
                                <th class="px-3 py-2 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($correlativos as $c)
                                <tr>
                                    <td class="px-3 py-2 font-mono">{{ $c->tipo_dte->value }} — {{ $c->tipo_dte->label() }}</td>
                                    <td class="px-3 py-2">{{ $c->establecimiento?->codigo }}</td>
                                    <td class="px-3 py-2">{{ $c->puntoVenta?->codigo ?? '—' }}</td>
                                    <td class="px-3 py-2">{{ $c->ambiente->label() }}</td>
                                    <td class="px-3 py-2">{{ $c->serie ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ $c->ultimo_numero }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ $c->siguiente_numero }}</td>
                                    <td class="px-3 py-2">{{ $c->activo ? 'Sí' : 'No' }}</td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">
                                        <a href="{{ route('configuracion.correlativos.edit', $c) }}" class="text-indigo-600 hover:underline">Editar</a>
                                        <form method="POST" action="{{ route('configuracion.correlativos.destroy', $c) }}" class="inline"
                                              onsubmit="return confirm('¿Eliminar este correlativo?');">
                                            @csrf @method('DELETE')
                                            <button class="text-red-600 hover:underline ml-2">Eliminar</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="px-3 py-6 text-center text-gray-400">No hay correlativos registrados.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
