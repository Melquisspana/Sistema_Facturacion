            @if (collect($pendientes ?? [])->flatten(1)->isNotEmpty())
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6 space-y-4 text-sm text-gray-700 dark:text-paper-100">
                    <h3 class="font-medium">Para volver a presentar</h3>
                    @can('ppq.gestionar')
                        <form method="POST" action="{{ route('cobros.pagos.reenviar', $cliente) }}" class="space-y-4">
                            @csrf
                            <input type="hidden" name="hash" value="{{ $informe['hash'] }}">
                    @endcan
                    @foreach (['no_pagados' => 'No vinieron pagados', 'nc_no_descontadas' => 'NC no descontadas', 'pagados_de_menos' => 'Pagados de menos'] as $grupo => $titulo)
                        @if (! empty($pendientes[$grupo]))
                            <div>
                                <h4 class="font-medium">{{ $titulo }}</h4>
                                <ul class="mt-1 space-y-1">
                                    @foreach ($pendientes[$grupo] as $entrada)
                                        <li>
                                            @can('ppq.gestionar')
                                                <label>
                                                    <input type="checkbox" name="items[]" value="{{ $entrada['item']->id }}" @checked($grupo !== 'pagados_de_menos')>
                                                    {{ $entrada['item']->numero_control }} · {{ $entrada['lote']->referencia }}
                                                </label>
                                            @else
                                                {{ $entrada['item']->numero_control }} · {{ $entrada['lote']->referencia }}
                                            @endcan
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
                    @can('ppq.gestionar')
                            <button type="submit" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Dejar listos para el próximo PPQ</button>
                        </form>
                    @endcan
                </div>
            @endif

