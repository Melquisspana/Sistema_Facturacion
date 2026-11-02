<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold leading-tight text-gray-800">Personas en planilla</h1></x-slot>
    <div class="px-4 py-5 sm:px-6"><div class="mx-auto max-w-5xl space-y-3">
        @if (session('planilla.aviso'))<div role="status" class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800">{{ session('planilla.aviso') }}</div>@endif
        @if ($errors->any())<div role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @can('planilla.gestionar')<a href="{{ route('planilla.empleados.create') }}" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Nuevo empleado</a>@endcan
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white"><table class="min-w-full text-sm">
            <caption class="sr-only">Registro de personas en planilla</caption>
            <thead><tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                @foreach (['Nombre', 'Cargo', 'Sistema', 'Asistencia', 'Rutas'] as $columna)<th scope="col" class="px-4 py-2 font-medium">{{ $columna }}</th>@endforeach
                @if ($verImportes)<th scope="col" class="px-4 py-2 text-right font-medium">Sueldo vigente por pago</th>@endif
                @can('planilla.gestionar')<th scope="col" class="px-4 py-2 font-medium">Acciones</th>@endcan
            </tr></thead>
            <tbody class="divide-y divide-gray-100">@foreach ($empleados as $e)<tr class="{{ $e->activo ? '' : 'opacity-60' }}">
                <td class="px-4 py-2.5 font-medium text-gray-900">{{ $e->nombre }} @unless($e->activo)<span class="text-xs text-gray-500">(inactivo)</span>@endunless</td>
                <td class="px-4 py-2.5 text-gray-600">{{ $e->cargo ?: '—' }}</td>
                @foreach (['user_id', 'asistencia_empleado_id', 'personal_ruta_id'] as $campo)<td class="px-4 py-2.5 text-gray-600">{{ $e->$campo ? 'Sí' : '—' }}</td>@endforeach
                @if ($verImportes)<td class="px-4 py-2.5 text-right tabular-nums text-gray-700">{{ isset($habituales[$e->id]) ? \App\Services\Gastos\Dinero::mostrar($habituales[$e->id]) : '—' }}</td>@endif
                @can('planilla.gestionar')<td class="px-4 py-2.5">
                    <form method="POST" action="{{ route($e->activo ? 'planilla.empleados.baja' : 'planilla.empleados.reactivar', $e) }}" onsubmit="return confirm('¿Confirmás el cambio de estado de esta persona y sus módulos enlazados?')">
                        @csrf
                        @if ($e->activo)<label for="fecha-{{ $e->id }}" class="block text-sm font-medium text-gray-700">Fecha de baja</label><input id="fecha-{{ $e->id }}" name="fecha" type="date" value="{{ now()->toDateString() }}" required class="block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">@endif
                        <button class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-3 text-xs font-medium text-gray-700 hover:bg-gray-50">{{ $e->activo ? 'Dar de baja' : 'Reactivar' }}</button>
                    </form>
                </td>@endcan
            </tr>@endforeach</tbody>
        </table></div>
    </div></div>
</x-app-layout>