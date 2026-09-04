@props(['dte', 'salasNotaCredito' => []])

{{--
    Reversión con NOTA DE CRÉDITO — atajo desde la ficha del CCF.

    Es un concepto DISTINTO de la invalidación oficial y por eso vive en su propia
    tarjeta, con su propio encabezado y su propio color: aquí no hay ningún evento
    `anulardte`. Crear la nota deja un BORRADOR; nunca emite, firma ni transmite.

    Ofrece LAS MISMAS cuatro modalidades operativas que la pantalla «Nueva nota de
    crédito» (ver App\Enums\ModalidadNotaCredito), para que el mismo trabajo no se pida
    de dos formas distintas según por dónde se entre. La única diferencia es el atajo: acá
    el CCF ya está elegido —es este documento— y no hay que buscarlo.

    Mismas rutas de siempre (`nota-credito.revertir` y `nota-credito.store`), mismas
    abilities y mismas validaciones de DteBorradorService.

    Solo lo usa el CCF (03) aceptado: es el único tipo con reversión por NC. Quien
    decide si se muestra es la vista contenedora (x-dte.acciones-documento).
--}}

<div class="bg-white shadow sm:rounded-lg p-6 border border-gray-200">
    <div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
            <h3 class="font-semibold text-gray-700">Revertir con nota de crédito</h3>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">
                Corrige el documento emitiendo una <strong>nota de crédito</strong> nueva.
                Se crea un <strong>borrador</strong> para revisión: no se emite, no se firma y
                <strong>no se transmite nada a Hacienda</strong> en este paso.
            </p>
        </div>
        <span class="inline-flex shrink-0 items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800">Crea un borrador</span>
    </div>

    {{-- Reversión TOTAL: crea un borrador de devolución con TODAS las líneas del CCF
         (saldo acreditable disponible). Solo visible con CCF aceptado REAL por Hacienda
         (ability revertirConNotaCredito). No emite/firma/transmite. --}}
    <div class="mt-4">
        @can('revertirConNotaCredito', $dte)
            <form method="POST" action="{{ route('facturacion.nota-credito.revertir', $dte) }}"
                  onsubmit="return confirm('¿Crear un borrador de nota de crédito que revierte TODO el CCF? Se copian todas las líneas con saldo disponible. No se emite ni transmite: quedará en borrador para revisión.');">
                @csrf
                <button class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700 font-medium">
                    Revertir CCF completo con NC
                </button>
            </form>
            <p class="mt-1 text-xs text-gray-400">
                Copia todas las líneas del CCF (cantidades, precios, descuentos e impuestos) a un
                borrador de devolución, respetando el saldo acreditable. No emite nada.
            </p>
        @else
            <button type="button" disabled title="Requiere aceptación real de Hacienda"
                    class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-400 text-sm rounded-md font-medium cursor-not-allowed">
                Revertir CCF completo con NC
            </button>
            <p class="mt-1 text-xs text-gray-400">
                Bloqueado: la reversión total requiere un CCF con <strong>aceptación real</strong> de Hacienda
                (APITEST o producción). Una aceptación simulada (MOCK) no habilita esta acción.
            </p>
        @endcan
    </div>

    {{-- Nota de crédito parcial: las cuatro modalidades operativas.

         La INVALIDACIÓN oficial no está entre las opciones a propósito: no es una
         modalidad de nota de crédito sino otro proceso, con su propia tarjeta. --}}
    <div class="mt-5 border-t border-gray-100 pt-4">
        <h4 class="text-sm font-semibold text-gray-600 mb-2">Otra nota de crédito para este CCF</h4>
        @php
            $modalidadesNc = \App\Enums\ModalidadNotaCredito::cases();
            // Modalidades que admiten emitir la NC a una sala distinta a la del CCF.
            $modalidadesOtraSala = collect($modalidadesNc)
                ->filter(fn ($m) => $m->permiteOtraSalaReceptora())->map(fn ($m) => $m->value)->values()->all();
            $submotivosNc = collect($modalidadesNc)
                ->mapWithKeys(fn ($m) => [$m->value => collect($m->submotivos())
                    ->map(fn ($label, $valor) => ['valor' => $valor, 'label' => $label])->values()->all()])
                ->all();
            // Códigos de albarán del CLIENTE de este CCF, solo si declaró un perfil
            // documental. La mayoría no tiene y no ve ningún código: la nota se emite con
            // las reglas fiscales generales.
            $perfilNc = app(\App\Services\Dte\PerfilDocumentoResolver::class)->paraCliente($dte->cliente_id);
            $codigosNc = collect($modalidadesNc)
                ->mapWithKeys(fn ($m) => [$m->value => collect($m->tiposInternos())
                    ->map(fn ($t) => $perfilNc?->reglaPara($t)?->codigo_externo)->filter()->first()])
                ->filter()->all();
        @endphp
        <form method="POST" action="{{ route('facturacion.nota-credito.store', $dte) }}"
              class="grid grid-cols-1 gap-3"
              x-data="{
                  modalidad: @js(old('modalidad', '')),
                  tipo: @js(old('tipo', '')),
                  otraSala: @js($modalidadesOtraSala),
                  subs: @js($submotivosNc),
                  salaCcf: @js((string) $dte->cliente_sucursal_id),
                  salaNc: @js((string) old('cliente_sucursal_id', $dte->cliente_sucursal_id)),
                  get permiteOtraSala() { return this.otraSala.includes(this.modalidad); },
                  get submotivos() { return this.subs[this.modalidad] ?? []; },
                  {{-- Mismo criterio que el formulario grande: las dos formas de cruzar de
                       sala cuestan lo mismo, una explicación escrita, y el servidor la
                       exige igual. --}}
                  get motivoObligatorio() { return this.permiteOtraSala && this.salaNc !== this.salaCcf; },
                  onModalidad() {
                      const s = this.submotivos;
                      this.tipo = s.length > 0 ? (s.some(x => x.valor === this.tipo) ? this.tipo : s[0].valor) : '';
                      if (! this.permiteOtraSala) { this.salaNc = this.salaCcf; }
                  },
              }"
              onsubmit="return confirm('¿Crear una nota de crédito para este CCF?');">
            @csrf
            <div>
                <x-input-label for="modalidad_nc" value="Modalidad de la nota de crédito *" />
                <select id="modalidad_nc" name="modalidad" x-model="modalidad" @change="onModalidad()"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm" required>
                    <option value="">— Seleccione —</option>
                    @foreach ($modalidadesNc as $m)
                        <option value="{{ $m->value }}" @selected(old('modalidad') === $m->value)>{{ $m->label() }}@if (! empty($codigosNc[$m->value])) · {{ $codigosNc[$m->value] }}@endif</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('modalidad')" class="mt-1" />
                <p class="mt-1 text-xs text-gray-400">Devolución y faltante usan las líneas de este CCF. Avería permite otros productos. Pronto pago y otro ajuste usan conceptos por monto.</p>
            </div>

            {{-- Submotivo (devolución vs. faltante): mismo tratamiento fiscal, dos hechos
                 distintos que conviene no perder. --}}
            <div x-show="submotivos.length > 0" x-cloak>
                <x-input-label for="submotivo_nc" value="¿Cuál de los dos fue?" />
                <select id="submotivo_nc" name="tipo" x-model="tipo"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                    <template x-for="s in submotivos" :key="s.valor">
                        <option :value="s.valor" x-text="s.label"></option>
                    </template>
                </select>
                <x-input-error :messages="$errors->get('tipo')" class="mt-1" />
            </div>

            {{-- SALA RECEPTORA: solo para las modalidades que la admiten (pronto pago, otro
                 ajuste). Permite emitir a una sala administrativa del mismo cliente que no
                 tenga CCF propios. El CCF relacionado y el cliente NO cambian. --}}
            @if (! empty($salasNotaCredito))
                <div x-show="permiteOtraSala" x-cloak
                     class="rounded-md border border-amber-200 bg-amber-50 p-3">
                    <x-input-label for="sala_nc_ccf" value="Sala receptora de la Nota de Crédito" />
                    <select id="sala_nc_ccf" name="cliente_sucursal_id" x-model="salaNc"
                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                        @foreach ($salasNotaCredito as $sala)
                            <option value="{{ $sala['id'] }}"
                                @selected((int) old('cliente_sucursal_id', $dte->cliente_sucursal_id) === (int) $sala['id'])>
                                {{ $sala['nombre'] }}@if ($sala['es_sala_ccf']) — sala del CCF @endif
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('cliente_sucursal_id')" class="mt-1" />
                    <p class="mt-1.5 text-xs text-amber-800">
                        Podés emitir la nota a una sala administrativa del mismo cliente, aunque
                        nunca haya recibido un CCF.
                        Solo cambia el establecimiento y la dirección mostrados:
                        <strong>el CCF relacionado, el NIT/NRC del cliente y el saldo acreditable no cambian.</strong>
                    </p>
                    <p class="mt-1.5 text-xs font-semibold text-amber-900" x-show="motivoObligatorio" x-cloak role="status">
                        Vas a emitir a una sala distinta a la del CCF: el motivo pasa a ser obligatorio.
                    </p>
                </div>
                <p class="text-xs text-gray-400" x-show="modalidad !== '' && ! permiteOtraSala" x-cloak>
                    Esta nota se emite a la misma sala del CCF relacionado.
                </p>
            @endif

            <div>
                <x-input-label for="motivo">
                    <span>Motivo / observaciones</span>
                    <span x-show="motivoObligatorio" x-cloak class="text-amber-700 font-semibold"> — obligatorio</span>
                    <span x-show="! motivoObligatorio" class="text-gray-400 font-normal"> (opcional)</span>
                </x-input-label>
                <x-text-input id="motivo" name="motivo" type="text" class="mt-1 block w-full"
                              ::required="motivoObligatorio"
                              placeholder="Ej. Devolución parcial de mercadería" :value="old('motivo')" />
                <x-input-error :messages="$errors->get('motivo')" class="mt-1" />
            </div>

            <div>
                <button class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                    Crear nota de crédito
                </button>
            </div>
        </form>
    </div>
</div>
