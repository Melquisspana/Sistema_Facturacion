{{-- La hoja «Qué va a <sala>»: un renglón por producto con algo pendiente, con lo que falta
     YA cargado. Cada cambio (también la casilla) se guarda solo en el servidor (fetch, con
     un solo retardo corto; lo que falta guardar sale antes de dejar la hoja) y el pedido
     queda «Armando»: se puede dejar para después y seguir con otro. La hoja NO se recarga
     sola. Pantalla táctil de fábrica (docs/GUIA_PANTALLAS.md, «Pantalla táctil»):
     botones de min-h-12 con py-4/py-5 y letra text-xl/2xl; funciona igual con el mouse.

     El motivo es opcional y nunca `required`: lo que decide es el servidor.

     A pantalla completa (12/11/2026): arriba, «Todos» (vuelve a la lista) y los otros
     pedidos por mandar, los que se están armando primero. Tocar uno cambia de pedido sin
     perder nada: como «Dejar para después», primero termina de guardar (salir()). Después
     los datos de la sala y, abajo, los productos. «Imprimir y mandar» registra el envío y
     abre su hoja para imprimir.

     «Salir» de la barra de arriba también espera los guardados (si uno falla, se queda y
     avisa), y cerrar o recargar la pestaña con algo sin guardar o en vuelo pide
     confirmación del navegador.

     En monitor (12/11/2026): la hoja usa todo el ancho (max-w-full), los datos de la sala
     van en una franja de una línea y cada producto es un renglón de dos mitades (lg):
     nombre, código y «Pidió» | «Va», «Todo», «Nada»; «Por qué no va» abajo, solo si hace
     falta. Desde xl los renglones van en dos columnas: un pedido de 12 se ve casi entero
     en 1600×900 sin bajar. Debajo de lg se ve como antes, uno abajo del otro.

     13/11/2026: la fila de otros pedidos va en UN renglón en todo tamaño: «Todos» fijo al
     principio y el resto se desliza de lado dentro de su propia caja (overflow-x-auto con
     min-w-0), sin que la página se corra. Y «No se trae» pasó a «No mandar nada de este
     pedido» («No mandar lo que falta» si ya salió una parte): rojo, lejos de «Imprimir y
     mandar», con un diálogo (x-modal) que pide el motivo; el servidor tampoco cierra sin
     él. Se deshace igual que antes, desde la lista. --}}
