<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-paper-100 leading-tight">
                {{ $documento->numero_control }}
            </h2>
            <a href="{{ route('cobros.index', ['cliente_id' => $documento->cliente_id]) }}"
               class="text-sm font-medium text-indigo-600 hover:text-indigo-800">← Cobros Calleja</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

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

            @if ($documento->estaInvalidado())
                <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700" role="alert">
                    Este CCF fue invalidado en Hacienda. No se presenta ni recibe albarán.
                </div>
            @endif

            {{-- ---------- Identidad ---------- --}}
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-3">Documento</h3>
                <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-8 gap-y-3 text-sm">
                    <div><dt class="text-gray-500 dark:text-paper-300">Tipo</dt><dd>{{ $documento->esNc() ? 'Nota de crédito' : 'Crédito fiscal' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Origen</dt><dd>{{ $documento->origen->label() }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Emitida</dt><dd>{{ $documento->fecha_emision?->format('d/m/Y') ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Días desde la emisión</dt><dd class="font-mono">{{ $documento->diasDesdeEmision() ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Código de generación</dt><dd class="font-mono text-xs break-all">{{ $documento->codigo_generacion ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Sello de recepción</dt><dd class="font-mono text-xs break-all">{{ $documento->sello_recepcion ?? '—' }}</dd></div>
                    {{-- Establecimiento y punto de venta: sin ellos, el mismo correlativo de
                         dos series distintas parecería el mismo documento. --}}
                    <div><dt class="text-gray-500 dark:text-paper-300">Establecimiento / punto de venta</dt>
                        <dd class="font-mono">{{ $documento->establecimiento_codigo ?? '—' }} / {{ $documento->punto_venta_codigo ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Monto</dt><dd class="font-mono">{{ number_format((float) $documento->monto, 2) }}</dd></div>
                </dl>
            </div>

            {{-- ---------- Estados ---------- --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-paper-300">Presentación</div>
                    <div class="mt-1"><span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $documento->presentacion_estado->clase() }}">{{ $documento->presentacion_estado->label() }}</span></div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-paper-300">{{ $documento->presentacion_estado->detalle() }}</p>
                    @if ($documento->solicitud)
                        <p class="mt-1 text-xs font-mono">{{ $documento->solicitud->referencia }}</p>
                    @endif
                </div>
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-paper-300">Pago</div>
                    <div class="mt-1"><span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $documento->pago_estado->clase() }}">{{ $documento->pago_estado->label() }}</span></div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-paper-300">{{ $documento->pago_estado->detalle() }}</p>
                    <p class="mt-1 text-xs font-mono">
                        cobrado {{ number_format((float) $documento->monto_pagado, 2) }} · saldo {{ number_format((float) $documento->saldo(), 2) }}
                    </p>
                </div>
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-paper-300">Albarán</div>
                    <div class="mt-1"><span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $documento->vinculacion_estado->clase() }}">{{ $documento->vinculacion_estado->label() }}</span></div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-paper-300">{{ $documento->vinculacion_motivo ?? 'Todavía no se ha auditado.' }}</p>
                    @if ($documento->albaran)
                        <p class="mt-1 text-xs font-mono">{{ $documento->albaran->numero_albaran }}</p>
                    @endif
                    @if ($corregible)
                        @if ($movible)
                            <button type="button" x-data x-on:click="$dispatch('open-modal', 'corregir-vinculo')"
                                    class="mt-2 text-xs font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 hover:underline">
                                {{ $documento->albaran ? 'Vincular con otro albarán' : 'Elegir albarán' }}
                            </button>
                        @else
                            <p class="mt-2 text-xs text-gray-500 dark:text-paper-300">Ya está en una solicitud o tiene pagos: el albarán no se cambia desde aquí.</p>
                        @endif
                    @endif
                </div>
            </div>

            {{-- ---------- Corregir vínculo: elegir el albarán entre sugeridos ---------- --}}
            @if ($corregible && $movible)
                @php
                    $salaHoy = $documento->albaran?->sala_codigo ?: \App\Support\OrdenCompra::salaDesde($documento->dte?->numero_orden_compra);
                @endphp
                <x-modal name="corregir-vinculo" maxWidth="2xl" :show="$errors->has('ppq_albaran_id') && old('desde_corregir')" focusable>
                    <form method="POST" action="{{ route('cobros.documentos.vincular', $documento) }}" class="p-6 space-y-4"
                          x-data="{
                              sugeridos: @js($sugeridos),
                              resultados: [],
                              q: '',
                              buscando: false,
                              buscado: false,
                              elegido: null,
                              nota: '',
                              url: @js(route('cobros.documentos.albaranes', $documento)),
                              elegir(a) {
                                  if (a.tomado_por) return;
                                  this.elegido = a;
                                  this.nota = ('Elegido en «Corregir vínculo»: ' + (a.motivos.length ? a.motivos.join(', ') : 'búsqueda por número') + '.').slice(0, 255);
                              },
                              async buscar() {
                                  if (this.q.trim().length < 3) { this.resultados = []; this.buscado = false; return; }
                                  this.buscando = true;
                                  try {
                                      const r = await fetch(this.url + '?q=' + encodeURIComponent(this.q.trim()), { headers: { 'Accept': 'application/json' } });
                                      this.resultados = r.ok ? await r.json() : [];
                                  } catch (e) {
                                      this.resultados = [];
                                  } finally {
                                      this.buscando = false;
                                      this.buscado = true;
                                  }
                              },
                          }">
                        @csrf
                        <input type="hidden" name="desde_corregir" value="1">
                        <input type="hidden" name="ppq_albaran_id" x-bind:value="elegido ? elegido.id : ''">

                        <div>
                            <h2 class="text-lg font-medium text-gray-900 dark:text-paper-100">{{ $documento->albaran ? 'Vincular con otro albarán' : 'Elegir albarán' }}</h2>
                            <p class="mt-1 text-sm text-gray-500 dark:text-paper-300">
                                Ahora: <span class="font-mono">{{ $documento->albaran?->numero_albaran ?? 'sin albarán' }}</span>
                                · {{ $salaHoy ? \App\Support\Sala::descripcion($salaHoy).' ('.$salaHoy.')' : 'sin sala' }}.
                                La factura ante Hacienda no cambia.
                            </p>
                        </div>

                        <template x-for="lista in [{ titulo: 'Sugeridos', filas: sugeridos, vacio: 'No hay albaranes parecidos cerca de la fecha del CCF. Búsquelo por número.' }, { titulo: 'Resultados de la búsqueda', filas: resultados, vacio: buscado ? 'Ningún albarán de entrega con ese número.' : null }]">
                            <div x-show="lista.titulo === 'Sugeridos' || q.trim().length >= 3">
                                <h3 class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-paper-300" x-text="lista.titulo"></h3>
                                <p x-show="lista.filas.length === 0 && lista.vacio" class="mt-1 text-sm text-gray-500 dark:text-paper-300" x-text="lista.vacio"></p>
                                <ul class="mt-2 space-y-2">
                                    <template x-for="a in lista.filas" :key="lista.titulo + a.id">
                                        <li>
                                            <button type="button" x-on:click="elegir(a)" x-bind:disabled="!!a.tomado_por"
                                                    x-bind:aria-pressed="elegido && elegido.id === a.id ? 'true' : 'false'"
                                                    x-bind:class="elegido && elegido.id === a.id ? 'ring-2 ring-indigo-500' : 'ring-1 ring-gray-200 dark:ring-ink-600'"
                                                    class="w-full rounded-md p-3 text-left text-sm hover:bg-gray-50 dark:hover:bg-ink-700 disabled:opacity-50">
                                                <span class="flex flex-wrap items-baseline justify-between gap-2">
                                                    <span class="font-mono font-semibold text-gray-800 dark:text-paper-100" x-text="a.numero"></span>
                                                    <span class="font-mono text-gray-700 dark:text-paper-100" x-text="a.monto === null ? '—' : a.monto"></span>
                                                </span>
                                                <span class="block text-gray-600 dark:text-paper-300">
                                                    <span x-text="a.sala_nombre"></span> <span class="font-mono text-xs" x-text="a.sala ? '(' + a.sala + ')' : ''"></span>
                                                    · <span x-text="a.fecha || 'sin fecha'"></span>
                                                </span>
                                                <span x-show="a.motivos.length" class="block text-xs text-emerald-700 dark:text-emerald-300" x-text="'Se sugiere por: ' + a.motivos.join(', ')"></span>
                                                <span x-show="a.tomado_por" class="block text-xs text-rose-700" x-text="'Ya vinculado a ' + a.tomado_por"></span>
                                            </button>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </template>

                        <div>
                            <x-input-label for="buscar_albaran" value="¿No está? Buscar por número de albarán" />
                            <x-text-input id="buscar_albaran" type="search" autocomplete="off" class="mt-1 block w-full font-mono"
                                          placeholder="Por ejemplo 0062/00/1234" x-model="q" x-on:input.debounce.400ms="buscar()"
                                          x-on:keydown.enter.prevent="buscar()" />
                            <p x-show="buscando" class="mt-1 text-xs text-gray-500 dark:text-paper-300">Buscando…</p>
                        </div>

                        <div x-show="elegido" class="rounded-md bg-gray-50 dark:bg-ink-700 p-3 text-sm text-gray-700 dark:text-paper-100" aria-live="polite">
                            <template x-if="elegido">
                                <div class="space-y-1">
                                    <p>Quedará vinculado a <strong class="font-mono" x-text="elegido.numero"></strong>,
                                        sala <strong x-text="elegido.sala_nombre"></strong> <span class="font-mono text-xs" x-text="elegido.sala ? '(' + elegido.sala + ')' : ''"></span>,
                                        como vinculado a mano por usted.</p>
                                    <template x-for="aviso in elegido.avisos">
                                        <p class="text-xs text-amber-700 dark:text-amber-300" x-text="'Ojo: ' + aviso"></p>
                                    </template>
                                </div>
                            </template>
                        </div>

                        <div>
                            <x-input-label for="nota_vinculo" value="Nota (queda en el historial)" />
                            <x-text-input id="nota_vinculo" name="nota" type="text" maxlength="255" required class="mt-1 block w-full" x-model="nota" />
                        </div>

                        <div class="flex justify-end gap-2">
                            <x-secondary-button x-on:click="$dispatch('close')">Cancelar</x-secondary-button>
                            <x-primary-button x-bind:disabled="!elegido">Vincular este albarán</x-primary-button>
                        </div>
                    </form>
                </x-modal>
            @endif

            {{-- ---------- Vinculación pendiente ---------- --}}
            @if ($veredicto['estado'] === \App\Enums\Cobros\EstadoVinculacionAlbaran::Revisar && $veredicto['candidatos'] !== [])
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                    <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-1">Revisar vinculación</h3>
                    <p class="text-sm text-amber-800 mb-4">{{ $veredicto['motivo'] }}</p>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Albaranes candidatos</caption>
                            <thead class="bg-gray-50 text-gray-600 dark:text-paper-300">
                                <tr>
                                    <th scope="col" class="p-2 text-left font-medium">Albarán</th>
                                    <th scope="col" class="p-2 text-left font-medium">Tipo</th>
                                    <th scope="col" class="p-2 text-left font-medium">Sala</th>
                                    <th scope="col" class="p-2 text-left font-medium">Fecha</th>
                                    <th scope="col" class="p-2 text-right font-medium">Monto</th>
                                    <th scope="col" class="p-2 text-left font-medium">Orden de compra</th>
                                    <th scope="col" class="p-2 text-right font-medium"><span class="sr-only">Elegir</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                                @foreach ($veredicto['candidatos'] as $candidato)
                                    <tr>
                                        <td class="p-2 font-mono">{{ $candidato['numero'] }}</td>
                                        <td class="p-2 font-mono">{{ $candidato['tipo'] }}</td>
                                        <td class="p-2 font-mono">{{ $candidato['sala'] }}</td>
                                        <td class="p-2">{{ $candidato['fecha'] }}</td>
                                        <td class="p-2 text-right font-mono">{{ $candidato['monto'] === null ? '—' : number_format((float) $candidato['monto'], 2) }}</td>
                                        <td class="p-2 font-mono text-xs">{{ $candidato['orden_compra'] }}</td>
                                        <td class="p-2 text-right">
                                            @if (! empty($candidato['tomado_por']))
                                                <span class="text-xs text-rose-700">ya vinculado a {{ $candidato['tomado_por'] }}</span>
                                            @else
                                                @can('ppq.gestionar')
                                                    <form method="POST" action="{{ route('cobros.documentos.vincular', $documento) }}" class="flex items-center gap-1 justify-end">
                                                        @csrf
                                                        <input type="hidden" name="ppq_albaran_id" value="{{ $candidato['id'] }}">
                                                        <label class="sr-only" for="nota_{{ $candidato['id'] }}">Nota</label>
                                                        <input id="nota_{{ $candidato['id'] }}" name="nota" required maxlength="255" placeholder="evidencia de por qué este"
                                                               class="rounded-md border-gray-300 text-xs w-40">
                                                        <button class="rounded-md bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-indigo-700">Vincular</button>
                                                    </form>
                                                @endcan
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- ---------- Pagos en revisión ---------- --}}
            @php $enRevision = $documento->pagosEnRevision(); @endphp
            @if ($enRevision->isNotEmpty())
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6 border-l-4 border-amber-400">
                    <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-1">
                        Pagos informados que todavía no cuentan ({{ $enRevision->count() }})
                    </h3>
                    <p class="text-sm text-gray-500 dark:text-paper-300 mb-4">
                        Otro archivo ya había informado un pago sobre este documento. Desde el archivo,
                        <strong>«el cliente lo repitió» y «el cliente pagó en dos abonos» se ven igual</strong>, así que
                        este importe quedó registrado con su evidencia pero <strong>no suma</strong> hasta que alguien
                        lo decida. Suman
                        <span class="font-mono">{{ number_format((float) $documento->montoEnRevision(), 2) }}</span>.
                    </p>

                    <div class="space-y-3">
                        @foreach ($enRevision as $evento)
                            <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-sm">
                                <div class="flex flex-wrap items-baseline gap-2">
                                    <span class="font-mono font-semibold">{{ number_format((float) $evento->monto, 2) }}</span>
                                    <span class="text-xs text-gray-600 dark:text-paper-300">
                                        {{ $evento->evidencia_nombre }} · línea {{ $evento->referencia_linea }} ·
                                        cargado {{ $evento->created_at?->format('d/m/Y H:i') }}
                                    </span>
                                </div>
                                <p class="mt-1 text-xs text-amber-800">{{ $evento->estado_motivo }}</p>

                                @can('ppq.gestionar')
                                    <form method="POST" action="{{ route('cobros.documentos.pagos.resolver', [$documento, $evento]) }}"
                                          class="mt-2 flex flex-wrap items-end gap-2">
                                        @csrf @method('PUT')
                                        <div class="grow">
                                            <label class="sr-only" for="motivo_{{ $evento->id }}">Motivo de la decisión</label>
                                            <input id="motivo_{{ $evento->id }}" name="motivo" required maxlength="255"
                                                   placeholder="qué se comprobó, y con quién"
                                                   class="w-full rounded-md border-gray-300 text-xs">
                                        </div>
                                        <button name="decision" value="aplicado"
                                                class="rounded-md bg-green-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-700">
                                            Es un abono más: aplicar
                                        </button>
                                        <button name="decision" value="descartado"
                                                class="rounded-md bg-gray-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-gray-700">
                                            Es una repetición: descartar
                                        </button>
                                    </form>
                                @endcan
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ---------- Notas de crédito del CCF ---------- --}}
            @if ($notas['aplica'])
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 mb-1">
                        <h3 class="font-medium text-gray-700 dark:text-paper-100">Notas de crédito vinculadas a este CCF</h3>
                        <span class="text-sm text-gray-500 dark:text-paper-300">{{ $notas['total'] }} nota(s)</span>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-paper-300 mb-4">
                        Todas las notas guardadas con este CCF como documento relacionado, en cualquier estado. Solo las aceptadas realmente por Hacienda son notas de crédito fiscales vigentes; las demás se muestran para trazabilidad con su estado.
                        Cada una tiene su propio albarán de crédito, distinto del albarán de entrega del CCF. Solo consulta: nada se modifica desde acá.
                    </p>

                    @if ($notas['lista']->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-paper-300">Este CCF no tiene notas de crédito vinculadas.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="block w-full min-w-0 text-sm md:table md:min-w-full">
                                <caption class="sr-only">Notas de crédito vinculadas a {{ $documento->numero_control }}</caption>
                                <thead class="hidden bg-gray-50 dark:bg-ink-900 text-gray-600 dark:text-paper-300 md:table-header-group">
                                    <tr>
                                        <th scope="col" class="p-2 text-left font-medium">Nota</th>
                                        <th scope="col" class="p-2 text-left font-medium">Estado</th>
                                        <th scope="col" class="p-2 text-right font-medium">Total</th>
                                        <th scope="col" class="p-2 text-left font-medium">Albarán de la nota</th>
                                        <th scope="col" class="p-2 text-left font-medium">Formato de NC</th>
                                    </tr>
                                </thead>
                                <tbody class="block divide-y divide-gray-100 dark:divide-ink-600 md:table-row-group">
                                    @foreach ($notas['lista'] as $nc)
                                        @php
                                            // El estado se dice tal cual: solo la aceptación REAL de Hacienda acredita.
                                            [$estadoTexto, $estadoClase] = match (true) {
                                                $nc->aceptadoRealmentePorMh() => ['Aceptada por Hacienda', 'bg-green-100 text-green-700'],
                                                $nc->estado === \App\Enums\EstadoDte::Invalidado => ['Invalidada: no acredita', 'bg-red-100 text-red-700'],
                                                $nc->estado === \App\Enums\EstadoDte::Rechazado => ['Rechazada por Hacienda: no acredita', 'bg-red-100 text-red-700'],
                                                $nc->estado === \App\Enums\EstadoDte::Aceptado => ['Sin aceptación real de Hacienda', 'bg-amber-100 text-amber-800'],
                                                default => ['Aún no aceptada ('.$nc->estado->label().')', 'bg-gray-100 text-gray-700'],
                                            };
                                            $alb = $nc->albaran;
                                            $lote = $nc->exportacionItem?->exportacion;
                                        @endphp
                                        <tr class="block py-3 md:table-row md:py-0">
                                            <td class="block p-2 md:table-cell">
                                                <span class="block break-all font-mono text-gray-800 dark:text-paper-100 md:whitespace-nowrap md:break-normal">{{ $nc->numero_control ?? $nc->numero_interno ?? '#'.$nc->id }}</span>
                                                <span class="block text-xs text-gray-500 dark:text-paper-300">{{ $nc->fecha_emision?->format('d/m/Y') ?? 'sin fecha' }}</span>
                                            </td>
                                            <td class="block p-2 md:table-cell">
                                                <span class="inline-flex max-w-full whitespace-normal rounded-full px-2 py-0.5 text-[11px] font-medium {{ $estadoClase }}">{{ $estadoTexto }}</span>
                                            </td>
                                            <td class="block p-2 font-mono text-gray-800 dark:text-paper-100 md:table-cell md:text-right md:whitespace-nowrap">
                                                <span class="font-sans text-gray-500 dark:text-paper-300 md:hidden">Total: </span>${{ number_format((float) $nc->total_pagar, 2) }}
                                            </td>
                                            <td class="block p-2 md:table-cell">
                                                <span class="block text-xs text-gray-500 dark:text-paper-300 md:hidden">Albarán de la nota</span>
                                                @if ($alb)
                                                    <span class="block font-mono text-gray-800 dark:text-paper-100">{{ $alb->numero_canonico }}</span>
                                                    <span class="block text-xs text-gray-500 dark:text-paper-300">
                                                        {{ $alb->fecha?->format('d/m/Y') ?? 'sin fecha' }} · {{ $alb->total !== null ? '$'.number_format((float) $alb->total, 2) : 'sin total' }}
                                                    </span>
                                                @else
                                                    <span class="text-xs text-gray-500 dark:text-paper-300">Sin albarán registrado</span>
                                                @endif
                                            </td>
                                            <td class="block p-2 md:table-cell">
                                                <span class="block text-xs text-gray-500 dark:text-paper-300 md:hidden">Formato de NC</span>
                                                @if ($lote)
                                                    {{-- Generado o descargado; nunca «enviado»: la entrega va fuera del sistema. --}}
                                                    <a href="{{ route('ppq.nc-exportaciones.show', $lote) }}" class="font-mono text-indigo-600 hover:underline">{{ $lote->referencia }}</a>
                                                    <span class="block text-xs text-gray-500 dark:text-paper-300">Archivo {{ mb_strtolower($lote->estado->label()) }}; su entrega al cliente no consta aquí</span>
                                                @else
                                                    <span class="text-xs text-gray-500 dark:text-paper-300">No incluida en ningún formato</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($notas['total'] > $notas['lista']->count())
                            <p class="mt-2 text-xs text-gray-500 dark:text-paper-300">Se muestran las primeras {{ $notas['lista']->count() }} de {{ $notas['total'] }}.</p>
                        @endif
                    @endif
                </div>
            @endif

            {{-- ---------- Observaciones ---------- --}}
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-1">Observaciones</h3>
                <p class="text-sm text-gray-500 dark:text-paper-300 mb-4">
                    Las nuestras y las del cliente conviven con los estados: actualizar la presentación o el pago
                    <strong>no las borra</strong>.
                </p>

                @can('ppq.gestionar')
                    <form method="POST" action="{{ route('cobros.documentos.observacion', $documento) }}" class="mb-4">
                        @csrf @method('PUT')
                        <label for="observaciones" class="block text-sm font-medium text-gray-700 dark:text-paper-100">Nuestra nota</label>
                        <textarea id="observaciones" name="observaciones" rows="3"
                                  class="mt-1 w-full rounded-md border-gray-300 text-sm">{{ old('observaciones', $documento->observaciones) }}</textarea>
                        <button class="mt-2 rounded-md bg-gray-800 px-3 py-1.5 text-sm text-white hover:bg-gray-700">Guardar</button>
                    </form>
                @else
                    <p class="text-sm whitespace-pre-line">{{ $documento->observaciones ?: '—' }}</p>
                @endcan

                @if ($documento->revisar_historico)
                    <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800">
                        <p class="font-medium">Requiere revisión histórica</p>
                        <p class="mt-1">{{ $documento->revisar_historico_motivo }}</p>
                        @can('ppq.gestionar')
                            <form method="POST" action="{{ route('cobros.documentos.revisado', $documento) }}" class="mt-2 flex flex-wrap items-end gap-2">
                                @csrf @method('PUT')
                                <label class="sr-only" for="nota_rev">Conclusión de la revisión</label>
                                <input id="nota_rev" name="nota" required placeholder="qué se comprobó"
                                       class="rounded-md border-gray-300 text-xs grow">
                                <label class="sr-only" for="evidencia_rev">Evidencia de la revisión</label>
                                <input id="evidencia_rev" name="evidencia" required maxlength="500" placeholder="correo, archivo o comprobación que lo respalda"
                                       class="rounded-md border-gray-300 text-xs grow">
                                <label class="sr-only" for="decision_rev">Conclusión</label>
                                <select id="decision_rev" name="decision" required class="w-full max-w-full rounded-md border-gray-300 text-xs sm:w-auto">
                                    <option value="mantener_bloqueo">Ya presentado, cobrado o todavía dudoso: mantener bloqueo</option>
                                    <option value="habilitar_presentacion">Confirmé que no se presentó ni se cobró: habilitar</option>
                                </select>
                                <button class="rounded-md bg-amber-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-amber-700">Guardar conclusión</button>
                                <p class="w-full text-xs">Esta revisión no registra un pago. Para conciliarlo, cargue la evidencia de pago por el circuito correspondiente.</p>
                            </form>
                        @endcan
                    </div>
                @endif
            </div>

            {{-- ---------- Bitácora ---------- --}}
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-1">Historia del documento</h3>
                <p class="text-sm text-gray-500 dark:text-paper-300 mb-4">
                    Cada hecho con su evidencia. Los importes cobrados salen de acá, no al revés.
                </p>

                @if ($documento->eventos->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-paper-300">Todavía no hay ningún hecho registrado.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Historia del documento</caption>
                            <thead class="bg-gray-50 text-gray-600 dark:text-paper-300">
                                <tr>
                                    <th scope="col" class="p-2 text-left font-medium">Registrado</th>
                                    <th scope="col" class="p-2 text-left font-medium">Hecho</th>
                                    <th scope="col" class="p-2 text-left font-medium">Origen</th>
                                    <th scope="col" class="p-2 text-right font-medium">Monto</th>
                                    <th scope="col" class="p-2 text-left font-medium">Fecha del hecho</th>
                                    <th scope="col" class="p-2 text-left font-medium">Detalle / evidencia</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                                @foreach ($documento->eventos as $evento)
                                    <tr>
                                        <td class="p-2 whitespace-nowrap">{{ $evento->created_at?->format('d/m/Y H:i') }}</td>
                                        <td class="p-2">
                                            {{ $evento->tipo->label() }}
                                            @if ($evento->tipo->afectaCobrado())
                                                <span class="block mt-0.5 inline-flex px-1.5 py-0.5 rounded-full text-[10px] {{ $evento->estado->clase() }}"
                                                      title="{{ $evento->estado->detalle() }}">{{ $evento->estado->label() }}</span>
                                            @endif
                                        </td>
                                        <td class="p-2 text-xs">{{ $evento->origen }}</td>
                                        <td class="p-2 text-right font-mono {{ $evento->cuenta() ? '' : 'text-gray-400 line-through' }}">
                                            {{ $evento->monto === null ? '—' : number_format((float) $evento->monto, 2) }}
                                        </td>
                                        <td class="p-2 whitespace-nowrap">{{ $evento->fecha?->format('d/m/Y') ?? '—' }}</td>
                                        <td class="p-2 text-xs text-gray-600 dark:text-paper-300">
                                            {{ $evento->detalle }}
                                            @if ($evento->evidencia_nombre)
                                                <span class="block font-mono text-[11px] text-gray-400">{{ $evento->evidencia_nombre }}</span>
                                            @endif
                                            @if (($evento->datos['fecha_documento_txt'] ?? null))
                                                <span class="block text-[11px] text-gray-400">
                                                    fecha del documento en el archivo: {{ $evento->datos['fecha_documento_txt'] }}
                                                    (no es la fecha del pago)
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
