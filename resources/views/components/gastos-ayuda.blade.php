@props(['titulo' => '¿Por qué?'])

{{-- Una explicación que se despliega solo si alguien la pide.

     El porqué de cada grupo importa —hay decisiones finas detrás de qué suma y qué
     no—, pero leerlo TODAS las veces no: quien entra a pagar el recibo de la luz ya
     lo sabe a la tercera visita y esos párrafos se vuelven ruido que empuja hacia
     abajo lo que sí se mira.

     `<details>` y no un panel de Alpine: funciona sin JavaScript, el navegador ya le
     da el rol y el estado de accesibilidad, y no hay que inventar teclado. --}}
<details class="border-t border-gray-100 bg-gray-50 px-4 py-2">
    <summary class="cursor-pointer select-none text-xs font-medium text-gray-500 hover:text-gray-700">
        {{ $titulo }}
    </summary>
    <div class="pb-1 pt-2 text-xs leading-relaxed text-gray-600">{{ $slot }}</div>
</details>
