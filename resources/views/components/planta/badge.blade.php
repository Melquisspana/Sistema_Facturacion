@props(['color' => 'gray'])
{{-- Etiqueta de color para los enums de Planta. El color lo decide el propio
     enum (`->color()`), así que la vista no reparte colores por su cuenta.
     Las clases se escriben completas para que Tailwind no las purgue. --}}
@php
    $clases = match ($color) {
        'green' => 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300',
        'blue' => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
        'indigo' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300',
        'amber' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
        'red' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
        'rose' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
        default => 'bg-gray-100 text-gray-600 dark:bg-ink-700 dark:text-paper-300',
    };
@endphp
<span {{ $attributes->merge(['class' => 'inline-block rounded-full px-2.5 py-0.5 text-xs font-medium '.$clases]) }}>
    {{ $slot }}
</span>
