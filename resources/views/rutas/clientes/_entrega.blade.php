{{-- Cómo va la entrega de un CCF ($entrega, de PanelClientes): entregado (con el día, si se
     sabe), por entregar (está en «Por entregar» del vendedor) o sin entrega anotada (de antes
     del control de entregas o de hace más de 45 días). Colores de la guía: verde listo, ámbar
     falta algo, gris sin dato. --}}
@php
    [$texto, $color] = match ($entrega['estado']) {
        \App\Services\Rutas\PanelClientes::ENTREGADO => [$entrega['fecha'] ? 'Entregado el '.$entrega['fecha']->format('d/m/Y') : 'Entregado', 'bg-green-100 text-green-700'],
        \App\Services\Rutas\PanelClientes::POR_ENTREGAR => ['Por entregar', 'bg-amber-100 text-amber-800'],
        default => ['Sin entrega anotada', 'bg-gray-100 text-gray-600'],
    };
@endphp
<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $color }}">{{ $texto }}</span>
