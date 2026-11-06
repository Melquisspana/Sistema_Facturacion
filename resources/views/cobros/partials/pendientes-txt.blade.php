@php
    // Al revisar se ve en solo lectura; el formulario solo sale ya aplicado y con permiso.
    $conFormulario = ! $soloRevisar && auth()->user()?->can('ppq.gestionar');
    $hayPendientes = collect($pendientes ?? [])->flatten(1)->isNotEmpty();
@endphp
@if ($hayPendientes)
    <div class="rounded-lg border border-gray-200 bg-white p-4 space-y-4 text-sm text-gray-700">
        <h3 class="font-medium text-gray-800">Lo que no cuadra en este PPQ</h3>
        @if ($conFormulario)
            <form method="POST" action="{{ route('cobros.pagos.reenviar', $cliente) }}" class="space-y-4">
                @csrf
                <input type="hidden" name="hash" value="{{ $informe['hash'] }}">
        @endif
        @foreach (['no_pagados' => 'No vinieron pagados', 'nc_no_descontadas' => 'NC no descontadas', 'pagados_de_menos' => 'Pagados de menos', 'no_aplican' => 'No aplican'] as $grupo => $titulo)
            @if (! empty($pendientes[$grupo]))
                <div>
                    <h4 class="font-medium">{{ $titulo }}</h4>
                    <ul class="mt-1 space-y-1">
                        @foreach ($pendientes[$grupo] as $entrada)
                            <li>
                                @if ($conFormulario && $grupo !== 'no_aplican')
                                    <label>
                                        <input type="checkbox" name="items[]" value="{{ $entrada['item']->id }}" @checked($grupo !== 'pagados_de_menos')>
                                        {{ $entrada['item']->numero_control }} · {{ $entrada['lote']->referencia }}
                                    </label>
                                @else
                                    {{ $entrada['item']->numero_control }} · {{ $entrada['lote']->referencia }}
                                @endif
                                @if ($grupo === 'no_aplican')
                                    <p class="mt-1">{{ $entrada['motivo'] }}</p>
                                @endif
                                @if ($grupo === 'pagados_de_menos')
                                    <p class="mt-1">Esperado {{ $money($entrada['esperado']) }} · Pagado {{ $money($entrada['pagado']) }} · Diferencia {{ $money($entrada['diferencia']) }}</p>
                                    <p>Posible devolución AC04: buscala en el correo.</p>
                                    @if ($entrada['ac04'] !== [])
                                        <p class="font-mono text-xs">{{ implode(' · ', $entrada['ac04']) }}</p>
                                    @endif
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endforeach
        @if ($conFormulario)
                <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Dejar listos para el próximo PPQ</button>
            </form>
        @endif
    </div>
@elseif ($informe['en_revision'] === [])
    <p class="rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-700" role="status">Todo el PPQ vino pagado como se presentó.</p>
@endif
