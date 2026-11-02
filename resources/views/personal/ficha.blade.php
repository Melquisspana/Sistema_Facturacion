{{-- La ficha de la persona: junta lo de planilla y lo de Asistencia. Cada bloque aparece
     solo si el usuario alcanza ese módulo: ver App\Http\Controllers\Personal\PersonalController. --}}
@php
    use App\Models\Planilla\Planilla;
    use App\Models\Planilla\PlanillaEmpleado;
    use App\Services\Gastos\Dinero;

    $tarjeta = 'rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
    $dt = 'text-gray-500 dark:text-paper-400';
    $dd = 'text-gray-800 dark:text-paper-200';
    $campo = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
    $etiqueta = 'block text-sm font-medium text-gray-700 dark:text-paper-200';
    $secundario = 'inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-200';
    $gestiona = $ficha !== null && auth()->user()->can('planilla.gestionar');
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">{{ $nombre }}</h2>
            @if ($asistencia)
                <div class="flex items-center gap-3">
                    <x-asistencia.estado :activo="$asistencia->activo"
                                         :accion="route('asistencia.empleados.toggle-activo', $asistencia)"
                                         etiquetaActivo="Marca" etiquetaInactivo="No marca" />
                    @can('asistencia.gestionar')
                        <a href="{{ route('asistencia.empleados.edit', $asistencia) }}"
                           class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-ink-600 dark:text-paper-200 dark:hover:bg-ink-700">Editar</a>
                    @endcan
                </div>
            @endif
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('planilla.aviso'))
                <p role="status" class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800">{{ session('planilla.aviso') }}</p>
            @endif

            @if ($ficha)
                @if ($errors->hasAny(['sueldo_mensual', 'vigente_desde', 'fecha', 'empleado', 'contrato']))
                    <div role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">
                        <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif

                <div class="{{ $tarjeta }}">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-paper-100">Planilla</h3>
                    <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        <div class="flex gap-2"><dt class="{{ $dt }}">Estado:</dt><dd class="{{ $dd }}">{{ $ficha->activo ? 'Activo' : 'De baja'.($ficha->baja_el ? ' desde el '.\Illuminate\Support\Carbon::parse($ficha->baja_el)->format('d/m/Y') : '') }}</dd></div>
                        <div class="flex gap-2"><dt class="{{ $dt }}">Descuentos de ley:</dt><dd class="{{ $dd }}">{{ $ficha->con_descuentos_ley ? 'Con descuentos' : 'Sin descuentos' }}</dd></div>
                        <div class="flex gap-2"><dt class="{{ $dt }}">{{ PlanillaEmpleado::DOCUMENTO_TIPOS[$ficha->documento_tipo] }}:</dt><dd class="{{ $dd }}">{{ $ficha->dui ?? '—' }}</dd></div>
                        <div class="flex gap-2"><dt class="{{ $dt }}">Cargo:</dt><dd class="{{ $dd }}">{{ $ficha->cargo ?? '—' }}</dd></div>
                        <div class="flex gap-2"><dt class="{{ $dt }}">Ingreso:</dt><dd class="{{ $dd }}">{{ $ficha->alta_el?->format('d/m/Y') ?? '—' }}</dd></div>
                        @if ($verImportes)
                            <div class="flex gap-2"><dt class="{{ $dt }}">Sueldo mensual:</dt><dd class="tabular-nums {{ $dd }}">{{ $mensual !== null ? Dinero::mostrar($mensual) : 'Sin sueldo' }}</dd></div>
                        @endif
                    </dl>

                    @if ($gestiona)
                        <div class="mt-4 flex flex-wrap items-start gap-3">
                            @if ($ficha->activo && $ficha->con_descuentos_ley && $verImportes)
                                <a href="{{ route('planilla.empleados.contrato', $ficha) }}" class="{{ $secundario }}">Contrato</a>
                            @endif

                            @if ($ficha->activo && $verImportes)
                                <details>
                                    <summary class="{{ $secundario }} cursor-pointer">Cambiar sueldo</summary>
                                    <form method="POST" action="{{ route('planilla.empleados.sueldo', $ficha) }}" class="mt-2 w-72 space-y-2">
                                        @csrf
                                        <div>
                                            <label for="sueldo_mensual" class="{{ $etiqueta }}">Sueldo mensual</label>
                                            <input id="sueldo_mensual" name="sueldo_mensual" inputmode="decimal" required class="{{ $campo }}">
                                        </div>
                                        <div>
                                            <label for="vigente_desde" class="{{ $etiqueta }}">Vigente desde</label>
                                            <input id="vigente_desde" name="vigente_desde" type="date" value="{{ now()->toDateString() }}" required class="{{ $campo }}">
                                        </div>
                                        <div>
                                            <label for="motivo" class="{{ $etiqueta }}">Motivo</label>
                                            <input id="motivo" name="motivo" maxlength="500" class="{{ $campo }}">
                                        </div>
                                        @unless ($ficha->con_descuentos_ley)
                                            <div>
                                                <input id="confirmar_bajo_minimo" name="confirmar_bajo_minimo" type="checkbox" value="1">
                                                <label for="confirmar_bajo_minimo" class="text-sm text-gray-700 dark:text-paper-200">Confirmo si queda por debajo del mínimo.</label>
                                            </div>
                                        @endunless
                                        <button class="{{ $secundario }}">Guardar sueldo</button>
                                    </form>
                                </details>
                            @endif

                            <details>
                                <summary class="{{ $secundario }} cursor-pointer">{{ $ficha->activo ? 'Dar de baja' : 'Reactivar' }}</summary>
                                <form method="POST" action="{{ route($ficha->activo ? 'planilla.empleados.baja' : 'planilla.empleados.reactivar', $ficha) }}"
                                      onsubmit="return confirm('¿Confirmás el cambio de estado de esta persona y sus módulos enlazados?')" class="mt-2 w-72 space-y-2">
                                    @csrf
                                    @if ($ficha->activo)
                                        <div>
                                            <label for="fecha" class="{{ $etiqueta }}">Fecha de baja</label>
                                            <input id="fecha" name="fecha" type="date" value="{{ now()->toDateString() }}" required class="{{ $campo }}">
                                        </div>
                                    @endif
                                    <button class="{{ $secundario }}">Confirmar</button>
                                </form>
                            </details>
                        </div>
                    @endif
                </div>

                <div class="{{ $tarjeta }}">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-paper-100">Últimas quincenas</h3>
                    @if ($quincenas->isEmpty())
                        <p class="mt-3 text-sm text-gray-500 dark:text-paper-400">Todavía no está en ninguna planilla.</p>
                    @else
                        <div class="mt-3 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <caption class="sr-only">Últimas planillas de la persona</caption>
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-ink-600 dark:text-paper-400">
                                        <th scope="col" class="py-2 pr-4">Período</th>
                                        <th scope="col" class="py-2 pr-4">Tipo</th>
                                        <th scope="col" class="py-2 pr-4">Estado</th>
                                        @if ($verImportes)
                                            <th scope="col" class="py-2 text-right">A pagar</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                                    @foreach ($quincenas as $q)
                                        <tr>
                                            <td class="py-2.5 pr-4"><a href="{{ route('planilla.show', $q->planilla) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $q->planilla->periodo }}</a></td>
                                            <td class="py-2.5 pr-4 text-gray-600 dark:text-paper-300">{{ Planilla::CLASES[$q->planilla->clase] }}</td>
                                            <td class="py-2.5 pr-4 text-gray-600 dark:text-paper-300">{{ Planilla::ESTADOS[$q->planilla->estado] }}</td>
                                            @if ($verImportes)
                                                <td class="py-2.5 text-right tabular-nums text-gray-800 dark:text-paper-200">{{ Dinero::mostrar($q->a_pagar) }}</td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif

            @if ($asistencia)
                @include('asistencia.empleados._ficha')
            @endif

            <a href="{{ route('personal.empleados') }}" class="inline-block text-sm text-gray-500 hover:underline dark:text-paper-400">← Volver a empleados</a>
        </div>
    </div>
</x-app-layout>
