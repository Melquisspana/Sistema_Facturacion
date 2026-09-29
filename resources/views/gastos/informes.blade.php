@php
    use App\Services\Gastos\Dinero;

    $control = 'mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
    $puedeExportar = auth()->user()->can('gastos.exportar');
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Informes de gastos</h1>
            <a href="{{ route('gastos.panel') }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver a Gastos</a>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-6xl space-y-4">
            <x-gastos-aviso />

            <div class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                Son <strong>dos preguntas distintas</strong>: lo pendiente es un saldo <em>a una fecha de corte</em> y lo pagado es
                dinero que salió <em>dentro de un período</em>. No se suman entre sí, y por eso van en dos tablas.
            </div>

            <form method="GET" action="{{ route('gastos.informes') }}" class="rounded-lg border border-gray-200 bg-white p-4">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                    <div>
                        <label for="corte" class="block text-sm font-medium text-gray-700">Pendientes al</label>
                        <input id="corte" name="corte" type="date" value="{{ $parametros['corte'] }}" class="{{ $control }} min-w-0">
                    </div>
                    <div>
                        <label for="desde" class="block text-sm font-medium text-gray-700">Pagos desde</label>
                        <input id="desde" name="desde" type="date" value="{{ $parametros['desde'] }}" class="{{ $control }} min-w-0">
                    </div>
                    <div>
                        <label for="hasta" class="block text-sm font-medium text-gray-700">Pagos hasta</label>
                        <input id="hasta" name="hasta" type="date" value="{{ $parametros['hasta'] }}" class="{{ $control }} min-w-0">
                    </div>
                    @can('gastos.personales')
                        <div>
                            <label for="ambito" class="block text-sm font-medium text-gray-700">Ámbito</label>
                            <select id="ambito" name="ambito" class="{{ $control }}">
                                <option value="">Empresa y personal</option>
                                <option value="empresarial" @selected($parametros['ambito'] === 'empresarial')>Solo empresa</option>
                                <option value="personal" @selected($parametros['ambito'] === 'personal')>Solo personal</option>
                            </select>
                        </div>
                    @endcan
                </div>
                <button type="submit" class="mt-3 min-h-11 rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Actualizar</button>
            </form>

            {{-- ─────────── Pendientes ─────────── --}}
            <section class="rounded-lg border border-gray-200 bg-white">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-2.5">
                    <h2 class="text-sm font-semibold text-gray-800">Pendientes al {{ \Illuminate\Support\Carbon::parse($parametros['corte'])->format('d/m/Y') }}</h2>
                    @if ($puedeExportar && $pendientes->isNotEmpty())
                        <a href="{{ route('gastos.informes.exportar', ['informe' => 'pendientes'] + $parametros) }}" class="text-sm text-indigo-700 underline">Exportar CSV</a>
                    @endif
                </div>

                @foreach ($totalesPendientes as $moneda => $t)
                    <div class="flex flex-wrap gap-x-6 gap-y-1 border-b border-gray-100 bg-gray-50 px-4 py-2 text-sm">
                        <span class="font-medium text-gray-800">{{ $moneda }}</span>
                        <span class="text-gray-700">Empresa <strong class="tabular-nums">{{ Dinero::mostrar($t['empresarial']) }}</strong></span>
                        <span class="text-gray-700">Personal <strong class="tabular-nums">{{ Dinero::mostrar($t['personal']) }}</strong></span>
                        <span class="text-gray-700">Total <strong class="tabular-nums">{{ Dinero::mostrar($t['total']) }}</strong></span>
                        <span class="text-red-700">De ello vencido <strong class="tabular-nums">{{ Dinero::mostrar($totalesVencidos[$moneda]['total'] ?? 0) }}</strong></span>
                    </div>
                @endforeach

                @if ($pendientes->isEmpty())
                    <p class="px-4 py-4 text-sm text-gray-500">No hay saldos abiertos a esa fecha.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <th scope="col" class="px-4 py-2 font-medium">Destinatario y concepto</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Categoría</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Ámbito</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Vence</th>
                                    <th scope="col" class="px-4 py-2 text-right font-medium">Pendiente</th>
                                    <th scope="col" class="px-4 py-2 text-right font-medium">De ello vencido</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Responsable</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($pendientes as $f)
                                    <tr>
                                        <th scope="row" class="px-4 py-2 text-left font-normal">
                                            <a href="{{ route('gastos.show', $f->id) }}" class="font-medium text-indigo-700 underline">{{ $f->beneficiario }}</a>
                                            <span class="block text-gray-600">{{ $f->concepto }}</span>
                                        </th>
                                        <td class="px-4 py-2 text-gray-700">{{ $f->categoria }}</td>
                                        <td class="px-4 py-2 text-gray-700">{{ $f->ambito === 'personal' ? 'Personal'.($f->persona ? ' · '.$f->persona : '') : 'Empresa' }}</td>
                                        <td class="px-4 py-2 text-gray-700">{{ $f->proxima ? \Illuminate\Support\Carbon::parse($f->proxima)->format('d/m/Y') : 'Sin fecha' }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums font-medium text-gray-900">{{ $f->moneda }} {{ Dinero::mostrar((int) $f->pendiente) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums {{ $f->vencido > 0 ? 'font-medium text-red-700' : 'text-gray-500' }}">{{ Dinero::mostrar((int) $f->vencido) }}</td>
                                        <td class="px-4 py-2 text-gray-600">{{ $f->responsable }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            {{-- ─────────── Pagos del período ─────────── --}}
            <section class="rounded-lg border border-gray-200 bg-white">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-2.5">
                    <h2 class="text-sm font-semibold text-gray-800">
                        Pagos del {{ \Illuminate\Support\Carbon::parse($parametros['desde'])->format('d/m/Y') }}
                        al {{ \Illuminate\Support\Carbon::parse($parametros['hasta'])->format('d/m/Y') }}
                    </h2>
                    @if ($puedeExportar && $pagos->isNotEmpty())
                        <a href="{{ route('gastos.informes.exportar', ['informe' => 'pagos'] + $parametros) }}" class="text-sm text-indigo-700 underline">Exportar CSV</a>
                    @endif
                </div>

                @foreach ($totalesPagos as $moneda => $t)
                    <div class="flex flex-wrap gap-x-6 gap-y-1 border-b border-gray-100 bg-gray-50 px-4 py-2 text-sm">
                        <span class="font-medium text-gray-800">{{ $moneda }}</span>
                        <span class="text-gray-700">Empresa <strong class="tabular-nums">{{ Dinero::mostrar($t['empresarial']) }}</strong></span>
                        <span class="text-gray-700">Personal <strong class="tabular-nums">{{ Dinero::mostrar($t['personal']) }}</strong></span>
                        <span class="text-gray-700">Total <strong class="tabular-nums">{{ Dinero::mostrar($t['total']) }}</strong></span>
                        <span class="text-gray-500">{{ $pagos->where('moneda', $moneda)->pluck('pago_id')->unique()->count() }} salida(s) de dinero</span>
                    </div>
                @endforeach

                <p class="border-b border-gray-100 px-4 py-2 text-xs text-gray-500">
                    Una fila por <strong>aplicación</strong>, no por pago: uno que cubre dos obligaciones aparece dos veces con lo que
                    tocó a cada una, para que ninguna categoría reciba el importe completo.
                    Un pago revertido <em>dentro</em> del período no aparece. Uno revertido <em>después</em> sí, porque durante el período
                    constaba como salido, y va marcado: así el mismo mes no da cifras distintas según cuándo se consulte.
                </p>

                @if ($pagos->isEmpty())
                    <p class="px-4 py-4 text-sm text-gray-500">No se registraron pagos en ese período.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <th scope="col" class="px-4 py-2 font-medium">Fecha</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Pago</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Destinatario y concepto</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Categoría</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Ámbito</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Método</th>
                                    <th scope="col" class="px-4 py-2 text-right font-medium">Aplicado</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Pagó</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($pagos as $f)
                                    <tr @class(['bg-gray-50' => $f->revertido_despues])>
                                        <td class="px-4 py-2 tabular-nums text-gray-700">
                                            {{ \Illuminate\Support\Carbon::parse($f->fecha)->format('d/m/Y') }}
                                            @if ($f->revertido_despues)
                                                <span class="mt-0.5 block rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800"
                                                      title="{{ $f->motivo_reversion }}">Revertido después del período</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2"><a href="{{ route('gastos.pagos.show', $f->pago_id) }}" class="text-indigo-700 underline">#{{ $f->pago_id }}</a></td>
                                        <th scope="row" class="px-4 py-2 text-left font-normal">
                                            <a href="{{ route('gastos.show', $f->gasto_id) }}" class="font-medium text-indigo-700 underline">{{ $f->beneficiario }}</a>
                                            <span class="block text-gray-600">{{ $f->concepto }}</span>
                                        </th>
                                        <td class="px-4 py-2 text-gray-700">{{ $f->categoria }}</td>
                                        <td class="px-4 py-2 text-gray-700">{{ $f->ambito === 'personal' ? 'Personal' : 'Empresa' }}</td>
                                        <td class="px-4 py-2 text-gray-700">{{ config('gastos.metodos')[$f->metodo] ?? $f->metodo }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums font-medium text-gray-900">{{ $f->moneda }} {{ $f->aplicado }}</td>
                                        <td class="px-4 py-2 text-gray-600">{{ $f->pagador }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            @unless ($puedeExportar)
                <p class="text-xs text-gray-500">Exportar requiere el permiso <code>gastos.exportar</code>.</p>
            @endunless
        </div>
    </div>
</x-app-layout>
