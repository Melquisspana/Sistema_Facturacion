{{-- La tarjeta del pedido web con ruta en «Por entregar» (16/11/2026). Es distinta de la de
     la sala porque ya se pagó en la página: no lleva monto, forma de pago, PDF ni hoja, y el
     vendedor solo dice quién lo entregó y marca «Entregado». La dirección y los teléfonos
     vienen cifrados de pedidos_web; Blade los escapa. Si es regalo, se entrega a quien lo
     recibe y el paquete va sin precios.

     Recibe: $web (PedidoWeb con pedidoSala y su nombre, de EntregasCcf::pendientesWeb()). --}}
@php
    $cajitas = (int) collect($web->cajitas_detalle)->sum('cantidad');
    $regalo = $web->regalo;
@endphp
<div class="flex flex-wrap items-center gap-2">
    <h3 class="text-lg font-semibold leading-tight text-gray-800 dark:text-paper-100">{{ $web->pedidoSala->nombreSala() }}</h3>
    <x-planta.badge color="red">WEB</x-planta.badge>
</div>
<p class="mt-1 text-sm text-gray-700 dark:text-paper-100">{{ $web->direccion }}{{ filled($web->municipio) ? ', '.$web->municipio : '' }}</p>
<p class="text-sm text-gray-700 dark:text-paper-100">Tel. {{ $web->cliente_telefono }}</p>
@if ($regalo)
    <p class="mt-1 text-sm font-medium text-gray-800 dark:text-paper-100">Entregar a: {{ $regalo['nombre'] ?? '' }}{{ filled($regalo['telefono'] ?? null) ? ' · '.$regalo['telefono'] : '' }}</p>
    <p class="text-sm text-amber-800 dark:text-amber-300">Regalo: el paquete va sin precios</p>
@endif
<div class="mt-1 flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1">
    <p class="text-sm text-gray-600 dark:text-paper-300">{{ $cajitas }} {{ $cajitas === 1 ? 'cajita' : 'cajitas' }}</p>
    <p class="text-sm font-semibold text-green-700 dark:text-green-400">Ya pagado</p>
</div>
