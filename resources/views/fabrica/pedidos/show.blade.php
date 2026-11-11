{{-- La hoja «Qué va a <sala>»: un renglón por producto con algo pendiente, con lo que falta
     YA cargado. Cada cambio (también la casilla) se guarda solo en el servidor (fetch, con
     un solo retardo corto; lo que falta guardar sale antes de dejar la hoja) y el pedido
     queda «Armando»: se puede dejar para después y seguir con otro. La hoja NO se recarga
     sola. Pantalla táctil de fábrica (docs/GUIA_PANTALLAS.md, «Pantalla táctil»):
     botones de min-h-12 con py-4/py-5 y letra text-xl/2xl; funciona igual con el mouse.

     El motivo es opcional y nunca `required`: lo que decide es el servidor. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Qué va a {{ $pedido->nombreSala() }}</h1>
        </div>
    </x-slot>

    @php
        $faltan = $pedido->lineas->filter->tienePendiente();
        $conBorrador = $pedido->armando_desde !== null || $pedido->lineas->whereNotNull('va_borrador')->isNotEmpty();
        // Lo escrito vuelve con old() tras un error, salvo si el pedido cambió: entonces se
        // muestra lo fresco. La clave y la versión salen SIEMPRE de los datos frescos: con
        // la versión vieja de old() cada «Mandar» volvía a dar «cambió».
        $usarOld = session()->hasOldInput() && ! $errors->has('pedido');
    @endphp

    <div class="py-6 sm:py-8">
        <div class="max-w-3xl mx-auto space-y-4 px-4 sm:px-6 lg:px-8"
             x-data="{
                 enviando: false, estado: '', error: false, espera: null, enCurso: null,
                 {{-- Un solo retardo corto desde el último cambio. --}}
                 guardar() {
                     if (this.enviando) return;
                     clearTimeout(this.espera);
                     this.espera = setTimeout(() => this.enviarBorrador(), 500);
                 },
                 {{-- keepalive: el guardado llega aunque se esté saliendo de la hoja. --}}
                 enviarBorrador() {
                     this.espera = null;
                     if (this.enviando) return Promise.resolve();
                     this.estado = 'Guardando…'; this.error = false;
                     const datos = new FormData(this.$refs.form);
                     datos.set('_method', 'PATCH');
                     const p = fetch(@js(route('fabrica.pedidos.borrador', $pedido)), {
                         method: 'POST', body: datos, keepalive: true,
                         headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                     }).then(async (r) => {
                         if (r.ok) { this.estado = 'Guardado'; return; }
                         this.error = true;
                         if (r.status === 401 || r.status === 419) { this.estado = 'Se cerró la sesión. Volvé a entrar.'; return; }
                         const j = await r.json().catch(() => ({}));
                         this.estado = Object.values(j.errors || {}).flat()[0] || 'No se pudo guardar. Volvé a abrir el pedido.';
                     }).catch(() => { this.error = true; this.estado = 'No se pudo guardar. Revisá la conexión.'; });
                     this.enCurso = p;
                     p.then(() => { if (this.enCurso === p) this.enCurso = null; });
                     return p;
                 },
                 {{-- Lo que falta guardar sale antes de irse (menú, otra pestaña, cerrar). --}}
                 alSalir() {
                     if (! this.enviando && this.espera !== null) { clearTimeout(this.espera); this.enviarBorrador(); }
                 },
                 {{-- «Dejar para después»: espera a que se guarde y recién ahí vuelve a la lista. --}}
                 async salir(destino) {
                     if (this.espera !== null) { clearTimeout(this.espera); await this.enviarBorrador(); }
                     else if (this.enCurso) { await this.enCurso; }
                     location.href = destino;
                 },
                 {{-- Mandar o borrar lo marcado: ningún guardado pendiente puede llegar después. --}}
                 async enviar(form) {
                     if (this.enviando) return;
                     clearTimeout(this.espera); this.espera = null; this.enviando = true;
                     if (this.enCurso) await this.enCurso;
                     form.submit();
                 },
             }"
             @pagehide.window="alSalir()">
            <x-rutas.avisos />

            <p class="text-sm text-gray-600">
                Del {{ $pedido->fecha->format('d/m/Y') }} · {{ $pedido->ruta?->nombre ?? 'Sin zona' }} · {{ $pedido->sacadoPor?->nombre ?? '—' }}
                {{ $pedido->numero_orden_compra ? ' · OC '.$pedido->numero_orden_compra : '' }}
            </p>
            @if ($pedido->notas)
                <p class="whitespace-pre-line text-sm text-gray-700">{{ $pedido->notas }}</p>
            @endif

            <form method="POST" action="{{ route('fabrica.pedidos.mandar', $pedido) }}" x-ref="form"
                  @input="guardar()"
                  @submit.prevent="enviar($el)">
                @csrf
                <input type="hidden" name="clave" value="{{ $clave }}">
                <input type="hidden" name="version" value="{{ $pedido->versionProduccion() }}">

                <div class="rounded-lg border border-gray-200 bg-white">
                    <ul class="divide-y divide-gray-100">
                        @foreach ($faltan as $linea)
                            @php
                                $pendiente = \App\Models\PedidoSalaLinea::legible($linea->pendiente());
                                $pendiente = str_replace(',', '', $pendiente);
                                $marcado = $linea->va_borrador !== null;
                                $va = $marcado ? str_replace(',', '', \App\Models\PedidoSalaLinea::legible((string) $linea->va_borrador)) : $pendiente;
                                // Lo marcado lleva su motivo; si no, el oficial del último envío.
                                $motivo = $marcado ? $linea->motivo_borrador : $linea->motivo_no_enviado;
                                if ($usarOld) {
                                    $va = old('cantidades.'.$linea->id, $va);
                                    $motivo = old('motivos.'.$linea->id, $motivo);
                                }
                                $mandado = $linea->mandado();
                            @endphp
                            <li class="p-4 sm:p-5" x-data="{ va: @js((string) $va), pendiente: @js($pendiente) }">
                                <p class="text-xl font-semibold text-gray-800">{{ $linea->producto?->nombre }}</p>
                                <p class="mt-0.5 text-sm text-gray-500">
                                    Pidió {{ $linea->cantidadLegible() }}{{ bccomp($mandado, '0', 4) === 1 ? ' · ya salió '.\App\Models\PedidoSalaLinea::legible($mandado) : '' }}
                                </p>
                                <div class="mt-3 flex flex-wrap items-center gap-3">
                                    <label for="va-{{ $linea->id }}" class="text-lg font-semibold text-gray-700">Va<span class="sr-only"> de {{ $linea->producto?->nombre }}</span></label>
                                    <input id="va-{{ $linea->id }}" name="cantidades[{{ $linea->id }}]" x-model="va" value="{{ $va }}"
                                           inputmode="decimal" autocomplete="off"
                                           class="min-h-12 w-28 rounded-xl border-gray-300 text-right text-2xl tabular-nums">
                                    <button type="button" @click="va = pendiente; $nextTick(() => guardar())"
                                            class="inline-flex min-h-12 items-center justify-center rounded-xl border border-gray-300 bg-white px-4 text-lg font-semibold text-gray-800 hover:bg-gray-50">Todo</button>
                                    <button type="button" @click="va = '0'; $nextTick(() => guardar())"
                                            class="inline-flex min-h-12 items-center justify-center rounded-xl border border-gray-300 bg-white px-4 text-lg font-semibold text-gray-800 hover:bg-gray-50">Nada</button>
                                </div>
                                <x-input-error :messages="$errors->get('cantidades.'.$linea->id)" class="mt-1" />
                                <div class="mt-3" x-show="parseFloat(va || 0) < parseFloat(pendiente)" x-cloak>
                                    <label for="motivo-{{ $linea->id }}" class="block text-sm font-medium text-gray-700">Por qué no va (opcional)</label>
                                    <input id="motivo-{{ $linea->id }}" name="motivos[{{ $linea->id }}]" maxlength="200" autocomplete="off"
                                           value="{{ $motivo }}"
                                           class="mt-1 min-h-12 w-full rounded-xl border-gray-300 text-lg">
                                    <x-input-error :messages="$errors->get('motivos.'.$linea->id)" class="mt-1" />
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <label class="mt-4 flex min-h-12 items-center gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3">
                    <input type="checkbox" name="sigue" value="1" @checked($usarOld ? old('sigue') : $pedido->armando_sigue) class="h-5 w-5 rounded border-gray-300">
                    <span>
                        <span class="block text-lg text-gray-800">Lo que falta va en otro envío</span>
                        <span class="block text-sm text-gray-500">Sin marcarla, el pedido queda «Mandado» con lo que faltó.</span>
                    </span>
                </label>

                <p class="mt-3 text-sm" role="status" aria-live="polite" x-text="estado" :class="error ? 'text-red-600' : 'text-gray-500'"></p>

                {{-- «Dejar para después» primero y «Mandar» después; en el celular, uno arriba del otro. --}}
                <div class="sticky bottom-0 mt-4 flex flex-col gap-3 border-t border-gray-200 bg-white px-4 py-4 sm:flex-row sm:justify-between">
                    <a href="{{ route('fabrica.pedidos.index') }}" @click.prevent="salir($el.href)"
                       class="inline-flex min-h-12 items-center justify-center rounded-xl border border-gray-300 bg-white px-5 py-4 text-lg font-semibold text-gray-800 hover:bg-gray-50">Dejar para después</a>
                    <button :disabled="enviando"
                            class="inline-flex min-h-12 items-center justify-center rounded-xl bg-indigo-600 px-5 py-5 text-xl font-bold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50">Mandar</button>
                </div>
            </form>

            @if ($conBorrador)
                <form method="POST" action="{{ route('fabrica.pedidos.borrador.borrar', $pedido) }}"
                      @submit.prevent="if (confirm('¿Borrar lo marcado? Vuelve a quedar cargado todo lo que falta.')) enviar($el)">
                    @csrf @method('PATCH')
                    <button :disabled="enviando"
                            class="inline-flex min-h-12 items-center justify-center rounded-xl border border-gray-300 bg-white px-5 py-4 text-lg font-semibold text-gray-800 hover:bg-gray-50 disabled:opacity-50">Borrar lo marcado</button>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
