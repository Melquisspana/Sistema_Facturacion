<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold leading-tight text-gray-800">Impresos · {{ $planilla->periodo }}</h1>
                <p class="text-sm text-gray-500">{{ $planilla->periodoEnPalabras() }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <nav class="flex gap-1 rounded-md border border-gray-200 bg-white p-1" aria-label="Formato">
                    @foreach (['hoja' => 'Hoja para firmas', 'recibo' => 'Recibos'] as $clave => $texto)
                        <a href="{{ route('planilla.impresos', ['planilla' => $planilla, 'formato' => $clave]) }}"
                           @class(['min-h-11 rounded px-3 py-2 text-sm font-medium', 'bg-indigo-50 text-indigo-700' => $formato === $clave, 'text-gray-600 hover:bg-gray-50' => $formato !== $clave])>{{ $texto }}</a>
                    @endforeach
                </nav>
                <a href="{{ route('planilla.show', $planilla) }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver</a>
            </div>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-5xl space-y-3">

            @if ($planilla->borrador())
                {{-- Un borrador se puede imprimir para revisarlo, pero no es un documento
                     de pago: nadie debe nada todavía. Se dice para que no se firme algo
                     que aún puede cambiar. --}}
                <div class="no-imprimir rounded-md border-2 border-dashed border-amber-400 bg-amber-50 px-4 py-2.5 text-sm text-amber-900" role="status">
                    <strong>Borrador.</strong> Esta planilla todavía no está confirmada: no hay obligaciones y los
                    importes pueden cambiar. Sirve para revisar, no para firmar.
                </div>
            @elseif ($planilla->estado === 'anulada')
                <div class="no-imprimir rounded-md border-2 border-red-300 bg-red-50 px-4 py-2.5 text-sm text-red-800" role="status">
                    <strong>Planilla anulada.</strong> {{ $planilla->motivo_anulacion }}
                </div>
            @endif

            @include('planilla.partials.impresos-estilo')

            @include('planilla.partials.'.($formato === 'hoja' ? 'hoja-firmas' : 'recibos'), [
                'cabecera' => $cabecera, 'lineas' => $lineas, 'suma' => $suma, 'moneda' => $moneda,
                'negocio' => $negocio, 'empleadora' => $empleadora, 'logo' => $logo,
                'entregaron' => $entregaron,
            ])

            @can('planilla.documentos')
                <p class="text-xs text-gray-500">
                    Cuando esté firmado, se adjunta desde la
                    <a href="{{ route('planilla.show', $planilla) }}" class="underline">ficha de la planilla</a>.
                </p>
            @endcan
        </div>
    </div>
</x-app-layout>
