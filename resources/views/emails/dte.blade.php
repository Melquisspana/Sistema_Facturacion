<x-mail::message>
{!! nl2br(e($cuerpo)) !!}

@if ($dte->sello_recepcion)
---
**Sello de recepción:** {{ $dte->sello_recepcion }}
@endif
</x-mail::message>
