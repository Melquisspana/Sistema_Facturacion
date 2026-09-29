@php
    use App\Services\Gastos\ConsultaGastos;
    use App\Services\Gastos\Dinero;
    use App\Services\Gastos\SaldosGastos;

    $control = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
    $hayFiltros = filled($filtros['q']) || filled($filtros['ambito']) || filled($filtros['categoria'])
        || filled($filtros['responsable_id']) || filled($filtros['moneda']);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            {{-- Ya no se llama «Por pagar»: ese nombre lo lleva el panel, y dos pantallas
                 con el mismo título obligan a mirar la URL para saber dónde se está.
                 Esta es la que busca y filtra, y se llama por lo que hace. --}}
            <div>
                <h1 class="text-xl font-semibold leading-tight text-gray-800">Buscar y filtrar</h1>
                <p class="text-sm text-gray-500">Todas las obligaciones, con búsqueda, filtros y pestañas.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('gastos.panel') }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver al panel</a>
                @can('gastos.pagos.registrar')
                    <a href="{{ route('gastos.pagos.create') }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Registrar pago</a>
                @endcan
                @can('gastos.registrar')
                    <a href="{{ route('gastos.create') }}" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Registrar gasto</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-7xl space-y-4">
            <x-gastos-aviso />
            <x-gastos-franja-avisos :avisos="$avisos" :filtros="array_filter($filtros)" />

            {{-- Totales. Pendiente, vencido y pagado NO se suman entre sí: vencido es un
                 subconjunto de pendiente y pagado pertenece al período. Se muestran en
                 columnas separadas y por ámbito para que nadie los mezcle. --}}
            @if ($totales->isNotEmpty())
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    {{-- Cinco columnas de dinero no caben en un teléfono: a 390 px la tabla
                         medía 525 y quedaba RECORTADA, sin barra para alcanzar «Pagado» ni
                         «Obligaciones». En móvil cada moneda/ámbito es un bloque; desde `sm`
                         vuelve la tabla, que es más fácil de comparar de un vistazo. --}}
                    <ul class="divide-y divide-gray-100 sm:hidden">
                        @foreach ($totales as $t)
                            <li class="px-4 py-2.5">
                                <p class="text-sm font-semibold text-gray-800">{{ $t->moneda }} · {{ $t->ambito === 'personal' ? 'Personal' : 'Empresa' }}</p>
                                <dl class="mt-1 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                                    <div><dt class="inline text-gray-500">Pendiente:</dt> <dd class="inline font-semibold tabular-nums text-gray-900">{{ Dinero::mostrar((int) $t->pendiente) }}</dd></div>
                                    <div><dt class="inline text-gray-500">Vencido:</dt> <dd class="inline font-semibold tabular-nums {{ $t->vencido > 0 ? 'text-red-700' : 'text-gray-600' }}">{{ Dinero::mostrar((int) $t->vencido) }}</dd></div>
                                    <div><dt class="inline text-gray-500">Pagado:</dt> <dd class="inline tabular-nums text-gray-600">{{ Dinero::mostrar((int) $t->pagado) }}</dd></div>
                                    <div><dt class="inline text-gray-500">Obligaciones:</dt> <dd class="inline tabular-nums text-gray-600">{{ $t->cantidad }}</dd></div>
                                </dl>
                            </li>
                        @endforeach
                    </ul>

                    <div class="hidden overflow-x-auto sm:block">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Totales por moneda y ámbito</caption>
                            <thead>
                                <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <th scope="col" class="px-4 py-2 font-medium">Moneda · Ámbito</th>
                                    <th scope="col" class="px-4 py-2 text-right font-medium">Pendiente a hoy</th>
                                    <th scope="col" class="px-4 py-2 text-right font-medium">De ello vencido</th>
                                    <th scope="col" class="px-4 py-2 text-right font-medium">Pagado (histórico)</th>
                                    <th scope="col" class="px-4 py-2 text-right font-medium">Obligaciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($totales as $t)
                                    <tr>
                                        <th scope="row" class="px-4 py-2 text-left font-medium text-gray-800">
                                            {{ $t->moneda }} · {{ $t->ambito === 'personal' ? 'Personal' : 'Empresa' }}
                                        </th>
                                        <td class="px-4 py-2 text-right tabular-nums font-semibold text-gray-900">{{ Dinero::mostrar((int) $t->pendiente) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums {{ $t->vencido > 0 ? 'font-semibold text-red-700' : 'text-gray-500' }}">{{ Dinero::mostrar((int) $t->vencido) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums text-gray-600">{{ Dinero::mostrar((int) $t->pagado) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums text-gray-600">{{ $t->cantidad }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <p class="border-t border-gray-100 px-4 py-2 text-xs text-gray-500">
                        Vencido es parte del pendiente, no una cifra aparte. Pagado es histórico y no se resta del pendiente.
                        @if ($esperandoMonto > 0)
                            · <a href="{{ route('gastos.index', ['pestana' => 'por_completar'] + $filtros) }}" class="underline">{{ $esperandoMonto }} esperando monto</a>, fuera de estos totales.
                        @endif
                    </p>
                </div>
            @endif

            {{-- Pestañas. El contador respeta los filtros puestos, así se ve si la
                 búsqueda tiene resultados en otra pestaña antes de cambiarla. --}}
            <div class="flex flex-wrap gap-1 border-b border-gray-200">
                @foreach (ConsultaGastos::PESTANAS as $clave => $etiqueta)
                    @php $activa = $filtros['pestana'] === $clave; @endphp
                    <a href="{{ route('gastos.index', ['pestana' => $clave] + $filtros) }}"
                       @if ($activa) aria-current="page" @endif
                       class="inline-flex min-h-11 items-center gap-2 rounded-t-md px-3 text-sm {{ $activa ? 'border-b-2 border-indigo-600 font-semibold text-indigo-700' : 'text-gray-600 hover:text-gray-900' }}">
                        {{ $etiqueta }}
                        <span class="rounded-full px-2 py-0.5 text-xs tabular-nums {{ $activa ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600' }}">{{ $conteos[$clave] }}</span>
                    </a>
                @endforeach
            </div>

            <form method="GET" action="{{ route('gastos.index') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <input type="hidden" name="pestana" value="{{ $filtros['pestana'] }}">
                <div class="sm:col-span-2">
                    <label for="q" class="sr-only">Buscar</label>
                    <input id="q" name="q" value="{{ $filtros['q'] }}" maxlength="120" class="{{ $control }}" placeholder="Buscar concepto, destinatario, categoría o persona…">
                </div>
                @can('gastos.personales')
                    <div>
                        <label for="ambito" class="sr-only">Ámbito</label>
                        <select id="ambito" name="ambito" class="{{ $control }}">
                            <option value="">Empresa y personal</option>
                            <option value="empresarial" @selected($filtros['ambito'] === 'empresarial')>Solo empresa</option>
                            <option value="personal" @selected($filtros['ambito'] === 'personal')>Solo personal</option>
                        </select>
                    </div>
                @endcan
                <div>
                    <label for="categoria" class="sr-only">Categoría</label>
                    <select id="categoria" name="categoria" class="{{ $control }}">
                        <option value="">Todas las categorías</option>
                        @foreach ($categorias as $categoria)
                            <option value="{{ $categoria }}" @selected($filtros['categoria'] === $categoria)>{{ $categoria }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="responsable_id" class="sr-only">Responsable</label>
                    <select id="responsable_id" name="responsable_id" class="{{ $control }}">
                        <option value="">Cualquier responsable</option>
                        @foreach ($usuarios as $usuario)
                            <option value="{{ $usuario->id }}" @selected((string) $filtros['responsable_id'] === (string) $usuario->id)>{{ $usuario->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="min-h-11 flex-1 rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Filtrar</button>
                    @if ($hayFiltros)
                        <a href="{{ route('gastos.index', ['pestana' => $filtros['pestana']]) }}" class="inline-flex min-h-11 items-center px-2 text-sm text-gray-500 underline">Limpiar</a>
                    @endif
                </div>
            </form>

            @if ($gastos->isEmpty())
                <div class="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center">
                    <p class="text-sm text-gray-600">
                        @if ($hayFiltros)
                            Ningún gasto de «{{ ConsultaGastos::PESTANAS[$filtros['pestana']] }}» coincide con estos filtros.
                        @else
                            Todavía no hay gastos en «{{ ConsultaGastos::PESTANAS[$filtros['pestana']] }}».
                        @endif
                    </p>
                </div>
            @else
                {{-- Escritorio: tabla. Móvil: la misma información en bloques, sin
                     desplazamiento horizontal de toda la página. --}}
                <div class="hidden overflow-hidden rounded-lg border border-gray-200 bg-white md:block">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                <th scope="col" class="px-4 py-2 font-medium">Destinatario y concepto</th>
                                <th scope="col" class="px-4 py-2 font-medium">Vence</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Saldo</th>
                                <th scope="col" class="px-4 py-2 font-medium">Situación</th>
                                <th scope="col" class="px-4 py-2 font-medium">Responsable</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($gastos as $gasto)
                                @php $r = $saldos->resumen($gasto, $hoy); @endphp
                                <tr class="hover:bg-gray-50">
                                    <th scope="row" class="px-4 py-2.5 text-left font-normal">
                                        <a href="{{ route('gastos.show', $gasto) }}" class="font-medium text-indigo-700 underline">{{ $gasto->beneficiario }}</a>
                                        <span class="block text-gray-600">{{ $gasto->concepto }}</span>
                                        <span class="block text-xs text-gray-500">
                                            {{ $gasto->categoria }}
                                            @if ($gasto->esPersonal())
                                                · <span class="rounded bg-violet-100 px-1.5 py-0.5 text-violet-700">Personal{{ $gasto->persona ? ' · '.$gasto->persona : '' }}</span>
                                            @endif
                                        </span>
                                    </th>
                                    <td class="px-4 py-2.5 text-gray-700">
                                        {{ $r['proxima'] ? \Illuminate\Support\Carbon::parse($r['proxima'])->format('d/m/Y') : 'Sin fecha' }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right tabular-nums font-medium text-gray-900">
                                        {{ $r['pendiente'] === null ? 'Por definir' : $gasto->moneda.' '.Dinero::mostrar($r['pendiente']) }}
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <x-gastos-situacion :resumen="$r" />
                                    </td>
                                    <td class="px-4 py-2.5 text-gray-600">{{ $gasto->responsable?->name }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <ul class="space-y-2 md:hidden">
                    @foreach ($gastos as $gasto)
                        @php $r = $saldos->resumen($gasto, $hoy); @endphp
                        <li class="rounded-lg border border-gray-200 bg-white p-3">
                            <a href="{{ route('gastos.show', $gasto) }}" class="block min-h-11">
                                <p class="font-medium text-indigo-700 underline">{{ $gasto->beneficiario }}</p>
                                <p class="text-sm text-gray-700">{{ $gasto->concepto }}</p>
                            </a>
                            <div class="mt-1 flex flex-wrap items-center justify-between gap-2">
                                <span class="text-base font-semibold tabular-nums text-gray-900">
                                    {{ $r['pendiente'] === null ? 'Por definir' : $gasto->moneda.' '.Dinero::mostrar($r['pendiente']) }}
                                </span>
                                <span class="text-sm text-gray-600">{{ $r['proxima'] ? \Illuminate\Support\Carbon::parse($r['proxima'])->format('d/m/Y') : 'Sin fecha' }}</span>
                            </div>
                            <div class="mt-1"><x-gastos-situacion :resumen="$r" /></div>
                        </li>
                    @endforeach
                </ul>

                <div>{{ $gastos->links() }}</div>
            @endif

            <p class="text-xs text-gray-500">
                <a href="{{ route('gastos.informes', ['ambito' => $filtros['ambito']]) }}" class="underline">Informes de pendientes y pagos</a>
            </p>
        </div>
    </div>
</x-app-layout>
