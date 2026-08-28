@props(['total' => 0, 'activas' => 0])
{{-- Conteo de salas de un cliente, en una sola frase.

     Vive en un componente porque aparece en dos sitios —el directorio y la ficha—
     y tiene que decir exactamente lo mismo en los dos: si una pantalla dijera
     «12 salas» y la otra «12 salas · 10 activas», el operador tendría que decidir
     cuál de las dos le está mintiendo.

     Solo cubre el caso CON salas. El vacío lo resuelve cada pantalla a su manera:
     el directorio con un aviso corto en la celda, la ficha con un estado vacío que
     ofrece crear la primera. --}}
@php
    $total = (int) $total;
    $activas = (int) $activas;
@endphp
<span {{ $attributes }}>{{ $total }} {{ $total === 1 ? 'sala' : 'salas' }} · {{ $activas }} {{ $activas === 1 ? 'activa' : 'activas' }}</span>
