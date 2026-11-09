<x-configuracion-layout titulo="Feriados de Hacienda">
    {{--
        Días inhábiles del MH por año (App\Http\Controllers\Configuracion\FeriadosHaciendaController,
        issue #49). Lo único editable del bloque de Facturación electrónica: cada cambio
        pasa por la confirmación N2 y queda en la auditoría.

        Distinto de la guía en una cosa, a propósito: «Quitar» es un botón de texto
        dentro de la fila (un formulario DELETE) y no un enlace, porque cambia datos.
        No lleva confirm(): el envío ya lleva a la pantalla de confirmación.
    --}}
    <div class="space-y-6">
        <p class="text-sm text-gray-500">
            Son los días que el Ministerio de Hacienda declara inhábiles en su calendario tributario. No cuentan en el
            plazo para invalidar un crédito fiscal, una nota de crédito o una nota de débito. Sábado y domingo nunca
            cuentan: no hace falta cargarlos.
        </p>

        <form method="GET" action="{{ route('configuracion.fiscal.feriados') }}" class="flex flex-wrap items-end gap-3">
            <div>
                <label for="anio-ver" class="block text-sm font-medium text-gray-700">Año</label>
                <select id="anio-ver" name="anio" class="mt-1 min-h-11 rounded-md border-gray-300 text-sm">
                    @foreach ($anios as $opcion)
                        <option value="{{ $opcion }}" @selected($opcion === $anio)>{{ $opcion }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit"
                    class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Ver año
            </button>
        </form>

        <x-configuracion.seccion :titulo="'Calendario de '.$anio">
            <x-slot name="acciones">
                <x-configuracion.badge :estado="match (true) {
                    ! $tieneCalendario => \App\Ajustes\Resumen\EstadoTarjeta::NoConfigurado,
                    $completo => \App\Ajustes\Resumen\EstadoTarjeta::Configurado,
                    default => \App\Ajustes\Resumen\EstadoTarjeta::Advertencia,
                }" />
            </x-slot>

            <p class="text-sm text-gray-700">
                @if (! $tieneCalendario)
                    Este año no tiene calendario. El plazo se calcula de lunes a viernes, sin feriados, y avisa que falta cargarlo.
                @elseif ($completo)
                    {{ count($fechas) === 1 ? '1 día' : count($fechas).' días' }}. El calendario está completo.
                @else
                    {{ count($fechas) === 1 ? '1 día' : count($fechas).' días' }}. Sin marcar como completo: el plazo usa estos días, pero avisa que falta el calendario completo.
                @endif
            </p>

            @if ($tieneCalendario && ! $enConfiguracion)
                {{-- text-blue-700 y no -800: el -800 no tiene arreglo de modo oscuro en el CSS de producción. --}}
                <p class="mt-3 rounded-md bg-blue-50 px-3 py-2 text-xs text-blue-700">
                    Estas fechas vienen del calendario que trae el sistema (Calendario Tributario {{ $anio }} del Ministerio de Hacienda).
                    Si cambiás algo, el año entero queda guardado acá.
                </p>
            @endif

            <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="px-4 py-2">Fecha</th>
                            <th scope="col" class="px-4 py-2">Día</th>
                            <th scope="col" class="px-4 py-2 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($fechas as $fecha)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-1 tabular-nums text-gray-800">{{ $fecha['texto'] }}</td>
                                <td class="px-4 py-1 text-gray-600">{{ $fecha['dia'] }}</td>
                                <td class="px-4 py-1 text-right">
                                    <form method="POST" action="{{ route('configuracion.fiscal.feriados.quitar') }}">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="anio" value="{{ $anio }}">
                                        <input type="hidden" name="fecha" value="{{ $fecha['valor'] }}">
                                        <button type="submit" class="min-h-11 px-2 text-sm font-medium text-indigo-600 hover:text-indigo-800"
                                                aria-label="Quitar el {{ $fecha['dia'] }} {{ $fecha['texto'] }}">
                                            Quitar
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-4 py-10 text-center text-gray-500">Todavía no hay días cargados para {{ $anio }}.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @error('fecha')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </x-configuracion.seccion>

        <x-configuracion.seccion titulo="Agregar días"
                                 descripcion="Un día suelto, o un rango «desde–hasta» del mismo año. El rango se guarda día por día y después se puede quitar cualquiera.">
            <form method="POST" action="{{ route('configuracion.fiscal.feriados.agregar') }}" class="space-y-5">
                @csrf
                <input type="hidden" name="anio" value="{{ $anio }}">

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-configuracion.campo nombre="desde" etiqueta="Fecha (o desde)">
                        <input type="date" id="desde" name="desde" required
                               min="{{ $anio }}-01-01" max="{{ $anio }}-12-31"
                               value="{{ old('desde') }}"
                               class="min-h-11 w-full rounded-md border-gray-300 text-sm">
                    </x-configuracion.campo>

                    <x-configuracion.campo nombre="hasta" etiqueta="Hasta (opcional)"
                                           :ayuda="'Solo para un rango. Hasta '.$maximo.' días por año.'">
                        <input type="date" id="hasta" name="hasta"
                               min="{{ $anio }}-01-01" max="{{ $anio }}-12-31"
                               value="{{ old('hasta') }}"
                               class="min-h-11 w-full rounded-md border-gray-300 text-sm">
                    </x-configuracion.campo>
                </div>

                <div class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:justify-end">
                    <button type="submit"
                            class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">
                        Agregar días
                    </button>
                </div>
            </form>
        </x-configuracion.seccion>

        <x-configuracion.seccion titulo="¿Está completo?">
            <form method="POST" action="{{ route('configuracion.fiscal.feriados.completo') }}" class="space-y-4">
                @csrf
                @method('PUT')
                <input type="hidden" name="anio" value="{{ $anio }}">

                <label for="completo" class="flex items-start gap-3">
                    <input type="checkbox" id="completo" name="completo" value="1" @checked($completo)
                           class="mt-0.5 h-5 w-5 rounded border-gray-300 text-indigo-600">
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium text-gray-800">El calendario de {{ $anio }} está completo</span>
                        <span class="mt-0.5 block text-sm text-gray-500">
                            Marcalo cuando ya estén todos los días del calendario tributario de ese año. Mientras no lo esté,
                            el plazo avisa que falta el calendario completo.
                        </span>
                    </span>
                </label>

                <div class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:justify-end">
                    <button type="submit"
                            class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">
                        Guardar marca de completo
                    </button>
                </div>
            </form>
        </x-configuracion.seccion>
    </div>
</x-configuracion-layout>
