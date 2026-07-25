<x-mail::message>
Se adjunta un documento recibido para registro contable.

**Emisor:** {{ $documento->emisor_nombre ?: ($documento->remitente ?: '—') }}
**Tipo:** {{ $documento->tipoLabel() }}
**Número de control:** {{ $documento->numero_control ?: '—' }}
**Fecha:** {{ optional($documento->fecha_dte)->format('d/m/Y') ?: '—' }}
**Total:** {{ $documento->total !== null ? '$'.number_format((float) $documento->total, 2) : '—' }}

Adjuntos disponibles: {{ $listaAdjuntos }}.

@if ($listaOmitidos !== '')
No se adjuntaron por el límite de tamaño del correo: {{ $listaOmitidos }}. Quedan guardados en el sistema y se pueden enviar por separado.
@endif
</x-mail::message>
