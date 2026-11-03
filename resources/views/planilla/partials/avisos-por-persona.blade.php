{{-- Junta los avisos de una misma persona en un renglón: «Ana: falta ISSS · falta AFP».
     Sin agrupar, una planilla de quince personas sin sueldo daba cuarenta y cinco renglones. --}}
@php
    $porPersona = [];
    foreach ($lista as $texto) {
        [$quien, $que] = str_contains($texto, ': ') ? explode(': ', $texto, 2) : ['', $texto];
        $porPersona[$quien][] = rtrim($que, '.');
    }
@endphp
<ul class="mt-1 list-inside list-disc">
    @foreach ($porPersona as $quien => $cosas)
        <li>@if ((string) $quien !== '')<strong>{{ $quien }}</strong>: @endif{{ implode(' · ', $cosas) }}.</li>
    @endforeach
</ul>
