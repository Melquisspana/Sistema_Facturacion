{{--
    Resumen de gastos pendientes.

    Los vencidos van PRIMERO y con su atraso: es lo único que exige una acción hoy.
    No hay saludo optimista ni «todo en orden»: este correo solo existe cuando hay
    algo que pagar, así que su primera línea ya es el pendiente.

    Nada de acá se puede leer como una orden de pago: son obligaciones registradas,
    y pagarlas sigue siendo una decisión que alguien toma y declara en el sistema.
--}}
<x-mail::message>
# Gastos pendientes

Hola {{ $nombre }}: esto es lo que queda pendiente al {{ $contenido['hasta'] }}.

@if (count($contenido['vencidos']) > 0)
## Vencidos ({{ count($contenido['vencidos']) }})

<x-mail::table>
| Concepto | Destinatario | Venció | Saldo |
|:---------|:-------------|:-------|------:|
@foreach ($contenido['vencidos'] as $fila)
| {{ $fila['concepto'] }} | {{ $fila['beneficiario'] }} | {{ $fila['vence'] ?? 'sin fecha' }} | {{ $fila['moneda'] }} {{ $fila['saldo'] }} |
@endforeach
</x-mail::table>
@endif

@if (count($contenido['proximos']) > 0)
## Próximos ({{ count($contenido['proximos']) }})

<x-mail::table>
| Concepto | Destinatario | Vence | Saldo |
|:---------|:-------------|:------|------:|
@foreach ($contenido['proximos'] as $fila)
| {{ $fila['concepto'] }} | {{ $fila['beneficiario'] }} | {{ $fila['vence'] ?? 'sin fecha' }} | {{ $fila['moneda'] }} {{ $fila['saldo'] }} |
@endforeach
</x-mail::table>
@endif

@if (count($contenido['sin_monto']) > 0)
## Esperando monto ({{ count($contenido['sin_monto']) }})

Estas obligaciones **no tienen importe todavía**, así que no suman a los totales de
arriba y no se reclaman como deuda. Falta el recibo:

@foreach ($contenido['sin_monto'] as $fila)
- {{ $fila['concepto'] }} — {{ $fila['beneficiario'] }}@if ($fila['vence_esperado']) (se esperaba para el {{ $fila['vence_esperado'] }})@endif

@endforeach
@endif

@if (count($contenido['totales']) > 0)
## Total pendiente

@foreach ($contenido['totales'] as $moneda => $total)
- **{{ $moneda }} {{ $total }}**
@endforeach

{{-- Vencido es un SUBCONJUNTO de pendiente, nunca otra cifra que se sume. --}}
El total incluye lo vencido y lo que aún no vence. No se suman entre sí.
@endif

<x-mail::button :url="url('/gastos')">
Ver en el sistema
</x-mail::button>

Este resumen se manda solo cuando hay pendientes. Podés cambiar la frecuencia, los
días de anticipación o desactivarlo desde **Gastos › Avisos › Preferencias**.

{{ config('app.name') }}
</x-mail::message>
