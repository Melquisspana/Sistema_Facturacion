@props(['contingencia'])
@php
    $eventosPlazo = app(\App\Services\Dte\ContingenciaEventoService::class);
    $lotesPlazo = app(\App\Services\Dte\LoteDteService::class);
    $partesPlazo = $eventosPlazo->partesVigentes($contingencia);
@endphp
@if ($eventosPlazo->requiereInformeTecnico($contingencia))
    <p role="alert">Informe Técnico de Contingencia: alcanzó tres días. Presentalo al MH antes del evento si duró más de tres días.</p>
@endif
@if ($contingencia->cese)
    @if ($contingencia->origen === 'automatica')<p>Volvió la conexión: enviá el aviso a Hacienda.</p>@endif
    @if ($partesPlazo->isEmpty())
        @php
            $limitePlazo = $eventosPlazo->plazo($contingencia);
        @endphp
        <p>24 h (evento): {{ $limitePlazo->format('d/m/Y H:i:s') }}.
            {{ \App\Support\HoraNegocio::ahora()->gt($limitePlazo) ? 'Vencido: gestioná una prórroga ante el MH.' : 'Pendiente de envío.' }}</p>
    @endif
    @foreach ($partesPlazo as $partePlazo)
        @php
            $esLotePlazo = $partePlazo->estado === 'recibido';
            $limitePlazo = $esLotePlazo ? $lotesPlazo->plazo($partePlazo) : $eventosPlazo->plazo($contingencia, $partePlazo);
        @endphp
        @if ($partePlazo->estado !== 'sin_documentos' && (!$esLotePlazo || $partePlazo->dtes()->where(fn ($q) => $q->whereNull('sello_recepcion')->orWhere('sello_recepcion', ''))->exists()))
            <p>{{ $esLotePlazo ? '72 h (lote)' : '24 h (evento)' }}, parte {{ $partePlazo->parte }}: {{ $limitePlazo?->format('d/m/Y H:i:s') }}.
                {{ $limitePlazo && \App\Support\HoraNegocio::ahora()->gt($limitePlazo) ? 'Vencido: gestioná una prórroga ante el MH.' : 'Pendiente de regularización.' }}</p>
        @endif
    @endforeach
@endif
