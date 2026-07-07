{{--
    Aviso inline del MODO DTE para las pantallas de facturación (ficha + creación de
    CCF/NC/FEX/Factura). Deja claro, junto a las acciones, si el sistema está en modo
    seguro (no emite a producción) o si una emisión real a producción es posible.

    Espera:
      $modo  array de DteTransmisionService::estadoOperativo() (o null si no es gestor).

    Solo presentación: no transmite, no muestra secretos.
--}}
@props(['modo' => null])

@if ($modo)
    @php
        $seguro = ! empty($modo['modo_seguro']);
        $real = ! empty($modo['transmision_real_posible']);
        [$caja, $texto, $chip] = $real
            ? ['bg-rose-50 border-rose-300', 'text-rose-800', 'bg-rose-600 text-white']
            : ['bg-green-50 border-green-300', 'text-green-800', 'bg-green-600 text-white'];
    @endphp
    <div class="mb-4 rounded-md border p-3 text-sm {{ $caja }} {{ $texto }}">
        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold {{ $chip }}">
                MODO {{ $modo['etiqueta'] }}
            </span>
            @if ($seguro)
                <span class="font-semibold">NO EMITE PRODUCCIÓN</span>
            @else
                <span class="font-bold">⚠ EMISIÓN REAL A PRODUCCIÓN POSIBLE</span>
            @endif
            @if (! empty($modo['mocks']['alguno']))
                <span class="inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-700">PRUEBAS / MOCK</span>
            @endif
        </div>
        <p class="mt-1 text-xs {{ $texto }}">{{ $modo['detalle'] }}</p>
    </div>
@endif
