@props(['documento'])

{{-- Los cuatro estados de un documento, en bloques rotulados y SIEMPRE en el mismo
     orden, para que la vista se escanee en vertical: la columna «Entrega» de
     todas las filas está a la misma altura y se lee de corrido.

        ENTREGA          DOCUMENTACIÓN      NOTA DE CRÉDITO   COBRO / PPQ
        ✓ Entregado      ○ Papel pendiente  ✓ NC aceptada     ✓ Pagado
        17 Jul 2026      —                  DTE-05-…          22 Jul · $113.58

     El orden cuenta la vida del documento de izquierda a derecha: sale, se
     entrega, vuelve el papel, se corrige si hizo falta y al final se cobra.

     Presentación pura: nada de lo que se pinta acá se guarda en
     `salida_ruta_documentos`. La entrega sale del albarán, la NC se lee de `dtes`
     y el cobro de `ppq_items`, todo en este mismo instante; este componente solo
     elige cómo mostrarlo. --}}
@php
    $rotulo = 'text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-paper-500';
    $valor = 'mt-1 flex items-center gap-1.5 text-sm font-medium';
    $meta = 'mt-0.5 text-xs text-gray-500 dark:text-paper-400';

    $ok = 'text-green-700 dark:text-green-400';
    $espera = 'text-gray-500 dark:text-paper-400';
    $aviso = 'text-amber-700 dark:text-amber-400';
    $malo = 'text-red-700 dark:text-red-400';

    $nc = $documento->notaCredito();
    $estadoNc = $nc?->estado instanceof \App\Enums\EstadoDte
        ? $nc->estado
        : ($nc ? \App\Enums\EstadoDte::tryFrom((string) $nc->estado) : null);

    $ppq = $documento->ppqItem();
    $ppqNc = $documento->ppqNotaCredito();
@endphp

