@props(['titulo', 'message' => null])
{{-- Como mail::message, pero con el encabezado de la empresa: el logo como un sello (un
     círculo blanco que se ve igual en el modo oscuro de Gmail), el nombre de la empresa y,
     debajo, el nombre del correo en el rojo de la marca. Va con el tema «documento»
     (themes/documento.css). El logo va incrustado en el correo (cid:, App\Support\Correo\LogoCorreo):
     los correos no cargan imágenes en data-URI ni de un servidor interno. Sin $message (vista
     previa sin mensaje), sin el archivo o si no se puede pasar a sello, sale solo el nombre. Hoy lo usa solo el resumen
     diario de pedidos. --}}
@php
    $empresa = \App\Support\NombreEmpresa::actual();
    $logo = $message !== null ? \App\Support\Correo\LogoCorreo::incrustar($message) : null;
@endphp
<x-mail::layout>
{{-- En el celular, menos margen alrededor. --}}
<x-slot:head>
<style>
@media only screen and (max-width: 600px) {
.doc-header { padding: 20px 16px 12px !important; }
.doc-head { width: 100% !important; }
.content-cell { padding: 20px !important; }
}
</style>
</x-slot:head>

{{-- Header --}}
<x-slot:header>
<tr>
<td class="doc-header">
<table class="doc-head" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
<tr>
@if ($logo)
{{-- 72 px, no menos de 65: a 64 o menos, el modo oscuro de Gmail para Android lo toma por ícono y lo invierte (LogoCorreo). --}}
<td class="doc-logo-cell" width="72"><img src="{{ $logo }}" class="doc-logo" width="72" height="72" alt="Logo de {{ $empresa }}"></td>
@endif
<td class="doc-emisor">
<span class="doc-razon">{{ $empresa }}</span><br>
<span class="doc-ttl">{{ $titulo }}</span>
</td>
</tr>
</table>
</td>
</tr>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy: las notas del final, más chicas y en gris (como mail::message). --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $empresa }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
