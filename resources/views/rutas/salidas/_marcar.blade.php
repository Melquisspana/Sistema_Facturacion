{{-- «Entregado» y «No entregado» de una tarjeta. Lo usan la hoja de la salida, «Mi salida»
     y «Por entregar»: los mismos datos van a EntregasCcf::registrar(), que valida.

     Recibe: $accion (URL), $metodo (PATCH o POST), $clave (id de la tarjeta; el formulario
     de «Entregado» se llama entregar-{clave}), $habitual (forma de pago del cliente),
     $motivos, $entregadoPorId (quién entregó, si va fijo) y $textoNo. --}}
@php
    $metodo ??= 'PATCH';
    $entregadoPorId ??= null;
    $textoNo ??= 'No entregado';
@endphp
<div class="mt-3 grid grid-cols-2 gap-2">
    <form id="entregar-{{ $clave }}" method="POST" action="{{ $accion }}">
        @csrf @method($metodo)
        <input type="hidden" name="resultado" value="entregado">
        @if ($entregadoPorId)
            <input type="hidden" name="entregado_por_id" value="{{ $entregadoPorId }}">
        @endif
        <button class="min-h-11 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600 w-full rounded-xl bg-green-600 px-3 py-3 text-sm font-semibold text-white hover:bg-green-700">Entregado</button>
        <label class="mt-1.5 flex items-center gap-2 text-xs text-gray-600 dark:text-paper-300">
            <input type="checkbox" name="trae_nota_averia" value="1" class="h-4 w-4 rounded border-gray-300 text-amber-600 dark:border-ink-600 dark:bg-ink-800">
            Trae nota de avería
        </label>
        {{-- Cómo pagó la sala esta vez. Vale la habitual del cliente y no se pregunta:
             solo si esta vez fue distinto se abre y se cambia. No toca su ficha. --}}
        <details class="mt-1.5 text-xs text-gray-600 dark:text-paper-300">
            <summary class="min-h-11 cursor-pointer py-2">Pago: {{ $habitual?->label() ?? 'Sin cobro (quedan)' }} · <span class="underline">cambiar</span></summary>
            <label for="forma-{{ $clave }}" class="sr-only">Forma de pago esta vez</label>
            <select id="forma-{{ $clave }}" name="forma_cobro" class="min-h-11 w-full rounded-lg border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                <option value="{{ \App\Services\Rutas\EntregasCcf::SIN_COBRO }}" @selected($habitual === null)>Sin cobro (quedan)</option>
                @foreach (\App\Enums\FormaCobroEntrega::opciones() as $valor => $label)
                    <option value="{{ $valor }}" @selected($habitual?->value === $valor)>{{ $label }}</option>
                @endforeach
            </select>
        </details>
    </form>

    <details class="group">
        <summary class="min-h-11 focus-visible:outline focus-visible:outline-2 focus-visible:outline-green-600 w-full cursor-pointer list-none rounded-xl bg-white px-3 py-3 text-center text-sm font-semibold text-red-700 ring-1 ring-red-200 hover:bg-red-50 dark:bg-ink-800 dark:text-red-300 dark:ring-red-500/40">
            {{ $textoNo }}
        </summary>
        <form method="POST" action="{{ $accion }}" class="mt-2 space-y-1.5">
            @csrf @method($metodo)
            <input type="hidden" name="resultado" value="no_entregado">
            @foreach ($motivos as $valor => $texto)
                @continue($valor === 'otro')
                <button name="motivo_no_entrega" value="{{ $valor }}"
                        class="min-h-11 focus-visible:outline focus-visible:outline-2 focus-visible:outline-green-600 block w-full rounded-lg bg-gray-50 px-3 py-2 text-left text-sm text-gray-700 hover:bg-red-50 hover:text-red-700 dark:bg-ink-700 dark:text-paper-100">
                    {{ $texto }}
                </button>
            @endforeach
            <div class="flex gap-1.5">
                <input name="nota" maxlength="300" placeholder="Otro motivo…" aria-label="Otro motivo de no entrega"
                       class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                <button name="motivo_no_entrega" value="otro" class="min-h-11 focus-visible:outline focus-visible:outline-2 focus-visible:outline-green-600 rounded-lg bg-gray-800 px-3 text-sm text-white dark:bg-paper-100 dark:text-ink-900">OK</button>
            </div>
        </form>
    </details>
</div>
