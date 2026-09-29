@php
    use App\Services\Gastos\Dinero;
    use App\Services\Planilla\EstadoPlanilla;

    $c = 'block w-full rounded border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
    $boton = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50';
    $hoy = now()->toDateString();
    $insignia = [
        'pendiente' => 'bg-amber-50 text-amber-800', 'parcial' => 'bg-sky-50 text-sky-800',
        'pagado' => 'bg-emerald-50 text-emerald-800', 'sin_obligacion' => 'bg-gray-100 text-gray-500',
        'preparado' => 'bg-gray-100 text-gray-700',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold leading-tight text-gray-800">Pagos · {{ $planilla->periodo }}</h1>
                <p class="text-sm text-gray-500">{{ $planilla->periodoEnPalabras() }}</p>
            </div>
            <a href="{{ route('planilla.show', $planilla) }}" class="{{ $boton }}">Volver a la planilla</a>
        </div>
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

            <p class="rounded-md border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-600">
                Cada pago de acá es un <strong>pago normal de Gastos</strong> contra la obligación de esa persona.
                No hay una segunda contabilidad: el saldo que ves es el de su gasto.
                Pendiente total: <strong class="tabular-nums">{{ $planilla->moneda }} {{ Dinero::mostrar($avance['empleados_pendiente'] + $avance['terceros_pendiente']) }}</strong>.
            </p>

            {{-- ══════ PAGO POR LOTE ══════
                 N pagos, uno por persona: no se inventa un pago único de todos, porque
                 eso rompería la regla de un beneficiario por pago y haría imposible
                 revertir el de una sola. Reintentar el lote salta a quien ya cobró. --}}
            @can('planilla.pagar')
                @if ($planilla->confirmada())
                    <form method="POST" action="{{ route('planilla.pagar.lote', $planilla) }}"
                          class="rounded-lg border border-indigo-200 bg-indigo-50/50 p-4"
                          x-data="{ todos: true }">
                        @csrf
                        <input type="hidden" name="clave" value="{{ $clave }}">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-indigo-900">Pagar por lote</h2>
                        <p class="mt-1 text-xs text-indigo-900">
                            Registra un pago por cada persona seleccionada, por lo que le queda pendiente. Si el lote
                            se interrumpe, repetirlo completa lo que falta <strong>sin pagar dos veces</strong>.
                        </p>

                        <div class="mt-3 grid gap-3 sm:grid-cols-4">
                            <div>
                                <label for="lote-fecha" class="block text-xs text-gray-500">Fecha</label>
                                <input id="lote-fecha" name="fecha" type="date" value="{{ $hoy }}" required class="{{ $c }} min-h-11">
                            </div>
                            <div>
                                <label for="lote-metodo" class="block text-xs text-gray-500">Cómo se paga</label>
                                <select id="lote-metodo" name="metodo" class="{{ $c }} min-h-11">
                                    @foreach (config('gastos.metodos') as $clave => $texto)
                                        <option value="{{ $clave }}">{{ $texto }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="lote-pagador" class="block text-xs text-gray-500">Quién paga</label>
                                <select id="lote-pagador" name="pagado_por" class="{{ $c }} min-h-11">
                                    @foreach ($usuarios as $u)
                                        <option value="{{ $u->id }}" @selected($u->id === auth()->id())>{{ $u->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="lote-ref" class="block text-xs text-gray-500">Referencia</label>
                                <input id="lote-ref" name="referencia" type="text" maxlength="180" class="{{ $c }} min-h-11">
                            </div>
                        </div>

                        <div class="mt-3 space-y-1">
                            @foreach ($planilla->detalles as $detalle)
                                @php $e = $estado->deDetalle($detalle); @endphp
                                @if ($e['pendiente'] > 0)
                                    <label class="flex min-h-11 items-center gap-2 rounded border border-gray-200 bg-white px-3 text-sm">
                                        <input type="checkbox" name="detalles[]" value="{{ $detalle->id }}" checked
                                               class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                        <span class="flex-1 truncate">{{ $detalle->nombre_snapshot }}</span>
                                        <span class="whitespace-nowrap tabular-nums text-gray-700">{{ $planilla->moneda }} {{ Dinero::mostrar($e['pendiente']) }}</span>
                                    </label>
                                @endif
                            @endforeach
                        </div>

                        <div class="mt-3">
                            <label for="lote-sin" class="block text-xs text-gray-500">Si no hay comprobante, explicá por qué</label>
                            <input id="lote-sin" name="sin_comprobante" type="text" maxlength="250" class="{{ $c }} min-h-11">
                        </div>

                        <button type="submit" class="mt-3 inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700">
                            Registrar el lote
                        </button>
                    </form>
                @endif
            @endcan

            {{-- ══════ PERSONA POR PERSONA ══════ --}}
            <section class="space-y-2">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Personas</h2>

                @foreach ($planilla->detalles as $detalle)
                    @php
                        $e = $estado->deDetalle($detalle);
                        $pagos = $detalle->gasto_id ? ($pagosPorGasto[$detalle->gasto_id] ?? collect()) : collect();
                    @endphp
                    <div class="rounded-lg border border-gray-200 bg-white p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900">{{ $detalle->nombre_snapshot }}</p>
                                <p class="text-xs text-gray-500">
                                    A pagar {{ $planilla->moneda }} {{ Dinero::mostrar($detalle->a_pagar) }}
                                    @if ($e['pagado'] > 0) · pagado {{ Dinero::mostrar($e['pagado']) }} @endif
                                    @if ($e['pendiente'] > 0) · <span class="text-amber-700">queda {{ Dinero::mostrar($e['pendiente']) }}</span> @endif
                                </p>
                            </div>
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $insignia[$e['estado']] }}">{{ EstadoPlanilla::ETIQUETAS[$e['estado']] }}</span>
                        </div>

                        @if ($pagos->isNotEmpty())
                            <ul class="mt-2 space-y-1 border-t border-gray-100 pt-2 text-xs">
                                @foreach ($pagos as $p)
                                    <li class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="{{ $p->revertido_at ? 'text-gray-400 line-through' : 'text-gray-700' }}">
                                            {{ \Illuminate\Support\Carbon::parse($p->fecha)->format('d/m/Y') }} ·
                                            {{ $planilla->moneda }} {{ Dinero::mostrar($p->importe) }} ·
                                            {{ config('gastos.metodos')[$p->metodo] ?? $p->metodo }}
                                        </span>
                                        @if ($p->revertido_at)
                                            <span class="text-amber-700">Revertido · {{ $p->motivo_reversion }}</span>
                                        @else
                                            @can('gastos.pagos.corregir')
                                                <form method="POST" action="{{ route('planilla.pagar.revertir', $p->pago_id) }}" class="flex items-center gap-1">
                                                    @csrf
                                                    <input name="motivo" type="text" required minlength="5" maxlength="500"
                                                           placeholder="Motivo para revertir" class="rounded border-gray-300 text-xs shadow-sm">
                                                    <button type="submit" class="{{ $boton }} border-transparent text-red-700 hover:bg-red-50">Revertir</button>
                                                </form>
                                            @endcan
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @can('planilla.pagar')
                            @if ($e['pendiente'] > 0)
                                {{-- Abono PARCIAL: el importe viene propuesto con lo que queda,
                                     pero se puede bajar. Lo valida el propio Gastos. --}}
                                <form method="POST" action="{{ route('planilla.pagar.persona', $detalle) }}" class="mt-2 grid gap-2 border-t border-gray-100 pt-2 sm:grid-cols-5">
                                    @csrf
                                    <input type="hidden" name="clave" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                    <div>
                                        <label class="block text-xs text-gray-500" for="imp-{{ $detalle->id }}">Importe</label>
                                        <input id="imp-{{ $detalle->id }}" name="importe" type="text" inputmode="decimal"
                                               value="{{ Dinero::decimal($e['pendiente']) }}" class="{{ $c }} min-h-11 text-right tabular-nums">
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-500" for="fec-{{ $detalle->id }}">Fecha</label>
                                        <input id="fec-{{ $detalle->id }}" name="fecha" type="date" value="{{ $hoy }}" class="{{ $c }} min-h-11">
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-500" for="met-{{ $detalle->id }}">Cómo</label>
                                        <select id="met-{{ $detalle->id }}" name="metodo" class="{{ $c }} min-h-11">
                                            @foreach (config('gastos.metodos') as $clave => $texto)
                                                <option value="{{ $clave }}">{{ $texto }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-500" for="pag-{{ $detalle->id }}">Quién paga</label>
                                        <select id="pag-{{ $detalle->id }}" name="pagado_por" class="{{ $c }} min-h-11">
                                            @foreach ($usuarios as $u)
                                                <option value="{{ $u->id }}" @selected($u->id === auth()->id())>{{ $u->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="flex items-end gap-1">
                                        <input name="sin_comprobante" type="hidden" value="Pago de planilla registrado desde el módulo.">
                                        <button type="submit" class="{{ $boton }} w-full">Pagar</button>
                                    </div>
                                </form>
                            @endif
                        @endcan
                    </div>
                @endforeach
            </section>

            {{-- ══════ TERCEROS ══════ --}}
            @if ($terceros->isNotEmpty())
                <section class="space-y-2">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Terceros</h2>
                    @foreach ($terceros as $t)
                        @php $e = $estado->deTercero($t); @endphp
                        <div class="rounded-lg border border-gray-200 bg-white p-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-medium text-gray-900">{{ $t->tercero }}</p>
                                <span class="text-sm tabular-nums">{{ $planilla->moneda }} {{ Dinero::mostrar($t->importe) }}
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $insignia[$e['estado']] }}">{{ EstadoPlanilla::ETIQUETAS[$e['estado']] }}</span>
                                </span>
                            </div>
                            @can('planilla.pagar')
                                @if ($e['pendiente'] > 0)
                                    <form method="POST" action="{{ route('planilla.pagar.tercero', $t) }}" class="mt-2 grid gap-2 sm:grid-cols-5">
                                        @csrf
                                        <input type="hidden" name="clave" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                        <input name="importe" type="text" inputmode="decimal" value="{{ Dinero::decimal($e['pendiente']) }}" class="{{ $c }} min-h-11 text-right tabular-nums" aria-label="Importe">
                                        <input name="fecha" type="date" value="{{ $hoy }}" class="{{ $c }} min-h-11" aria-label="Fecha">
                                        <select name="metodo" class="{{ $c }} min-h-11" aria-label="Cómo se paga">
                                            @foreach (config('gastos.metodos') as $clave => $texto)
                                                <option value="{{ $clave }}">{{ $texto }}</option>
                                            @endforeach
                                        </select>
                                        <select name="pagado_por" class="{{ $c }} min-h-11" aria-label="Quién paga">
                                            @foreach ($usuarios as $u)
                                                <option value="{{ $u->id }}" @selected($u->id === auth()->id())>{{ $u->name }}</option>
                                            @endforeach
                                        </select>
                                        <div class="flex items-end">
                                            <input name="sin_comprobante" type="hidden" value="Pago a tercero desde planilla.">
                                            <button type="submit" class="{{ $boton }} w-full">Pagar</button>
                                        </div>
                                    </form>
                                @endif
                            @endcan
                        </div>
                    @endforeach
                </section>
            @endif
        </div>
    </div>
</x-app-layout>
