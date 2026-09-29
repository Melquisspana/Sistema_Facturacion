@php
    use App\Services\Gastos\Dinero;

    $control = 'mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
    $porAmbito = $aplicaciones->groupBy('ambito')->map(fn ($g) => $g->sum(fn ($a) => Dinero::centavos((string) $a->importe)));
@endphp

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">Pago #{{ $pago->id }}</h1>
        <p class="text-sm text-gray-500">{{ $pago->beneficiario }} · {{ $pago->moneda }} {{ Dinero::mostrar($pago->importe) }}</p>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-4xl space-y-4">
            <x-gastos-aviso />

            @unless ($pago->vigente())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    <p class="font-medium">Pago revertido el {{ $pago->revertido_at->format('d/m/Y H:i') }} por {{ $pago->reversor?->name }}.</p>
                    <p class="mt-0.5">{{ $pago->motivo_reversion }}</p>
                    <p class="mt-1 text-xs">El saldo volvió a las obligaciones. Revertir corrige el registro; no significa que el banco haya devuelto el dinero.</p>
                </div>
            @endunless

            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div><dt class="text-xs uppercase tracking-wide text-gray-500">Importe</dt><dd class="mt-0.5 text-base font-semibold tabular-nums text-gray-900">{{ $pago->moneda }} {{ Dinero::mostrar($pago->importe) }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-500">Fecha real</dt><dd class="mt-0.5 text-base font-semibold text-gray-900">{{ $pago->fecha->format('d/m/Y') }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-500">Método</dt><dd class="mt-0.5 text-base font-semibold text-gray-900">{{ $pago->etiquetaMetodo() }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-500">Pagó</dt><dd class="mt-0.5 text-base font-semibold text-gray-900">{{ $pago->pagador?->name }}</dd></div>
                </dl>
                @if ($pago->referencia)<p class="mt-2 text-sm text-gray-700">Referencia: {{ $pago->referencia }}</p>@endif
                <p class="mt-2 border-t border-gray-100 pt-2 text-xs text-gray-500">
                    Registrado por {{ $pago->registrador?->name }} el {{ $pago->created_at->format('d/m/Y H:i') }}.
                    Declarado por el operador: el sistema no ejecuta la transferencia ni concilia con el banco.
                </p>
            </div>

            {{-- Subtotales por ámbito DERIVADOS de las aplicaciones. El pago no guarda un
                 ámbito propio, así que no hay un segundo reparto que los contradiga. --}}
            @if ($porAmbito->count() > 1)
                <div class="rounded-md border border-violet-200 bg-violet-50 px-4 py-3 text-sm text-violet-800">
                    <p class="font-medium">Pago mixto.</p>
                    <p class="mt-0.5">
                        Empresa {{ $pago->moneda }} {{ Dinero::mostrar($porAmbito['empresarial'] ?? 0) }}
                        · Personal {{ $pago->moneda }} {{ Dinero::mostrar($porAmbito['personal'] ?? 0) }}.
                        Los subtotales salen de las aplicaciones, no de un campo del pago.
                    </p>
                </div>
            @endif

            <section class="rounded-lg border border-gray-200 bg-white">
                <h2 class="border-b border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-800">Obligaciones cubiertas</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-500">
                                <th scope="col" class="px-4 py-2 font-medium">Gasto</th>
                                <th scope="col" class="px-4 py-2 font-medium">Cuota</th>
                                <th scope="col" class="px-4 py-2 font-medium">Ámbito</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Aplicado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($aplicaciones as $a)
                                <tr>
                                    <th scope="row" class="px-4 py-2 text-left font-normal">
                                        <a href="{{ route('gastos.show', $a->gasto_id) }}" class="text-indigo-700 underline">{{ $a->concepto }}</a>
                                    </th>
                                    <td class="px-4 py-2 text-gray-700">{{ $a->numero }} · {{ $a->vence ? \Illuminate\Support\Carbon::parse($a->vence)->format('d/m/Y') : 'Sin fecha' }}</td>
                                    <td class="px-4 py-2 text-gray-700">{{ $a->ambito === 'personal' ? 'Personal' : 'Empresa' }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums font-medium text-gray-900">{{ Dinero::mostrar($a->importe) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-4">
                <h2 class="text-sm font-semibold text-gray-800">Comprobantes del pago</h2>
                @if ($comprobantes->isNotEmpty())
                    <ul class="mt-2 space-y-1">
                        @foreach ($comprobantes as $adjunto)
                            <li class="text-sm">
                                <a href="{{ route('gastos.archivo', ['adjunto' => $adjunto->id, 'ver' => 1]) }}" target="_blank" rel="noopener" class="inline-block min-h-11 break-all py-2 text-indigo-700 underline sm:min-h-0 sm:py-0">{{ $adjunto->nombre }}</a>
                                <a href="{{ route('gastos.archivo', $adjunto->id) }}" class="ml-3 text-xs text-gray-500 underline">Descargar</a>
                            </li>
                        @endforeach
                    </ul>
                @elseif ($pago->sin_comprobante)
                    <p class="mt-2 text-sm text-amber-700"><span class="font-medium">Falta comprobante.</span> Motivo declarado: «{{ $pago->sin_comprobante }}»</p>
                @else
                    <p class="mt-2 text-sm text-gray-500">Sin comprobante y sin motivo declarado.</p>
                @endif

                @if (auth()->user()->can('gastos.pagos.registrar') && $pago->vigente())
                    <form method="POST" action="{{ route('gastos.pagos.comprobantes', $pago) }}" enctype="multipart/form-data" class="mt-3 space-y-2 border-t border-gray-100 pt-3">
                        @csrf
                        <x-gastos-adjuntos nombre="comprobantes" titulo="Adjuntar comprobante" />
                        <button type="submit" class="min-h-11 rounded-md bg-gray-800 px-4 text-sm font-medium text-white">Adjuntar</button>
                    </form>
                @endif
            </section>

            @if (auth()->user()->can('gastos.pagos.corregir') && $pago->vigente())
                <section class="rounded-lg border border-red-200 bg-red-50 p-4">
                    <h2 class="text-sm font-semibold text-red-800">Corregir este pago</h2>
                    <p class="mt-0.5 text-xs text-red-800">
                        Un pago registrado no se edita ni se borra: se revierte entero, con motivo, y después se registra el correcto. Las aplicaciones originales se conservan en el historial.
                    </p>
                    <form method="POST" action="{{ route('gastos.pagos.revertir', $pago) }}" class="mt-2 space-y-2">
                        @csrf
                        <label for="motivo" class="block text-sm font-medium text-gray-700">Motivo *</label>
                        <input id="motivo" name="motivo" required minlength="5" maxlength="500" class="{{ $control }}" placeholder="Se registró con la fecha equivocada">
                        <button type="submit" class="min-h-11 rounded-md bg-red-700 px-4 text-sm font-medium text-white">Revertir pago</button>
                    </form>
                </section>
            @endif

            <section class="rounded-lg border border-gray-200 bg-white">
                <h2 class="border-b border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-800">Historial</h2>
                <ol class="divide-y divide-gray-100">
                    @foreach ($historial as $evento)
                        <li class="px-4 py-2 text-sm">
                            <span class="tabular-nums text-gray-500">{{ \Illuminate\Support\Carbon::parse($evento->created_at)->format('d/m/Y H:i') }}</span>
                            <span class="ml-2 font-medium text-gray-800">{{ ['pago_registrado' => 'Pago registrado', 'pago_revertido' => 'Pago revertido', 'comprobantes_adjuntados' => 'Comprobantes adjuntados'][$evento->accion] ?? $evento->accion }}</span>
                            <span class="ml-1 text-gray-500">· {{ $evento->usuario }}</span>
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>
    </div>
</x-app-layout>
