<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-paper-100 leading-tight">Vista previa del archivo de quedan</h2>
            <a href="{{ $volver }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">← Volver a seleccionar</a>
        </div>
    </x-slot>

    @php
        $gris = 'text-gray-500 dark:text-paper-300';
        $texto = 'text-gray-800 dark:text-paper-100';
    @endphp

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Qué es esta pantalla, y sobre todo qué NO pasó todavía. --}}
            <p class="rounded-md bg-blue-50 border border-blue-200 p-3 text-sm text-blue-700" role="status">
                <strong>Todavía no se preparó nada</strong>: no hay solicitud ni archivo, nada se presentó y ningún pago
                cambió. Estos son los renglones que llevaría el archivo, en su orden. Al confirmar se vuelve a
                comprobar todo; si algo cambió mientras tanto, no se prepara y se avisa el motivo.
            </p>

            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-4 text-sm">
                    <div>
                        <dt class="text-xs {{ $gris }}">Cliente</dt>
                        <dd class="{{ $texto }}">{{ $cliente->nombre }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs {{ $gris }}">Documentos</dt>
                        <dd class="font-mono {{ $texto }}">{{ count($filas) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs {{ $gris }}">Suma de los CCF</dt>
                        <dd class="font-mono {{ $texto }}">{{ number_format((float) $total, 2) }}</dd>
                        <dd class="text-xs {{ $gris }}">El archivo no lleva importes: identifica entregas.</dd>
                    </div>
                </dl>
            </div>

            {{-- ---------- Notas de crédito de estos CCF: en el portal van PRIMERO ---------- --}}
            @php
                $conNotas = collect($notas['documentos'])->filter(fn ($d) => $d['notas'] !== []);
                $situaciones = [
                    \App\Services\Cobros\NotasDelQuedan::AVISO => ['No se exporta ni descuenta', 'bg-gray-100 text-gray-700'],
                    \App\Services\Cobros\NotasDelQuedan::BLOQUEADA => ['Bloqueada: falta un dato', 'bg-red-100 text-red-700'],
                    \App\Services\Cobros\NotasDelQuedan::POR_EXPORTAR => ['Aún en ningún archivo de NC', 'bg-amber-100 text-amber-800'],
                    \App\Services\Cobros\NotasDelQuedan::SIN_PRESENTAR => ['En un lote sin carga registrada', 'bg-amber-100 text-amber-800'],
                    \App\Services\Cobros\NotasDelQuedan::PRESENTADA => ['Carga al portal registrada', 'bg-green-100 text-green-700'],
                ];
            @endphp
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-1">Notas de crédito de estos CCF</h3>
                <p class="text-sm {{ $gris }} mb-4">
                    Calleja no paga un CCF subido sin sus notas: en el portal se carga <strong>primero</strong> el archivo de NC
                    (AC02/AC04) y <strong>después</strong> este de quedan. Se listan las NC relacionadas con cada CCF en este sistema;
                    solo las aceptadas realmente por Hacienda se exportan y descuentan.
                </p>

                @if ($bloqueos !== [])
                    <div class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">
                        <strong>Todavía no se puede preparar el quedan de estos CCF.</strong>
                        <ul class="mt-1 list-disc pl-5">
                            @foreach ($bloqueos as $bloqueo)<li>{{ $bloqueo }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                @if ($notas['no_verificables'] !== [])
                    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800" role="status">
                        <strong>NC sin verificar en {{ count($notas['no_verificables']) }} CCF.</strong>
                        No se afirma que no tengan notas: la relación de NC de los CCF externos queda pendiente de la captura
                        masiva de Gmail. Antes de confirmar, compruebe fuera del sistema si llevan NC y si ya se subieron.
                        <ul class="mt-1 list-disc pl-5">
                            @foreach ($notas['no_verificables'] as $externo)
                                <li><span class="font-mono">{{ $externo->numero_control }}</span>: {{ $notas['documentos'][$externo->id]['motivo'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($notas['avisos'] > 0)
                    <p class="mb-4 text-sm text-gray-700 dark:text-paper-100">
                        {{ $notas['avisos'] }} nota(s) relacionada(s) no están aceptadas realmente por Hacienda: se muestran como aviso,
                        pero no se exportan ni descuentan.
                    </p>
                @endif

                @if ($conNotas->isEmpty())
                    <p class="text-sm {{ $gris }}">
                        @if (count($notas['no_verificables']) === count($notas['documentos']))
                            No hay CCF con DTE en este sistema en esta selección: sus NC no se pudieron verificar.
                        @else
                            Ninguno de los CCF con DTE en este sistema tiene notas de crédito relacionadas.
                        @endif
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Notas de crédito relacionadas con los CCF seleccionados</caption>
                            <thead class="bg-gray-50 dark:bg-ink-700 text-gray-600 dark:text-paper-300">
                                <tr>
                                    <th scope="col" class="p-2 text-left font-medium">CCF</th>
                                    <th scope="col" class="p-2 text-left font-medium">Nota de crédito</th>
                                    <th scope="col" class="p-2 text-left font-medium">Estado fiscal</th>
                                    <th scope="col" class="p-2 text-left font-medium">Albarán propio</th>
                                    <th scope="col" class="p-2 text-right font-medium">Importe</th>
                                    <th scope="col" class="p-2 text-left font-medium">Archivo de NC</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                                @foreach ($conNotas as $entrada)
                                    @foreach ($entrada['notas'] as $n)
                                        <tr>
                                            <td class="p-2 font-mono whitespace-nowrap">{{ $entrada['documento']->numero_control }}</td>
                                            <td class="p-2 font-mono whitespace-nowrap">
                                                {{ $n['nc']->numero_control ?? ('#'.$n['nc']->id) }}
                                                <span class="block font-sans text-[11px] {{ $gris }}">{{ $n['nc']->fecha_emision?->format('d/m/Y') ?? '—' }}</span>
                                            </td>
                                            <td class="p-2 text-xs {{ $n['aceptada'] ? 'text-green-700' : 'text-gray-700 dark:text-paper-100' }}">{{ $n['estado'] }}</td>
                                            <td class="p-2 font-mono text-xs">
                                                @if ($n['albaran'])
                                                    {{ $n['albaran']->numero_canonico }}
                                                    <span class="block font-sans text-[11px] {{ $gris }}">{{ $n['albaran']->tipo_codigo }}</span>
                                                @else
                                                    <span class="font-sans {{ $n['aceptada'] ? 'text-red-700' : $gris }}">Sin albarán propio</span>
                                                @endif
                                            </td>
                                            <td class="p-2 text-right font-mono">{{ number_format((float) $n['importe'], 2) }}</td>
                                            <td class="p-2 text-xs">
                                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] {{ $situaciones[$n['situacion']][1] }}">{{ $situaciones[$n['situacion']][0] }}</span>
                                                @if ($n['lote'])
                                                    <a href="{{ route('ppq.nc-exportaciones.show', $n['lote']) }}" class="block font-mono text-indigo-600 hover:underline">{{ $n['lote']->referencia }}</a>
                                                    @if ($n['lote']->presentada())
                                                        <span class="block {{ $gris }}">cargado el {{ $n['lote']->presentada_en->format('d/m/Y') }}</span>
                                                    @endif
                                                @endif
                                                @if ($n['faltantes'] !== [])
                                                    <span class="block text-red-700">Falta {{ implode(', ', $n['faltantes']) }}.</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($notas['por_exportar'] !== [] && $notas['bloqueadas'] === [])
                    <form method="POST" action="{{ route('cobros.solicitudes.notas', $cliente) }}" class="mt-4 flex flex-wrap items-center gap-3">
                        @csrf
                        <input type="hidden" name="previa" value="{{ $token }}">
                        <button class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            Preparar notas de estos CCF
                        </button>
                        <span class="text-xs {{ $gris }}">
                            Crea un archivo de NC solo con las {{ count($notas['por_exportar']) }} nota(s) aceptada(s) que aún no están en ninguno.
                            Las ya exportadas conservan su archivo. Después hay que descargarlo, subirlo y registrar la carga.
                        </span>
                    </form>
                @elseif ($notas['por_exportar'] !== [])
                    <p class="mt-4 text-sm text-red-700">Resuelva primero los datos faltantes: el archivo de NC no se prepara con huecos.</p>
                @endif
            </div>

            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-1">Renglones del archivo</h3>
                <p class="text-sm {{ $gris }} mb-4">
                    Las cinco columnas de la derecha son las del archivo, con sus encabezados tal como vienen en la plantilla.
                    Orden, CCF y monto son solo referencia: no viajan en el archivo.
                </p>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">Renglones que llevaría el archivo de quedan de {{ $cliente->nombre }}</caption>
                        <thead class="bg-gray-50 text-gray-600 dark:text-paper-300">
                            <tr>
                                <th scope="col" class="p-2 text-right font-medium">Orden</th>
                                <th scope="col" class="p-2 text-left font-medium">CCF</th>
                                <th scope="col" class="p-2 text-right font-medium">Monto</th>
                                @foreach ($columnas as $columna)
                                    <th scope="col" class="p-2 text-left font-medium font-mono whitespace-pre border-l border-gray-200 dark:border-ink-600">{{ $columna }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                            @foreach ($filas as $fila)
                                <tr>
                                    <td class="p-2 text-right font-mono {{ $gris }}">{{ $fila['orden'] }}</td>
                                    <td class="p-2 font-mono whitespace-nowrap">
                                        {{ $fila['documento']->numero_control }}
                                        <span class="block font-sans text-[11px] {{ $gris }}">{{ $fila['documento']->fecha_emision?->format('d/m/Y') ?? '—' }}</span>
                                    </td>
                                    <td class="p-2 text-right font-mono">{{ number_format((float) $fila['datos']['monto'], 2) }}</td>
                                    @foreach (\App\Services\Cobros\Exportadores\ExportadorSolicitudCargaMasivaV1::celdas($fila['datos']) as $celda)
                                        <td class="p-2 font-mono border-l border-gray-200 dark:border-ink-600">{{ $celda }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <form method="POST" action="{{ route('cobros.solicitudes.store', $cliente) }}" class="mt-6 flex flex-wrap items-center gap-3">
                    @csrf
                    <input type="hidden" name="previa" value="{{ $token }}">
                    @if ($bloqueos === [])
                        <button class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            Confirmar y preparar quedan
                        </button>
                    @else
                        <button type="button" disabled aria-describedby="quedan-bloqueado"
                                class="inline-flex items-center rounded-md bg-gray-300 px-4 py-2 text-sm font-medium text-gray-600 cursor-not-allowed">
                            Confirmar y preparar quedan
                        </button>
                        <span id="quedan-bloqueado" class="text-xs text-red-700">Primero las NC: falta registrar la carga al portal de todas las notas aceptadas de estos CCF.</span>
                    @endif
                    <a href="{{ $volver }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Volver a seleccionar
                    </a>
                </form>
                <p class="mt-2 text-xs {{ $gris }}">
                    Confirmar prepara la solicitud; no la presenta. La presentación se registra después, cuando alguien suba el archivo al portal.
                </p>
            </div>
        </div>
    </div>
</x-app-layout>
