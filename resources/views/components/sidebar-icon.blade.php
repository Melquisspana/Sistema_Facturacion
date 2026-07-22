@props(['name'])
{{--
    Set mínimo de iconos de línea para las categorías del sidebar. Trazo simple
    y consistente (stroke-width 1.75, 20x20), sin librería externa: mismo
    criterio que los íconos ya escritos a mano en layouts/navigation.blade.php
    (hamburguesa) y components/dropdown (chevron).
--}}
@php
    $paths = [
        'inicio' => 'M3 10.5 12 3l9 7.5M5 9.5V21h5v-6h4v6h5V9.5',
        'comercial' => 'M4 8.5h16M6 8.5V6.75A1.75 1.75 0 0 1 7.75 5h8.5A1.75 1.75 0 0 1 18 6.75V8.5m1 0v10.75A1.75 1.75 0 0 1 17.25 21H6.75A1.75 1.75 0 0 1 5 19.25V8.5h14Z',
        'facturacion' => 'M7 3.5h7l4 4v13a.5.5 0 0 1-.5.5H7a.5.5 0 0 1-.5-.5v-16a.5.5 0 0 1 .5-.5Z M9.5 12h5M9.5 15.5h5M9.5 8.5h2',
        'ppq' => 'M11 4.5a6.5 6.5 0 1 1 0 13 6.5 6.5 0 0 1 0-13Z M20 20l-4.3-4.3',
        'contabilidad' => 'M5 4.5h14a.5.5 0 0 1 .5.5v14a.5.5 0 0 1-.5.5H5a.5.5 0 0 1-.5-.5V5a.5.5 0 0 1 .5-.5Z M8 8.5h8M8 12h3M8 15.5h3M14 12h2M14 15.5h2',
        'exportaciones' => 'M11 3.5v17M3.6 8h14.8M3.6 14h14.8 M11 3.5a13 13 0 0 1 3.6 8.5A13 13 0 0 1 11 20.5 13 13 0 0 1 7.4 12 13 13 0 0 1 11 3.5Z',
        'admin' => 'M11 8.75a2.25 2.25 0 1 0 0 4.5 2.25 2.25 0 0 0 0-4.5Z M18.94 12.7a7.3 7.3 0 0 0 .06-.7 7.3 7.3 0 0 0-.06-.7l1.68-1.31a.5.5 0 0 0 .12-.64l-1.6-2.77a.5.5 0 0 0-.6-.22l-1.98.8a7.4 7.4 0 0 0-1.21-.7l-.3-2.1a.5.5 0 0 0-.5-.42h-3.2a.5.5 0 0 0-.5.42l-.3 2.1c-.44.18-.85.42-1.21.7l-1.98-.8a.5.5 0 0 0-.6.22l-1.6 2.77a.5.5 0 0 0 .12.64L4.96 11.3a7.3 7.3 0 0 0-.06.7c0 .24.02.47.06.7L3.28 14a.5.5 0 0 0-.12.64l1.6 2.77c.12.21.38.3.6.22l1.98-.8c.36.28.77.52 1.21.7l.3 2.1c.05.24.26.42.5.42h3.2c.24 0 .45-.18.5-.42l.3-2.1c.44-.18.85-.42 1.21-.7l1.98.8c.22.08.48-.01.6-.22l1.6-2.77a.5.5 0 0 0-.12-.64l-1.68-1.3Z',
    ];
    $d = $paths[$name] ?? $paths['inicio'];
@endphp
<svg viewBox="0 0 22 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"
     {{ $attributes->merge(['class' => 'h-4 w-4 shrink-0']) }} aria-hidden="true">
    <path d="{{ $d }}" />
</svg>
