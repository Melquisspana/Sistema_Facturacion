@php
    $control = 'mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">Documento de Compras → Gastos</h1>
        <p class="text-sm text-gray-500">{{ $documento->emisor_nombre }} · {{ $documento->numero_control ?: $documento->codigo_generacion }}</p>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-3xl space-y-4">
            <x-gastos-aviso />

            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-xs uppercase tracking-wide text-gray-500">Emisor</dt><dd class="mt-0.5 font-medium text-gray-900">{{ $documento->emisor_nombre }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-500">Tipo</dt><dd class="mt-0.5 font-medium text-gray-900">{{ $documento->tipo_documento }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-500">Fecha</dt><dd class="mt-0.5 font-medium text-gray-900">{{ $documento->fecha_dte?->format('d/m/Y') ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-500">Total del documento</dt><dd class="mt-0.5 font-medium tabular-nums text-gray-900">{{ $documento->total ?? '—' }}</dd></div>
                </dl>
                <p class="mt-3 border-t border-gray-100 pt-2 text-xs text-gray-500">
                    Vincular NO cambia el estado en Compras. Una factura puede estar enviada a contabilidad y pendiente de pago a la vez.
                </p>
            </div>

            {{-- El documento ya originó una deuda: no se ofrece crear otra. --}}
            @if ($gastoExistente)
                <div class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                    <p class="font-medium">Este documento ya originó un gasto.</p>
                    <p class="mt-0.5">
                        <a href="{{ route('gastos.show', $gastoExistente) }}" class="underline">{{ $gastoExistente->concepto }}</a>
                        — no se crea otra deuda por el mismo papel.
                    </p>
                </div>
            @endif

            {{-- No todo `total` es deuda: retenciones y notas de crédito nunca crean una. --}}
            @if ($motivoBloqueo)
                <div class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <p class="font-medium">Este documento no se convierte en deuda automáticamente.</p>
                    <p class="mt-0.5">{{ $motivoBloqueo }}</p>
                </div>
            @endif

            @unless ($gastoExistente || $motivoBloqueo)
                <section class="rounded-lg border border-gray-200 bg-white p-4">
                    <h2 class="text-base font-semibold text-gray-800">Registrar un gasto nuevo</h2>
                    <p class="mt-0.5 text-sm text-gray-600">
                        Abre el formulario con el destinatario, el importe y la fecha ya puestos. La deuda nace cuando guardés,
                        no al hacer clic: seguís eligiendo categoría, ámbito, vencimiento y responsable, que el documento no trae.
                    </p>
                    <a href="{{ route('gastos.create', ['documento' => $documento->id]) }}"
                       class="mt-3 inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">
                        Registrar gasto con estos datos
                    </a>
                </section>
            @endunless

            <section class="rounded-lg border border-gray-200 bg-white p-4">
                <h2 class="text-base font-semibold text-gray-800">Vincular a un gasto que ya existe</h2>
                <p class="mt-0.5 text-sm text-gray-600">
                    Si la deuda ya estaba registrada —porque el proveedor cobra antes de mandar el papel—, el documento se cuelga
                    como <strong>respaldo</strong>. No se genera otra obligación.
                </p>

                @if ($candidatos->isEmpty())
                    <p class="mt-3 text-sm text-gray-500">No hay gastos registrados a nombre de «{{ $documento->emisor_nombre }}».</p>
                @else
                    <form method="POST" action="{{ route('gastos.compras.vincular', $documento) }}" class="mt-3 space-y-3">
                        @csrf
                        <input type="hidden" name="papel" value="respaldo">
                        <div>
                            <label for="gasto_id" class="block text-sm font-medium text-gray-700">Gasto *</label>
                            <select id="gasto_id" name="gasto_id" required class="{{ $control }}">
                                @foreach ($candidatos as $candidato)
                                    <option value="{{ $candidato->id }}">
                                        #{{ $candidato->id }} · {{ $candidato->concepto }}
                                        · {{ $candidato->moneda }} {{ $candidato->importe ?? 'por definir' }}
                                        @if ($candidato->esPersonal()) · Personal @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="min-h-11 rounded-md bg-gray-800 px-4 text-sm font-medium text-white">Vincular como respaldo</button>
                    </form>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
