<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 dark:text-paper-100 leading-tight">Cobros Calleja</h2>
                <p class="text-sm text-gray-500 dark:text-paper-300">Cada CCF desde la entrega hasta el pago</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <a href="{{ route('ppq.lotes.index') }}"
                   class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 dark:border-ink-600 px-3 py-1.5 font-medium text-gray-700 dark:text-paper-100 hover:bg-gray-50 dark:hover:bg-ink-700">
                    Historial de PPQ
                </a>
                <a href="{{ route('ppq.index') }}"
                   class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 dark:border-ink-600 px-3 py-1.5 font-medium text-gray-700 dark:text-paper-100 hover:bg-gray-50 dark:hover:bg-ink-700">
                    Buscar CCF / NC
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-full xl:max-w-[1500px] mx-auto px-4 sm:px-6 lg:px-8 space-y-5">

            @if (session('status'))
                <div class="rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800 dark:bg-green-900/30 dark:border-green-800 dark:text-green-200" role="status">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700 dark:bg-red-900/30 dark:border-red-800 dark:text-red-200" role="alert">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700 dark:bg-red-900/30 dark:border-red-800 dark:text-red-200" role="alert">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @if ($gmailDesconectado ?? false)
                <div class="rounded-lg bg-red-50 border border-red-300 px-4 py-3 text-sm text-red-800 dark:bg-red-900/40 dark:border-red-700 dark:text-red-100" role="alert">
                    <strong>Gmail está desconectado:</strong> no están entrando los albaranes, así que los CCF no pasan a «Entregado».
                    @can('configuracion.gestionar')
                        <a href="{{ route('configuracion.integraciones.gmail') }}" class="font-semibold underline">Reconectar la cuenta</a>.
                    @else
                        Pedí a un administrador que la reconecte en Configuración → Integraciones.
                    @endcan
                </div>
            @endif

            {{-- ---------- Cliente (solo si hay más de uno) y TXT de pago ---------- --}}
            <div class="bg-white dark:bg-ink-800 shadow-sm ring-1 ring-gray-200 dark:ring-ink-600 rounded-xl p-4 flex flex-wrap items-end justify-between gap-4">
                @if ($clientes->count() > 1 || $cliente === null)
                    <form method="GET" action="{{ route('cobros.index') }}" class="flex flex-wrap items-end gap-3">
                        <div>
                            <label for="cliente_id" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Cliente</label>
                            <select id="cliente_id" name="cliente_id" class="mt-1 w-64 rounded-md border-gray-300 text-sm">
                                <option value="">— Elegir cliente —</option>
                                @foreach ($clientes as $c)
                                    <option value="{{ $c->id }}" @selected($cliente?->id === $c->id)>{{ $c->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button class="rounded-md bg-gray-800 px-4 py-2 text-sm text-white hover:bg-gray-700">Ver</button>
                    </form>
                @else
                    <p class="text-sm font-medium text-gray-700 dark:text-paper-100">{{ $cliente->nombre }}</p>
                @endif

                @if ($cliente)
                    @can('ppq.gestionar')
                        <form method="POST" action="{{ route('cobros.pagos', $cliente) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2">
                            @csrf
                            <div>
                                <label for="archivo" class="block text-xs font-medium text-gray-600 dark:text-paper-300">TXT de pago de Calleja</label>
                                <input id="archivo" name="archivo" type="file" accept=".txt,text/plain" required class="mt-1 text-sm">
                            </div>
                            <div>
                                <label for="fecha_pago" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Fecha del pago</label>
                                <input id="fecha_pago" name="fecha_pago" type="date" class="mt-1 rounded-md border-gray-300 text-sm">
                            </div>
                            <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Cargar pagos</button>
                        </form>
                    @endcan
                @endif

                @if ($clientes->isEmpty())
                    <p class="w-full text-sm text-amber-700">Ningún cliente tiene perfil documental activo.</p>
                @endif
            </div>

            @if ($cliente)
                @php
                    $base = ['cliente_id' => $cliente->id];
                    $conMes = $base + array_filter(['mes' => $filtros['mes']]);
                    $activa = array_filter(['etapa' => $filtros['etapa']]);
                    $tarjetas = [
                        ['', 'Todos', 'text-gray-800 dark:text-paper-100', 'border-l-gray-300'],
                        ['no_entregados', 'No entregados', 'text-amber-600 dark:text-amber-400', 'border-l-amber-400'],
                        ['listos', 'Entregados, por presentar', 'text-emerald-600 dark:text-emerald-400', 'border-l-emerald-500'],
                        ['presentados', 'En PPQ / presentados', 'text-sky-600 dark:text-sky-400', 'border-l-sky-500'],
                        ['pagados', 'Pagados', 'text-indigo-600 dark:text-indigo-300', 'border-l-indigo-500'],
                        ['diferencias', 'Pagos con diferencia', 'text-rose-600 dark:text-rose-300', 'border-rose-500'],
                    ];
                @endphp

                {{-- ---------- Etapas ---------- --}}
                <nav aria-label="Etapas" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($tarjetas as [$clave, $titulo, $color, $borde])
                        <a href="{{ route('cobros.index', $conMes + array_filter(['etapa' => $clave])) }}"
                           @class(['block rounded-xl border-l-4 bg-white dark:bg-ink-800 p-4 shadow-sm ring-1 transition hover:shadow-md', $borde,
                                   'ring-indigo-500 ring-2' => $filtros['etapa'] === $clave,
                                   'ring-gray-200 dark:ring-ink-600' => $filtros['etapa'] !== $clave])>
                            <span class="block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-paper-300">{{ $titulo }}</span>
                            <span class="mt-1 block text-3xl font-bold {{ $color }}">{{ $etapas[$clave] ?? 0 }}</span>
                        </a>
                    @endforeach
                </nav>

                <div class="bg-white dark:bg-ink-800 shadow-sm ring-1 ring-gray-200 dark:ring-ink-600 rounded-xl">
                    {{-- ---------- Meses y búsqueda ---------- --}}
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 dark:border-ink-600 px-4 py-3">
                        <nav aria-label="Meses" class="flex flex-wrap gap-1.5">
                            <a href="{{ route('cobros.index', $base + $activa) }}"
                               @class(['rounded-full px-3 py-1 text-sm',
                                       'bg-indigo-600 text-white' => $filtros['mes'] === '',
                                       'text-gray-600 dark:text-paper-300 hover:bg-gray-100 dark:hover:bg-ink-700' => $filtros['mes'] !== ''])>
                                Todos los meses
                            </a>
                            @foreach ($meses as $m)
                                <a href="{{ route('cobros.index', $base + ['mes' => $m['mes']] + $activa) }}"
                                   @class(['rounded-full px-3 py-1 text-sm',
                                           'bg-indigo-600 text-white' => $filtros['mes'] === $m['mes'],
                                           'text-gray-600 dark:text-paper-300 hover:bg-gray-100 dark:hover:bg-ink-700' => $filtros['mes'] !== $m['mes']])>
                                    {{ $m['etiqueta'] }}
                                    @if ($m['por_presentar'] > 0)
                                        <span class="ml-1 rounded-full bg-emerald-100 px-1.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300"
                                              title="Entregados, por presentar">{{ $m['por_presentar'] }}</span>
                                    @endif
                                </a>
                            @endforeach
                        </nav>
                        <form method="GET" action="{{ route('cobros.index') }}" class="flex items-center gap-2">
                            @foreach ($conMes + $activa as $k => $v)
                                <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                            @endforeach
                            <label for="f-q" class="sr-only">Buscar CCF o albarán</label>
                            <input id="f-q" name="q" type="search" value="{{ $filtros['q'] }}" placeholder="Buscar CCF o albarán"
                                   class="w-52 rounded-md border-gray-300 text-sm">
                        </form>
                    </div>

                    {{-- ---------- Lista de CCF ---------- --}}
                    @if ($documentos->isEmpty() && $documentos->currentPage() > 1)
                        <p class="p-6 text-sm text-amber-700" role="status">
                            Esta página no existe. <a href="{{ $documentos->url(1) }}" class="font-medium text-indigo-600 hover:underline">Ir a la primera página</a>.
                        </p>
                    @elseif ($documentos->isEmpty())
                        <p class="p-6 text-sm text-gray-500 dark:text-paper-300">
                            No hay CCF aquí.
                            @if ($hayFiltros)
                                <a href="{{ route('cobros.index', $base) }}" class="text-indigo-600 hover:underline">Ver todos</a>.
                            @endif
                        </p>
                    @else
                        @php $puedeGestionar = auth()->user()?->can('ppq.gestionar') ?? false; $listasEnPagina = 0; @endphp
                        <form method="POST" autocomplete="off" action="{{ route('cobros.ppq.crear', $cliente) }}"
                              x-data="{
                                  montos: @js($listosPpq), filtro: @js($listosEnFiltro->keys()->map(fn ($id) => (string) $id)->values()),
                                  pagina: @js($documentos->getCollection()->pluck('id')->map(fn ($id) => (string) $id)->values()),
                                  clave: 'ppq-seleccion-{{ $cliente->id }}', seleccion: [], enviando: false,
                                  init() {
                                      try { const guardados = JSON.parse(localStorage.getItem(this.clave) || '[]');
                                          if (Array.isArray(guardados)) this.seleccion = [...new Set(guardados.map(String))].filter(id => Object.hasOwn(this.montos, id));
                                      } catch (e) {}
                                      this.guardar();
                                  },
                                  guardar() { try { localStorage.setItem(this.clave, JSON.stringify(this.seleccion)); } catch (e) {} },
                                  get marcados() { return this.seleccion.length; },
                                  get total() { return (this.seleccion.reduce((s, id) => s + Math.round(Number(this.montos[id]) * 100), 0) / 100).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); },
                                  get fuera() { return this.seleccion.filter(id => !this.pagina.includes(id)); },
                                  cambiar(id, marcado) { this.seleccion = marcado ? [...new Set([...this.seleccion, id])] : this.seleccion.filter(v => v !== id); this.guardar(); },
                                  quitar() { this.seleccion = []; this.guardar(); },
                                  todas() {
                                      const ids = [...new Set([...this.seleccion, ...this.filtro])];
                                      if (ids.length > {{ \App\Http\Controllers\Cobros\CobrosController::MAX_PPQ }}) { alert('El máximo por PPQ es de 500 CCF. Seleccione hasta 500.'); return; }
                                      this.seleccion = ids; this.guardar();
                                  },
                                  enviar(evento) {
                                      if (this.enviando || !this.marcados) { evento.preventDefault(); return; }
                                      if (this.marcados > 500) { alert('El máximo por PPQ es de 500 CCF. Quite algunos de la selección.'); evento.preventDefault(); return; }
                                      if (!confirm('Se creará un PPQ con ' + this.marcados + ' CCF por $' + this.total + '. ¿Continuar?')) { evento.preventDefault(); return; }
                                      this.enviando = true;
                                      try { localStorage.removeItem(this.clave); } catch (e) {}
                                  }
                              }" x-on:submit="enviar($event)">
                            @csrf
                            @if ($puedeGestionar)
                                {{-- Lo marcado en OTRAS páginas viaja oculto; el servidor vuelve a validar cada CCF. --}}
                                <template x-for="id in fuera" :key="id">
                                    <input type="hidden" name="documentos[]" :value="id">
                                </template>
                                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                    <span class="text-sm text-gray-700 dark:text-paper-100" x-text="marcados + ' CCF marcados · $' + total">0 CCF marcados · $0.00</span>
                                    <button type="button" class="text-sm text-indigo-600 hover:underline" x-on:click="quitar()">Quitar selección</button>
                                    @if ($cantidadListosEnFiltro > 0)
                                        <button type="button" class="text-sm text-indigo-600 hover:underline" x-on:click="todas()">Seleccionar todas las pendientes ({{ $cantidadListosEnFiltro }} · ${{ number_format((float) $totalListosEnFiltro, 2) }})</button>
                                        @if ($cantidadListosEnFiltro > 500)
                                            <span class="text-xs text-gray-500 dark:text-paper-300">El máximo por PPQ es de 500 CCF.</span>
                                        @endif
                                    @endif
                                </div>
                            @endif
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <caption class="sr-only">CCF de {{ $cliente->nombre }}, página {{ $documentos->currentPage() }} de {{ $documentos->lastPage() }}</caption>
                                    <thead class="text-xs uppercase tracking-wide text-gray-500 dark:text-paper-300">
                                        <tr class="border-b border-gray-100 dark:border-ink-600">
                                            <th scope="col" class="px-4 py-2.5 w-8"><span class="sr-only">Incluir en el PPQ</span></th>
                                            <th scope="col" class="px-2 py-2.5 text-left font-medium">CCF</th>
                                            <th scope="col" class="px-2 py-2.5 text-left font-medium">Fecha</th>
                                            <th scope="col" class="px-2 py-2.5 text-left font-medium">Albarán</th>
                                            <th scope="col" class="px-2 py-2.5 text-left font-medium">Notas de crédito</th>
                                            <th scope="col" class="px-2 py-2.5 text-right font-medium">Monto</th>
                                            <th scope="col" class="px-4 py-2.5 text-left font-medium">Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                                        @foreach ($documentos as $doc)
                                            @php
                                                $pagado = $doc->pago_estado === \App\Enums\Cobros\EstadoPagoCobro::Pagado;
                                                $conDiferencia = in_array($doc->pago_estado, [\App\Enums\Cobros\EstadoPagoCobro::Parcial, \App\Enums\Cobros\EstadoPagoCobro::Diferencia], true);
                                                $pres = $doc->presentacion_estado;
                                                $estado = match (true) {
                                                    $doc->estaInvalidado() => 'invalidado',
                                                    $pagado => 'pagado',
                                                    $conDiferencia => 'diferencia',
                                                    in_array($pres, [\App\Enums\Cobros\EstadoPresentacionCobro::Presentada, \App\Enums\Cobros\EstadoPresentacionCobro::Recibida], true) => 'presentado',
                                                    $pres === \App\Enums\Cobros\EstadoPresentacionCobro::Preparada => 'en_ppq',
                                                    $doc->esNc() => 'nc',
                                                    $doc->albaran === null => 'no_entregado',
                                                    $doc->revisar_historico => 'revisar',
                                                    default => 'listo',
                                                };
                                                $presentable = isset($listosPpq[$doc->id]);
                                                $motivoPpq = $motivosPpq[$doc->id] ?? null;
                                                if ($estado === 'listo' && ! $presentable) {
                                                    $estado = 'bloqueado_ppq';
                                                }
                                                $listasEnPagina += $presentable ? 1 : 0;
                                                $notas = $doc->dte_id ? ($notasPorCcf[$doc->dte_id] ?? collect()) : collect();
                                                $invalidadas = $notas->where('estado', \App\Enums\EstadoDte::Invalidado)->count();
                                                $vigentes = $notas->where('estado', '!=', \App\Enums\EstadoDte::Invalidado);
                                            @endphp
                                            <tr class="align-top hover:bg-gray-50/70 dark:hover:bg-ink-700/40">
                                                <td class="px-4 py-3">
                                                    @if ($presentable && $puedeGestionar)
                                                        <label class="sr-only" for="doc_{{ $doc->id }}">Incluir {{ $doc->numero_control }}</label>
                                                        <input id="doc_{{ $doc->id }}" type="checkbox" name="documentos[]" value="{{ $doc->id }}"
                                                               :checked="seleccion.includes('{{ $doc->id }}')" x-on:change="cambiar('{{ $doc->id }}', $event.target.checked)"
                                                               class="h-5 w-5 rounded border-gray-300 text-indigo-600">
                                                    @endif
                                                </td>
                                                <td class="px-2 py-3">
                                                    <a href="{{ route('cobros.documentos.show', $doc) }}" title="{{ $doc->numero_control }}"
                                                       class="font-mono text-base font-semibold text-indigo-600 dark:text-indigo-300 hover:underline">{{ $doc->correlativoCorto() }}</a>
                                                    @if ($doc->esNc())
                                                        <span class="ml-1 rounded px-1 text-[10px] bg-indigo-100 text-indigo-700">NC</span>
                                                    @endif
                                                    {{-- La sala sale del albarán o, si aún no llegó, de la orden de compra del CCF. --}}
                                                    @php $salaCodigo = $doc->albaran?->sala_codigo ?: \App\Support\OrdenCompra::salaDesde($doc->dte?->numero_orden_compra); @endphp
                                                    @if ($salaCodigo)
                                                        <span class="block text-xs text-gray-500 dark:text-paper-300">{{ \App\Support\Sala::descripcion($salaCodigo) }}</span>
                                                    @endif
                                                </td>
                                                <td class="px-2 py-3 whitespace-nowrap text-gray-700 dark:text-paper-100">{{ $doc->fecha_emision?->format('d/m/Y') ?? '—' }}</td>
                                                <td class="px-2 py-3 font-mono text-xs whitespace-nowrap text-gray-700 dark:text-paper-100">
                                                    {{ $doc->albaran?->numero_albaran ?? '—' }}
                                                </td>
                                                <td class="px-2 py-3 text-xs text-gray-700 dark:text-paper-100">
                                                    @foreach ($vigentes as $nc)
                                                        <span class="block whitespace-nowrap">
                                                            <span class="font-mono">NC {{ preg_match('/(\d+)$/', (string) $nc->numero_control, $mm) ? (ltrim($mm[1], '0') ?: '0') : '?' }}</span>
                                                            · {{ number_format((float) $nc->total_pagar, 2) }}
                                                            @if ($nc->albaran)
                                                                · <span class="font-mono text-gray-500 dark:text-paper-300">{{ $nc->albaran->numero_canonico }}</span>
                                                            @endif
                                                        </span>
                                                    @endforeach
                                                    @if ($invalidadas > 0)
                                                        <span class="block text-gray-400">+{{ $invalidadas }} invalidada(s)</span>
                                                    @endif
                                                    @if ($notas->isEmpty())
                                                        <span class="text-gray-300 dark:text-ink-500">—</span>
                                                    @endif
                                                </td>
                                                <td class="px-2 py-3 text-right font-mono whitespace-nowrap text-gray-800 dark:text-paper-100">{{ number_format((float) $doc->monto, 2) }}</td>
                                                <td class="px-4 py-3 text-xs">
                                                    @php
                                                        [$texto, $clase] = match ($estado) {
                                                            'invalidado' => ['Invalidado', 'bg-rose-100 text-rose-700'],
                                                            'pagado' => ['Pagado'.($doc->fecha_pago ? ' '.$doc->fecha_pago->format('d/m') : ''), 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/50 dark:text-indigo-200'],
                                                            'diferencia' => [$doc->pago_estado->label(), 'bg-rose-100 text-rose-700'],
                                                            'presentado' => ['Presentado, sin pagar', 'bg-sky-100 text-sky-700 dark:bg-sky-900/50 dark:text-sky-200'],
                                                            'en_ppq' => ['En PPQ', 'bg-sky-50 text-sky-700 ring-1 ring-sky-200 dark:bg-sky-900/30 dark:text-sky-200 dark:ring-sky-800'],
                                                            'nc' => ['Va con su CCF', 'bg-gray-100 text-gray-600 dark:bg-ink-700 dark:text-paper-300'],
                                                            'no_entregado' => ['No entregado', 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'],
                                                            'revisar' => ['Revisar', 'bg-gray-100 text-gray-600 dark:bg-ink-700 dark:text-paper-300'],
                                                            'bloqueado_ppq' => [match ($motivoPpq) {
                                                                'ya está en un PPQ' => 'En otro PPQ',
                                                                'tiene pagos en revisión' => 'Pago en revisión',
                                                                default => ucfirst($motivoPpq ?? 'Revisar'),
                                                            }, 'bg-gray-100 text-gray-600 dark:bg-ink-700 dark:text-paper-300'],
                                                            default => ['Entregado · listo', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-200'],
                                                        };
                                                    @endphp
                                                    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 font-medium {{ $clase }}">
                                                        <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>{{ $texto }}
                                                    </span>
                                                    @if ($estado === 'invalidado')
                                                        <span class="mt-0.5 block text-gray-500 dark:text-paper-300">{{ in_array($pres, [\App\Enums\Cobros\EstadoPresentacionCobro::Preparada, \App\Enums\Cobros\EstadoPresentacionCobro::Presentada, \App\Enums\Cobros\EstadoPresentacionCobro::Recibida], true) ? 'Estaba en PPQ o presentado: revíselo' : 'Tiene pago registrado: revíselo' }}</span>
                                                    @elseif ($estado === 'diferencia')
                                                        <a href="{{ route('cobros.documentos.show', $doc) }}" class="mt-0.5 block text-gray-500 dark:text-paper-300 hover:underline">Cobrado {{ number_format((float) $doc->monto_pagado, 2) }}: revíselo</a>
                                                    @elseif ($estado === 'no_entregado')
                                                        <span class="mt-0.5 block text-gray-500 dark:text-paper-300">El albarán no ha llegado al correo</span>
                                                    @elseif ($estado === 'revisar')
                                                        <a href="{{ route('cobros.documentos.show', $doc) }}" class="mt-0.5 block text-gray-500 dark:text-paper-300 hover:underline">Anterior al seguimiento</a>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 dark:border-ink-600 px-4 py-3">
                                <p class="text-xs text-gray-500 dark:text-paper-300">
                                    Mostrando {{ $documentos->firstItem() }}–{{ $documentos->lastItem() }} de {{ $documentos->total() }}
                                    · {{ $listasEnPagina }} listo(s) en esta página
                                </p>
                                @if ($puedeGestionar)
                                    <span class="text-sm text-gray-700 dark:text-paper-100" x-text="marcados + ' CCF marcados · $' + total">0 CCF marcados · $0.00</span>
                                    <button type="button" class="text-sm text-indigo-600 hover:underline" x-on:click="quitar()">Quitar selección</button>
                                    <button class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
                                            :disabled="enviando || marcados === 0">
                                        Crear PPQ con lo marcado
                                        <span x-show="marcados > 0" x-text="'(' + marcados + ')'"></span>
                                    </button>
                                @else
                                    <p class="text-xs text-gray-500 dark:text-paper-300">Solo lectura.</p>
                                @endif
                            </div>
                        </form>

                        @if ($documentos->hasPages())
                            <nav class="border-t border-gray-100 dark:border-ink-600 px-4 py-3" aria-label="Páginas de documentos">{{ $documentos->links() }}</nav>
                        @endif
                    @endif
                </div>

                {{-- ---------- Información secundaria ---------- --}}
                @if ($ajustes->isNotEmpty() || $correos->isNotEmpty())
                    <details class="bg-white dark:bg-ink-800 shadow-sm ring-1 ring-gray-200 dark:ring-ink-600 rounded-xl p-4">
                        <summary class="cursor-pointer text-sm font-medium text-gray-700 dark:text-paper-100">Ajustes y correos de Calleja</summary>
                        @if ($ajustes->isNotEmpty())
                            <h3 class="mt-4 text-sm font-medium text-gray-700 dark:text-paper-100">Ajustes informados por el cliente (QD)</h3>
                            <div class="mt-2 overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                                        @foreach ($ajustes as $ajuste)
                                            <tr><td class="p-2"><x-cobros.ajuste :ajuste="$ajuste" /></td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                        @if ($correos->isNotEmpty())
                            <h3 class="mt-4 text-sm font-medium text-gray-700 dark:text-paper-100">Correos del cliente leídos</h3>
                            <div class="mt-2 overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                                        @foreach ($correos as $correo)
                                            <tr>
                                                <td class="p-2 whitespace-nowrap">{{ $correo->fecha_mensaje?->format('d/m/Y H:i') ?? '—' }}</td>
                                                <td class="p-2 text-xs">{{ \Illuminate\Support\Str::limit((string) $correo->asunto, 70) }}</td>
                                                <td class="p-2 font-mono">{{ $correo->referencia_calleja ?? '—' }}</td>
                                                <td class="p-2 text-xs text-gray-500 dark:text-paper-300">{{ $correo->estado }} · {{ $correo->motivo }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </details>
                @endif
            @endif
        </div>
    </div>
</x-app-layout>
