@props(['documento', 'salida', 'motivos', 'destinos', 'formPapel'])

{{-- Una tarjeta por documento, con tres franjas de arriba abajo:

       1. IDENTIDAD   correlativo corto + sala + monto: lo que se busca de un vistazo.
       2. ESTADOS     entrega / documentación / nota de crédito, rotulados.
       3. ACCIONES    la principal a la izquierda, las secundarias y la
                      destructiva empujadas a la derecha y sin color.

     El número de control completo va en la línea secundaria, en mono y pequeño:
     identifica sin competir con el nombre de la sala.

     Sobre los FORMULARIOS: el checkbox de selección múltiple pertenece al
     formulario masivo mediante `form="{{ $formPapel }}"`, no por estar dentro de
     él. Es a propósito — un <form> dentro de otro <form> es HTML inválido y el
     navegador descarta el interior, así que anidarlos dejaba sin efecto todos los
     botones por documento. Con el atributo `form` cada acción es un formulario
     hermano e independiente, y la selección masiva sigue funcionando igual. --}}
@php
    $puedeGestionar = auth()->user()?->can('rutas.gestionar') ?? false;
    $cancelada = $salida->estado === \App\Enums\EstadoSalidaRuta::Cancelada;
    $editable = $salida->estado->esEditable();

    // Los hechos operativos (papel, requiere NC) siguen disponibles en una salida
    // ya finalizada, porque el papel puede llegar después; en una cancelada no.
    $puedeAnotar = $puedeGestionar && ! $cancelada;

    // Solo display: el módulo únicamente transporta CCF, pero si algún día entrara
    // otro tipo se muestra el suyo en vez de mentir.
    $tipo = $documento->dte?->tipo_dte?->value === '03'
        ? 'CCF'
        : ($documento->dte?->tipo_dte?->label() ?? 'CCF');

    $accionSecundaria = 'text-xs text-gray-500 hover:text-gray-800 hover:underline dark:text-paper-400 dark:hover:text-paper-100';
@endphp

