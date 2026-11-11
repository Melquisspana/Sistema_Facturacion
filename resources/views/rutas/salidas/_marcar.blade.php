{{-- «Entregado» y «No entregado» de una tarjeta. Lo usan la hoja de la salida, «Mi salida»
     y «Por entregar»: los mismos datos van a EntregasCcf::registrar(), que valida.

     Recibe: $accion (URL), $metodo (PATCH o POST), $clave (id de la tarjeta; el formulario
     de «Entregado» se llama entregar-{clave}), $habitual (forma de pago del cliente),
     $motivos, $entregadoPorId (quién entregó, si va fijo) y $textoNo.

     Con $pidePago («Por entregar», 10/11/2026) se pregunta «¿Cómo te pagaron?» con las cuatro
     opciones a la vista y sin ninguna marcada: el servidor no deja entregar sin elegir. Con
     $portal (el supermercado que cobra por el portal) no se pregunta. --}}
@php
    $metodo ??= 'PATCH';
    $entregadoPorId ??= null;
    $textoNo ??= 'No entregado';
    $pidePago ??= false;
    $portal ??= false;
@endphp
{{-- Con la pregunta del pago, «Entregado» va a todo el ancho y «No se pudo» abajo: las cuatro formas entran sin cortarse en el celular. --}}
<div class="mt-3 grid {{ $pidePago || $portal ? 'grid-cols-1' : 'grid-cols-2' }} gap-2">
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
        @if ($portal)
            <p class="mt-1.5 text-xs text-gray-600 dark:text-paper-300">El cobro va por el portal; la entrega se confirma sola con el albarán.</p>
        @elseif ($pidePago)
            {{-- `forma` solo decide qué se ve (Alpine, que ya carga la plantilla): los datos del
                 quedan aparecen al elegir «Quedan». Sin JavaScript quedan siempre a la vista. --}}
            <div x-data="{ forma: '' }">
            <fieldset class="mt-1.5">
                <legend class="text-xs font-medium text-gray-700 dark:text-paper-100">¿Cómo te pagaron?</legend>
                <div class="mt-1 grid grid-cols-2 gap-1.5">
                    @foreach (\App\Enums\FormaCobroEntrega::opciones() + [\App\Services\Rutas\EntregasCcf::SIN_COBRO => 'Quedan'] as $valor => $label)
                        <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                            <input type="radio" name="forma_cobro" value="{{ $valor }}" required x-model="forma" class="h-4 w-4 border-gray-300 text-indigo-600">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            {{-- Quedan: el número y la fecha de pago que le dieron, a la vista. No son obligatorios
                 (la tienda puede no dar la fecha): sin fecha, la oficina lo ve como «Sin fecha de pago». --}}
            <div x-show="forma === '{{ \App\Services\Rutas\EntregasCcf::SIN_COBRO }}'" class="mt-2 rounded-lg bg-gray-50 p-3 ring-1 ring-gray-200 dark:bg-ink-700 dark:ring-ink-600">
                <p class="text-sm font-medium text-gray-800 dark:text-paper-100">Datos del quedan</p>
                <p class="text-xs text-gray-600 dark:text-paper-300">Si la tienda no te dio la fecha, dejala vacía.</p>
                <label for="quedan-numero-{{ $clave }}" class="mt-2 block text-sm text-gray-700 dark:text-paper-100">N.º de quedan</label>
                <input id="quedan-numero-{{ $clave }}" name="quedan_numero" maxlength="30" autocomplete="off"
                       class="mt-1 min-h-11 w-full rounded-lg border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                <label for="quedan-fecha-{{ $clave }}" class="mt-2 block text-sm text-gray-700 dark:text-paper-100">Fecha de pago</label>
                <input id="quedan-fecha-{{ $clave }}" name="quedan_fecha_pago" type="date" min="{{ \App\Support\HoraNegocio::fechaHoy() }}"
                       class="mt-1 min-h-11 w-full rounded-lg border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
            </div>
            </div>
        @else
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
        @endif
        @if ($pidePago || $portal)
            {{-- Una observación de la entrega, por ejemplo una devolución (casi no hay). --}}
            <label for="obs-{{ $clave }}" class="sr-only">Observación de la entrega</label>
            <input id="obs-{{ $clave }}" name="nota" maxlength="300" autocomplete="off" placeholder="Observación (opcional): devolución…"
                   class="mt-1.5 min-h-11 w-full rounded-lg border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        @endif
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
