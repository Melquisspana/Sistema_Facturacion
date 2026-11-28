{{-- Pestañas de «Clientes» (28/11/2026): Clientes, la de entrada, y Todos los pedidos (la lista
     de siempre, rutas.pedidos.index). Las dos piden `rutas.pedidos.ver`, el permiso del panel.
     Mismo dibujo que las del Tablero (docs/GUIA_PANTALLAS.md, «Pestañas»). Recibe `$activa`:
     'clientes' o 'pedidos'. --}}
<nav aria-label="Clientes y pedidos" class="mb-4 flex flex-wrap gap-1 border-b border-gray-200">
    @foreach ([
        'clientes' => ['Clientes', route('rutas.clientes.index')],
        'pedidos' => ['Todos los pedidos', route('rutas.pedidos.index')],
    ] as $clave => [$texto, $url])
        <a href="{{ $url }}" @if ($activa === $clave) aria-current="page" @endif
           class="inline-flex min-h-11 items-center rounded-t-md px-3 text-sm {{ $activa === $clave ? 'border-b-2 border-indigo-600 font-semibold text-indigo-700' : 'text-gray-600 hover:text-gray-900' }}">{{ $texto }}</a>
    @endforeach
</nav>
