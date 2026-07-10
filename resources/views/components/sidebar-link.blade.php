@props(['href', 'active' => false])
{{-- Enlace de la sidebar. Activo = barra índigo a la izquierda + fondo suave, para
     que la opción actual se distinga con claridad sin colores estridentes. --}}
<a href="{{ $href }}"
   @if ($active) aria-current="page" @endif
   {{ $attributes->merge(['class' => 'flex items-center justify-between gap-2 border-l-2 rounded-r-md px-3 py-2 text-sm transition '
        .($active
            ? 'border-indigo-600 bg-indigo-50 font-semibold text-indigo-700'
            : 'border-transparent text-gray-600 hover:bg-gray-100 hover:text-gray-900')]) }}>
    {{ $slot }}
</a>
