<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-paper-100 leading-tight">
                Formato de notas de crédito
            </h2>
            <a href="{{ route('facturacion.index', ['tipo_dte' => '05']) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">← Notas de crédito</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700" role="alert">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            {{-- Qué es y qué NO hace. Lo segundo importa tanto como lo primero. --}}
            <div class="rounded-md bg-blue-50 border border-blue-200 p-4 text-sm text-blue-700">
                <p>
                    Armá el archivo que pide el cliente: <strong>una fila por nota de crédito</strong>, con los datos de
                    su albarán. Las notas se acumulan hasta que decidas generarlo, así que
                    <strong>un mismo archivo puede llevar notas de días distintos</strong>.
                </p>
                <p class="mt-2">
                    El sistema <strong>no envía correos</strong>: registra el formato como generado y, al bajarlo, como
                    descargado. Descargar o regenerar cuantas veces haga falta
                    <strong>no duplica ni vuelve a marcar documentos</strong>.
                </p>
            </div>

            {{-- ---------- Cliente ---------- --}}
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-4">
                <form method="GET" action="{{ route('ppq.nc-exportaciones.index') }}" class="flex flex-wrap items-end gap-3">
                    <div class="grow sm:grow-0">
                        <label for="cliente_id" class="block text-sm font-medium text-gray-700 dark:text-paper-100">Cliente</label>
                        <select id="cliente_id" name="cliente_id" class="mt-1 w-full sm:w-72 rounded-md border-gray-300 text-sm">
                            <option value="">— Elegir cliente —</option>
                            @foreach ($clientes as $c)
                                <option value="{{ $c->id }}" @selected($cliente?->id === $c->id)>{{ $c->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="rounded-md bg-gray-800 px-4 py-2 text-sm text-white hover:bg-gray-700">Ver</button>
                </form>

                @if ($clientes->isEmpty())
                    <p class="mt-3 text-sm text-amber-700">
                        Ningún cliente tiene perfil documental activo con formato de exportación.
                        Configuralo en la ficha del cliente → <span class="font-medium">Perfil documental</span>.
                    </p>
                @endif
            </div>

            @if ($cliente)
                {{-- ---------- Filtros: ayudan a encontrar, no agrupan ---------- --}}
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-4">
                    <form method="GET" action="{{ route('ppq.nc-exportaciones.index') }}">
                        <input type="hidden" name="cliente_id" value="{{ $cliente->id }}">
                        <fieldset>
                            <legend class="text-sm font-medium text-gray-700 dark:text-paper-100 mb-1">Filtros (opcionales)</legend>
                            <p class="text-xs text-gray-500 dark:text-paper-300 mb-3">
                                Solo acotan lo que ves para encontrar más rápido. No limitan el archivo a un rango:
                                podés marcar notas de cualquier fecha.
                            </p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
                                <div>
                                    <label for="desde" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Emitidas desde</label>
                                    <input id="desde" name="desde" type="date" value="{{ $filtros['desde'] }}"
                                           class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                </div>
                                <div>
                                    <label for="hasta" class="block text-xs font-medium text-gray-600 dark:text-paper-300">hasta</label>
                                    <input id="hasta" name="hasta" type="date" value="{{ $filtros['hasta'] }}"
                                           class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                </div>
                                <div>
                                    <label for="tipo" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Tipo de albarán</label>
                                    <select id="tipo" name="tipo" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                        <option value="">Todos</option>
                                        @foreach ($cliente->perfilDocumento?->tiposNc ?? [] as $regla)
                                            <option value="{{ $regla->codigo_externo }}" @selected($filtros['tipo'] === $regla->codigo_externo)>
                                                {{ $regla->codigo_externo }} — {{ $regla->tipo_nota_credito?->label() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="sala" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Sala</label>
                                    <select id="sala" name="sala" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                        <option value="">Todas</option>
                                        @foreach ($salas as $s)
                                            <option value="{{ $s }}" @selected($filtros['sala'] === $s)>{{ $s }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="q" class="block text-xs font-medium text-gray-600 dark:text-paper-300">N.º de control o albarán</label>
                                    <input id="q" name="q" type="search" value="{{ $filtros['q'] }}" placeholder="3209 · AC04 · …"
                                           class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap items-center gap-3">
                                <button class="rounded-md bg-gray-800 px-4 py-2 text-sm text-white hover:bg-gray-700">Filtrar</button>
                                @if ($hayFiltros)
                                    <a href="{{ route('ppq.nc-exportaciones.index', ['cliente_id' => $cliente->id]) }}"
                                       class="text-sm text-indigo-600 hover:underline">Quitar filtros y ver todas</a>
                                @endif
                            </div>
                        </fieldset>
                    </form>
                </div>

                {{-- ---------- 1 · Pendientes ---------- --}}
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 mb-1">
                        <h3 class="font-medium text-gray-700 dark:text-paper-100">1 · Pendientes de incluir en un formato</h3>
                        <span class="text-sm text-gray-500 dark:text-paper-300">
                            {{ $pendientes->count() }} nota(s){{ $hayFiltros ? ' con los filtros aplicados' : '' }}
                        </span>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-paper-300 mb-4">
                        Notas de {{ $cliente->nombre }} aceptadas por Hacienda, con albarán registrado, que todavía no
                        entraron en ningún formato. <strong>De la más antigua a la más reciente</strong>, para que
                        ninguna quede olvidada.
                    </p>

                    @if ($pendientes->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-paper-300">
                            @if ($hayFiltros)
                                Ninguna nota coincide con los filtros.
                                <a href="{{ route('ppq.nc-exportaciones.index', ['cliente_id' => $cliente->id]) }}" class="text-indigo-600 hover:underline">Ver todas</a>.
                            @else
                                No hay notas pendientes. Aparecen acá cuando están aceptadas por Hacienda y tienen su albarán registrado.
                            @endif
                        </p>
                    @else
                        <form method="POST" action="{{ route('ppq.nc-exportaciones.store') }}" x-data="{ todas: true }">
                            @csrf
                            <input type="hidden" name="cliente_id" value="{{ $cliente->id }}">

                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <caption class="sr-only">Notas de crédito pendientes de incluir en un formato</caption>
                                    <thead class="bg-gray-50 text-gray-600 dark:text-paper-300">
                                        <tr>
                                            <th scope="col" class="p-2 text-left w-10">
                                                <label class="sr-only" for="marcar_todas">Marcar todas</label>
                                                <input id="marcar_todas" type="checkbox" x-model="todas"
                                                       @change="$el.closest('form').querySelectorAll('input[name=\'dtes[]\']').forEach(c => c.checked = todas)"
                                                       checked class="rounded border-gray-300">
                                            </th>
                                            <th scope="col" class="p-2 text-left font-medium">Emitida</th>
                                            <th scope="col" class="p-2 text-left font-medium">Número de control</th>
                                            <th scope="col" class="p-2 text-left font-medium">Tipo</th>
                                            <th scope="col" class="p-2 text-left font-medium">Sala</th>
                                            <th scope="col" class="p-2 text-left font-medium">Albarán</th>
                                            <th scope="col" class="p-2 text-right font-medium">Total albarán</th>
                                            <th scope="col" class="p-2 text-right font-medium">Total nota</th>
                                            <th scope="col" class="p-2 text-right font-medium">Retención</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                                        @foreach ($pendientes as $nc)
                                            @php
                                                $alb = $nc->albaran;
                                                $difiere = $alb?->total !== null
                                                    && round((float) $alb->total, 2) !== round((float) $nc->total_pagar, 2);
                                            @endphp
                                            <tr>
                                                <td class="p-2">
                                                    <label class="sr-only" for="nc_{{ $nc->id }}">Incluir la nota {{ $nc->numero_control }}</label>
                                                    <input id="nc_{{ $nc->id }}" type="checkbox" name="dtes[]" value="{{ $nc->id }}" checked
                                                           class="rounded border-gray-300">
                                                </td>
                                                <td class="p-2 whitespace-nowrap">{{ $nc->fecha_emision?->format('d/m/Y') }}</td>
                                                <td class="p-2 font-mono whitespace-nowrap">{{ $nc->numero_control }}</td>
                                                <td class="p-2 font-mono">{{ $alb?->tipo_codigo }}</td>
                                                <td class="p-2 font-mono">{{ $alb?->sala_codigo ?? $nc->clienteSucursal?->codigo ?? '—' }}</td>
                                                <td class="p-2 font-mono">{{ $alb?->numero_canonico }}</td>
                                                <td class="p-2 text-right font-mono">{{ $alb?->total !== null ? number_format((float) $alb->total, 2) : '—' }}</td>
                                                <td class="p-2 text-right font-mono {{ $difiere ? 'text-amber-700 font-semibold' : '' }}">
                                                    {{ number_format((float) $nc->total_pagar, 2) }}
                                                    @if ($difiere)<span class="sr-only">— no coincide con el total del albarán</span>@endif
                                                </td>
                                                <td class="p-2 text-right font-mono {{ (float) $nc->iva_retenido > 0 ? 'text-amber-700 font-semibold' : 'text-gray-400' }}">
                                                    {{ number_format((float) $nc->iva_retenido, 2) }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            @can('ppq.gestionar')
                                <button class="mt-4 inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                    Generar formato con las marcadas
                                </button>
                                <p class="mt-2 text-xs text-gray-500 dark:text-paper-300">
                                    Las marcadas dejan de estar pendientes y no podrán entrar en otro formato.
                                    Podés mezclar notas de fechas distintas.
                                </p>
                            @else
                                <p class="mt-4 text-sm text-gray-500 dark:text-paper-300">
                                    Solo lectura: generar el formato requiere permiso de gestión de cobros.
                                </p>
                            @endcan
                        </form>
                    @endif
                </div>

                {{-- ---------- 2 · Ya incluidas ---------- --}}
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 mb-1">
                        <h3 class="font-medium text-gray-700 dark:text-paper-100">2 · Ya incluidas en un formato</h3>
                        <span class="text-sm text-gray-500 dark:text-paper-300">{{ $yaEnLote->count() }} nota(s)</span>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-paper-300 mb-4">
                        Estas ya viajaron en un archivo. No vuelven a pendientes ni pueden entrar en otro.
                    </p>

                    @if ($yaEnLote->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-paper-300">Ninguna nota exportada{{ $hayFiltros ? ' con los filtros aplicados' : ' todavía' }}.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <caption class="sr-only">Notas de crédito ya incluidas en un formato</caption>
                                <thead class="bg-gray-50 text-gray-600 dark:text-paper-300">
                                    <tr>
                                        <th scope="col" class="p-2 text-left font-medium">Emitida</th>
                                        <th scope="col" class="p-2 text-left font-medium">Número de control</th>
                                        <th scope="col" class="p-2 text-left font-medium">Albarán</th>
                                        <th scope="col" class="p-2 text-right font-medium">Total nota</th>
                                        <th scope="col" class="p-2 text-left font-medium">Formato</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                                    @foreach ($yaEnLote as $nc)
                                        <tr>
                                            <td class="p-2 whitespace-nowrap">{{ $nc->fecha_emision?->format('d/m/Y') }}</td>
                                            <td class="p-2 font-mono whitespace-nowrap">{{ $nc->numero_control }}</td>
                                            <td class="p-2 font-mono">{{ $nc->albaran?->numero_canonico ?? '—' }}</td>
                                            <td class="p-2 text-right font-mono">{{ number_format((float) $nc->total_pagar, 2) }}</td>
                                            <td class="p-2 font-mono">{{ $nc->exportacionItem?->exportacion?->referencia ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                {{-- ---------- 3 · Formatos generados ---------- --}}
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                    <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-1">3 · Formatos generados</h3>
                    <p class="text-sm text-gray-500 dark:text-paper-300 mb-4">
                        Volver a descargar uno lo <strong>regenera con el mismo contenido</strong>: las mismas notas, en
                        el mismo orden. No agrega notas nuevas ni marca ningún documento adicional; solo suma una
                        descarga al registro.
                    </p>

                    @if ($lotes->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-paper-300">Todavía no se ha generado ningún formato para este cliente.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <caption class="sr-only">Formatos de notas de crédito generados para {{ $cliente->nombre }}</caption>
                                <thead class="bg-gray-50 text-gray-600 dark:text-paper-300">
                                    <tr>
                                        <th scope="col" class="p-2 text-left font-medium">Referencia</th>
                                        <th scope="col" class="p-2 text-left font-medium">Generado</th>
                                        <th scope="col" class="p-2 text-right font-medium">Notas</th>
                                        <th scope="col" class="p-2 text-left font-medium">Estado</th>
                                        <th scope="col" class="p-2 text-left font-medium">Archivo</th>
                                        <th scope="col" class="p-2 text-right font-medium">Descargas</th>
                                        <th scope="col" class="p-2 text-right font-medium"><span class="sr-only">Acciones</span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                                    @foreach ($lotes as $lote)
                                        <tr>
                                            <td class="p-2 font-mono whitespace-nowrap">{{ $lote->referencia }}</td>
                                            <td class="p-2 whitespace-nowrap">{{ $lote->created_at?->format('d/m/Y H:i') }}</td>
                                            <td class="p-2 text-right font-mono">{{ $lote->items_count }}</td>
                                            <td class="p-2">
                                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $lote->estado->clase() }}"
                                                      title="{{ $lote->estado->detalle() }}">{{ $lote->estado->label() }}</span>
                                            </td>
                                            <td class="p-2 font-mono text-gray-500 dark:text-paper-300 break-all">{{ $lote->archivo_nombre }}</td>
                                            <td class="p-2 text-right font-mono">
                                                {{ $lote->descargas }}
                                                @if ($lote->descargado_en)
                                                    <span class="block text-xs text-gray-400 dark:text-paper-500 whitespace-nowrap">{{ $lote->descargado_en->format('d/m/Y H:i') }}</span>
                                                @endif
                                            </td>
                                            <td class="p-2 text-right whitespace-nowrap">
                                                <a href="{{ route('ppq.nc-exportaciones.descargar', $lote) }}"
                                                   class="inline-flex items-center gap-1 rounded-md bg-green-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-700">
                                                    Descargar Excel
                                                    <span class="sr-only">del formato {{ $lote->referencia }}</span>
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <p class="mt-4 text-xs text-gray-500 dark:text-paper-300">
                            «Descargado» significa que alguien bajó el archivo, no que se le haya enviado al cliente.
                            El envío por correo se hace fuera del sistema y se registrará acá cuando exista de verdad.
                        </p>
                    @endif
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
