{{-- Una tarjeta de «Por mandar»: la sala, quién lo sacó, lo que FALTA mandar en grande y los
     tres botones. Pantalla táctil de fábrica (ver docs/GUIA_PANTALLAS.md, «Pantalla táctil»):
     botones de min-h-12 con py-4/py-5 y letra text-xl/2xl, a propósito más grandes que en
     la oficina. Funciona igual con el mouse.

     Si se está armando, el botón grande manda LO MARCADO en la hoja (cantidades, motivos y
     la casilla «va en otro envío»), no todo lo que falta: si no, registraría un despacho
     de lo que se marcó «Nada».

     Variables: $pedido (con lo de PedidosFabrica::relaciones()), $clave, $atrasado (bool),
     $hoy (Y-m-d). --}}
@php
    $faltan = $pedido->lineas->filter->tienePendiente();
    $completas = $pedido->lineas->reject->tienePendiente();
    $parcial = $pedido->estado === \App\Enums\EstadoPedidoSala::Parcial;
    $armando = $pedido->armando_desde !== null;
    $version = $pedido->versionProduccion();
    $dias = (int) $pedido->fecha->diffInDays(\Illuminate\Support\Carbon::parse($hoy));
@endphp
<article class="rounded-2xl border p-5 {{ $atrasado ? 'border-amber-300 bg-amber-50 ring-1 ring-amber-300' : 'border-gray-200 bg-white' }}"
         aria-labelledby="pedido-{{ $pedido->id }}">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <h2 id="pedido-{{ $pedido->id }}" class="min-w-0 text-2xl font-bold text-gray-800">{{ $pedido->nombreSala() }}</h2>
        <div class="flex flex-wrap items-center gap-2">
            @if ($pedido->armando_desde)
                <span class="inline-block rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-700">Armando</span>
            @endif
            <x-rutas.estado-badge :estado="$pedido->estado" />
        </div>
    </div>
    <p class="mt-1 text-sm text-gray-600">
        {{ $pedido->ruta?->nombre ?? 'Sin zona' }} · {{ $pedido->sacadoPor?->nombre ?? '—' }} · {{ $pedido->created_at->format('H:i') }}
    </p>
    @if ($atrasado)
        <p class="mt-1 text-sm font-semibold text-amber-800">Del {{ $pedido->fecha->format('d/m/Y') }} · hace {{ $dias === 1 ? '1 día' : $dias.' días' }}</p>
    @endif
    @if ($pedido->armando_desde)
        <p class="mt-1 text-sm text-gray-600">Lo está armando {{ $pedido->armandoPor?->name }} desde las {{ $pedido->armando_desde->format('H:i') }}.</p>
    @endif
    @if ($pedido->numero_orden_compra)
        <p class="mt-1 font-mono text-sm text-gray-600">OC {{ $pedido->numero_orden_compra }}</p>
    @endif
    @if ($pedido->notas)
        <p class="mt-2 whitespace-pre-line text-sm text-gray-700">{{ $pedido->notas }}</p>
    @endif

    <ul class="mt-4 divide-y divide-gray-100">
        @foreach ($faltan as $linea)
            <li class="flex items-baseline justify-between gap-3 py-2">
                <span class="min-w-0 text-lg text-gray-800">{{ $linea->producto?->nombre }}</span>
                <span class="shrink-0 text-right">
                    <span class="text-2xl font-bold tabular-nums text-gray-800">{{ \App\Models\PedidoSalaLinea::legible($linea->pendiente()) }}</span>
                    @if (bccomp($linea->mandado(), '0', 4) === 1)
                        <span class="block text-sm text-gray-500">faltan {{ \App\Models\PedidoSalaLinea::legible($linea->pendiente()) }} de {{ $linea->cantidadLegible() }}</span>
                    @endif
                    @if ($armando && $linea->va_borrador !== null && bccomp((string) $linea->va_borrador, $linea->pendiente(), 4) !== 0)
                        <span class="block text-sm font-semibold text-indigo-700">Marcado: va {{ \App\Models\PedidoSalaLinea::legible((string) $linea->va_borrador) }}</span>
                    @endif
                </span>
            </li>
        @endforeach
        @foreach ($completas as $linea)
            <li class="flex items-baseline justify-between gap-3 py-2 text-gray-400 line-through">
                <span class="min-w-0">{{ $linea->producto?->nombre }}</span>
                <span class="shrink-0 tabular-nums">{{ $linea->cantidadLegible() }}</span>
            </li>
        @endforeach
    </ul>

    <div class="mt-4 space-y-3">
        {{-- Un solo toque y sin confirmación (nivel 1): sale el aviso con «Deshacer». --}}
        <form method="POST" action="{{ route('fabrica.pedidos.mandar', $pedido) }}" @submit="enviando = true">
            @csrf
            <input type="hidden" name="clave" value="{{ $clave }}">
            <input type="hidden" name="version" value="{{ $version }}">
            @foreach ($faltan as $linea)
                @php($marcado = $armando && $linea->va_borrador !== null)
                <input type="hidden" name="cantidades[{{ $linea->id }}]" value="{{ $marcado ? $linea->va_borrador : $linea->pendiente() }}">
                @if ($marcado && filled($linea->motivo_borrador))
                    <input type="hidden" name="motivos[{{ $linea->id }}]" value="{{ $linea->motivo_borrador }}">
                @endif
            @endforeach
            @if ($armando && $pedido->armando_sigue)
                <input type="hidden" name="sigue" value="1">
            @endif
            <button :disabled="enviando"
                    class="inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-indigo-600 px-5 py-5 text-xl font-bold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50">
                {{ $armando ? 'Mandar lo marcado' : ($parcial ? 'Mandar lo que falta' : 'Se manda todo') }}
            </button>
        </form>
        <div class="grid grid-cols-2 gap-3">
            <a href="{{ route('fabrica.pedidos.show', $pedido) }}"
               class="inline-flex min-h-12 items-center justify-center rounded-xl border border-gray-300 bg-white px-3 py-4 text-center text-lg font-semibold text-gray-800 hover:bg-gray-50">
                {{ $pedido->armando_desde ? 'Seguir armando' : 'Faltan cosas' }}
            </a>
            <form method="POST" action="{{ route('fabrica.pedidos.cerrar', $pedido) }}" @submit="enviando = true">
                @csrf @method('PATCH')
                <input type="hidden" name="version" value="{{ $version }}">
                <button :disabled="enviando"
                        class="inline-flex min-h-12 w-full items-center justify-center rounded-xl border border-gray-300 bg-white px-3 py-4 text-lg font-semibold text-gray-800 hover:bg-gray-50 disabled:opacity-50">
                    {{ $parcial ? 'Lo que falta no se trae' : 'No se trae' }}
                </button>
            </form>
        </div>
    </div>
</article>
