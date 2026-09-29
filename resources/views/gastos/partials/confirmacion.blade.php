{{-- Confirmación de lo que acaba de guardarse. Se recalcula desde la base en cada
     carga (no se arrastra en sesión) y el controlador ya filtró el pago y sus
     comprobantes por permiso.

     Los DOS EJES van separados: «Parcialmente pagado» dice cuánto se cubrió y
     «Vencida» dice si la fecha pasó. Antes iban fundidos en una sola frase y el
     parcial se perdía: un gasto con abono se leía igual que uno sin pagar nada. --}}
@php
    use App\Services\Gastos\Dinero;

    $moneda = $gasto->moneda;
    $cuotasPorId = $cuotas->keyBy('id');
    $hoy = now()->toDateString();
@endphp

<div class="mb-5 overflow-hidden rounded-lg border border-emerald-300 bg-emerald-50" role="status" tabindex="-1" x-init="$el.focus()">
    <div class="flex flex-wrap items-start justify-between gap-2 border-b border-emerald-200 px-4 py-2.5">
        <div class="min-w-0">
            <p class="text-sm font-semibold text-emerald-800">
                ✓ Gasto #{{ $gasto->id }} guardado{{ $pagos->isNotEmpty() ? ' junto con su pago' : '' }}
            </p>
            <p class="text-sm text-emerald-800">{{ $gasto->concepto }} · {{ $gasto->beneficiario }}</p>
        </div>
        <a href="{{ route('gastos.show', $gasto) }}" class="inline-flex min-h-11 items-center text-sm font-medium text-emerald-800 underline">Abrir la ficha</a>
    </div>

    <div class="bg-white px-4 py-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <dl class="grid flex-1 grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-4">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500">Importe</dt>
                    <dd class="text-base font-semibold tabular-nums text-gray-900">{{ $gasto->montoDesconocido() ? 'Por definir' : $moneda.' '.$gasto->importe }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500">Pendiente</dt>
                    <dd class="text-base font-semibold tabular-nums text-gray-900">{{ $resumen['pendiente'] === null ? 'Por definir' : $moneda.' '.Dinero::mostrar($resumen['pendiente']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500">De ello vencido</dt>
                    <dd class="text-base font-semibold tabular-nums {{ $resumen['vencido'] > 0 ? 'text-red-700' : 'text-gray-900' }}">{{ $moneda }} {{ Dinero::mostrar($resumen['vencido']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500">Ámbito</dt>
                    <dd class="text-base font-semibold text-gray-900">
                        {{ $gasto->etiquetaAmbito() }}
                        @if ($gasto->persona)<span class="block text-xs font-normal text-gray-500">Para {{ $gasto->persona }}</span>@endif
                    </dd>
                </div>
            </dl>
            <div class="shrink-0"><x-gastos-situacion :resumen="$resumen" /></div>
        </div>

        {{-- Vencido es un SUBCONJUNTO de pendiente y se calcula cuota por cuota: una
             cuota futura no vuelve vencido todo el gasto. --}}
        @if ($cuotas->count() > 1)
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">Cuotas del gasto con su saldo</caption>
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                            <th scope="col" class="py-1.5 pr-4 font-medium">Cuota</th>
                            <th scope="col" class="py-1.5 pr-4 font-medium">Vence</th>
                            <th scope="col" class="py-1.5 pr-4 text-right font-medium">Importe</th>
                            <th scope="col" class="py-1.5 pr-4 text-right font-medium">Pendiente</th>
                            <th scope="col" class="py-1.5 font-medium">Situación</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($cuotas as $cuota)
                            @php
                                $pendienteCuota = $saldos->pendienteCuota($cuota);
                                $vencida = $pendienteCuota > 0 && $cuota->venceAntesDe($hoy);
                            @endphp
                            <tr>
                                <th scope="row" class="py-1.5 pr-4 text-left font-normal text-gray-700">{{ $cuota->numero }}</th>
                                <td class="py-1.5 pr-4 text-gray-700">{{ $cuota->vence?->format('d/m/Y') ?? 'Sin fecha' }}</td>
                                <td class="py-1.5 pr-4 text-right tabular-nums text-gray-700">{{ Dinero::mostrar($cuota->importe) }}</td>
                                <td class="py-1.5 pr-4 text-right tabular-nums font-medium text-gray-900">{{ Dinero::mostrar($pendienteCuota) }}</td>
                                <td class="py-1.5 text-gray-700">
                                    @if ($pendienteCuota <= 0)
                                        <span class="text-emerald-600">Saldada</span>
                                    @elseif ($vencida)
                                        <span class="text-red-700">Vencida</span>
                                    @elseif (! $cuota->vence)
                                        Sin fecha
                                    @else
                                        Próxima
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @foreach ($pagos as $pago)
            <div class="mt-3 rounded-md border border-gray-200 bg-gray-50 p-3">
                <p class="text-sm font-semibold text-gray-800">
                    Pago #{{ $pago->id }} · {{ $moneda }} {{ Dinero::mostrar($pago->importe) }}
                    <span class="font-normal text-gray-600">· {{ $pago->etiquetaMetodo() }} · {{ $pago->fecha->format('d/m/Y') }}</span>
                </p>
                <p class="mt-0.5 text-sm text-gray-600">
                    Aplicado a
                    {{ $aplicaciones->get($pago->id, collect())
                        ->map(fn ($a) => 'cuota '.($cuotasPorId[$a->cuota_id]->numero ?? '?').' ('.$moneda.' '.$a->importe.')')
                        ->join(', ', ' y ') }}.
                </p>
                @if ($pago->referencia)<p class="text-sm text-gray-600">Referencia: {{ $pago->referencia }}</p>@endif

                {{-- «Falta comprobante» y «el motivo por el que falta» son DOS cosas.
                     Antes se mostraban en una sola línea y parecía que el motivo era el
                     comprobante. --}}
                @if ($comprobantes->isNotEmpty())
                    <p class="mt-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Comprobantes del pago</p>
                    <ul class="mt-0.5 flex flex-wrap gap-x-4">
                        @foreach ($comprobantes as $adjunto)
                            <li>
                                <a href="{{ route('gastos.archivo', ['adjunto' => $adjunto->id, 'ver' => 1]) }}" target="_blank" rel="noopener" class="inline-block min-h-11 break-all py-2 text-sm text-indigo-700 underline sm:min-h-0 sm:py-0">{{ $adjunto->nombre }}</a>
                            </li>
                        @endforeach
                    </ul>
                @elseif ($pago->sin_comprobante)
                    <p class="mt-1 text-sm text-amber-700"><span class="font-medium">Falta comprobante.</span></p>
                    <p class="text-sm text-gray-600">Motivo declarado: «{{ $pago->sin_comprobante }}»</p>
                    <p class="mt-0.5 text-xs text-gray-500">Podés adjuntarlo después desde la ficha; el motivo queda en el historial.</p>
                @else
                    <p class="mt-1 text-sm text-gray-500">Sin comprobante y sin motivo declarado.</p>
                @endif

                <p class="mt-1 text-xs text-gray-500">Declarado por el operador. El sistema no ejecuta la transferencia ni concilia con el banco.</p>
            </div>
        @endforeach

        {{-- Pago mixto fuera de alcance: solo lo que tocó a este gasto. --}}
        @if ($aplicadoReservado->isNotEmpty())
            @php $subtotalReservado = Dinero::mostrar($aplicadoReservado->sum(fn ($a) => Dinero::centavos((string) $a->importe))); @endphp
            <div class="mt-3 rounded-md border border-gray-200 bg-gray-50 p-3">
                <p class="text-sm font-semibold text-gray-800">Aplicado a este gasto: {{ $moneda }} {{ $subtotalReservado }}</p>
                <p class="mt-0.5 text-sm text-gray-600">{{ $aplicadoReservado->map(fn ($a) => 'cuota '.$a->numero.' ('.$moneda.' '.$a->importe.')')->join(', ', ' y ') }}.</p>
                <p class="mt-0.5 text-xs text-gray-500">El pago que lo cubre incluye obligaciones fuera de tu alcance, así que su detalle y su comprobante no se muestran acá.</p>
            </div>
        @endif

        {{-- Documento del gasto: presente, pendiente o inexistente. Son tres estados
             distintos y «no entregaron» no es lo mismo que «falta adjuntar». --}}
        @if ($documentos->isNotEmpty())
            <div class="mt-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Documentos del gasto</p>
                <ul class="mt-0.5 flex flex-wrap gap-x-4">
                    @foreach ($documentos as $adjunto)
                        <li><a href="{{ route('gastos.archivo', ['adjunto' => $adjunto->id, 'ver' => 1]) }}" target="_blank" rel="noopener" class="inline-block min-h-11 break-all py-2 text-sm text-indigo-700 underline sm:min-h-0 sm:py-0">{{ $adjunto->nombre }}</a></li>
                    @endforeach
                </ul>
            </div>
        @elseif ($gasto->documentacion === 'pendiente')
            <p class="mt-3 text-sm text-amber-700">Falta adjuntar el documento del gasto. Se puede subir después desde la ficha.</p>
        @elseif ($gasto->documentacion === 'no_entregaron')
            <p class="mt-3 text-sm text-gray-600">No entregaron documento. No se reclamará uno.</p>
        @endif
    </div>
</div>

{{-- El formulario de abajo NO edita lo que acabás de guardar: empieza un registro
     nuevo, con su propia clave de idempotencia. Decirlo evita que alguien crea que
     está corrigiendo el gasto anterior y termine con dos. --}}
<div class="mb-3 flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-2">
    <h2 class="text-base font-semibold text-gray-800">Registrar OTRO gasto</h2>
    <p class="text-xs text-gray-500">Para cambiar el anterior, abrí su ficha. Este formulario crea uno nuevo.</p>
</div>
