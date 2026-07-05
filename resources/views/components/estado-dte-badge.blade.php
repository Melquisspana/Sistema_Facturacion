@props(['estado'])

{{--
    Badge ÚNICO para el estado de un DTE (App\Enums\EstadoDte), reutilizado en listado,
    ficha y edición para que texto/color sean siempre los mismos. Solo presentación: usa
    $estado->label() (no reimplementa el texto) y NO decide transiciones ni lógica de
    estado. Clases Tailwind LITERALES (no interpoladas) para que el JIT las incluya.

    El mapeo de color coincide con App\Enums\EstadoDte::color() (gray/blue/indigo/amber/
    green/red/rose); se repite aquí en literal porque Tailwind no puede purgar clases
    armadas por interpolación (p. ej. "bg-{$color}-100").
--}}
@php
    $clases = match ($estado) {
        \App\Enums\EstadoDte::Borrador => 'bg-gray-100 text-gray-700',
        \App\Enums\EstadoDte::Generado => 'bg-blue-100 text-blue-700',
        \App\Enums\EstadoDte::Firmado => 'bg-indigo-100 text-indigo-700',
        \App\Enums\EstadoDte::Enviado => 'bg-amber-100 text-amber-700',
        \App\Enums\EstadoDte::Aceptado => 'bg-green-100 text-green-700',
        \App\Enums\EstadoDte::Rechazado => 'bg-red-100 text-red-700',
        \App\Enums\EstadoDte::Invalidado => 'bg-rose-100 text-rose-700',
    };
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium '.$clases]) }}>
    {{ $estado->label() }}
</span>
