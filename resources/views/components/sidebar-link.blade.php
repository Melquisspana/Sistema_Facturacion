@props(['href', 'active' => false])
{{-- Enlace de la sidebar, ligeramente indentado respecto al título de categoría.
     Activo = barra índigo a la izquierda + fondo suave + texto más pesado, para
     que la opción actual se distinga con claridad sin colores estridentes.
     Hover discreto (un tono de fondo más, sin cambiar de color). --}}
<a href="{{ $href }}"
   @if ($active) aria-current="page" @endif
   {{ $attributes->merge(['class' => 'ms-1 flex items-center justify-between gap-2 border-l-2 rounded-r-md px-3 py-1.5 text-sm transition '
        .($active
            ? 'border-indigo-600 bg-indigo-50 font-semibold text-indigo-700 dark:border-indigo-400 dark:bg-indigo-500/15 dark:text-indigo-300'
            : 'border-transparent text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-paper-300 dark:hover:bg-ink-700 dark:hover:text-paper-100')]) }}>
    {{ $slot }}
</a>
