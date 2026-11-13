{{-- Pestañas del tablero de Rutas: Resumen, Viajes y Mapa. «Vendedores» no es pestaña: es una
     sección del Resumen, y la pestaña que solo saltaba ahí se quitó (12/11/2026, pedido del
     dueño). El Mapa solo se dibuja con `rutas.mapa.ver`; ocultarlo no autoriza: la ruta
     lleva su middleware. Mismo dibujo que las pestañas de Gastos (docs/GUIA_PANTALLAS.md,
     «Pestañas»). Recibe `$activa`: 'resumen', 'viajes' o 'mapa'. --}}
<nav aria-label="Tablero de Rutas" class="flex flex-wrap gap-1 border-b border-gray-200">
    @foreach (array_filter([
        'resumen' => ['Resumen', route('rutas.dashboard')],
        'viajes' => ['Viajes', route('rutas.viajes')],
        'mapa' => auth()->user()->can('rutas.mapa.ver') ? ['Mapa', route('rutas.mapa')] : null,
    ]) as $clave => [$texto, $url])
        <a href="{{ $url }}" @if ($activa === $clave) aria-current="page" @endif
           class="inline-flex min-h-11 items-center rounded-t-md px-3 text-sm {{ $activa === $clave ? 'border-b-2 border-indigo-600 font-semibold text-indigo-700' : 'text-gray-600 hover:text-gray-900' }}">{{ $texto }}</a>
    @endforeach
</nav>
