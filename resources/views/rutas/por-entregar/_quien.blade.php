{{-- «¿Quién lo entregó?» de una tarjeta de «Por entregar» (12/11/2026). Viene elegido el que
     tiene el celular; «cambiar» abre una pastilla por vendedor activo (la guía: quién lo
     entregó se elige con pastillas en la misma tarjeta). Las pastillas no son de ningún
     formulario: guardan la elección en `quien` (Alpine, del x-data de quien incluye esto) y
     cada formulario de la tarjeta la manda en su `entregado_por_id` oculto. Sin JavaScript
     queda el que tiene el celular. El servidor vuelve a validar que sea un vendedor activo.

     Recibe: $quienes (PersonalRuta: id y nombre), $quienElegido (id) y $clave (de la tarjeta). --}}
<details class="mt-3 rounded-lg bg-gray-50 px-3 ring-1 ring-gray-200 dark:bg-ink-700 dark:ring-ink-600">
    <summary class="flex min-h-11 cursor-pointer flex-wrap items-center gap-x-2 py-2 text-sm text-gray-700 dark:text-paper-100">
        <span>¿Quién lo entregó?</span>
        <span class="font-semibold" x-text="nombres[quien]">{{ $quienes->firstWhere('id', $quienElegido)?->nombre }}</span>
        <span class="text-gray-500 dark:text-paper-300">·</span>
        <span class="text-indigo-600 underline dark:text-indigo-300">cambiar</span>
    </summary>
    <fieldset class="pb-3">
        <legend class="sr-only">Elegí quién lo entregó</legend>
        <div class="flex flex-wrap gap-2">
            @foreach ($quienes as $p)
                <label class="cursor-pointer">
                    <input type="radio" name="quien-{{ $clave }}" value="{{ $p->id }}" x-model="quien" @checked($p->id === $quienElegido) class="peer sr-only">
                    <span class="inline-flex min-h-11 items-center rounded-full bg-white px-4 text-sm text-gray-800 ring-1 ring-gray-300 peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:ring-indigo-600 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-indigo-600 dark:bg-ink-800 dark:text-paper-100 dark:ring-ink-500">{{ $p->nombre }}</span>
                </label>
            @endforeach
        </div>
    </fieldset>
</details>
