<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">Pendientes de envío</h2></x-slot>
    <div class="mx-auto max-w-7xl space-y-4 px-6 py-8 text-gray-900 dark:text-gray-100">
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if ($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
        @if ($paraRevision->isNotEmpty())
            <section class="space-y-3 rounded border border-amber-500 p-4" aria-labelledby="revision-manual">
                <h3 id="revision-manual" class="font-semibold">Para revisión manual</h3>
                <p>Abrí el documento e intentá «Firmar y transmitir» de nuevo. Si Hacienda sigue sin responder, consultá con la contadora antes de emitir otro.</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <caption class="sr-only">Documentos firmados sin enviar a Hacienda que requieren revisión manual</caption>
                        <thead><tr><th scope="col">Número / cliente</th><th scope="col">Total</th><th scope="col">Fecha/hora de emisión</th><th scope="col">Antigüedad</th><th scope="col">Acciones</th></tr></thead>
                        <tbody>
                            @foreach ($paraRevision as $revision)
                                @php
                                    $emisionRevision = \Illuminate\Support\Carbon::parse($revision->fecha_emision->format('Y-m-d').' '.$revision->hora_emision, \App\Support\HoraNegocio::zona());
                                @endphp
                                <tr class="border-t border-gray-300 dark:border-gray-600">
                                    <td class="p-3">{{ $revision->numero_control }}<br>{{ $revision->cliente?->nombre ?? $revision->cliente?->razon_social ?? 'Sin cliente' }}</td>
                                    <td class="p-3">${{ number_format((float) $revision->total_pagar, 2) }}</td>
                                    <td class="p-3">{{ $emisionRevision->format('d/m/Y H:i:s') }}</td>
                                    <td class="p-3">{{ (int) $emisionRevision->diffInMinutes(\App\Support\HoraNegocio::ahora()) }} minutos</td>
                                    <td class="p-3"><a class="underline" href="{{ route('facturacion.show', $revision) }}">Abrir documento</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $paraRevision->withQueryString()->links() }}
            </section>
        @endif
        <p>Plazos en hora de El Salvador. Vencida la contingencia, gestioná una prórroga ante el MH.</p>
        @if ($lotesConError->isNotEmpty())
            <section class="space-y-3 rounded border border-amber-500 p-4" aria-labelledby="lotes-rechazados">
                <h3 id="lotes-rechazados" class="font-semibold">Lotes rechazados al enviar</h3>
                <p>Revisá la respuesta de Hacienda antes de reenviar los documentos liberados.</p>
                @foreach ($lotesConError as $loteConError)
                    <div class="border-t border-amber-500 pt-2">
                        <p>Lote {{ $loteConError->id_envio }} · {{ $loteConError->cantidad }} documentos · error</p>
                        @if ($loteConError->contingenciaEvento)
                            <a class="underline" href="{{ route('facturacion.contingencia.show', $loteConError->contingenciaEvento->contingencia_id) }}">Ver contingencia</a>
                        @endif
                        <p>{{ $loteConError->respuesta_envio['mensaje'] ?? $loteConError->respuesta_envio['descripcionMsg'] ?? 'Revisar respuesta de Hacienda.' }}</p>
                        @foreach (($loteConError->respuesta_envio['observaciones'] ?? []) as $observacion)
                            <p>{{ is_string($observacion) ? $observacion : json_encode($observacion, JSON_UNESCAPED_UNICODE) }}</p>
                        @endforeach
                    </div>
                @endforeach
                @if ($lotesConError instanceof \Illuminate\Contracts\Pagination\Paginator){{ $lotesConError->withQueryString()->links() }}@endif
            </section>
        @endif
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <caption class="sr-only">Documentos emitidos en contingencia y sus resultados</caption>
                <thead><tr><th scope="col">Documento / cliente</th><th scope="col">Total</th><th scope="col">Estado</th><th scope="col">Plazo</th><th scope="col">Acciones</th></tr></thead>
                <tbody>
                @forelse ($documentos as $dte)
                    @php
                        $evento = $dte->contingenciaEvento;
                        $recibido = $evento?->estado === 'recibido';
                        $plazo = $recibido ? $service->plazo($evento) : $eventos->plazo($dte->contingencia, $evento);
                        $vencido = $dte->contingencia->estado === 'vencida' || ($plazo && \App\Support\HoraNegocio::ahora()->gt($plazo));
                        $final = in_array($dte->estado->value, ['aceptado', 'rechazado'], true);
                        $estado = $final ? $dte->estado->value : ($dte->lote_dte_id ? 'en lote' : ($recibido ? 'informado' : 'pendiente'));
                        $segundos = $plazo ? max(0, (int) \App\Support\HoraNegocio::ahora()->diffInSeconds($plazo)) : 0;
                    @endphp
                    <tr class="border-t border-gray-300 dark:border-gray-600">
                        <td class="p-3"><a class="underline" href="{{ route('facturacion.show', $dte) }}">{{ $dte->codigo_generacion }}</a><br>{{ $dte->cliente?->nombre ?? $dte->cliente?->razon_social ?? 'Sin cliente' }}</td>
                        <td class="p-3">${{ number_format((float) $dte->total_pagar, 2) }}</td>
                        <td class="p-3">{{ $estado }}
                            @foreach (($dte->respuesta_mh['observaciones'] ?? []) as $observacion)<p>{{ is_string($observacion) ? $observacion : json_encode($observacion) }}</p>@endforeach
                        </td>
                        <td class="p-3">{{ $recibido ? '72 h (lote)' : '24 h (evento)' }}
                            @if ($eventos->requiereInformeTecnico($dte->contingencia))
                                <p role="alert">Informe Técnico de Contingencia: alcanzó tres días. Presentalo al MH si duró más de tres días.</p>
                            @endif
                            @if ($plazo && !$final)
                                <br>{{ $plazo->format('d/m/Y H:i:s') }}
                                <br>{{ $vencido ? 'Vencido' : 'Restan '.sprintf('%02d:%02d:%02d', intdiv($segundos, 3600), intdiv($segundos % 3600, 60), $segundos % 60).' (hh:mm:ss)' }}
                            @endif
                        </td>
                        <td class="space-y-2 p-3">
                            @if ($dte->estado === \App\Enums\EstadoDte::Aceptado && filled($dte->sello_recepcion))
                                @can('enviarCorreo', $dte)
                                    <form method="POST" action="{{ route('facturacion.correo.cliente', $dte) }}">@csrf<button class="underline" type="submit">Reenviar al cliente</button></form>
                                @endcan
                            @endif
                            @if (!$final && (!$dte->lote_dte_id || $dte->loteDte?->estado === 'pendiente') && !$vencido)
                                <form method="POST" action="{{ $recibido ? route('facturacion.contingencia.lotes.enviar', $evento) : route('facturacion.contingencia.enviar', $dte->contingencia) }}">@csrf<button class="underline" type="submit">Enviar a Hacienda</button></form>
                            @endif
                            @if ($dte->loteDte?->estado === 'error')
                                <p role="alert">Lote rechazado: {{ $dte->loteDte->respuesta_envio['mensaje'] ?? $dte->loteDte->respuesta_envio['descripcionMsg'] ?? 'Revisar respuesta de Hacienda.' }}</p>
                                @foreach (($dte->loteDte->respuesta_envio['observaciones'] ?? []) as $observacion)
                                    <p>{{ is_string($observacion) ? $observacion : json_encode($observacion, JSON_UNESCAPED_UNICODE) }}</p>
                                @endforeach
                            @endif
                            @if ($dte->loteDte?->estado === 'recibido')
                                <form method="POST" action="{{ route('facturacion.contingencia.lotes.consultar', $dte->loteDte) }}">@csrf<button class="underline" type="submit">Consultar resultado</button></form>
                            @endif
                            @if ($dte->estado === \App\Enums\EstadoDte::Rechazado)
                                @if ($dte->tipo_dte === \App\Enums\TipoDte::CreditoFiscal)
                                    <form method="POST" action="{{ route('facturacion.contingencia.corregir', $dte) }}">@csrf<button class="underline" type="submit">Emitir corregido</button></form>
                                @else
                                    <button disabled aria-describedby="sin-duplicador-{{ $dte->id }}">Emitir corregido</button>
                                    <p id="sin-duplicador-{{ $dte->id }}">Este tipo de documento todavía no tiene duplicador.</p>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-3">No hay documentos pendientes o falta habilitar la tabla de lotes.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($documentos instanceof \Illuminate\Contracts\Pagination\Paginator){{ $documentos->links() }}@endif
    </div>
</x-app-layout>
