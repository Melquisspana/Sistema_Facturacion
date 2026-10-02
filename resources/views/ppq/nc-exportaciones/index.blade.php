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

            {{-- Qué es y qué NO hace. Lo segundo importa tanto como lo primero. El texto de
                 entrega lo declara el FORMATO activo (correo o portal), no esta vista. --}}
            <div class="rounded-md bg-blue-50 border border-blue-200 p-4 text-sm text-blue-700">
                <p>
                    Armá el archivo que pide el cliente: <strong>una fila por nota de crédito</strong>, con los datos de
                    su albarán. Las notas se acumulan hasta que decidas generarlo, así que
                    <strong>un mismo archivo puede llevar notas de días distintos</strong>.
                </p>
                @if ($formato)
                    <p class="mt-2">
                        Formato de este cliente: <strong>{{ $formato['nombre'] }}</strong>.
                        {{ $formato['entrega'] }}
                    </p>
                @endif
                <p class="mt-2">
                    El sistema <strong>no envía ni carga nada por su cuenta</strong>: registra el formato como generado
                    y, al bajarlo, como descargado. Descargarlo cuantas veces haga falta
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
                        <p class="text-sm text-gray-500 dark:text-paper-300">
                            @if ($pendientes->isNotEmpty())
                                Mostrando {{ $pendientes->firstItem() }}–{{ $pendientes->lastItem() }} de {{ $pendientes->total() }} nota(s){{ $hayFiltros ? ' con los filtros aplicados' : '' }}
                            @else
                                {{ $pendientes->total() }} nota(s){{ $hayFiltros ? ' con los filtros aplicados' : '' }}
                            @endif
                        </p>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-paper-300 mb-4">
                        Notas de {{ $cliente->nombre }} aceptadas por Hacienda, con albarán registrado, que todavía no
                        entraron en ningún formato. <strong>De la más antigua a la más reciente</strong>, para que
                        ninguna quede olvidada.
                    </p>

                    {{-- Datos que faltan, dichos ANTES de generar nada: el archivo no se arma
                         con huecos ni con valores inventados. --}}
                    @if ($faltantes)
                        <div class="mb-4 rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800" role="alert">
                            <p class="font-medium">
                                {{ count($faltantes) }} nota(s) de esta página no se pueden incluir todavía: les falta un
                                dato que el formato exige y el sistema no lo inventa.
                            </p>
                            <ul class="mt-2 list-disc list-inside space-y-1">
                                @foreach ($pendientes as $nc)
                                    @if (isset($faltantes[$nc->id]))
                                        <li>
                                            <span class="font-mono">{{ $nc->numero_control }}</span>
                                            — falta {{ implode(', ', $faltantes[$nc->id]) }}.
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                            <p class="mt-2 text-xs">
                                Completá el dato en el albarán de la nota y volvé a esta pantalla. Las demás sí se pueden
                                generar.
                            </p>
                        </div>
                    @endif

                    @if ($pendientes->isEmpty() && $pendientes->currentPage() > 1)
                        {{-- Página fuera de rango (enlace viejo, o se exportaron notas mientras tanto). --}}
                        <p class="text-sm text-amber-700" role="status">
                            @if ($pendientes->total() > 0)
                                Esta página de pendientes no existe ({{ $pendientes->total() }} nota(s) en {{ $pendientes->lastPage() }} página(s)).
                            @else
                                Ya no quedan notas pendientes{{ $hayFiltros ? ' con estos filtros' : '' }}.
                            @endif
                            <a href="{{ $pendientes->url(1) }}" class="font-medium text-indigo-600 hover:underline">Ir a la primera página</a>@if ($hayFiltros)
                                · <a href="{{ route('ppq.nc-exportaciones.index', ['cliente_id' => $cliente->id]) }}" class="font-medium text-indigo-600 hover:underline">Quitar filtros</a>@endif.
                        </p>
                    @elseif ($pendientes->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-paper-300">
                            @if ($hayFiltros)
                                Ninguna nota coincide con los filtros.
                                <a href="{{ route('ppq.nc-exportaciones.index', ['cliente_id' => $cliente->id]) }}" class="text-indigo-600 hover:underline">Ver todas</a>.
                            @else
                                No hay notas pendientes. Aparecen acá cuando están aceptadas por Hacienda y tienen su albarán registrado.
                            @endif
                        </p>
                    @else
                        @php $puedeGestionar = auth()->user()?->can('ppq.gestionar') ?? false; @endphp
                        {{-- Nada viene marcado: un lote se arma a propósito, no por no desmarcar.
                             autocomplete=off evita que el navegador restaure marcas que no se enviaron. --}}
                        {{-- La casilla general refleja las individuales: si se desmarca una, deja de
                             aparentar «todas» (queda indeterminada). Solo cuenta las habilitadas. --}}
                        <form method="POST" action="{{ route('ppq.nc-exportaciones.store') }}" autocomplete="off"
                              x-data="{
                                  todas: false,
                                  casillas() { return Array.from(this.$el.querySelectorAll('input[data-nc]:not(:disabled)')) },
                                  marcarPagina() { const v = this.$refs.general.checked; this.casillas().forEach(c => c.checked = v); this.sincronizar() },
                                  sincronizar() {
                                      const c = this.casillas();
                                      const marcadas = c.filter(x => x.checked).length;
                                      this.todas = c.length > 0 && marcadas === c.length;
                                      if (this.$refs.general) { this.$refs.general.indeterminate = marcadas > 0 && marcadas < c.length }
                                  },
                              }">
                            @csrf
                            <input type="hidden" name="cliente_id" value="{{ $cliente->id }}">

                            @if ($puedeGestionar)
                                {{-- Alcance VISIBLE de la casilla general: solo esta página. --}}
                                <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                                    <input id="marcar_pagina" type="checkbox" x-ref="general" x-model="todas" @change="marcarPagina()"
                                           class="rounded border-gray-300">
                                    <label for="marcar_pagina" class="font-medium text-gray-700 dark:text-paper-100">Marcar las de esta página</label>
                                    <span class="text-xs text-gray-500 dark:text-paper-300">({{ $pendientes->count() - count($faltantes) }} disponible(s); las incompletas no se marcan)</span>
                                </div>
                            @endif

                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <caption class="sr-only">
                                        Notas de crédito pendientes de incluir en un formato, página {{ $pendientes->currentPage() }} de {{ $pendientes->lastPage() }}
                                    </caption>
                                    <thead class="bg-gray-50 text-gray-600 dark:text-paper-300">
                                        <tr>
                                            <th scope="col" class="p-2 text-left w-10"><span class="sr-only">Incluir</span></th>
                                            <th scope="col" class="p-2 text-left font-medium">Emitida</th>
                                            <th scope="col" class="p-2 text-left font-medium">Número de control</th>
                                            <th scope="col" class="p-2 text-left font-medium">Tipo</th>
                                            <th scope="col" class="p-2 text-left font-medium">Sala</th>
                                            <th scope="col" class="p-2 text-left font-medium">Albarán</th>
                                            {{-- El año y el mes del formato de carga masiva salen de acá, no de la
                                                 fecha de emisión de la nota: se muestra para poder comprobarlo. --}}
                                            <th scope="col" class="p-2 text-left font-medium">Fecha albarán</th>
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
                                                $faltaEnEsta = $faltantes[$nc->id] ?? [];
                                            @endphp
                                            <tr class="{{ $faltaEnEsta ? 'bg-amber-50' : '' }}">
                                                <td class="p-2">
                                                    @if ($puedeGestionar)
                                                        <label class="sr-only" for="nc_{{ $nc->id }}">Incluir la nota {{ $nc->numero_control }}</label>
                                                        {{-- Deshabilitada, no solo desmarcada: con un dato faltante no hay
                                                             fila posible, y el servidor rechaza el lote entero igual. --}}
                                                        <input id="nc_{{ $nc->id }}" type="checkbox" name="dtes[]" value="{{ $nc->id }}" data-nc
                                                               @change="sincronizar()"
                                                               @disabled((bool) $faltaEnEsta)
                                                               @if ($faltaEnEsta) aria-describedby="falta_{{ $nc->id }}" @endif
                                                               class="rounded border-gray-300 disabled:opacity-50">
                                                    @endif
                                                </td>
                                                <td class="p-2 whitespace-nowrap">{{ $nc->fecha_emision?->format('d/m/Y') }}</td>
                                                <td class="p-2 font-mono whitespace-nowrap">
                                                    {{ $nc->numero_control }}
                                                    @if ($faltaEnEsta)
                                                        <span id="falta_{{ $nc->id }}" class="block font-sans text-xs font-medium text-amber-700">
                                                            Falta {{ implode(', ', $faltaEnEsta) }}
                                                        </span>
                                                    @endif
                                                </td>
                                                <td class="p-2 font-mono">{{ $alb?->tipo_codigo }}</td>
                                                <td class="p-2 font-mono">{{ $alb?->sala_codigo ?? $nc->clienteSucursal?->codigo ?? '—' }}</td>
                                                <td class="p-2 font-mono">{{ $alb?->numero_canonico }}</td>
                                                <td class="p-2 whitespace-nowrap {{ $alb?->fecha === null ? 'text-amber-700 font-medium' : '' }}">
                                                    {{ $alb?->fecha?->format('d/m/Y') ?? 'sin fecha' }}
                                                </td>
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

                            @if ($puedeGestionar)
                                <button class="mt-4 inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                    Generar formato con las marcadas
                                </button>
                                {{-- Una sola línea a propósito: la frase se busca entera en las pruebas. --}}
                                <p class="mt-2 text-xs text-gray-500 dark:text-paper-300">El archivo lleva solo las marcadas <strong>en esta página</strong>: cambiar de página no guarda la selección. Las marcadas dejan de estar pendientes y no podrán entrar en otro formato.</p>
                            @else
                                <p class="mt-4 text-sm text-gray-500 dark:text-paper-300">
                                    Solo lectura: generar el formato requiere permiso de gestión de cobros.
                                </p>
                            @endif
                        </form>

                        @if ($pendientes->hasPages())
                            <nav class="mt-4" aria-label="Páginas de notas pendientes">{{ $pendientes->links() }}</nav>
                        @endif
                    @endif
                </div>

                {{-- ---------- 2 · Historial de archivos ---------- --}}
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 mb-1">
                        <h3 class="font-medium text-gray-700 dark:text-paper-100">2 · Historial de archivos</h3>
                        @if ($lotes->total() > 0 && $lotes->isNotEmpty())
                            <p class="text-sm text-gray-500 dark:text-paper-300">Mostrando {{ $lotes->firstItem() }}–{{ $lotes->lastItem() }} de {{ $lotes->total() }} archivo(s)</p>
                        @endif
                    </div>
                    <p class="text-sm text-gray-500 dark:text-paper-300 mb-4">
                        Una fila por archivo, del más reciente al más antiguo. Las notas de cada uno están en su detalle.
                        La primera descarga <strong>guarda una copia</strong> del archivo; las siguientes sirven esa misma
                        copia. Los archivos bajados antes de que existiera la copia se reconstruyen desde sus notas y quedan
                        marcados como <strong>reconstrucción</strong>. Descargar no agrega notas, y «Descargado» solo dice
                        que alguien lo bajó, no que el cliente lo recibió.
                    </p>

                    @if ($lotes->isEmpty() && $lotes->currentPage() > 1)
                        <p class="text-sm text-amber-700" role="status">
                            Esta página del historial no existe ({{ $lotes->total() }} archivo(s) en {{ $lotes->lastPage() }} página(s)).
                            <a href="{{ $lotes->url(1) }}" class="font-medium text-indigo-600 hover:underline">Ir a la primera página</a>.
                        </p>
                    @elseif ($lotes->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-paper-300">Todavía no se ha generado ningún formato para este cliente.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <caption class="sr-only">Historial de archivos de notas de crédito de {{ $cliente->nombre }}, página {{ $lotes->currentPage() }} de {{ $lotes->lastPage() }}</caption>
                                <thead class="bg-gray-50 text-gray-600 dark:text-paper-300">
                                    <tr>
                                        <th scope="col" class="p-2 text-left font-medium">Archivo</th>
                                        <th scope="col" class="p-2 text-left font-medium">Generado</th>
                                        <th scope="col" class="p-2 text-left font-medium">Cliente</th>
                                        <th scope="col" class="p-2 text-right font-medium">NC</th>
                                        <th scope="col" class="p-2 text-right font-medium" title="Suma del total a pagar de las notas del lote">Total NC</th>
                                        <th scope="col" class="p-2 text-left font-medium">Estado</th>
                                        <th scope="col" class="p-2 text-right font-medium"><span class="sr-only">Acciones</span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                                    @foreach ($lotes as $lote)
                                        <tr>
                                            <td class="p-2">
                                                <a href="{{ route('ppq.nc-exportaciones.show', $lote) }}" class="font-mono text-indigo-600 hover:underline">{{ $lote->referencia }}</a>
                                                <span class="block font-mono text-xs text-gray-500 dark:text-paper-300 break-all">{{ $lote->archivo_nombre }}</span>
                                                {{-- El formato con el que se armó ESE lote, no el que usa hoy el cliente. --}}
                                                <span class="block text-xs text-gray-500 dark:text-paper-300">{{ \App\Services\Ppq\Exportadores\ExportadorNcFactory::etiqueta($lote->formato) }}</span>
                                            </td>
                                            <td class="p-2 whitespace-nowrap">{{ $lote->created_at?->format('d/m/Y H:i') }}</td>
                                            <td class="p-2">{{ $lote->cliente?->nombre }}</td>
                                            <td class="p-2 text-right font-mono">{{ $lote->items_count }}</td>
                                            <td class="p-2 text-right font-mono">{{ number_format((float) $lote->total_notas, 2) }}</td>
                                            <td class="p-2">
                                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $lote->estado->clase() }}"
                                                      title="{{ $lote->estado->detalle() }}">{{ $lote->estado->label() }}</span>
                                                {{-- Procedencia de la copia: la reconstrucción se dice, no se disfraza. --}}
                                                @if ($lote->tieneCopiaArchivada() && ! $lote->registroDeCopiaCompleto())
                                                    <span class="block text-xs text-red-700">Registro de la copia incompleto</span>
                                                @elseif ($lote->archivo_origen)
                                                    <span class="block text-xs {{ $lote->archivo_origen === \App\Enums\ProcedenciaArchivoNc::Reconstruccion ? 'text-amber-700' : 'text-gray-500 dark:text-paper-300' }}"
                                                          title="{{ $lote->archivo_origen->detalle() }}">{{ $lote->archivo_origen->label() }}</span>
                                                @elseif ($lote->descargadoSinCopia())
                                                    <span class="block text-xs text-amber-700" title="{{ \App\Enums\ProcedenciaArchivoNc::Reconstruccion->detalle() }}">Sin copia: se reconstruirá</span>
                                                @endif
                                            </td>
                                            <td class="p-2 text-right whitespace-nowrap space-x-1">
                                                <a href="{{ route('ppq.nc-exportaciones.show', $lote) }}"
                                                   class="inline-flex rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                                    Ver notas<span class="sr-only"> del archivo {{ $lote->referencia }}</span>
                                                </a>
                                                <form method="POST" action="{{ route('ppq.nc-exportaciones.descargar', $lote) }}" class="inline">
                                                    @csrf
                                                    <button type="submit"
                                                            class="inline-flex items-center gap-1 rounded-md bg-green-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-700">
                                                        Descargar Excel
                                                        <span class="sr-only">del formato {{ $lote->referencia }}</span>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if ($lotes->hasPages())
                            <nav class="mt-4" aria-label="Páginas del historial de archivos">{{ $lotes->links() }}</nav>
                        @endif
                    @endif
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
