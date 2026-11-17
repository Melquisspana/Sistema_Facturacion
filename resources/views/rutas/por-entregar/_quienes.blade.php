{{-- La lista de «¿Quién lo entregó?» de «Por entregar», UNA vez por página (16/11/2026). Antes
     iba entera en cada tarjeta y con 102 CCF y 21 personas la página pesaba 2,7 MB, que el
     vendedor abre en el celular. Cada tarjeta la copia con rutas.por-entregar._quien, así que
     en pantalla todo se ve y se usa igual que antes.

     Desde el 12/11/2026 es una fila de botones a la vista, uno por quien puede entregar, con
     su nombre corto (primer nombre y primer apellido; {@see \App\Models\PersonalRuta::nombresCortos()}),
     y ninguno viene elegido: antes venía el que tiene el celular y el que lo prestaba figuraba
     como que entregó todo. Los botones no son de ningún formulario: guardan la elección en
     `quien` (Alpine, del x-data de la tarjeta) y cada formulario de la tarjeta la manda en su
     `entregado_por_id` oculto; los botones de confirmar siguen apagados hasta elegir. El
     grupo de los botones (`grupo`) es el de cada tarjeta. El servidor exige que venga y que
     sea uno de la lista.

     Recibe: $quienes (PersonalRuta: id y nombre). --}}
@php
    $cortos = \App\Models\PersonalRuta::nombresCortos($quienes);
@endphp
<template id="lista-quienes">
    <fieldset class="mt-3">
        <legend class="text-sm font-medium text-gray-700 dark:text-paper-100">¿Quién lo entregó?</legend>
        <div class="mt-1.5 flex flex-wrap gap-2">
            @foreach ($quienes as $p)
                <label class="cursor-pointer">
                    <input type="radio" x-bind:name="grupo" value="{{ $p->id }}" x-model="quien" class="peer sr-only">
                    <span title="{{ $p->nombre }}" class="inline-flex min-h-11 items-center rounded-full bg-white px-4 text-sm text-gray-800 ring-1 ring-gray-300 peer-checked:bg-indigo-600 peer-checked:font-semibold peer-checked:text-white peer-checked:ring-indigo-600 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-indigo-600 dark:bg-ink-800 dark:text-paper-100 dark:ring-ink-500">{{ $cortos[$p->id] }}</span>
                </label>
            @endforeach
        </div>
        <p x-show="! quien" class="mt-1.5 text-xs text-gray-600 dark:text-paper-300">Tocá quién lo entregó</p>
    </fieldset>
</template>
