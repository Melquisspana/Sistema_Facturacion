@php
    use App\Services\Gastos\Dinero;

    $c = 'block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
    $boton = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">Anticipos</h1>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-5xl space-y-3">

            @if (session('planilla.aviso'))
                <div class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800" role="status">{{ session('planilla.aviso') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            {{-- Lo que hay que entender de esta pantalla, y es lo que evita el error caro. --}}
            <p class="rounded-md border border-sky-200 bg-sky-50 px-4 py-2.5 text-sm text-sky-900">
                Un anticipo es <strong>dinero que ya se le entregó a alguien</strong>. Se registra acá con su
                importe, y cada vez que una planilla lo descuenta se aplica contra él. Así
                <strong>no se puede recuperar dos veces</strong>: escribir «anticipo» en un descuento no bastaría,
                porque el mes siguiente alguien lo escribiría otra vez y nada lo impediría.
            </p>

            @if ($anticipos->isNotEmpty())
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">Anticipos registrados</caption>
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                <th scope="col" class="px-4 py-2 font-medium">Persona</th>
                                <th scope="col" class="px-4 py-2 font-medium">Fecha</th>
                                <th scope="col" class="px-4 py-2 font-medium">De dónde salió</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Importe</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Recuperado</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Queda</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($anticipos as $a)
                                @php $pendiente = $a->pendiente(); @endphp
                                <tr class="{{ $pendiente === 0 ? 'opacity-60' : '' }}">
                                    <td class="px-4 py-2.5 font-medium text-gray-900">{{ $a->empleado?->nombre }}</td>
                                    <td class="px-4 py-2.5 tabular-nums text-gray-600">{{ $a->fecha->format('d/m/Y') }}</td>
                                    <td class="px-4 py-2.5 text-xs text-gray-600">
                                        {{ $a->origen() }}
                                        @if ($a->pago_id === null)
                                            <span class="block text-amber-700">Sin pago enlazado</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $a->moneda }} {{ Dinero::mostrar($a->importe) }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums text-gray-600">{{ Dinero::mostrar($a->recuperado()) }}</td>
                                    <td class="px-4 py-2.5 text-right font-semibold tabular-nums {{ $pendiente > 0 ? 'text-amber-800' : 'text-emerald-700' }}">
                                        {{ Dinero::mostrar($pendiente) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @can('planilla.gestionar')
                <section class="rounded-lg border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Registrar un anticipo</h2>

                    @if ($empleados->isEmpty())
                        <p class="mt-2 text-sm text-gray-600">
                            Primero hay que tener personas en planilla.
                            <a href="{{ route('planilla.empleados') }}" class="underline">Registrar o vincular una persona</a>.
                        </p>
                    @else
                        <div x-data="{
                                empleado: '',
                                candidatos: @js($candidatosPorEmpleado),
                                pagos() { return this.candidatos[this.empleado] || []; }
                             }" class="mt-2">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="empleado" class="block text-xs text-gray-500">¿A quién?</label>
                                    <select id="empleado" x-model="empleado" class="{{ $c }} min-h-11">
                                        <option value="">Elegir persona…</option>
                                        @foreach ($empleados as $e)
                                            <option value="{{ $e->id }}">{{ $e->nombre }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <template x-if="empleado !== ''">
                                <form method="POST" :action="'{{ url('planilla/empleados') }}/' + empleado + '/anticipos'" class="mt-3 grid gap-3 sm:grid-cols-2">
                                    @csrf
                                    <input type="hidden" name="clave" value="{{ $clave }}">

                                    <div>
                                        <label for="fecha" class="block text-xs text-gray-500">¿Cuándo se le entregó?</label>
                                        <input id="fecha" name="fecha" type="date" value="{{ now()->toDateString() }}" required class="{{ $c }} min-h-11">
                                    </div>
                                    <div>
                                        <label for="importe" class="block text-xs text-gray-500">Importe</label>
                                        <input id="importe" name="importe" type="text" inputmode="decimal" required placeholder="0.00" class="{{ $c }} min-h-11 text-right tabular-nums">
                                    </div>

                                    <input type="hidden" name="moneda" value="{{ config('gastos.monedas')[0] }}">

                                    {{-- El vínculo con el pago REAL cuando existe. Es único: ese
                                         pago no puede quedar como dos anticipos distintos. --}}
                                    <div class="sm:col-span-2">
                                        <label for="pago_id" class="block text-xs text-gray-500">¿Salió por un pago ya registrado?</label>
                                        <select id="pago_id" name="pago_id" class="{{ $c }} min-h-11">
                                            <option value="">No, se entregó fuera del sistema</option>
                                            <template x-for="p in pagos()" :key="p.id">
                                                <option :value="p.id" x-text="'Pago #' + p.id + ' · ' + p.fecha.substring(0,10) + ' · ' + p.moneda + ' ' + p.importe"></option>
                                            </template>
                                        </select>
                                        <p class="mt-1 text-xs text-gray-500">
                                            Enlazarlo es mejor: deja el anticipo atado al dinero que de verdad salió.
                                        </p>
                                    </div>

                                    <div>
                                        <label for="referencia" class="block text-xs text-gray-500">Referencia (si no hay pago)</label>
                                        <input id="referencia" name="referencia" type="text" maxlength="180" placeholder="Recibo 148, vale firmado…" class="{{ $c }} min-h-11">
                                    </div>
                                    <div>
                                        <label for="descripcion" class="block text-xs text-gray-500">Nota</label>
                                        <input id="descripcion" name="descripcion" type="text" maxlength="250" class="{{ $c }} min-h-11">
                                    </div>

                                    <div class="sm:col-span-2">
                                        <button type="submit" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700">
                                            Registrar el anticipo
                                        </button>
                                    </div>
                                </form>
                            </template>
                        </div>
                    @endif
                </section>
            @endcan
        </div>
    </div>
</x-app-layout>
