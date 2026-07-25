<x-mail::message>
Se adjunta el documento electrónico para registro contable.

**Tipo:** {{ $dte->tipo_dte->label() }}
**Número de control:** {{ $dte->numero_control ?: '—' }}
**Fecha:** {{ optional($dte->fecha_emision)->format('d/m/Y') ?: '—' }}
**Cliente:** {{ $dte->cliente?->nombre ?: '—' }}
**Total:** ${{ number_format((float) $dte->total_pagar, 2) }}

Adjuntos: {{ $adjuntos }}.

@if ($dte->sello_recepcion)
---
**Sello de recepción:** {{ $dte->sello_recepcion }}
@endif
</x-mail::message>