<x-app-layout :pantalla-completa="true">
    @php
        $faltan = $pedido->lineas->filter->tienePendiente();
        $conBorrador = $pedido->armando_desde !== null || $pedido->lineas->whereNotNull('va_borrador')->isNotEmpty();
        // Lo escrito vuelve con old() tras un error, salvo si el pedido cambió: entonces se
        // muestra lo fresco. La clave y la versión salen SIEMPRE de los datos frescos: con
        // la versión vieja de old() cada «Mandar» volvía a dar «cambió».
        $usarOld = session()->hasOldInput() && ! $errors->has('pedido');
    @endphp

    <div class="py-6 sm:py-8">
        <div class="max-w-full mx-auto space-y-4 px-4 sm:px-6 lg:px-8"
             x-data="{
                 enviando: false, saliendo: false, sucio: false, guardando: false, cerrando: false, cola: Promise.resolve(),
                 estado: '', error: false, aviso: false, espera: null,
                 {{-- Un solo retardo corto desde el último cambio. `sucio`: hay algo sin guardar. --}}
                 guardar() {
                     if (this.enviando) return;
                     this.sucio = true;
                     clearTimeout(this.espera);
                     if (! this.saliendo) this.espera = setTimeout(() => this.enviarBorrador(), 500);
                 },
                 {{-- Los guardados van EN FILA, nunca dos a la vez: cada uno sale cuando terminó
                      el anterior y lleva lo que hay en ese momento en la hoja. Así uno viejo no
                      llega después y pisa lo último. --}}
                 enviarBorrador() {
                     clearTimeout(this.espera); this.espera = null;
                     const p = this.cola.then(() => this.guardarAhora());
                     this.cola = p;
                     return p;
                 },
                 {{-- keepalive: el guardado llega aunque se esté saliendo de la hoja. Si falla,
                      lo marcado sigue `sucio`. Nunca rechaza: la fila no se corta. `guardando`:
                      hay uno en vuelo (ya no está `sucio`, pero todavía no llegó). --}}
                 guardarAhora() {
                     if (this.enviando || ! this.sucio) return Promise.resolve();
                     this.sucio = false; this.guardando = true;
                     this.estado = 'Guardando…'; this.error = false;
                     const datos = new FormData(this.$refs.form);
                     datos.set('_method', 'PATCH');
                     return fetch(@js(route('fabrica.pedidos.borrador', $pedido)), {
                         method: 'POST', body: datos, keepalive: true,
                         headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                     }).then(async (r) => {
                         if (r.ok) { this.estado = 'Guardado'; this.aviso = false; return; }
                         this.error = true; this.sucio = true;
                         if (r.status === 401 || r.status === 419) { this.estado = 'Se cerró la sesión. Volvé a entrar.'; return; }
                         const j = await r.json().catch(() => ({}));
                         this.estado = Object.values(j.errors || {}).flat()[0] || 'No se pudo guardar. Volvé a abrir el pedido.';
                     }).catch(() => { this.error = true; this.sucio = true; this.estado = 'No se pudo guardar lo marcado. Revisá la conexión y probá de nuevo.'; })
                       .finally(() => { this.guardando = false; });
                 },
                 {{-- ¿Queda algo por guardar o un guardado en vuelo? (`sucio` solo no alcanza.) --}}
                 faltaGuardar() {
                     return this.sucio || this.guardando;
                 },
                 {{-- Cerrar o recargar la pestaña con algo sin guardar: el navegador pregunta.
                      Mandando o borrando lo marcado no: el formulario lleva los datos. --}}
                 avisarSiFalta(e) {
                     if (this.enviando || ! this.faltaGuardar()) return;
                     e.preventDefault(); e.returnValue = '';
                 },
                 {{-- Lo que falta guardar sale antes de irse (la barra de arriba, otra pestaña, cerrar). --}}
                 alSalir() {
                     if (! this.enviando && ! this.saliendo && this.sucio) this.enviarBorrador();
                 },
                 {{-- «Dejar para después», «Todos», los otros pedidos y «Salir»: espera la fila
                      entera y se va solo cuando no queda nada sin guardar. Si un guardado falla,
                      NO se va: queda todo como está, avisa arriba y devuelve false. Mientras se
                      manda, no se sale. `destino`: una dirección o, para «Salir», qué hacer. --}}
                 async salir(destino) {
                     if (this.enviando || this.saliendo) return;
                     this.saliendo = true;
                     await this.enviarBorrador();
                     while (this.sucio && ! this.error) await this.enviarBorrador();
                     if (this.error) { this.aviso = true; this.saliendo = false; return false; }
                     if (typeof destino === 'function') destino(); else location.href = destino;
                 },
                 {{-- «Salir» de la barra de arriba (form[data-salir]), en la captura de window:
                      antes que el cierre de sesión del layout (sw-contingencia), que sigue
                      después con requestSubmit, cuando ya se guardó todo. Si no se pudo guardar
                      (sesión vencida, error del servidor), «Salir» no queda trabado: pregunta si
                      sale igual y pierde lo marcado; si dice que sí, sigue por el mismo camino. --}}
                 async cerrarSesion(e) {
                     const form = e.target;
                     if (this.cerrando || ! (form instanceof HTMLFormElement) || ! form.hasAttribute('data-salir')) return;
                     e.preventDefault(); e.stopImmediatePropagation();
                     const seguir = () => { this.cerrando = true; form.requestSubmit ? form.requestSubmit() : form.submit(); };
                     if (await this.salir(seguir) === false && confirm('No se pudo guardar lo marcado. ¿Salir igual y perderlo?')) {
                         {{-- Ya no pregunta el navegador ni intenta guardar al irse: lo eligió ella. --}}
                         this.sucio = false;
                         seguir();
                     }
                 },
                 {{-- Mandar o borrar lo marcado: espera los guardados en vuelo y ninguno sale
                      después. Mientras se sale, no se manda. --}}
                 async enviar(form) {
                     if (this.enviando || this.saliendo) return;
                     clearTimeout(this.espera); this.espera = null; this.enviando = true;
                     await this.cola;
                     form.submit();
                 },
             }"
             @pagehide.window="alSalir()" @beforeunload.window="avisarSiFalta($event)" @submit.window.capture="cerrarSesion($event)">
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

            <p x-show="aviso" x-cloak role="alert"
               class="rounded-md border border-red-200 bg-red-50 p-3 text-base text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                Te quedaste en este pedido porque no se guardó lo marcado. <span x-text="estado"></span>
            </p>

            <x-rutas.avisos />

            <section aria-labelledby="titulo-pedido" class="rounded-lg border border-gray-200 bg-white p-4 sm:p-5">
                <h1 id="titulo-pedido" class="text-2xl font-bold text-gray-800">Qué va a {{ $pedido->nombreSala() }}</h1>
                <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 text-base sm:grid-cols-2 lg:grid-cols-6">
                    @if ($pedido->direccionSala())
                        <div class="sm:col-span-2"><dt class="text-sm text-gray-500">Dirección</dt><dd class="text-gray-800">{{ $pedido->direccionSala() }}</dd></div>
                    @endif
                    <div><dt class="text-sm text-gray-500">Zona</dt><dd class="text-gray-800">{{ $pedido->zonaNombre() }}</dd></div>
                    <div><dt class="text-sm text-gray-500">Orden de compra</dt><dd class="font-mono text-gray-800">{{ $pedido->numero_orden_compra ?? 'Sin OC' }}</dd></div>
                    <div><dt class="text-sm text-gray-500">Lo sacó</dt><dd class="text-gray-800">{{ $pedido->sacadoPor?->nombre ?? '—' }}</dd></div>
                    <div><dt class="text-sm text-gray-500">Fecha y hora</dt><dd class="tabular-nums text-gray-800">{{ $pedido->fecha->format('d/m/Y') }} {{ $pedido->created_at->format('H:i') }}</dd></div>
                    @if ($pedido->notas)
                        <div class="sm:col-span-2 lg:col-span-4"><dt class="text-sm text-gray-500">Notas</dt><dd class="whitespace-pre-line text-gray-800">{{ $pedido->notas }}</dd></div>
                    @endif
                </dl>
            </section>

            <form method="POST" action="{{ route('fabrica.pedidos.mandar', $pedido) }}" x-ref="form"
                  @input="guardar()"
                  @submit.prevent="enviar($el)">
                @csrf
                <input type="hidden" name="clave" value="{{ $clave }}">
                <input type="hidden" name="version" value="{{ $pedido->versionProduccion() }}">

                <div class="rounded-lg border border-gray-200 bg-white">
                    {{-- En monitor (xl), dos columnas de renglones: un pedido de 12 entra sin bajar. --}}
                    <ul class="grid grid-cols-1 gap-x-6 xl:grid-cols-2">
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
                            {{-- En monitor, dos mitades: el producto con lo que pidió | Va, Todo y Nada en una sola línea. --}}
                            <li class="items-center border-b border-gray-100 p-4 sm:p-5 lg:grid lg:grid-cols-2 lg:gap-3 lg:p-4" x-data="{ va: @js((string) $va), pendiente: @js($pendiente) }">
                                <div class="min-w-0">
                                    <p class="text-xl font-semibold text-gray-800">
                                        {{ $linea->producto?->nombre }}
                                        @if ($linea->producto?->codigo)
                                            <span class="ms-1 font-mono text-sm font-normal text-gray-500">{{ $linea->producto->codigo }}</span>
                                        @endif
                                    </p>
                                    <p class="mt-0.5 text-base text-gray-600 lg:font-semibold">
                                        Pidió {{ $linea->cantidadLegible() }}{{ bccomp($mandado, '0', 4) === 1 ? ' · ya salió '.\App\Models\PedidoSalaLinea::legible($mandado) : '' }}
                                    </p>
                                </div>
                                <div class="mt-3 flex flex-wrap items-center gap-2 lg:mt-0">
                                    <label for="va-{{ $linea->id }}" class="text-lg font-semibold text-gray-700">Va<span class="sr-only"> de {{ $linea->producto?->nombre }}</span></label>
                                    <input id="va-{{ $linea->id }}" name="cantidades[{{ $linea->id }}]" x-model="va" value="{{ $va }}"
                                           inputmode="decimal" autocomplete="off"
                                           class="min-h-12 w-28 rounded-xl border-gray-300 text-right text-2xl tabular-nums">
                                    <button type="button" @click="va = pendiente; $nextTick(() => guardar())"
                                            class="inline-flex min-h-12 items-center justify-center rounded-xl border border-gray-300 bg-white px-4 text-lg font-semibold text-gray-800 hover:bg-gray-50">Todo</button>
                                    <button type="button" @click="va = '0'; $nextTick(() => guardar())"
                                            class="inline-flex min-h-12 items-center justify-center rounded-xl border border-gray-300 bg-white px-4 text-lg font-semibold text-gray-800 hover:bg-gray-50">Nada</button>
                                </div>
                                <x-input-error :messages="$errors->get('cantidades.'.$linea->id)" class="mt-1 lg:col-span-2 lg:mt-0" />
                                <div class="mt-3 lg:col-span-2 lg:mt-0" x-show="parseFloat(va || 0) < parseFloat(pendiente)" x-cloak>
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

                {{-- «Dejar para después» primero e «Imprimir y mandar» después; en el celular, uno arriba del otro. --}}
                <div class="sticky bottom-0 mt-4 flex flex-col gap-3 border-t border-gray-200 bg-white px-4 py-4 sm:flex-row sm:justify-between">
                    <a href="{{ route('fabrica.pedidos.index') }}" @click.prevent="salir($el.href)"
                       class="inline-flex min-h-12 items-center justify-center rounded-xl border border-gray-300 bg-white px-5 py-4 text-lg font-semibold text-gray-800 hover:bg-gray-50">Dejar para después</a>
                    <button :disabled="enviando || saliendo"
                            class="inline-flex min-h-12 items-center justify-center rounded-xl bg-indigo-600 px-5 py-5 text-xl font-bold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50">Imprimir y mandar</button>
                </div>
            </form>

            {{-- «No mandar nada de este pedido» (o lo que falta): rojo de peligro y aparte, debajo
                 de la barra de «Dejar para después» e «Imprimir y mandar». Abre un diálogo que
                 pide el motivo; en la lista sale el aviso con «Deshacer», como antes. --}}
            @php
                $parcial = $pedido->estado === \App\Enums\EstadoPedidoSala::Parcial;
                $opciones = ['No hay producto', 'El cliente canceló', 'Pedido repetido'];
                $motivoViejo = (string) old('motivo', '');
            @endphp
            <div class="flex flex-wrap gap-3 border-t border-gray-200 pt-6">
                <button type="button" x-on:click="$dispatch('open-modal', 'no-mandar')" :disabled="enviando || saliendo"
                        class="inline-flex min-h-12 items-center justify-center rounded-xl bg-red-600 px-5 py-4 text-lg font-semibold text-white hover:bg-red-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600 disabled:opacity-50">
                    {{ $parcial ? 'No mandar lo que falta' : 'No mandar nada de este pedido' }}
                </button>

                @if ($conBorrador)
                    <form method="POST" action="{{ route('fabrica.pedidos.borrador.borrar', $pedido) }}"
                          @submit.prevent="if (confirm('¿Borrar lo marcado? Vuelve a quedar cargado todo lo que falta.')) enviar($el)">
                        @csrf @method('PATCH')
                        <button :disabled="enviando || saliendo"
                                class="inline-flex min-h-12 items-center justify-center rounded-xl border border-gray-300 bg-white px-5 py-4 text-lg font-semibold text-gray-800 hover:bg-gray-50 disabled:opacity-50">Borrar lo marcado</button>
                    </form>
                @endif
            </div>

            {{-- La confirmación: sin `confirm()` del navegador. El motivo es uno de los que se
                 tocan u «Otro» con texto; va en un solo campo `motivo`. Sin motivo, el botón no
                 deja y el servidor tampoco. Si el servidor lo rechaza, vuelve abierto con el aviso. --}}
            <x-modal name="no-mandar" :show="$errors->has('motivo')" maxWidth="lg" focusable>
                <div role="dialog" aria-modal="true" aria-labelledby="titulo-no-mandar" class="p-5 sm:p-6"
                     x-data="{
                         eleccion: @js(in_array($motivoViejo, $opciones, true) ? $motivoViejo : ($motivoViejo !== '' ? 'Otro' : '')),
                         otro: @js(in_array($motivoViejo, $opciones, true) ? '' : $motivoViejo),
                         motivo() { return (this.eleccion === 'Otro' ? this.otro : this.eleccion).trim(); },
                     }">
                    <h2 id="titulo-no-mandar" class="text-xl font-bold text-gray-800">
                        {{ $parcial ? 'Lo que falta de este pedido no va a salir.' : 'Nada de este pedido va a salir.' }} ¿Por qué?
                    </h2>
                    <form method="POST" action="{{ route('fabrica.pedidos.cerrar', $pedido) }}" class="mt-4"
                          @submit.prevent="if (motivo() !== '') enviar($el)">
                        @csrf @method('PATCH')
                        <input type="hidden" name="version" value="{{ $pedido->versionProduccion() }}">
                        <input type="hidden" name="motivo" :value="motivo()">
                        <fieldset>
                            <legend class="sr-only">Por qué no se manda</legend>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach ([...$opciones, 'Otro'] as $opcion)
                                    <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 text-lg font-semibold text-gray-800"
                                           :class="eleccion === @js($opcion) ? 'border-red-400 bg-red-50' : 'border-gray-300 bg-white hover:bg-gray-50'">
                                        <input type="radio" name="eleccion" value="{{ $opcion }}" x-model="eleccion" class="h-5 w-5 border-gray-300 text-red-600">
                                        {{ $opcion }}
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <div class="mt-3" x-show="eleccion === 'Otro'" x-cloak>
                            <label for="motivo-otro" class="block text-sm font-medium text-gray-700">Escribí el motivo</label>
                            <input id="motivo-otro" x-model="otro" maxlength="200" autocomplete="off"
                                   class="mt-1 min-h-12 w-full rounded-xl border-gray-300 text-lg">
                        </div>
                        <x-input-error :messages="$errors->get('motivo')" class="mt-2" />

                        <div class="mt-6 flex flex-col gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:justify-end">
                            <button type="button" x-on:click="$dispatch('close-modal', 'no-mandar')"
                                    class="inline-flex min-h-12 items-center justify-center rounded-xl border border-gray-300 bg-white px-5 py-4 text-lg font-semibold text-gray-800 hover:bg-gray-50">Cancelar</button>
                            <button :disabled="motivo() === '' || enviando || saliendo"
                                    class="inline-flex min-h-12 items-center justify-center rounded-xl bg-red-600 px-5 py-4 text-lg font-bold text-white hover:bg-red-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600 disabled:opacity-50">
                                {{ $parcial ? 'Sí, no mandar lo que falta' : 'Sí, no mandar nada' }}
                            </button>
                        </div>
                    </form>
                </div>
            </x-modal>
        </div>
    </div>
</x-app-layout>