<div class="p-4 sm:p-5">

    {{-- ───────────── 1. Identidad ───────────── --}}
    <div class="flex items-start gap-3">
        @if ($puedeAnotar && ! $documento->documentacionFisicaRecibida())
            <input type="checkbox" name="documentos[]" value="{{ $documento->id }}" form="{{ $formPapel }}"
                   class="mt-1.5 h-4 w-4 shrink-0 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-ink-500 dark:bg-ink-700"
                   aria-label="Seleccionar {{ $documento->numeroLegible() }} para marcar documentación recibida">
        @else
            <span class="mt-1.5 h-4 w-4 shrink-0" aria-hidden="true"></span>
        @endif

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <span class="font-mono text-base font-semibold tracking-tight text-gray-900 dark:text-paper-50">{{ $documento->ultimos4() }}</span>
                <span class="text-gray-300 dark:text-ink-500" aria-hidden="true">·</span>
                <span class="truncate text-base font-medium text-gray-800 dark:text-paper-100">{{ $documento->salaNombre() ?? 'Sala sin resolver' }}</span>

                @if ($documento->esHistorico())
                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-ink-700 dark:text-paper-400"
                          title="Histórico P001: no está en la base de DTE, solo se conoce lo que se ve acá">Histórico P001</span>
                @endif
                @if ($documento->asignacion_automatica)
                    <span class="text-[10px] uppercase tracking-wide text-gray-400 dark:text-paper-500" title="Lo asoció el barrido automático, no una persona">auto</span>
                @endif
            </div>

            <p class="mt-1 text-xs text-gray-500 dark:text-paper-400">
                <span class="font-medium">{{ $tipo }}</span>
                <span class="mx-1 text-gray-300 dark:text-ink-600" aria-hidden="true">·</span>{{ $documento->fecha()?->translatedFormat('d M Y') ?? 'Sin fecha' }}
                @if ($documento->orden())
                    <span class="mx-1 text-gray-300 dark:text-ink-600" aria-hidden="true">·</span>OC {{ $documento->orden() }}
                @endif
                @if ($documento->clienteNombre())
                    <span class="mx-1 text-gray-300 dark:text-ink-600" aria-hidden="true">·</span>{{ $documento->clienteNombre() }}
                @endif
            </p>

            <p class="mt-0.5 truncate font-mono text-[11px] text-gray-400 dark:text-paper-500">{{ $documento->numeroLegible() }}</p>
        </div>

        <div class="shrink-0 text-right">
            <p class="text-lg font-semibold tabular-nums text-gray-900 dark:text-paper-50">
                {{ $documento->monto() !== null ? '$'.number_format($documento->monto(), 2) : '—' }}
            </p>
        </div>
    </div>

    {{-- ───────────── 2. Estados ───────────── --}}
    <div class="mt-4 sm:pl-7">
        <x-rutas.documento-estados :documento="$documento" />
    </div>

    {{-- ───────────── 3. Acciones ───────────── --}}
    @if ($puedeGestionar && ! $cancelada)
        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 sm:pl-7">

            {{-- Principal: lo que hay que hacer con este documento ahora. --}}
            @if (! $documento->documentacionFisicaRecibida())
                <form method="POST" action="{{ route('rutas.salidas.documentos.documentacion-fisica', $salida) }}">
                    @csrf
                    <input type="hidden" name="documentos[]" value="{{ $documento->id }}">
                    <button class="rounded-md bg-gray-800 px-3 py-1.5 text-xs font-medium text-white hover:bg-gray-900 dark:bg-paper-100 dark:text-ink-900 dark:hover:bg-white">
                        Marcar papel recibido
                    </button>
                </form>
            @else
                <form method="POST" action="{{ route('rutas.salidas.documentos.documentacion-fisica.destroy', [$salida, $documento]) }}"
                      onsubmit="return confirm('¿Deshacer la documentación física de este documento? Queda registrado quién lo hizo.');">
                    @csrf @method('DELETE')
                    <button class="{{ $accionSecundaria }}">Deshacer papel</button>
                </form>
            @endif

            {{-- Nota de crédito: marcar, o quitar la marca. Nunca emite una NC. --}}
            @if ($documento->requiere_nc)
                <form method="POST" action="{{ route('rutas.salidas.documentos.requiere-nc.destroy', [$salida, $documento]) }}">
                    @csrf @method('DELETE')
                    <button class="{{ $accionSecundaria }}">Quitar «requiere NC»</button>
                </form>
            @else
                <details class="group relative">
                    <summary class="cursor-pointer list-none rounded-md border border-amber-200 px-3 py-1.5 text-xs font-medium text-amber-700 hover:bg-amber-50 dark:border-amber-500/40 dark:text-amber-400 dark:hover:bg-amber-500/10">
                        Marcar que requiere NC
                    </summary>
                    <form method="POST" action="{{ route('rutas.salidas.documentos.requiere-nc', [$salida, $documento]) }}"
                          class="absolute left-0 z-10 mt-2 w-64 space-y-2 rounded-lg border border-gray-200 bg-white p-3 shadow-lg dark:border-ink-600 dark:bg-ink-800">
                        @csrf @method('PATCH')
                        <label class="block text-[11px] font-medium text-gray-600 dark:text-paper-300">Motivo (opcional)</label>
                        <select name="motivo_revision" class="w-full rounded-md border-gray-300 text-xs dark:border-ink-500 dark:bg-ink-700 dark:text-paper-100">
                            <option value="">Sin motivo</option>
                            @foreach ($motivos as $motivo)
                                <option value="{{ $motivo->value }}">{{ $motivo->label() }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="motivo_revision_nota" maxlength="300" placeholder="Detalle (opcional)"
                               class="w-full rounded-md border-gray-300 text-xs dark:border-ink-500 dark:bg-ink-700 dark:text-paper-100">
                        <p class="text-[10px] text-gray-500 dark:text-paper-400">Esto NO emite ninguna nota de crédito.</p>
                        <button class="w-full rounded-md bg-amber-500 px-2 py-1.5 text-xs font-medium text-white hover:bg-amber-600">Marcar</button>
                    </form>
                </details>
            @endif

            {{-- Secundarias y destructiva: a la derecha, sin color, para que no
                 compitan con la acción principal. --}}
            @if ($editable)
                <div class="ml-auto flex items-center gap-4">
                    @if ($destinos->isNotEmpty())
                        <details class="group relative">
                            <summary class="{{ $accionSecundaria }} cursor-pointer list-none">Mover</summary>
                            <form method="POST" action="{{ route('rutas.salidas.documentos.mover', [$salida, $documento]) }}"
                                  class="absolute right-0 z-10 mt-2 w-64 space-y-2 rounded-lg border border-gray-200 bg-white p-3 shadow-lg dark:border-ink-600 dark:bg-ink-800">
                                @csrf @method('PATCH')
                                <label class="block text-[11px] font-medium text-gray-600 dark:text-paper-300">Mover a la salida</label>
                                <select name="salida_destino_id" required class="w-full rounded-md border-gray-300 text-xs dark:border-ink-500 dark:bg-ink-700 dark:text-paper-100">
                                    @foreach ($destinos as $destino)
                                        <option value="{{ $destino->id }}">{{ $destino->descripcionCorta() }}</option>
                                    @endforeach
                                </select>
                                <button class="w-full rounded-md bg-gray-200 px-2 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-300 dark:bg-ink-600 dark:text-paper-100 dark:hover:bg-ink-500">Mover documento</button>
                            </form>
                        </details>
                    @endif

                    <form method="POST" action="{{ route('rutas.salidas.documentos.destroy', [$salida, $documento]) }}"
                          onsubmit="return confirm('¿Quitar este documento de la salida? El DTE no se toca.');">
                        @csrf @method('DELETE')
                        <button class="text-xs text-gray-400 hover:text-red-600 hover:underline dark:text-paper-500 dark:hover:text-red-400">Quitar</button>
                    </form>
                </div>
            @endif
        </div>
    @endif
</div>
