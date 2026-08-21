{{-- Lectores biométricos dados de alta.

     NUNCA se muestra el token ni su hash: `token_hash` está en `$hidden` del
     modelo y no llega a esta vista. Lo único que se publica del secreto es que
     existe, y eso ni se dice — se da por hecho: un lector sin token no podría
     haberse creado.

     La última conexión es TELEMETRÍA de solo lectura: dice si el aparato sigue
     vivo. No se audita, porque una entrada por cada dedo que toca el sensor
     dejaría el registro de auditoría inservible para lo que existe. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">Lectores de huella</h2>
            @can('asistencia.dispositivos.gestionar')
                <a href="{{ route('asistencia.dispositivos.create') }}"
                   class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nuevo lector</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">

            <x-asistencia.avisos />

            <div class="overflow-hidden bg-white shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:shadow-none dark:ring-ink-600 sm:rounded-xl">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-ink-600 dark:bg-ink-700 dark:text-paper-400">
                                <th class="px-4 py-3">Lector</th>
                                <th class="px-4 py-3">Código</th>
                                <th class="px-4 py-3">Ranuras en uso</th>
                                <th class="px-4 py-3">Última conexión</th>
                                <th class="px-4 py-3 text-center">Estado</th>
                                <th class="px-4 py-3 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                            @forelse ($dispositivos as $dispositivo)
                                <tr class="hover:bg-gray-50 dark:hover:bg-ink-700 {{ $dispositivo->activo ? '' : 'opacity-60' }}">
                                    <td class="px-4 py-3 font-medium text-gray-800 dark:text-paper-100">{{ $dispositivo->nombre }}</td>
                                    <td class="px-4 py-3">
                                        <code class="text-xs text-gray-600 dark:text-paper-300">{{ $dispositivo->codigo }}</code>
                                    </td>
                                    <td class="px-4 py-3 tabular-nums text-gray-600 dark:text-paper-300">{{ $dispositivo->huellas_activas_count }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-paper-300">
                                        @if ($dispositivo->ultima_conexion_at)
                                            <span title="{{ $dispositivo->ultima_conexion_at->format('d/m/Y H:i:s') }}">
                                                {{ $dispositivo->ultima_conexion_at->diffForHumans() }}
                                            </span>
                                            @if ($dispositivo->ultima_ip)
                                                <span class="block text-xs text-gray-400 dark:text-paper-500">{{ $dispositivo->ultima_ip }}</span>
                                            @endif
                                        @else
                                            <span class="text-gray-400 dark:text-paper-500">nunca se ha comunicado</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <x-asistencia.estado :activo="$dispositivo->activo"
                                                             :accion="route('asistencia.dispositivos.toggle-activo', $dispositivo)"
                                                             permiso="asistencia.dispositivos.gestionar" />
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        @can('asistencia.dispositivos.gestionar')
                                            <a href="{{ route('asistencia.dispositivos.edit', $dispositivo) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Editar</a>
                                            <span class="px-1 text-gray-300 dark:text-ink-600">|</span>
                                            <a href="{{ route('asistencia.dispositivos.rotar-token', $dispositivo) }}" class="text-amber-700 hover:underline dark:text-amber-400">Rotar token</a>
                                        @else
                                            <span class="text-xs text-gray-400 dark:text-paper-500">&mdash;</span>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-10 text-center text-gray-400 dark:text-paper-500">
                                        Todavía no hay lectores dados de alta.
                                        @can('asistencia.dispositivos.gestionar')
                                            <a href="{{ route('asistencia.dispositivos.create') }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Dá de alta el primero</a>.
                                        @endcan
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="mt-4 text-xs text-gray-400 dark:text-paper-500">
                El token de cada lector solo se ve una vez, al generarlo o rotarlo. El servidor guarda
                nada más su huella criptográfica: no hay ninguna pantalla que pueda mostrarlo de nuevo.
            </p>
        </div>
    </div>
</x-app-layout>
