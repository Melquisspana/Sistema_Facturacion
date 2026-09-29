@props([
    'titulo',
    // El color dice de qué clase es el grupo, y es parte del mensaje: verde para lo que
    // se debe de verdad, ámbar para lo que solo está programado. No es decoración.
    'tono' => 'gris',
    'total' => null,
    'leyenda' => null,
    'vacio' => null,
    // Un grupo vacío que no aporta nada se reduce a UNA LÍNEA en vez de ocupar una
    // tarjeta entera. «Nada vencido» es una buena noticia de un renglón; dibujarla como
    // un bloque rojo grande le da el peso visual de un problema y empuja hacia abajo lo
    // que sí hay que mirar.
    'compacto' => false,
    'pie' => null,
])

@php
    $tonos = [
        // Solo clases que el tema oscuro del proyecto YA retematiza en app.css
        // (bg-*-50, border-*-200, text-*-700/800). Las variantes con opacidad y los
        // tonos 900 no están cubiertos y quedaban ilegibles en oscuro.
        'verde' => ['borde' => 'border-emerald-200', 'cabecera' => 'bg-emerald-50', 'texto' => 'text-emerald-800'],
        'ambar' => ['borde' => 'border-amber-200', 'cabecera' => 'bg-amber-50', 'texto' => 'text-amber-800'],
        'rojo' => ['borde' => 'border-red-200', 'cabecera' => 'bg-red-50', 'texto' => 'text-red-700'],
        'gris' => ['borde' => 'border-gray-200', 'cabecera' => 'bg-gray-50', 'texto' => 'text-gray-800'],
    ];
    $c = $tonos[$tono] ?? $tonos['gris'];
    $hayFilas = trim($slot->toHtml()) !== '';
@endphp

{{-- Un grupo de la pantalla principal: cabecera con su total propio y las filas debajo.

     CADA GRUPO LLEVA SU PROPIO TOTAL Y NINGUNO LLEVA LA SUMA DE OTRO. Esa es la razón
     de que este componente exista en vez de repetir el marcado: el día que alguien
     quiera «un total general» tendrá que escribirlo a mano y a la vista, no heredarlo
     sin querer de una plantilla compartida. --}}
@if (! $hayFilas && $compacto)
    <p class="flex items-center gap-2 px-1 text-sm text-gray-500">
        <span class="inline-block h-1.5 w-1.5 rounded-full bg-gray-300"></span>
        {{ $vacio ?? 'Nada por acá.' }}
    </p>
@else
    <div class="overflow-hidden rounded-lg border {{ $c['borde'] }} bg-white">
        <div class="flex items-center justify-between gap-3 border-b {{ $c['borde'] }} {{ $c['cabecera'] }} px-4 py-2.5">
            <p class="text-sm font-semibold {{ $c['texto'] }}">{{ $titulo }}</p>
            <p class="text-sm font-semibold tabular-nums {{ $c['texto'] }}">
                @if ($leyenda !== null)
                    {{ $leyenda }}
                @elseif ($total !== null)
                    ${{ \App\Services\Gastos\Dinero::mostrar((int) $total) }}
                @endif
            </p>
        </div>

        @if ($hayFilas)
            {{ $slot }}
            {{-- El pie va crudo: así el llamador puede pasar una ayuda desplegable en
                 vez de un párrafo siempre abierto. --}}
            {{ $pie }}
        @else
            <p class="px-4 py-6 text-center text-sm text-gray-500">{{ $vacio ?? 'Nada por acá.' }}</p>
        @endif
    </div>
@endif
