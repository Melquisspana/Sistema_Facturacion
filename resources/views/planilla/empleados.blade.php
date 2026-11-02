@php
    use App\Services\Gastos\Dinero;

    $campo = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
    $secundario = 'inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-3 text-xs font-medium text-gray-700 hover:bg-gray-50';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">Personas en planilla</h1>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-5xl space-y-3">
            @if (session('planilla.aviso'))
                <div role="status" class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800">{{ session('planilla.aviso') }}</div>
            @endif
            @if ($errors->any())
                <div role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            @if ($verImportes && $minimo === null)
                <div role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">
                    No hay salario mínimo vigente cargado en la configuración.
                </div>
            @elseif ($verImportes && $bajoMinimo !== [])
                <div role="status" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900">
                    {{ count($bajoMinimo) === 1 ? 'Una persona queda' : count($bajoMinimo).' personas quedan' }} por debajo del
                    salario mínimo vigente ({{ Dinero::mostrar($minimo) }}). El sueldo no cambia solo: actualizalo abajo y volvé a
                    generar el contrato.
                </div>
            @endif

            @can('planilla.gestionar')
                <a href="{{ route('planilla.empleados.create') }}" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Nuevo empleado</a>
            @endcan

            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">Registro de personas en planilla</caption>
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            @foreach (['Nombre', 'Cargo', 'Descuentos', 'Sistema', 'Asistencia', 'Rutas'] as $columna)
                                <th scope="col" class="px-4 py-2 font-medium">{{ $columna }}</th>
                            @endforeach
                            @if ($verImportes)
                                <th scope="col" class="px-4 py-2 text-right font-medium">Sueldo mensual</th>
                            @endif
                            @can('planilla.gestionar')
                                <th scope="col" class="px-4 py-2 font-medium">Acciones</th>
                            @endcan
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($empleados as $e)
                            <tr class="{{ $e->activo ? '' : 'opacity-60' }}">
                                <td class="px-4 py-2.5 font-medium text-gray-900">
                                    {{ $e->nombre }}
                                    @unless ($e->activo)<span class="text-xs text-gray-500">(inactivo)</span>@endunless
                                </td>
                                <td class="px-4 py-2.5 text-gray-600">{{ $e->cargo ?: '—' }}</td>
                                <td class="px-4 py-2.5 text-gray-600">{{ $e->con_descuentos_ley ? 'Con' : 'Sin' }}</td>
                                @foreach (['user_id', 'asistencia_empleado_id', 'personal_ruta_id'] as $puntero)
                                    <td class="px-4 py-2.5 text-gray-600">{{ $e->$puntero ? 'Sí' : '—' }}</td>
                                @endforeach
                                @if ($verImportes)
                                    <td class="px-4 py-2.5 text-right tabular-nums text-gray-700">
                                        @if (isset($mensuales[$e->id]))
                                            {{ Dinero::mostrar($mensuales[$e->id]) }}
                                            @if (in_array($e->id, $bajoMinimo, true))
                                                <span class="block text-xs font-medium text-amber-700">Bajo el mínimo</span>
                                            @endif
                                        @else
                                            <span class="text-xs text-amber-700">Sin sueldo</span>
                                        @endif
                                    </td>
                                @endif
                                @can('planilla.gestionar')
                                    <td class="space-y-2 px-4 py-2.5">
                                        @if ($e->activo && $verImportes)
                                            <details>
                                                <summary class="cursor-pointer text-xs font-medium text-indigo-700">Cambiar sueldo</summary>
                                                <form method="POST" action="{{ route('planilla.empleados.sueldo', $e) }}" class="mt-2 space-y-2">
                                                    @csrf
                                                    <div>
                                                        <label for="sueldo-{{ $e->id }}" class="block text-sm font-medium text-gray-700">Sueldo mensual</label>
                                                        <input id="sueldo-{{ $e->id }}" name="sueldo_mensual" inputmode="decimal" required class="{{ $campo }}">
                                                    </div>
                                                    <div>
                                                        <label for="vigente-{{ $e->id }}" class="block text-sm font-medium text-gray-700">Vigente desde</label>
                                                        <input id="vigente-{{ $e->id }}" name="vigente_desde" type="date" value="{{ now()->toDateString() }}" required class="{{ $campo }}">
                                                    </div>
                                                    <div>
                                                        <label for="motivo-{{ $e->id }}" class="block text-sm font-medium text-gray-700">Motivo</label>
                                                        <input id="motivo-{{ $e->id }}" name="motivo" maxlength="500" class="{{ $campo }}">
                                                    </div>
                                                    @unless ($e->con_descuentos_ley)
                                                        <div>
                                                            <input id="bajo-{{ $e->id }}" name="confirmar_bajo_minimo" type="checkbox" value="1">
                                                            <label for="bajo-{{ $e->id }}" class="text-sm text-gray-700">Confirmo si queda por debajo del mínimo.</label>
                                                        </div>
                                                    @endunless
                                                    <button class="{{ $secundario }}">Guardar sueldo</button>
                                                </form>
                                            </details>
                                        @endif
                                        @if ($e->activo && $e->con_descuentos_ley && $verImportes)
                                            <a href="{{ route('planilla.empleados.contrato', $e) }}" class="block text-xs font-medium text-indigo-700 underline">Contrato</a>
                                        @endif
                                        <form method="POST" action="{{ route($e->activo ? 'planilla.empleados.baja' : 'planilla.empleados.reactivar', $e) }}"
                                              onsubmit="return confirm('¿Confirmás el cambio de estado de esta persona y sus módulos enlazados?')">
                                            @csrf
                                            @if ($e->activo)
                                                <label for="fecha-{{ $e->id }}" class="block text-sm font-medium text-gray-700">Fecha de baja</label>
                                                <input id="fecha-{{ $e->id }}" name="fecha" type="date" value="{{ now()->toDateString() }}" required class="{{ $campo }}">
                                            @endif
                                            <button class="mt-1 {{ $secundario }}">{{ $e->activo ? 'Dar de baja' : 'Reactivar' }}</button>
                                        </form>
                                    </td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
