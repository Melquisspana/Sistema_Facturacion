@props(['estado'])
{{-- Badge del estado de una salida. El color sale del enum (EstadoSalidaRuta),
     que es la fuente única: la vista no decide qué color va con qué estado.

     Las clases se escriben completas y no interpoladas dentro del atributo para
     que Tailwind las encuentre al compilar; un `bg-{{ $color }}-100` quedaría
     fuera del CSS final. --}}
@php
    $clases = match ($estado->color()) {
        'amber' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
        'green' => 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300',
        'red' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
        default => 'bg-gray-100 text-gray-600 dark:bg-ink-700 dark:text-paper-300',
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-block rounded-full px-2.5 py-0.5 text-xs font-medium '.$clases]) }}>
    {{ $estado->label() }}
</span>
