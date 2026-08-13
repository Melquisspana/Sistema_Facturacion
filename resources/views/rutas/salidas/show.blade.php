<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Salida de ruta</h2>
            <a href="{{ route('rutas.salidas.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Volver a salidas</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-rutas.avisos />

            {{-- ===================== Encabezado ===================== --}}
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h3 class="text-2xl font-semibold uppercase tracking-wide text-gray-800 dark:text-paper-100">
                            {{ $salida->ruta->nombre }}
                        </h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-paper-400">{{ $salida->periodoLegible() }}</p>
                    </div>
                    <x-rutas.estado-badge :estado="$salida->estado" class="!px-3 !py-1 !text-sm" />
                </div>

                <dl class="mt-6 grid grid-cols-1 gap-x-8 gap-y-4 border-t border-gray-100 pt-5 sm:grid-cols-3 dark:border-ink-700">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-paper-400">Vendedores</dt>
                        <dd class="mt-1 text-sm text-gray-800 dark:text-paper-100">
                            {{ $salida->vendedores->pluck('name')->implode(' · ') ?: '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-paper-400">Regreso</dt>
                        <dd class="mt-1 text-sm text-gray-800 dark:text-paper-100">
                            @if ($salida->fecha_fin_real)
                                {{ $salida->fecha_fin_real->translatedFormat('d M Y') }}
                                <span class="text-xs text-gray-400 dark:text-paper-500">(real)</span>
                            @elseif ($salida->fecha_fin_estimada)
                                {{ $salida->fecha_fin_estimada->translatedFormat('d M Y') }}
                                <span class="text-xs text-gray-400 dark:text-paper-500">(estimado)</span>
                            @else
                                <span class="text-gray-400 dark:text-paper-500">Sin definir</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-paper-400">Registró</dt>
                        <dd class="mt-1 text-sm text-gray-800 dark:text-paper-100">{{ $salida->creador?->name ?? '—' }}</dd>
                    </div>
                </dl>

                @if ($salida->observaciones)
                    <div class="mt-4 border-t border-gray-100 pt-4 dark:border-ink-700">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-paper-400">Observaciones</p>
                        <p class="mt-1 whitespace-pre-line text-sm text-gray-700 dark:text-paper-200">{{ $salida->observaciones }}</p>
                    </div>
                @endif

                {{-- ===================== Acciones de estado =====================
                     Cada transición es un acto con nombre propio, no un campo
                     editable. Solo se dibuja lo que el estado actual permite; el
                     candado real está en el controlador y en el modelo. --}}
                @can('rutas.gestionar')
                    @if (! $salida->estado->esTerminal())
                        <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-gray-100 pt-5 dark:border-ink-700">
                            @if ($salida->estado->puedeTransicionarA(\App\Enums\EstadoSalidaRuta::EnCurso))
                                <form method="POST" action="{{ route('rutas.salidas.iniciar', $salida) }}">
                                    @csrf @method('PATCH')
                                    <button class="rounded-md bg-amber-500 px-4 py-2 text-sm font-medium text-white hover:bg-amber-600">Iniciar salida</button>
                                </form>
                            @endif

                            @if ($salida->estado->puedeTransicionarA(\App\Enums\EstadoSalidaRuta::Finalizada))
                                <form method="POST" action="{{ route('rutas.salidas.finalizar', $salida) }}">
                                    @csrf @method('PATCH')
                                    <button class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">Finalizar salida</button>
                                </form>
                            @endif

                            @if ($salida->estado->esEditable())
                                <a href="{{ route('rutas.salidas.edit', $salida) }}"
                                   class="rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-200 dark:hover:bg-ink-600">Editar</a>
                            @endif

                            @if ($salida->estado->puedeTransicionarA(\App\Enums\EstadoSalidaRuta::Cancelada))
                                <form method="POST" action="{{ route('rutas.salidas.cancelar', $salida) }}" class="ml-auto"
                                      onsubmit="return confirm('¿Cancelar esta salida? La acción queda registrada y no se puede deshacer.');">
                                    @csrf @method('PATCH')
                                    <button class="rounded-md px-3 py-2 text-sm text-gray-500 hover:bg-red-50 hover:text-red-600 dark:text-paper-400 dark:hover:bg-red-500/10 dark:hover:text-red-300">Cancelar salida</button>
                                </form>
                            @endif
                        </div>
                    @endif
                @endcan
            </div>

            {{-- ===================== Resumen =====================
                 Datos reales, contados sobre las MISMAS filas que se listan abajo.

                 Cada tarjeta cuenta UNA sola cosa y su rótulo dice exactamente cuál.
                 Antes «NC / PPQ» mezclaba tres números distintos —marcados para
                 revisar, NC emitidas y documentos en PPQ— y el número grande podía
                 decir 0 mientras el pie decía «1 con NC emitida». No era un error de
                 cálculo sino de rotulación: se separaron sin tocar ninguna cuenta,
                 son exactamente los mismos valores que ya devolvía el resumen. --}}
            <div class="mt-6">
                @php
                    $caja = 'rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none';
                    $rotulo = 'text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-paper-400';
                    $numero = 'mt-1.5 text-3xl font-semibold tabular-nums text-gray-800 dark:text-paper-100';
                    $pie = 'mt-1 text-xs text-gray-400 dark:text-paper-500';
                @endphp

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <div class="{{ $caja }}">
                        <p class="{{ $rotulo }}">Documentos</p>
                        <p class="{{ $numero }}">{{ $resumen['total'] }}</p>
                        <p class="{{ $pie }}">en esta salida</p>
                    </div>

                    <div class="{{ $caja }}">
                        <p class="{{ $rotulo }}">Entregados</p>
                        <p class="{{ $numero }} @if ($resumen['entregados'] > 0) !text-green-600 dark:!text-green-400 @endif">{{ $resumen['entregados'] }}</p>
                        <p class="{{ $pie }}">con albarán</p>
                    </div>

                    <div class="{{ $caja }}">
                        <p class="{{ $rotulo }}">Sin albarán</p>
                        <p class="{{ $numero }} @if ($resumen['sin_albaran'] > 0) !text-amber-600 dark:!text-amber-400 @endif">{{ $resumen['sin_albaran'] }}</p>
                        <p class="{{ $pie }}">esperando entrega</p>
                    </div>

                    <div class="{{ $caja }}">
                        <p class="{{ $rotulo }}">Doc. física</p>
                        <p class="{{ $numero }}">{{ $resumen['documentacion_fisica'] }}</p>
                        <p class="{{ $pie }}">papel de regreso</p>
                    </div>

                    {{-- Número grande = NC que SIGUEN CORRIGIENDO algo. Una rechazada o
                         una invalidada no descuenta nada, así que contarlas acá decía
                         que hay correcciones donde no quedó ninguna.

                         Las sin efecto no se ocultan: se declaran en el pie, y la
                         tarjeta del documento las sigue mostrando en rojo. El pie
                         cuenta además la marca operativa, que es otra cosa. --}}
                    <div class="{{ $caja }}">
                        <p class="{{ $rotulo }}">Notas de crédito</p>
                        <p class="{{ $numero }}">{{ $resumen['nc_vigentes'] }}</p>
                        @php $ncSinEfecto = $resumen['nc_reales'] - $resumen['nc_vigentes']; @endphp
                        <p class="{{ $pie }} @if ($resumen['requieren_nc'] > 0) !text-amber-600 dark:!text-amber-400 @endif">
                            @if ($ncSinEfecto > 0)
                                <span class="text-red-600 dark:text-red-400">{{ $ncSinEfecto }} sin efecto</span> ·
                            @endif
                            {{ $resumen['requieren_nc'] }} marcado{{ $resumen['requieren_nc'] === 1 ? '' : 's' }} para revisar
                        </p>
                    </div>

                    {{-- Número grande = documentos ya ingresados a un lote. El pie cuenta
                         los que además YA SE COBRARON, que es otra cosa: estar en un lote
                         no es estar pagado. Van juntos en una tarjeta y no en dos porque
                         se leen como una sola progresión («12 en PPQ, de esos 5 pagados»),
                         igual que la tarjeta de NC de al lado. --}}
                    <div class="{{ $caja }}">
                        <p class="{{ $rotulo }}">En PPQ</p>
                        <p class="{{ $numero }}">{{ $resumen['en_ppq'] }}</p>
                        <p class="{{ $pie }} @if ($resumen['pagados'] > 0) !text-green-600 dark:!text-green-400 @endif">
                            {{ $resumen['pagados'] }} pagado{{ $resumen['pagados'] === 1 ? '' : 's' }}
                        </p>
                    </div>
                </div>
            </div>

            {{-- ===================== Documentos ===================== --}}
            <div class="mt-8">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-paper-200">Documentos de la salida</h3>

                    @can('rutas.gestionar')
                        @if ($salida->estado->esEditable())
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('rutas.salidas.documentos.candidatos', $salida) }}"
                                   class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">
                                    Documentos candidatos para esta ruta
                                </a>
                                <a href="{{ route('rutas.salidas.documentos.historico', $salida) }}"
                                   class="rounded-md bg-gray-100 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-200 dark:hover:bg-ink-600">
                                    Agregar documento histórico
                                </a>
                                @if ($salida->estado === \App\Enums\EstadoSalidaRuta::EnCurso)
                                    {{-- El barrido automático NO está programado: se dispara acá,
                                         a mano, y aplica sus mismas reglas estrictas. --}}
                                    <form method="POST" action="{{ route('rutas.salidas.documentos.asociar-automatico', $salida) }}">
                                        @csrf
                                        <button class="rounded-md px-3 py-1.5 text-sm text-gray-500 hover:bg-gray-100 dark:text-paper-400 dark:hover:bg-ink-700"
                                                title="Busca CCF nuevos de esta ruta y los asocia solo si no hay ninguna ambigüedad">
                                            Buscar CCF nuevos
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    @endcan
                </div>

                @if ($documentos->isEmpty())
                    <div class="rounded-xl bg-white p-8 text-center shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                        <p class="text-sm text-gray-500 dark:text-paper-400">Esta salida todavía no tiene documentos.</p>
                        @can('rutas.gestionar')
                            @if ($salida->estado->esEditable())
                                <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">
                                    Empezá por «Documentos candidatos para esta ruta»: propone los CCF de las salas de la ruta dentro de las fechas de la salida.
                                </p>
                            @endif
                        @endcan
                    </div>
                @else
                    {{-- El formulario de la acción MASIVA se declara vacío y aparte. Los
                         checkboxes de cada tarjeta se enganchan a él con `form="..."`,
                         así no hace falta envolver la lista y los formularios por
                         documento no quedan anidados dentro de otro — HTML inválido que
                         el navegador descarta, dejando esos botones sin efecto. --}}
                    <form id="form-papel-{{ $salida->id }}" method="POST"
                          action="{{ route('rutas.salidas.documentos.documentacion-fisica', $salida) }}">
                        @csrf
                    </form>

                    <div class="divide-y divide-gray-100 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200 dark:divide-ink-700 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                        @foreach ($documentos as $documento)
                            <x-rutas.documento-fila
                                :documento="$documento"
                                :salida="$salida"
                                :motivos="$motivos"
                                :destinos="$destinos"
                                :form-papel="'form-papel-'.$salida->id" />
                        @endforeach
                    </div>

                    @can('rutas.gestionar')
                        @if ($salida->estado !== \App\Enums\EstadoSalidaRuta::Cancelada && $resumen['documentacion_fisica'] < $resumen['total'])
                            <div class="mt-4 flex flex-wrap items-center gap-3 rounded-xl border border-dashed border-gray-300 px-4 py-3 dark:border-ink-600">
                                <button type="submit" form="form-papel-{{ $salida->id }}"
                                        class="rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-900 dark:bg-paper-100 dark:text-ink-900 dark:hover:bg-white">
                                    Marcar documentación recibida
                                </button>
                                <span class="text-xs text-gray-500 dark:text-paper-400">
                                    Acción masiva: se aplica a los documentos marcados arriba. Guarda fecha, hora y usuario.
                                </span>
                            </div>
                        @endif
                    @endcan
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
