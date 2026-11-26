<x-mail::message>
{!! nl2br(e($cuerpo)) !!}

@if ($provisional ?? false)
---
**Documento provisional.** Se emitió en contingencia, sin conexión con Hacienda: le enviaremos el documento definitivo cuando Hacienda lo selle.
@endif

@if ($dte->sello_recepcion)
---
**Sello de recepción:** {{ $dte->sello_recepcion }}
@endif
</x-mail::message>
