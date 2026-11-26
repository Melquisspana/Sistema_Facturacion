<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">Aviso de contingencia a Hacienda</h2></x-slot>
    <div class="mx-auto max-w-5xl space-y-4 px-6 py-8 text-gray-900 dark:text-gray-100">
        <a class="underline" href="{{ route('facturacion.contingencia.pendientes') }}">Pendientes de envío</a>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if ($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
        @if (!app(\App\Services\Dte\ContingenciaService::class)->eventoEnviado($contingencia))
            <form method="POST" action="{{ route('facturacion.contingencia.actualizar', $contingencia) }}" class="space-y-2">
                @csrf @method('PATCH')
                @if ($contingencia->cese)
                    <label class="block">Ajustar cese (hora de El Salvador)
                        <input class="text-gray-900" type="datetime-local" step="1" name="cese" value="{{ $contingencia->cese->format('Y-m-d\TH:i:s') }}" min="{{ $contingencia->inicio->format('Y-m-d\TH:i:s') }}" max="{{ \App\Support\HoraNegocio::ahora()->format('Y-m-d\TH:i:s') }}">
                    </label>
                @endif
                <label class="block">Tipo <select name="tipo" class="text-gray-900">@foreach ([1 => 'MH no disponible', 2 => 'Sistema del emisor', 3 => 'Internet', 4 => 'Energía', 5 => 'Otro'] as $tipo => $nombre)<option value="{{ $tipo }}" @selected($contingencia->tipo === $tipo)>{{ $tipo }} · {{ $nombre }}</option>@endforeach</select></label>
                <label class="block">Motivo <textarea class="text-gray-900" name="motivo" maxlength="500">{{ $contingencia->motivo }}</textarea></label>
                <p>El tipo y motivo solo se pueden corregir mientras no haya documentos firmados.</p>
                <button type="submit" class="underline">Guardar corrección</button>
            </form>
        @endif
        <p>Estado: {{ $contingencia->estado }}. Tipo {{ $contingencia->tipo }}: {{ $contingencia->motivo }}</p>
        <p>Inicio: {{ $contingencia->inicio->format('d/m/Y H:i:s') }}.
            Cese: {{ $contingencia->cese?->format('d/m/Y H:i:s') ?? 'Todavía no registrado' }}.</p>
        @if ($service->requiereInformeTecnico($contingencia))
            <p role="alert" class="rounded border border-amber-500 p-3">La contingencia alcanzó tres días. Presentá el Informe Técnico de Contingencia al MH fuera del sistema antes de transmitir el evento si duró más de tres días.</p>
        @endif
        @if ($contingencia->cese)
            <p>Plazo inicial: {{ $service->plazo($contingencia)->format('d/m/Y H:i:s') }} (hora de El Salvador).</p>
            @if ($partes->isEmpty() && \App\Support\HoraNegocio::ahora()->gt($service->plazo($contingencia)))
                <p role="alert">Plazo vencido: gestioná una prórroga ante el MH.</p>
            @endif
        @endif
        @foreach ($partes as $parte)
            @php($plazo = $service->plazo($contingencia, $parte))
            <p>Parte {{ $parte->parte }}: {{ $parte->estado }}.
                @if ($parte->estado !== 'recibido')
                    Plazo: {{ $plazo?->format('d/m/Y H:i:s') }}.
                    @if ($plazo && \App\Support\HoraNegocio::ahora()->gt($plazo))
                        <strong>Vencido: gestioná una prórroga ante el MH.</strong>
                    @endif
                @endif
            </p>
            @if ($parte->estado === 'incierto')
                <p role="alert" class="rounded border border-amber-500 p-3">Parte {{ $parte->parte }} en duda: un envío anterior se cortó y, al reintentar, Hacienda la rechazó. Puede que ya la tenga.
                    Buscá en Hacienda el aviso con el código {{ $parte->codigo_generacion }}. Si lo tiene, no mandes otro: confirmalo abajo con «Hacienda sí lo tiene».
                    Si no lo tiene, marcá «Ya verifiqué en Hacienda que no tiene la parte en duda» y enviá el aviso de nuevo.</p>
                @if ($contingencia->estado === 'cerrada')
                    @php($horaDelAviso = $service->horaDelAviso($parte))
                    <form method="POST" action="{{ route('facturacion.contingencia.eventos.recibido', $parte) }}" class="space-y-2">
                        @csrf
                        {{-- Confirmación de nivel 3 (toca a Hacienda): dice qué pasa con los documentos y el plazo. --}}
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="confirmo_recibido" value="1" required class="mt-1 h-5 w-5">
                            <span>Vi en Hacienda el aviso {{ $parte->codigo_generacion }}. Se da por recibido sin mandar otro, sus {{ $parte->dtes()->count() }} documentos salen en lote y las 72 horas para mandarlos cuentan desde las {{ $horaDelAviso->format('H:i') }} del {{ $horaDelAviso->format('d/m/Y') }}, la hora del aviso.</span>
                        </label>
                        <x-secondary-button type="submit">Hacienda sí lo tiene</x-secondary-button>
                    </form>
                @endif
            @endif
        @endforeach
        @foreach ($eventos as $evento)
            <section class="rounded border border-gray-400 p-4">
                <h3>Parte {{ $evento->parte }} · {{ $evento->codigo_generacion }} · {{ $evento->estado }}</h3>
                @if ($evento->sello_recibido)<p>Sello: {{ $evento->sello_recibido }}</p>@endif
                {{-- Recibida sin sello: una persona apretó «Hacienda sí lo tiene». La respuesta
                     guardada es el rechazo del reintento y queda en la base, pero ya no vale
                     como rechazo (simulacro del 26/11, F17). --}}
                @if ($evento->estado === 'recibido' && ! $evento->sello_recibido)
                    <p>Dada por recibida: se verificó en Hacienda que tiene este aviso.</p>
                @elseif ($evento->respuesta_mh)
                    <p>{{ $evento->respuesta_mh['mensaje'] ?? $evento->respuesta_mh['descripcionMsg'] ?? '' }}</p>
                    @foreach (($evento->respuesta_mh['observaciones'] ?? []) as $observacion)
                        <p role="alert">{{ is_string($observacion) ? $observacion : json_encode($observacion, JSON_UNESCAPED_UNICODE) }}</p>
                    @endforeach
                @endif
            </section>
        @endforeach
        @if ($contingencia->cese && $contingencia->estado === 'cerrada')
            <p>Revisá los datos del responsable en la configuración antes de firmar. Un rechazo permite corregir y reenviar con un código nuevo durante 24 horas.</p>
            <form method="POST" action="{{ route('facturacion.contingencia.enviar', $contingencia) }}" class="space-y-2">
                @csrf
                {{-- Confirmación de nivel 3 (toca a Hacienda): sin la casilla, una parte en duda no se reemplaza. --}}
                @if ($partes->contains('estado', 'incierto'))
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="reemplazar_incierto" value="1" class="mt-1 h-5 w-5">
                        <span>Ya verifiqué en Hacienda que no tiene la parte en duda: mandar un aviso nuevo, con otro código, para sus documentos.</span>
                    </label>
                @endif
                <button type="submit" class="rounded border border-blue-500 px-4 py-2 font-semibold">Enviar aviso de contingencia</button>
            </form>
        @endif
    </div>
</x-app-layout>
