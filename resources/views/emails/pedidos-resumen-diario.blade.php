{{-- El correo de las 18:00 para quien dirige la fábrica (Melqui, 25/11/2026: «que ella entienda
     de forma fácil todo»). Tres partes, en este orden y una línea por sala: lo que llegó de los
     vendedores, lo que salió de la fábrica (con lo que faltó y por qué) y lo que quedó allá
     (listos para salir, armando y sin empezar, cada uno con su día); al final, lo vendido,
     como antes. Sin botón al sistema: el detalle con los productos va en el PDF adjunto. Los
     ceros no se dicen; si una parte no tuvo nada, lo dice en una frase. Lo escrito por alguien
     (sala, producto, motivo, vendedor) se escapa también para Markdown mediante $literal. --}}
@php
    $dinero = fn ($n) => ($n < 0 ? '−' : '').'$'.number_format(abs($n), 2);
    $plural = fn (int $n, string $uno, string $varios) => $n === 1 ? '1 '.$uno : $n.' '.$varios;
    $facturas = fn (int $n) => $plural($n, 'factura', 'facturas');
    $productos = fn (int $n) => $plural($n, 'producto', 'productos');
    $ventas = $contenido['ventas'] ?? null;
    $vendedores = $contenido['vendedores'] ?? null;
    // Hasta qué hora cuenta lo de hoy: la del envío diario o, a mano, la hora en que salió.
    $hastaLas = $contenido['hasta_las'] ?? $hora;
    $nombres = fn (array $lista) => implode(', ', array_map($literal, $lista));
    $salas = collect($contenido['salas']);
    $sala = fn (string $nombre) => '**'.$literal(str_replace(' (web)', '', $nombre)).'**';
    $motivo = fn (?string $texto) => $literal(rtrim((string) $texto, '. '));
    // De qué día es: «de hoy», «de ayer» o «del 06/11».
    $dia = fn (array $p) => match ($p['atraso']) { 0 => 'de hoy', 1 => 'de ayer', default => 'del '.substr($p['fecha'], 0, 5) };

    // 2. Lo que salió de una sala, en una línea: los pedidos, lo que faltó o no se mandó y por qué.
    $salio = $salas->filter(fn ($d) => $d['salio'] !== [] || $d['cerrados'] !== [] || $d['deshecho'] !== []);
    $lineaSalio = function (array $d) use ($plural, $productos, $literal, $motivo): string {
        $partes = [];
        if ($d['salio'] !== []) {
            $pedidos = array_values(array_unique(array_column($d['salio'], 'pedido')));
            $partes[] = isset($d['cajitas'])
                ? $plural((int) $d['cajitas'], 'cajita', 'cajitas')
                : (count($pedidos) === 1 ? 'pedido' : 'pedidos').' N.º '.collect($pedidos)->join(', ', ' y ')
                    .', '.$productos(collect($d['salio'])->sum(fn ($e) => count($e['productos'])));
        }
        foreach ($d['cerrados'] as $c) {
            if ($c['nada']) {
                $partes[] = 'no se mandó el pedido N.º '.$c['numero'].($c['motivo'] ? ' ('.$motivo($c['motivo']).')' : '');

                continue;
            }
            $partes[] = 'faltó '.collect($c['productos'])->map(fn ($p) => $literal($p['nombre']).' '.$p['cantidad']
                .($p['motivo'] ? ' ('.$motivo($p['motivo']).')' : ''))->join(', ', ' y ');
            if ($c['motivo']) {
                $partes[] = 'lo que faltó ya no va: '.$motivo($c['motivo']);
            }
        }
        $deshechos = count($d['deshecho']);
        if ($deshechos > 0) {
            $partes[] = $deshechos === 1 ? 'se deshizo 1 envío que había salido en el correo anterior'
                : 'se deshicieron '.$deshechos.' envíos que habían salido en el correo anterior';
        }

        return collect($partes)->map(fn ($p, $i) => $i === 0 ? $p : ucfirst($p))->join('. ').'.';
    };

    // 3. Lo que quedó: primero lo listo, después lo que están armando y al final lo que nadie tocó.
    $quedo = [
        'Listos para salir' => $salas->map(fn ($d) => $d['listos'])->filter(),
        'Armando' => $salas->map(fn ($d) => array_values(array_filter($d['por_mandar'], fn ($p) => $p['armando'])))->filter(),
        'Sin empezar' => $salas->map(fn ($d) => array_values(array_filter($d['por_mandar'], fn ($p) => ! $p['armando'])))->filter(),
    ];
    $quedoNada = collect($quedo)->every(fn ($grupo) => $grupo->isEmpty());
@endphp
<x-mail::documento titulo="Resumen diario" :message="$message ?? null">
# Pedidos del {{ $contenido['fecha'] }}

## Llegó de los vendedores

@forelse ($salas->filter(fn ($d) => $d['llego'] !== []) as $nombre => $datos)
- {{ $sala($nombre) }}: {{ collect($datos['llego'])->map(fn ($p) => 'N.º '.$p['numero'].($p['vendedor'] ? ' · '.$literal($p['vendedor']) : '').' · '.$productos(count($p['productos'])))->join('; ') }}
@empty
No llegaron pedidos nuevos.
@endforelse

## Salió de la fábrica

@if (! $contenido['salio_algo'])
Hoy no salió ningún pedido.

@endif
@foreach ($salio as $nombre => $datos)
- {{ $sala($nombre) }}: {{ $lineaSalio($datos) }}
@endforeach

## Quedó allá

@foreach ($quedo as $grupo => $porSala)
@if ($porSala->isNotEmpty())
### {{ $grupo }}

@foreach ($porSala as $nombre => $pedidos)
- {{ $sala($nombre) }}: {{ collect($pedidos)->map(fn ($p) => ($p['resto'] ? 'el resto del ' : '').'N.º '.$p['numero'].', '.$dia($p))->join('; ') }}
@endforeach

@endif
@endforeach
@if ($quedoNada)
No quedó nada por mandar.

@endif
## Ventas del día

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
- **{{ $literal($vendedor['nombre']) }}**: {{ implode(' · ', $partes) }}
@endforeach

@foreach ($vendedores['cuentas_fallaron'] ?? [] as $nombre)
No se pudo calcular el día de {{ $literal($nombre) }}.

@endforeach
@if ($contenido['fabrica_pdf_fallo'] ?? false)
**No se pudo adjuntar el PDF de la fábrica.**

@endif
<x-slot:subcopy>
@if ($contenido['salas'] !== [] && ! ($contenido['fabrica_pdf_fallo'] ?? false))
El detalle de cada pedido, con sus productos, va en el PDF adjunto.

@endif
Este resumen llega cada día a {{ $hora }}. Los correos que lo reciben se cambian en **Configuración › Rutas**.
</x-slot:subcopy>
</x-mail::documento>