<div class="grid grid-cols-1 gap-x-6 gap-y-3 rounded-lg bg-gray-50 px-4 py-3 sm:grid-cols-2 lg:grid-cols-4 dark:bg-ink-900/40">

    {{-- ENTREGA — derivada del albarán. --}}
    <div>
        <p class="{{ $rotulo }}">Entrega</p>
        @if ($documento->entregado())
            <p class="{{ $valor }} {{ $ok }}"><span aria-hidden="true">✓</span> Entregado</p>
            <p class="{{ $meta }}">
                {{ $documento->fechaEntrega()?->translatedFormat('d M Y') ?? 'Sin fecha de albarán' }}
                <span class="block truncate font-mono text-[11px] opacity-70">{{ $documento->albaran()->numero_albaran }}</span>
            </p>
        @else
            <p class="{{ $valor }} {{ $espera }}"><span aria-hidden="true">○</span> Esperando albarán</p>
            <p class="{{ $meta }} opacity-70">Aún no llegó de Calleja</p>
        @endif
    </div>

    {{-- DOCUMENTACIÓN — el único de los tres que es manual. --}}
    <div>
        <p class="{{ $rotulo }}">Documentación</p>
        @if ($documento->documentacionFisicaRecibida())
            <p class="{{ $valor }} {{ $ok }}"><span aria-hidden="true">✓</span> Papel recibido</p>
            <p class="{{ $meta }}">
                {{ $documento->documentacion_fisica_recibida_at->translatedFormat('d M Y') }}
                @if ($documento->documentacionRecibidaPor)
                    <span class="block truncate opacity-70">{{ $documento->documentacionRecibidaPor->name }}</span>
                @endif
            </p>
        @else
            <p class="{{ $valor }} {{ $espera }}"><span aria-hidden="true">○</span> Papel pendiente</p>
            <p class="{{ $meta }} opacity-70">No ha regresado firmado</p>
        @endif
    </div>

    {{-- NOTA DE CRÉDITO — manda el documento REAL sobre la marca operativa.
         Si la NC ya existe y está aceptada, el asunto está resuelto y no tiene
         por qué seguir pintado de amarillo como si hubiera algo pendiente; la
         marca «requiere NC» pasa a ser una nota al pie. --}}
    <div>
        <p class="{{ $rotulo }}">Nota de crédito</p>
        @if ($nc)
            @php
                [$clase, $icono, $texto] = match ($estadoNc) {
                    \App\Enums\EstadoDte::Aceptado => [$ok, '✓', 'NC aceptada'],
                    \App\Enums\EstadoDte::Rechazado => [$malo, '✕', 'NC rechazada'],
                    \App\Enums\EstadoDte::Invalidado => [$malo, '✕', 'NC invalidada'],
                    default => [$aviso, '⚠', 'NC '.($estadoNc?->label() ?? $nc->estado)],
                };
            @endphp
            <p class="{{ $valor }} {{ $clase }}"><span aria-hidden="true">{{ $icono }}</span> {{ $texto }}</p>
            <p class="{{ $meta }}">
                <span class="block truncate font-mono text-[11px] opacity-70">{{ $nc->numero_control }}</span>
                @if ($documento->requiere_nc)
                    <span class="block opacity-70">Se había marcado para revisar</span>
                @endif
            </p>
        @elseif ($documento->requiere_nc)
            <p class="{{ $valor }} {{ $aviso }}"><span aria-hidden="true">⚠</span> Requiere NC</p>
            <p class="{{ $meta }}">
                {{ $documento->motivo_revision?->label() ?? 'Sin motivo indicado' }}
                @if ($documento->motivo_revision_nota)
                    <span class="block truncate opacity-70">{{ $documento->motivo_revision_nota }}</span>
                @endif
            </p>
        @else
            <p class="{{ $valor }} {{ $espera }} opacity-60"><span aria-hidden="true">—</span> Sin novedad</p>
            <p class="{{ $meta }} opacity-60">No se pidió corrección</p>
        @endif
    </div>

    {{-- COBRO / PPQ — leído de `ppq_items` en este instante.

         La distinción que sostiene todo este bloque: estar en un lote NO es estar
         pagado. Un documento puede llevar semanas dentro de un PPQ sin que Calleja
         lo haya pagado, y decirle «pagado» a eso sería mentirle a quien cobra. Solo
         se pinta PAGADO cuando el documento apareció en el TXT de pagos, que es la
         misma regla que aplica el módulo PPQ ({@see App\Services\Ppq\ConciliadorPpq}).

         El estado del LOTE se muestra como contexto («PPQ 30 de junio · listo»),
         nunca como estado de este documento: un lote marcado «pagado» a mano es una
         etiqueta de gestión del paquete, no la prueba de que este renglón se cobró. --}}
    <div>
        <p class="{{ $rotulo }}">Cobro / PPQ</p>

        @if (! $ppq)
            <p class="{{ $valor }} {{ $espera }}"><span aria-hidden="true">○</span> No está en PPQ</p>
            <p class="{{ $meta }} opacity-70">Aún no entró a un lote de cobro</p>

        @elseif ($documento->pagado())
            <p class="{{ $valor }} {{ $ok }}"><span aria-hidden="true">✓</span> Pagado</p>
            <p class="{{ $meta }}">
                {{ $documento->fechaPago()?->translatedFormat('d M Y') ?? 'Sin fecha en el TXT' }}
                @if ($documento->montoPagado() !== null)
                    <span class="mx-1 text-gray-300 dark:text-ink-600" aria-hidden="true">·</span>${{ number_format($documento->montoPagado(), 2) }}
                @endif
                @if ($documento->diferenciaPago() !== null)
                    <span class="block text-amber-700 dark:text-amber-400">
                        Difiere ${{ number_format(abs($documento->diferenciaPago()), 2) }} del documento
                    </span>
                @endif
                <span class="block truncate opacity-70">{{ $ppq->lote?->referencia ?? 'Lote sin referencia' }}</span>
            </p>

        @elseif ($documento->ncAplicada())
            {{-- «Aplicada» es el estado real que PPQ le pone a una NC descontada en el
                 TXT. Es terminal como «pagado», pero no es un pago: se muestra con su
                 nombre propio en vez de forzarlo dentro de otro estado. --}}
            <p class="{{ $valor }} text-indigo-700 dark:text-indigo-400"><span aria-hidden="true">✓</span> Aplicada</p>
            <p class="{{ $meta }}">
                {{ $documento->fechaPago()?->translatedFormat('d M Y') ?? 'Sin fecha en el TXT' }}
                @if ($documento->montoPagado() !== null)
                    <span class="mx-1 text-gray-300 dark:text-ink-600" aria-hidden="true">·</span>${{ number_format($documento->montoPagado(), 2) }}
                @endif
                <span class="block opacity-70">Descontada por Calleja</span>
            </p>

        @else
            <p class="{{ $valor }} {{ $aviso }}"><span aria-hidden="true">◐</span> En PPQ</p>
            <p class="{{ $meta }}">
                Pendiente de pago
                <span class="block truncate opacity-70">
                    {{ $ppq->lote?->referencia ?? 'Lote sin referencia' }}
                    @if ($ppq->lote?->estado)
                        <span class="mx-1 text-gray-300 dark:text-ink-600" aria-hidden="true">·</span>{{ $ppq->lote->estado->label() }}
                    @endif
                </span>
            </p>
        @endif

        {{-- La NC va aparte del cobro del CCF a propósito: son dos hechos distintos y
             que la NC esté aplicada no significa que el documento esté cobrado. Solo
             aparece si la NC además entró a PPQ por su cuenta. --}}
        @if ($ppqNc)
            <p class="{{ $meta }} mt-1 border-t border-gray-200 pt-1 dark:border-ink-700">
                NC en PPQ: {{ $ppqNc->estadoPagoLabel() }}
            </p>
        @endif
    </div>
</div>
