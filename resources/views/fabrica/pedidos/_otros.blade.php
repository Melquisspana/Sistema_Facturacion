            <nav aria-label="Otros pedidos" class="flex items-start gap-3">
                <a href="{{ route('fabrica.pedidos.index') }}" @click.prevent="salir($el.href)"
                   class="inline-flex min-h-12 shrink-0 items-center justify-center rounded-xl bg-indigo-600 px-5 py-4 text-lg font-semibold text-white hover:bg-indigo-700">Todos</a>
                @if ($otros->isNotEmpty())
                    <div class="flex min-w-0 flex-1 gap-3 overflow-x-auto pb-2">
                        @foreach ($otros as $otro)
                            <a href="{{ route('fabrica.pedidos.show', $otro) }}" @click.prevent="salir($el.href)"
                               class="inline-flex min-h-12 shrink-0 whitespace-nowrap items-center justify-center gap-2 rounded-xl border px-4 py-4 text-lg font-semibold text-gray-800 hover:bg-gray-50 {{ $otro->armando_desde ? 'border-indigo-300 bg-indigo-50' : 'border-gray-300 bg-white' }}">
                                {{ $otro->nombreSala() }}
                                @if ($otro->armando_desde)
                                    <span class="inline-block rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-700">Armando</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            </nav>