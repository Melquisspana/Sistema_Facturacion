{{--
    El correo diario (18:00), cierre del día. Arriba, lo de hoy: lo vendido en una línea (el
    total y cuántas facturas; exportación aparte y solo si hubo; nunca cada CCF ni el mes) y
    de quién van adjuntos los PDF del día. Si la cuenta o un PDF falló, lo dice. Después,
    los pedidos de sala desde el correo anterior. Por sala: lo
    que salió, lo que se deshizo, lo que faltó, lo que no se trae y lo que sigue por mandar. Listas y no tablas: el nombre de un
    producto con «|» rompería una tabla de markdown. Todo lo que escribió alguien pasa por
    $literal: sin eso, un motivo con `[texto](url)` sería un enlace en el correo.

    $ventas y $vendedores en null: no se pudieron calcular (ResumenDiarioPedidos lo reporta).
--}}
@php
    $dinero = fn ($n) => ($n < 0 ? '−' : '').'$'.number_format(abs($n), 2);
    $facturas = fn (int $n) => $n === 1 ? '1 factura' : $n.' facturas';
    $ventas = $contenido['ventas'] ?? null;
    $vendedores = $contenido['vendedores'] ?? null;
    $nombres = fn (array $lista) => implode(', ', array_map($literal, $lista));
@endphp
<x-mail::message>
# Pedidos del {{ $contenido['fecha'] }}

@if ($ventas === null)
**No se pudo calcular lo vendido hoy.** Se puede ver en el sistema, en Ventas.
@elseif ($ventas['nacional']['facturas'] === 0 && $ventas['nacional']['monto'] == 0)
**Hoy no se facturó nada.**
@else
**Vendido hoy: {{ $dinero($ventas['nacional']['monto']) }} en {{ $facturas($ventas['nacional']['facturas']) }}.** Sin IVA.
@endif
@if ($ventas !== null && ($ventas['exportacion']['facturas'] > 0 || $ventas['exportacion']['monto'] != 0))

Exportación: {{ $dinero($ventas['exportacion']['monto']) }} en {{ $facturas($ventas['exportacion']['facturas']) }}.
@endif

@if ($vendedores === null)
**No se pudieron preparar los PDF del día de los vendedores.** Se pueden abrir en el sistema, en el viaje de cada uno.
@elseif (count($vendedores['adjuntos']) > 0)
Van adjuntos los PDF del día de: {{ $nombres($vendedores['adjuntos']) }}.
@elseif (count($vendedores['fallaron']) === 0)
Hoy ningún vendedor marcó entregas.
@endif
@if ($vendedores !== null && count($vendedores['fallaron']) > 0)

**No se pudo adjuntar el PDF de: {{ $nombres($vendedores['fallaron']) }}.** Se puede abrir en el sistema, en su viaje.
@endif

@if (! $contenido['salio_algo'])
**Hoy no salió ningún pedido.**

@endif
@forelse ($contenido['salas'] as $sala => $datos)
## {{ $literal($sala) }}

@if (count($datos['salio']) > 0)
**Salió**

@foreach ($datos['salio'] as $envio)
- Envío {{ $envio['numero'] }} ({{ $envio['hora'] }}): {{ collect($envio['productos'])->map(fn ($p) => $literal($p['nombre']).' '.$p['cantidad'])->implode(', ') }}

@endforeach

@endif
@if (count($datos['deshecho']) > 0)
**Se deshizo** (había salido en un correo anterior; vuelve a quedar pendiente)

@foreach ($datos['deshecho'] as $envio)
- Envío {{ $envio['numero'] }} ({{ $envio['hora'] }}): {{ collect($envio['productos'])->map(fn ($p) => $literal($p['nombre']).' '.$p['cantidad'])->implode(', ') }}

@endforeach

@endif
@if (count($datos['falto']) > 0)
**Faltó**

@foreach ($datos['falto'] as $p)
- {{ $literal($p['nombre']) }}: {{ $p['cantidad'] }}@if ($p['motivo']) — {{ $literal($p['motivo']) }}@endif

@endforeach

@endif
@if (count($datos['no_se_trae']) > 0)
**No se trae**

@foreach ($datos['no_se_trae'] as $p)
- {{ $literal($p['nombre']) }}: {{ $p['cantidad'] }}
@endforeach

@endif
@if (count($datos['por_mandar']) > 0)
**Sigue por mandar**

@foreach ($datos['por_mandar'] as $pedido)
- Pedido del {{ $pedido['fecha'] }} ({{ $pedido['atraso'] === 0 ? 'de hoy' : ($pedido['atraso'] === 1 ? '1 día de atraso' : $pedido['atraso'].' días de atraso') }}): {{ collect($pedido['productos'])->map(fn ($p) => $literal($p['nombre']).' '.$p['cantidad'])->implode(', ') }}

@endforeach

@endif
@empty
No queda nada por mandar.

@endforelse
<x-mail::button :url="url('/fabrica/pedidos')">
Ver en el sistema
</x-mail::button>

Este resumen llega cada día a las 6 de la tarde. Los correos que lo reciben se cambian
en **Configuración › Rutas**.

{{ \App\Support\NombreEmpresa::actual() }}
</x-mail::message>
