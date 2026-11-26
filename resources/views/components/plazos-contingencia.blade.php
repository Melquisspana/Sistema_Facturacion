@props(['contingencia'])
@php
    $eventosPlazo = app(\App\Services\Dte\ContingenciaEventoService::class);
    $lotesPlazo = app(\App\Services\Dte\LoteDteService::class);
    $partesPlazo = $eventosPlazo->partesVigentes($contingencia);
    // Vencido, por vencer (menos de 6 horas) o el texto de siempre.
    $estadoPlazo = fn ($limite, string $pendiente) => $limite && \App\Support\HoraNegocio::ahora()->gt($limite)
        ? 'Vencido: gestioná una prórroga ante el MH.'
        : ($lotesPlazo->porVencer($limite) ? 'Por vencer: quedan menos de '.\App\Services\Dte\LoteDteService::HORAS_AVISO_POR_VENCER.' horas. ' : '').$pendiente;
@endphp
@if ($eventosPlazo->requiereInformeTecnico($contingencia))
    <p role="alert">Informe Técnico de Contingencia: alcanzó tres días. Presentalo al MH antes del evento si duró más de tres días.</p>
@endif
@if ($contingencia->cese)
    {{-- Solo mientras el aviso se puede enviar, igual que el botón «Enviar aviso de
         contingencia»: recibido, la contingencia pasa a «informada» (simulacro del 17/11, F12).
         Sin persona que la cerró, la cerró la reconexión porque Hacienda contestó. Si la terminó
         alguien con el botón, Hacienda puede seguir caída (simulacro del 26/11, F16). --}}
    @if ($contingencia->origen === 'automatica' && $contingencia->estado === 'cerrada')
        <p>{{ $contingencia->cerrada_por ? 'Contingencia terminada: enviá el aviso a Hacienda cuando haya conexión.' : 'Volvió la conexión: enviá el aviso a Hacienda.' }}</p>
    @endif
    @if ($partesPlazo->isEmpty())
        @php
            $limitePlazo = $eventosPlazo->plazo($contingencia);
        @endphp
        <p>24 h (evento): {{ $limitePlazo->format('d/m/Y H:i:s') }}.
            {{ $estadoPlazo($limitePlazo, 'Pendiente de envío.') }}</p>
    @endif
    @foreach ($partesPlazo as $partePlazo)
        @php
            $esLotePlazo = $partePlazo->estado === 'recibido';
            $limitePlazo = $esLotePlazo ? $lotesPlazo->plazo($partePlazo) : $eventosPlazo->plazo($contingencia, $partePlazo);
        @endphp
        @if ($partePlazo->estado !== 'sin_documentos' && (!$esLotePlazo || $partePlazo->dtes()->where(fn ($q) => $q->whereNull('sello_recepcion')->orWhere('sello_recepcion', ''))->exists()))
            <p>{{ $esLotePlazo ? '72 h (lote)' : '24 h (evento)' }}, parte {{ $partePlazo->parte }}: {{ $limitePlazo?->format('d/m/Y H:i:s') }}.
                {{ $estadoPlazo($limitePlazo, 'Pendiente de regularización.') }}</p>
        @endif
    @endforeach
@endif
