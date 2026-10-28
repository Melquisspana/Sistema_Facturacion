<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">Hoy</h2>
    </x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
            <p class="text-sm text-gray-500 dark:text-paper-400">{{ \Carbon\CarbonImmutable::parse($hoy['fecha_hoy'])->format('d/m/Y') }} · Hora oficial ({{ $hoy['zona'] }})</p>
            <div class="grid grid-cols-3 gap-3">
                @foreach (['marcaron' => 'Marcaron', 'faltan' => 'Todavía no marcaron', 'activos' => 'Personas activas'] as $clave => $etiqueta)
                    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                        <p class="text-3xl font-semibold tabular-nums text-gray-800 dark:text-paper-100">{{ $hoy['conteos'][$clave] }}</p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-paper-400">{{ $etiqueta }}</p>
                    </div>
                @endforeach
            </div>
            @if ($hoy['empleados_sin_huella'])
                <p class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                    {{ $hoy['empleados_sin_huella'] }} persona(s) activa(s) sin huella vigente.
                    <a href="{{ route('asistencia.empleados.index') }}" class="font-medium underline">Ir a Personal</a>
                </p>
            @endif
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                <h3 class="text-base font-semibold text-gray-800 dark:text-paper-100">Marcaron</h3>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead><tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-ink-600 dark:text-paper-400">
                            <th scope="col" class="py-2 pr-4">Persona</th><th scope="col" class="py-2 pr-4">Primera entrada</th><th scope="col" class="py-2 pr-4">Última salida</th><th scope="col" class="py-2 pr-4">Tiempo</th>
                        </tr></thead>
                        <tbody class="divide-y divide-gray-100 text-gray-800 dark:divide-ink-700 dark:text-paper-200">
                            @forelse ($hoy['marcaron'] as $fila)
                                <tr>
                                    <td class="py-3 pr-4"><a href="{{ route('asistencia.empleados.show', $fila['persona']) }}" class="text-indigo-600 hover:underline dark:text-indigo-300">{{ $fila['persona']?->nombreCompleto() }}</a></td>
                                    <td class="py-3 pr-4 tabular-nums">{{ $fila['primera_entrada']?->copy()->setTimezone($hoy['zona'])->format('H:i') ?? '—' }}</td>
                                    <td class="py-3 pr-4 tabular-nums">{{ $fila['ultima_salida']?->copy()->setTimezone($hoy['zona'])->format('H:i') ?? 'sigue adentro' }}</td>
                                    <td class="py-3 pr-4 tabular-nums">{{ $fila['horas'] }} @unless ($fila['exacto']) <span class="text-xs text-gray-500 dark:text-paper-400">(mínimo)</span> @endunless</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-6 text-gray-500 dark:text-paper-400">Todavía no hay marcaciones hoy.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                <h3 class="text-base font-semibold text-gray-800 dark:text-paper-100">Todavía no marcaron</h3>
                <ul class="mt-3 divide-y divide-gray-100 dark:divide-ink-700">
                    @forelse ($hoy['faltan'] as $persona)
                        <li class="py-3 text-sm"><a href="{{ route('asistencia.empleados.show', $persona) }}" class="text-indigo-600 hover:underline dark:text-indigo-300">{{ $persona->nombreCompleto() }}</a></li>
                    @empty
                        <li class="py-3 text-sm text-gray-500 dark:text-paper-400">{{ $hoy['conteos']['activos'] ? 'Todo el personal activo ya marcó.' : 'Todavía no hay personal activo.' }}</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
