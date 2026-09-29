@props(['avisos' => [], 'filtros' => []])

{{--
    Franja COMPACTA de avisos, arriba de «Por pagar».

    Una sola línea. Si hay que escanearla, ya falló: lo que interesa es cuántos y de
    qué tipo, y cada cifra lleva al filtro que corresponde. El detalle está en la
    lista de abajo, que es la pantalla de verdad.

    «Ya lo vi» MARCA LECTURA Y NADA MÁS. No salda gastos, no los quita de pendientes
    y no cancela recordatorios futuros: si una deuda sigue vencida, la semana que
    viene vuelve a avisar. Se dice en el propio botón porque la confusión sería cara
    —alguien podría creer que limpiar la franja resuelve la deuda—.

    Abrir la pantalla NO marca nada como visto. Silenciar un vencido por el mero
    hecho de pasar por acá es exactamente lo que no puede ocurrir.
--}}
@php
    $etiquetas = [
        'vencido' => ['texto' => 'vencido', 'plural' => 'vencidos', 'pestana' => 'vencidos', 'clase' => 'text-red-800'],
        'vence' => ['texto' => 'vence pronto', 'plural' => 'vencen pronto', 'pestana' => 'proximos', 'clase' => 'text-amber-800'],
        'falta_monto' => ['texto' => 'esperando monto', 'plural' => 'esperando monto', 'pestana' => 'por_completar', 'clase' => 'text-sky-800'],
    ];
    $total = array_sum($avisos);
@endphp

@if ($total > 0)
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm" role="status">
        <svg class="h-4 w-4 flex-none text-amber-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
        </svg>

        @foreach ($etiquetas as $tipo => $e)
            @if (($avisos[$tipo] ?? 0) > 0)
                <a href="{{ route('gastos.index', ['pestana' => $e['pestana']] + $filtros) }}"
                   class="font-medium underline decoration-dotted underline-offset-2 {{ $e['clase'] }}">
                    {{ $avisos[$tipo] }} {{ $avisos[$tipo] === 1 ? $e['texto'] : $e['plural'] }}
                </a>
            @endif
        @endforeach

        <span class="ml-auto flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route('gastos.avisos.leer-todos') }}">
                @csrf
                <button type="submit"
                        title="Solo marca los avisos como leídos. No salda nada ni deja de recordarlo."
                        class="inline-flex min-h-11 items-center rounded-md border border-amber-300 bg-white px-3 text-xs font-medium text-amber-900 hover:bg-amber-100">
                    Ya lo vi
                </button>
            </form>
            <a href="{{ route('gastos.avisos.preferencias') }}"
               class="inline-flex min-h-11 items-center px-2 text-xs text-amber-800 underline">Ajustar avisos</a>
        </span>
    </div>
@endif
