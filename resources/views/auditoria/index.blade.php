<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Auditoría</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">

                <form method="GET" class="flex flex-wrap items-end gap-3 mb-6">
                    <div>
                        <x-input-label for="causer_id" value="Usuario" />
                        <select id="causer_id" name="causer_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            <option value="">Todos</option>
                            @foreach ($usuarios as $u)
                                <option value="{{ $u->id }}" @selected((string) $filtros['causerId'] === (string) $u->id)>{{ $u->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="log_name" value="Módulo" />
                        <select id="log_name" name="log_name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            <option value="">Todos</option>
                            @foreach ($logNames as $ln)
                                <option value="{{ $ln }}" @selected($filtros['logName'] === $ln)>{{ ucfirst($ln) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="desde" value="Desde" />
                        <x-text-input id="desde" name="desde" type="date" class="mt-1 block" :value="$filtros['desde']" />
                    </div>
                    <div>
                        <x-input-label for="hasta" value="Hasta" />
                        <x-text-input id="hasta" name="hasta" type="date" class="mt-1 block" :value="$filtros['hasta']" />
                    </div>
                    <div class="flex gap-2">
                        <x-primary-button>Filtrar</x-primary-button>
                        <a href="{{ route('auditoria.index') }}" class="px-4 py-2 text-sm text-gray-500 hover:underline self-center">Limpiar</a>
                    </div>
                </form>

                {{-- Acceso ESCONDIDO a los documentos de prueba/simulación (ambiente 00). No está
                     en el listado principal de facturación; solo aquí, para administrador/contador. --}}
                <div class="mb-6 flex items-center justify-between rounded-md border border-gray-200 bg-gray-50 p-3">
                    <span class="text-sm text-gray-600">Documentos de <strong>prueba / simulación</strong> (ambiente 00), fuera del listado de producción.</span>
                    <a href="{{ route('auditoria.documentos_prueba') }}"
                       class="inline-flex items-center px-3 py-1.5 bg-amber-600 text-white text-sm rounded-md hover:bg-amber-700">
                        Ver documentos de prueba/simulación
                    </a>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-3 py-2">Fecha</th>
                                <th class="px-3 py-2">Usuario</th>
                                <th class="px-3 py-2">Módulo</th>
                                <th class="px-3 py-2">Acción</th>
                                <th class="px-3 py-2">Sobre</th>
                                <th class="px-3 py-2">Cambios</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($actividades as $actividad)
                                <tr>
                                    <td class="px-3 py-2 whitespace-nowrap text-gray-500">{{ $actividad->created_at->format('d/m/Y H:i') }}</td>
                                    <td class="px-3 py-2">{{ $actividad->causer?->name ?? 'Sistema' }}</td>
                                    <td class="px-3 py-2">{{ ucfirst($actividad->log_name) }}</td>
                                    <td class="px-3 py-2">{{ $actividad->description }}</td>
                                    <td class="px-3 py-2 text-gray-500">{{ class_basename($actividad->subject_type) }} #{{ $actividad->subject_id }}</td>
                                    <td class="px-3 py-2 text-gray-400">
                                        @if ($actividad->properties->has('attributes'))
                                            {{ collect($actividad->properties->get('attributes'))->keys()->implode(', ') }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">No hay actividad registrada para esos filtros.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">{{ $actividades->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>
