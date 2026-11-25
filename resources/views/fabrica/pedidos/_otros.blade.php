            <nav aria-label="Otros pedidos" class="flex items-start gap-3">
                <a href="{{ route('fabrica.pedidos.index') }}" @click.prevent="salir($el.href)"
                   class="inline-flex min-h-12 shrink-0 items-center justify-center rounded-xl bg-indigo-600 px-5 py-2 text-lg font-semibold text-white hover:bg-indigo-700">Todos</a>
                @if ($otros->isNotEmpty())
                    <div class="flex min-w-0 flex-1 gap-3 overflow-x-auto pb-2">
                        @foreach ($otros as $otro)
                            {{-- El listo para salir (24/11/2026) ya no se está armando: en gris, sin el fondo índigo. --}}
                            @php($otroListo = $otro->estado === \App\Enums\EstadoPedidoSala::Listo)
                            <a href="{{ route('fabrica.pedidos.show', $otro) }}" @click.prevent="salir($el.href)"
                               class="inline-flex min-h-12 shrink-0 whitespace-nowrap items-center justify-center gap-2 rounded-xl border px-4 py-2 text-lg font-semibold text-gray-800 hover:bg-gray-50 {{ $otro->armando_desde && ! $otroListo ? 'border-indigo-300 bg-indigo-50' : 'border-gray-300 bg-white' }}">
                                {{ $otro->nombreSala() }}
                                @if ($otroListo)
                                    <span class="inline-block rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">Listo para salir</span>
                                @elseif ($otro->armando_desde)
                                    <span class="inline-block rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-700">Armando</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            </nav>