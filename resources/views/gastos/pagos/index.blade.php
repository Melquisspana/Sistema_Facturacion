@php
    use App\Services\Gastos\Dinero;

    $control = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
    $hayFiltros = filled($filtros['q']) || filled($filtros['desde']) || filled($filtros['hasta'])
        || filled($filtros['metodo']) || filled($filtros['moneda']) || filled($filtros['ambito'] ?? null)
        || $filtros['revertidos'] !== 'incluir';

    $metodos = config('gastos.metodos');
    // Los nombres de mes salen del calendario del propio módulo, que los tiene en
    // castellano. `translatedFormat` depende del locale de la app —hoy en inglés— y
    // dejaba «september» en una pantalla escrita en castellano.
    $calendario = app(\App\Services\Gastos\Recurrencia\CalendarioRecurrencia::class);
    $mes = fn (?string $iso) => $iso
        ? $calendario->nombreMes((int) \Carbon\CarbonImmutable::parse($iso)->format('n'))
        : null;

    // QUÉ se pagó, en palabras. «Universidad · septiembre», «Abono a Proveedor A · pepitoria»:
    // lo que una persona recuerda, no «1 obligación».
    $etiqueta = function ($f) use ($mes) {
        $texto = $f->concepto;
        if (($p = $mes($f->periodo_desde)) !== null) {
            $texto .= ' · '.mb_strtolower($p);
        }
        // Un abono a una cuenta abierta se nombra como tal: ese pago no salda nada.
        return (int) $f->pendiente_actual > 0 && $f->naturaleza === 'compra'
            ? 'Abono a '.$f->beneficiario.' · '.$texto
            : $texto;
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Historial de pagos</h1>
            @can('gastos.pagos.registrar')
                <a href="{{ route('gastos.pagos.create') }}" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Registrar pago</a>
            @endcan
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-7xl space-y-4">
            <x-gastos-aviso />

            <p class="rounded-md border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-600">
                Acá está el <strong>dinero que salió</strong>. Lo que todavía se debe se mira en
                <a href="{{ route('gastos.panel') }}" class="font-medium text-indigo-600 underline">Por pagar</a>.
                Una deuda saldada con nota de crédito no aparece acá: no salió dinero.
            </p>

            {{-- Totales POR MONEDA. Nunca se suman entre sí, y lo revertido va aparte:
                 no es dinero que salió, pero tampoco desapareció del registro. --}}
            @if ($totales->isNotEmpty())
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($totales as $t)
                        <div class="rounded-lg border border-gray-200 bg-white p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $t->moneda }}</p>
                            <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ $t->moneda }} {{ Dinero::mostrar((int) $t->vigente) }}</p>
                            <p class="text-xs text-gray-500">{{ $t->pagos }} pago(s) vigente(s)</p>
                            @if ($t->pagos_revertidos > 0)
                                <p class="mt-2 border-t border-gray-100 pt-2 text-xs text-amber-800">
                                    Revertidos: {{ $t->pagos_revertidos }} por {{ $t->moneda }} {{ Dinero::mostrar((int) $t->revertido) }}
                                    <span class="block text-gray-500">No suman al total de arriba.</span>
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            <form method="GET" action="{{ route('gastos.pagos.index') }}" class="rounded-lg border border-gray-200 bg-white p-3">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <div class="lg:col-span-2">
                        <label for="q" class="sr-only">Buscar</label>
                        <input id="q" name="q" type="search" value="{{ $filtros['q'] }}" placeholder="Concepto, destinatario, categoría o referencia" class="{{ $control }}">
                    </div>
                    <div>
                        <label for="desde" class="block text-xs text-gray-500">Desde</label>
                        <input id="desde" name="desde" type="date" value="{{ $filtros['desde'] }}" class="{{ $control }}">
                    </div>
                    <div>
                        <label for="hasta" class="block text-xs text-gray-500">Hasta</label>
                        <input id="hasta" name="hasta" type="date" value="{{ $filtros['hasta'] }}" class="{{ $control }}">
                    </div>
                    @can('gastos.personales')
                        <div>
                            <label for="ambito" class="block text-xs text-gray-500">De quién</label>
                            <select id="ambito" name="ambito" class="{{ $control }}">
                                <option value="">Todo</option>
                                <option value="empresarial" @selected(($filtros['ambito'] ?? null) === 'empresarial')>Empresa</option>
                                <option value="personal" @selected(($filtros['ambito'] ?? null) === 'personal')>Personal o casa</option>
                            </select>
                        </div>
                    @endcan
                    <div>
                        <label for="metodo" class="block text-xs text-gray-500">Cómo se pagó</label>
                        <select id="metodo" name="metodo" class="{{ $control }}">
                            <option value="">Cualquiera</option>
                            @foreach (config('gastos.metodos') as $clave => $texto)
                                <option value="{{ $clave }}" @selected($filtros['metodo'] === $clave)>{{ $texto }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-end justify-between gap-2">
                    <fieldset class="flex flex-wrap items-center gap-3">
                        <legend class="sr-only">Pagos revertidos</legend>
                        @foreach (['incluir' => 'Todos', 'excluir' => 'Solo vigentes', 'solo' => 'Solo revertidos'] as $clave => $texto)
                            <label class="flex min-h-11 items-center gap-1.5 text-sm text-gray-700">
                                <input type="radio" name="revertidos" value="{{ $clave }}" @checked($filtros['revertidos'] === $clave)
                                       class="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                <span>{{ $texto }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                    <div class="flex gap-2">
                        @if ($hayFiltros)
                            <a href="{{ route('gastos.pagos.index') }}" class="inline-flex min-h-11 items-center px-2 text-sm text-gray-500 underline">Limpiar</a>
                        @endif
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Filtrar</button>
                    </div>
                </div>
            </form>

            @if ($pagos->isEmpty())
                <div class="rounded-lg border border-dashed border-gray-300 bg-white px-4 py-10 text-center">
                    <p class="text-sm text-gray-600">
                        {{ $hayFiltros ? 'Ningún pago coincide con estos filtros.' : 'Todavía no hay pagos registrados.' }}
                    </p>
                </div>
            @else
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    {{-- En móvil cada pago es un bloque: cinco columnas de datos no entran
                         en un teléfono sin recortar la última. --}}
                    <ul class="divide-y divide-gray-100 sm:hidden">
                        @foreach ($pagos as $pago)
                            @php $mixtoOculto = (int) $pago->gastos_visibles < (int) $pago->gastos_totales; @endphp
                            <li class="px-4 py-3 {{ $pago->revertido_at ? 'bg-amber-50/40' : '' }}">
                                <a href="{{ route('gastos.pagos.show', $pago) }}" class="block min-h-11">
                                    <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-gray-900">
                                        {{ $pago->beneficiario }}
                                        @if ($pago->revertido_at)
                                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Revertido</span>
                                        @endif
                                    </p>
                                    <p class="text-sm tabular-nums text-gray-700">
                                        {{ $pago->moneda }} {{ Dinero::mostrar((int) $pago->importe_visible) }}
                                        @if ($mixtoOculto)
                                            <span class="text-xs font-normal text-gray-500">(tu parte)</span>
                                        @endif
                                    </p>
                                    <p class="mt-0.5 text-xs text-gray-500">
                                        {{ $pago->fecha->format('Y-m-d') }} · {{ $metodos[$pago->metodo] ?? $pago->metodo }}
                                    </p>
                                    @php $filas = $reparto[$pago->id] ?? collect(); @endphp
                                    @if ($filas->isNotEmpty())
                                        <p class="mt-0.5 text-xs text-gray-600">
                                            {{ $filas->count() === 1 ? $etiqueta($filas->first()) : $filas->count().' obligaciones' }}
                                        </p>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>

                    <div class="hidden overflow-x-auto sm:block">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Pagos realizados</caption>
                            <thead>
                                <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <th scope="col" class="px-4 py-2 font-medium">Fecha</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Destinatario</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Cómo</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Cubre</th>
                                    <th scope="col" class="px-4 py-2 text-right font-medium">Importe</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($pagos as $pago)
                                    @php $mixtoOculto = (int) $pago->gastos_visibles < (int) $pago->gastos_totales; @endphp
                                    <tr class="hover:bg-gray-50 {{ $pago->revertido_at ? 'bg-amber-50/40' : '' }}">
                                        <td class="px-4 py-2.5 tabular-nums text-gray-600">{{ $pago->fecha->format('Y-m-d') }}</td>
                                        <td class="px-4 py-2.5">
                                            <a href="{{ route('gastos.pagos.show', $pago) }}" class="font-medium text-indigo-600 hover:underline">{{ $pago->beneficiario }}</a>
                                            @if ($pago->revertido_at)
                                                <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Revertido</span>
                                                <span class="block text-xs text-gray-500">{{ $pago->motivo_reversion }}</span>
                                            @elseif (! $mixtoOculto && filled($pago->referencia))
                                                {{-- La referencia es parte de la CABECERA del pago: solo se
                                                     muestra a quien alcanza el pago entero. --}}
                                                <span class="block text-xs text-gray-500">Ref. {{ $pago->referencia }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-gray-600">{{ config('gastos.metodos')[$pago->metodo] ?? $pago->metodo }}</td>
                                        <td class="px-4 py-2.5 text-gray-600">
                                            @php $filas = $reparto[$pago->id] ?? collect(); @endphp

                                            @if ($filas->count() === 1)
                                                {{-- Una sola obligación: se dice cuál, sin desplegar nada. --}}
                                                @php $f = $filas->first(); @endphp
                                                <a href="{{ route('gastos.show', $f->gasto_id) }}" class="hover:underline">{{ $etiqueta($f) }}</a>
                                                <span class="ml-1 rounded px-1.5 py-0.5 text-[11px] font-medium {{ $f->ambito === 'personal' ? 'bg-violet-50 text-violet-700' : 'bg-slate-100 text-slate-600' }}">
                                                    {{ $f->ambito === 'personal' ? 'Casa' : 'Empresa' }}
                                                </span>
                                            @elseif ($filas->count() > 1)
                                                {{-- Varias: resumen arriba y el reparto a un clic. --}}
                                                <details>
                                                    <summary class="cursor-pointer select-none hover:underline">
                                                        {{ $filas->count() }} obligaciones ·
                                                        <span class="text-gray-500">{{ $filas->pluck('beneficiario')->unique()->take(2)->implode(', ') }}</span>
                                                    </summary>
                                                    <ul class="mt-1 space-y-1 border-l-2 border-gray-200 pl-3">
                                                        @foreach ($filas as $f)
                                                            <li class="text-xs">
                                                                <a href="{{ route('gastos.show', $f->gasto_id) }}" class="text-indigo-600 hover:underline">{{ $etiqueta($f) }}</a>
                                                                <span class="tabular-nums text-gray-600">· {{ $f->moneda }} {{ Dinero::mostrar((int) $f->aplicado) }}</span>
                                                                @if ((int) $f->pendiente_actual > 0)
                                                                    <span class="block text-gray-500">Le queda {{ $f->moneda }} {{ Dinero::mostrar((int) $f->pendiente_actual) }} <em>hoy</em></span>
                                                                @endif
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                </details>
                                            @else
                                                <span class="text-gray-400">—</span>
                                            @endif

                                            @if ($mixtoOculto)
                                                <span class="block text-xs text-gray-500">de {{ $pago->gastos_totales }} que cubre</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-right">
                                            <span class="font-semibold tabular-nums {{ $pago->revertido_at ? 'text-gray-400 line-through' : 'text-gray-900' }}">
                                                {{ $pago->moneda }} {{ Dinero::mostrar((int) $pago->importe_visible) }}
                                            </span>
                                            @if ($mixtoOculto)
                                                {{-- Nunca el total: delataría el importe del ámbito que no se alcanza. --}}
                                                <span class="block text-xs text-gray-500">tu parte</span>
                                            @endif

                                            @php $filas = $reparto[$pago->id] ?? collect(); @endphp
                                            @if (! $pago->revertido_at && $filas->isNotEmpty())
                                                @if ($filas->every(fn ($f) => (int) $f->pendiente_actual <= 0))
                                                    <span class="mt-0.5 block text-xs text-emerald-700">Saldó lo que cubre</span>
                                                @else
                                                    <span class="mt-0.5 block text-xs text-amber-700">Abono · queda saldo hoy</span>
                                                @endif
                                            @endif

                                            @if ((int) ($pago->comprobantes ?? 0) > 0)
                                                <a href="{{ route('gastos.pagos.show', $pago) }}" class="mt-0.5 block text-xs text-indigo-600 underline">
                                                    Ver comprobante{{ $pago->comprobantes > 1 ? 's ('.$pago->comprobantes.')' : '' }}
                                                </a>
                                            @else
                                                <span class="mt-0.5 block text-xs text-gray-400">Sin comprobante</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                {{ $pagos->links() }}

                <p class="text-xs text-gray-500">
                    Un pago puede cubrir varias obligaciones. Cuando cubre alguna que no alcanzás, se muestra
                    <strong>tu parte</strong> y no el importe total del pago. El saldo que aparece en el reparto es
                    <strong>el de hoy</strong>, no el que quedó justo después de ese pago: si hubo movimientos
                    posteriores, son cifras distintas.
                </p>
            @endif
        </div>
    </div>
</x-app-layout>
