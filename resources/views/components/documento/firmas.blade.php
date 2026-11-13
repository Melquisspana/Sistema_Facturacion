@props(['quienes'])
{{-- Firmas de los documentos impresos (con <x-documento.estilos>): una línea fina por quien firma. --}}
<table class="firmas"><tr>
    @foreach ($quienes as $quien)
        <td><span class="flk">{{ $quien }}</span><span class="fline"></span></td>
    @endforeach
</tr></table>
