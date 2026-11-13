@props(['titulo', 'fecha' => null, 'message' => null])
{{-- Como mail::message, pero con el encabezado de los documentos impresos (docs/GUIA_PANTALLAS.md,
     «Documentos impresos»): logo y empresa a la izquierda y el recuadro con el nombre del correo
     a la derecha. Va con el tema «documento» (themes/documento.css). El logo va incrustado en el
     correo (cid:): los correos no cargan imágenes en data-URI ni de un servidor interno. Sin
     $message (vista previa sin mensaje) o sin el archivo, sale solo el nombre. --}}
@php
    $empresa = \App\Support\NombreEmpresa::actual();
    $rutaLogo = (string) config('dte.pdf.logo_path', '');
    $logo = $message !== null && $rutaLogo !== '' && is_file($rutaLogo) ? $message->embed($rutaLogo) : null;
@endphp
<x-mail::layout>
{{-- En el celular el recuadro baja debajo de la empresa. --}}
<x-slot:head>
<style>
@media only screen and (max-width: 600px) {
.doc-header { padding: 16px 16px 12px !important; }
.doc-head { width: 100% !important; }
.doc-emisor, .doc-box { display: block !important; width: auto !important; }
.doc-box { margin-top: 10px !important; }
}
</style>
</x-slot:head>

{{-- Header --}}
<x-slot:header>
<tr>
<td class="doc-header">
<table class="doc-head" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="doc-emisor">
<table cellpadding="0" cellspacing="0" role="presentation">
<tr>
@if ($logo)
<td class="doc-logo-cell"><img src="{{ $logo }}" class="doc-logo" width="32" height="48" alt=""></td>
@endif
<td class="doc-razon">{{ $empresa }}</td>
</tr>
</table>
</td>
<td class="doc-box">
<span class="doc-lbl">Correo interno</span><br>
<span class="doc-ttl">{{ $titulo }}</span>
@if ($fecha)
<br><span class="doc-fecha">{{ $fecha }}</span>
@endif
</td>
</tr>
</table>
</td>
</tr>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $empresa }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
