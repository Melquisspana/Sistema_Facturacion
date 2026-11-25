{{-- Cierre breve del día (Melqui, 16/11/2026: «que sea conciso»): lo vendido, una línea por
     vendedor, una línea por sala de lo que salió y una de lo pendiente. Los productos, motivos
     y hojas de despacho van en el PDF «Salió de la fábrica». Los ceros no se dicen.
     «Listos para salir» (24/11/2026; con ese nombre, el de la fábrica, desde el 25/11/2026):
     una línea por sala con las cajas listas, con el N.º de su orden; no cuentan en «Por mandar».
     Lo escrito por alguien se escapa también para Markdown mediante $literal. --}}
@php
    $dinero = fn ($n) => ($n < 0 ? '−' : '').'$'.number_format(abs($n), 2);
    $plural = fn (int $n, string $uno, string $varios) => $n === 1 ? '1 '.$uno : $n.' '.$varios;
    $facturas = fn (int $n) => $plural($n, 'factura', 'facturas');
    $ventas = $contenido['ventas'] ?? null;
    $vendedores = $contenido['vendedores'] ?? null;
    // Hasta qué hora cuenta lo de hoy: la del envío diario o, a mano, la hora en que salió.
    $hastaLas = $contenido['hasta_las'] ?? $hora;
    $nombres = fn (array $lista) => implode(', ', array_map($literal, $lista));
@endphp
<x-mail::documento titulo="Resumen diario" :fecha="$contenido['fecha']" :message="$message ?? null">
# Pedidos del {{ $contenido['fecha'] }}

@if ($ventas === null)
**No se pudo calcular lo vendido hoy.** Se puede ver en el sistema, en Ventas.
@elseif ($ventas['nacional']['facturas'] === 0 && $ventas['nacional']['monto'] == 0)
**Hoy no se facturó nada.**
@else
**Vendido hoy hasta {{ $hastaLas }}: {{ $dinero($ventas['nacional']['monto']) }} en {{ $facturas($ventas['nacional']['facturas']) }}.** Sin IVA.
@endif
@if ($ventas !== null && ($ventas['exportacion']['facturas'] > 0 || $ventas['exportacion']['monto'] != 0))

Exportación: {{ $dinero($ventas['exportacion']['monto']) }} en {{ $facturas($ventas['exportacion']['facturas']) }}.
@endif

@if ($vendedores === null)
**No se pudieron preparar los PDF del día de los vendedores.** Se pueden abrir en el sistema, en el viaje de cada uno.
@elseif (count($vendedores['adjuntos']) > 0)
PDF del día adjuntos, con lo marcado hasta {{ $hastaLas }}.
@elseif (count($vendedores['fallaron']) === 0)
Hoy ningún vendedor marcó entregas.
@endif
@if ($vendedores !== null && count($vendedores['fallaron']) > 0)

**No se pudo adjuntar el PDF de: {{ $nombres($vendedores['fallaron']) }}.** Se puede abrir en el sistema, en su viaje.
@endif

@foreach ($vendedores['resumenes'] ?? [] as $vendedor)
@php
    $partes = array_filter([
        'Entregas '.$vendedor['entregas'],
        $vendedor['no_se_pudo'] > 0 ? 'No se pudo '.$vendedor['no_se_pudo'] : null,
        'Cobrado '.$dinero($vendedor['cobrado'] / 100),
        $vendedor['quedan'] != 0 ? 'Quedan recibidos '.$dinero($vendedor['quedan'] / 100) : null,
        $vendedor['portal'] != 0 ? 'Cobro por el portal '.$dinero($vendedor['portal'] / 100) : null,
    ]);
@endphp
- {{ $literal($vendedor['nombre']) }}: {{ implode(' · ', $partes) }}

@endforeach
@foreach ($vendedores['cuentas_fallaron'] ?? [] as $nombre)
No se pudo calcular el día de {{ $literal($nombre) }}.

@endforeach
@if (! $contenido['salio_algo'])
**Hoy no salió ningún pedido.**

@endif
## Salió de la fábrica

@php
    $faltaron = 0; $pendientes = 0; $viejo = 0; $deshechos = 0;
@endphp
@foreach ($contenido['salas'] as $sala => $datos)
@php
    $faltaron += count($datos['falto']) > 0 ? 1 : 0;
    $pendientes += count($datos['por_mandar']);
    $viejo = max($viejo, collect($datos['por_mandar'])->max('atraso') ?? 0);
    $deshechos += count($datos['deshecho']);
@endphp
@if (count($datos['salio']) > 0)
@if (isset($datos['cajitas']))
- {{ $literal(str_replace(' (web)', '', $sala)) }}: {{ $plural((int) $datos['cajitas'], 'cajita', 'cajitas') }}
@else
- {{ $literal($sala) }}: {{ $plural(collect($datos['salio'])->sum(fn ($e) => count($e['productos'])), 'producto', 'productos') }} ({{ $plural(count($datos['salio']), 'envío', 'envíos') }})
@endif
@endif
@endforeach

@if ($deshechos > 0)
{{ $deshechos === 1 ? 'Se deshizo 1 envío' : 'Se deshicieron '.$deshechos.' envíos' }} (detalle en el PDF).

@endif
@if ($faltaron > 0 || $pendientes > 0)
{{ $faltaron > 0 ? 'Faltaron cosas en '.$plural($faltaron, 'sala', 'salas') : '' }}{{ $faltaron > 0 && $pendientes > 0 ? ' · ' : '' }}{{ $pendientes > 0 ? 'Por mandar: '.$plural($pendientes, 'pedido', 'pedidos').' (el más viejo, '.($viejo > 0 ? 'de hace '.$plural((int) $viejo, 'día', 'días') : 'de hoy').')' : '' }}.

@endif
@php
    $armados = collect($contenido['salas'])->filter(fn ($d) => count($d['armados'] ?? []) > 0);
@endphp
@if ($armados->isNotEmpty())
## Listos para salir

@foreach ($armados as $sala => $datos)
- {{ $literal(str_replace(' (web)', '', $sala)) }}: {{ count($datos['armados']) === 1 ? 'pedido' : 'pedidos' }} N.º {{ collect($datos['armados'])->pluck('numero')->join(', ', ' y ') }}
@endforeach

@endif
@if ($contenido['fabrica_pdf_fallo'] ?? false)
**No se pudo adjuntar el PDF de la fábrica.**

@endif
<x-mail::button :url="url('/fabrica/pedidos')">
Ver en el sistema
</x-mail::button>

Este resumen llega cada día a {{ $hora }}. Los correos que lo reciben se cambian
en **Configuración › Rutas**.

{{ \App\Support\NombreEmpresa::actual() }}
</x-mail::documento>
