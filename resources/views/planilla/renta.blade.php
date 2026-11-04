@php
    use App\Services\Gastos\Dinero;

    $c = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
    $secundario = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
    // Los errores son del formulario que se mandó, no de todos.
    $conError = (int) old('planilla_empleado_id');
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Renta antes del sistema</h1>
            <a href="{{ route('planilla.index') }}" class="{{ $secundario }}">Volver a Planillas</a>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-3xl space-y-3">
            @if (session('status'))
                <div class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
                    <p class="font-medium">Revisá estos datos. No se guardó nada.</p>
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            <p class="rounded-md border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-600">
                La última quincena de junio y la de diciembre recalculan la renta con todo lo gravado del año.
                Lo de los meses que no están en el sistema se escribe acá, una vez por persona y año.
                Se propone el sueldo habitual de cada quincena que falta: revisalo y confirmalo.
                Si la persona trabajó con otro patrono ese año, sumá lo de su constancia de retención.
            </p>

            <nav aria-label="Año" class="flex gap-2 overflow-x-auto">
                @foreach ($anios as $a)
                    <a href="{{ route('planilla.renta.index', ['anio' => $a]) }}" @if ($a === $anio) aria-current="page" @endif
                       class="inline-flex min-h-11 shrink-0 items-center rounded-full px-4 text-sm font-medium {{ $a === $anio ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50' }}">{{ $a }}</a>
                @endforeach
            </nav>

            @forelse ($personas as $p)
                @php
                    $e = $p['empleado'];
                    $fila = $p['confirmada'];
                    $propuesta = $p['propuesta'];
                    $esta = $conError === $e->id;
                    $valor = fn (string $campo, ?string $guardado, ?string $propuesto) => $esta ? old($campo) : ($guardado ?? $propuesto);
                    $id = 'p'.$e->id.'-';
                @endphp
                <section class="rounded-lg border border-gray-200 bg-white p-4" aria-labelledby="{{ $id }}nombre">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="{{ $id }}nombre" class="text-sm font-semibold text-gray-900">{{ $e->nombre }}</h2>
                        @if ($fila)
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium bg-emerald-50 text-emerald-800">Confirmada</span>
                        @elseif ($propuesta === null)
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-700">No hace falta</span>
                        @else
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium bg-amber-50 text-amber-800">Falta confirmar</span>
                        @endif
                    </div>

                    @if ($fila)
                        <p class="mt-1 text-xs text-gray-500">
                            Confirmada por {{ $fila->registrador?->name ?? '—' }} el {{ $fila->updated_at->format('d/m/Y H:i') }}:
                            gravado ${{ Dinero::mostrar((string) $fila->gravado) }} y renta ${{ Dinero::mostrar((string) $fila->renta_retenida) }} hasta el {{ $fila->hasta->format('d/m/Y') }}.
                        </p>
                    @endif

                    @if (! $fila && $propuesta === null)
                        <p class="mt-1 text-sm text-gray-600">Las planillas del sistema la tienen desde el 1 de enero de {{ $anio }}.</p>
                    @else
                        @if ($propuesta && ! $fila)
                            <p class="mt-1 text-xs text-gray-500">
                                Propuesta: {{ $propuesta['quincenas'] }} {{ $propuesta['quincenas'] === 1 ? 'quincena' : 'quincenas' }}
                                con el sueldo habitual, hasta el {{ \Carbon\CarbonImmutable::parse($propuesta['hasta'])->format('d/m/Y') }}.
                            </p>
                        @endif

                        <form method="POST" action="{{ route('planilla.renta.store') }}" class="mt-3 space-y-3">
                            @csrf
                            <input type="hidden" name="planilla_empleado_id" value="{{ $e->id }}">
                            <input type="hidden" name="anio" value="{{ $anio }}">

                            <div class="grid gap-3 sm:grid-cols-3">
                                <div>
                                    <label for="{{ $id }}hasta" class="block text-sm font-medium text-gray-700">Cubre hasta el</label>
                                    <input id="{{ $id }}hasta" name="hasta" type="date" required class="{{ $c }}"
                                           value="{{ $valor('hasta', $fila?->hasta->toDateString(), $propuesta['hasta'] ?? null) }}">
                                    @if ($esta) @error('hasta')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror @endif
                                </div>
                                <div>
                                    <label for="{{ $id }}gravado" class="block text-sm font-medium text-gray-700">Gravado</label>
                                    <input id="{{ $id }}gravado" name="gravado" type="text" inputmode="decimal" required class="{{ $c }} text-right tabular-nums"
                                           value="{{ $valor('gravado', $fila ? (string) $fila->gravado : null, isset($propuesta['gravado']) ? Dinero::decimal($propuesta['gravado']) : null) }}">
                                    <p class="mt-1 text-xs text-gray-500">Lo que cotiza menos ISSS y AFP.</p>
                                    @if ($esta) @error('gravado')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror @endif
                                </div>
                                <div>
                                    <label for="{{ $id }}renta" class="block text-sm font-medium text-gray-700">Renta retenida</label>
                                    <input id="{{ $id }}renta" name="renta_retenida" type="text" inputmode="decimal" required class="{{ $c }} text-right tabular-nums"
                                           value="{{ $valor('renta_retenida', $fila ? (string) $fila->renta_retenida : null, isset($propuesta['renta']) ? Dinero::decimal($propuesta['renta']) : null) }}">
                                    @if ($esta) @error('renta_retenida')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror @endif
                                </div>
                            </div>

                            <div>
                                <label for="{{ $id }}obs" class="block text-sm font-medium text-gray-700">Observaciones (opcional)</label>
                                <input id="{{ $id }}obs" name="observaciones" type="text" maxlength="500" class="{{ $c }}"
                                       value="{{ $valor('observaciones', $fila?->observaciones, null) }}">
                            </div>

                            <div class="flex flex-wrap justify-end gap-3 border-t border-gray-100 pt-4">
                                <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">
                                    {{ $fila ? 'Guardar cambios' : 'Confirmar cifras' }}
                                </button>
                            </div>
                        </form>
                    @endif
                </section>
            @empty
                <div class="rounded-lg border border-gray-200 bg-white px-4 py-10 text-center text-sm text-gray-500">
                    Nadie va con descuentos de ley en {{ $anio }}.
                </div>
            @endforelse
        </div>
    </div>
</x-app-layout>
