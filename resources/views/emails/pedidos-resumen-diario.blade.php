{{--
    Resumen diario de los pedidos de sala (17:00), desde el correo anterior. Por sala: lo
    que salió, lo que se deshizo, lo que faltó, lo que no se trae y lo que sigue por mandar. Listas y no tablas: el nombre de un
    producto con «|» rompería una tabla de markdown. Todo lo que escribió alguien pasa por
    $literal: sin eso, un motivo con `[texto](url)` sería un enlace en el correo.
--}}
<x-mail::message>
# Pedidos del {{ $contenido['fecha'] }}

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

Este resumen llega cada día a las 5 de la tarde. Los correos que lo reciben se cambian
en **Configuración › Rutas**.

{{ config('app.name') }}
</x-mail::message>
