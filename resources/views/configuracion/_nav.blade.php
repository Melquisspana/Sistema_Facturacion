@php
    $tabs = [
        ['ruta' => 'configuracion.empresa.edit', 'patron' => 'configuracion.empresa.*', 'titulo' => 'Empresa emisora'],
        ['ruta' => 'configuracion.establecimientos.index', 'patron' => 'configuracion.establecimientos.*', 'titulo' => 'Establecimientos'],
        ['ruta' => 'configuracion.puntos-venta.index', 'patron' => 'configuracion.puntos-venta.*', 'titulo' => 'Puntos de venta'],
        ['ruta' => 'configuracion.correlativos.index', 'patron' => 'configuracion.correlativos.*', 'titulo' => 'Correlativos'],
        ['ruta' => 'configuracion.contabilidad.edit', 'patron' => 'configuracion.contabilidad.*', 'titulo' => 'Contabilidad'],
    ];
@endphp

<nav class="flex flex-wrap gap-2 border-b border-gray-200 mb-6">
    @foreach ($tabs as $tab)
        @php $activo = request()->routeIs($tab['patron']); @endphp
        <a href="{{ route($tab['ruta']) }}"
           class="px-4 py-2 text-sm font-medium rounded-t-md {{ $activo ? 'bg-white border border-b-0 border-gray-200 text-indigo-600' : 'text-gray-500 hover:text-gray-700' }}">
            {{ $tab['titulo'] }}
        </a>
    @endforeach
</nav>

@if (session('status'))
    <div class="mb-4 rounded-md bg-green-50 border border-green-200 p-4 text-sm text-green-700">
        {{ session('status') }}
    </div>
@endif

@if (session('error'))
    <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700">
        {{ session('error') }}
    </div>
@endif

@if ($errors->any())
    <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700">
        <p class="font-medium">Corrige los siguientes errores:</p>
        <ul class="list-disc list-inside mt-1">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
