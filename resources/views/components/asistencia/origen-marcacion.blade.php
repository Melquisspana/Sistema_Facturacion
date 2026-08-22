@props(['marcacion'])
{{-- DE DÓNDE salió una marcación, sin inventar nada.

     Hay cuatro estados reales y son distintos entre sí. Meterlos todos en un «—»
     sería cómodo y mentiroso: quien audita una planilla necesita distinguir «lo
     puso el lector de la entrada» de «alguien lo corrigió a mano» y de «el lector
     que lo registró ya no existe».

       origen=dispositivo + lector presente  -> el nombre del lector
       origen=dispositivo + lector NULL      -> lo registró un lector que se borró
       origen=manual                         -> corrección hecha por una persona
       cualquier otro valor                  -> se muestra crudo, no se adivina

     El tercer caso todavía no puede ocurrir —no existe la corrección manual— pero
     la columna ya lo admite y la pantalla tiene que estar lista para leerlo
     correctamente el día que exista, no reinterpretarlo entonces. --}}
@php
    $esManual = $marcacion->origen === 'manual';
    $esDispositivo = $marcacion->origen === 'dispositivo';
    $lector = $marcacion->dispositivo;
@endphp

@if ($esManual)
    <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-500/15 dark:text-blue-300"
          title="Corrección registrada por una persona, no por el lector">
        Manual
    </span>
@elseif ($esDispositivo && $lector)
    <span class="text-gray-700 dark:text-paper-200">{{ $lector->nombre }}</span>
    <span class="block text-xs text-gray-400 dark:text-paper-500">{{ $lector->codigo }}</span>
@elseif ($esDispositivo)
    {{-- El lector se borró de la base (`nullOnDelete`). La marcación sigue siendo
         válida: la registró un aparato, aunque ya no se pueda decir cuál. --}}
    <span class="text-amber-700 dark:text-amber-300" title="La marcación la registró un lector que ya no está en el sistema">
        Lector no disponible
    </span>
@else
    <span class="text-gray-500 dark:text-paper-400" title="Valor de origen no reconocido">{{ $marcacion->origen }}</span>
@endif
