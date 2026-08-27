@props(['dte', 'salasNotaCredito' => []])

{{--
    Reversión con NOTA DE CRÉDITO — extraído tal cual de facturacion/show.blade.php.

    Es un concepto DISTINTO de la invalidación oficial y por eso vive en su propia
    tarjeta, con su propio encabezado y su propio color: aquí no hay ningún evento
    `anulardte`. Crear la nota deja un BORRADOR; nunca emite, firma ni transmite.

    No cambia ninguna ruta, ningún campo ni ninguna regla: mismos formularios
    (`nota-credito.revertir` y `nota-credito.store`), mismas abilities y mismas
    validaciones de DteBorradorService.

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

    {{-- Otras notas de crédito: selector de tipo existente (parcial / avería / ajuste). --}}
    <div class="mt-5 border-t border-gray-100 pt-4">
        <h4 class="text-sm font-semibold text-gray-600 mb-2">Otras notas de crédito (parcial / avería / ajuste)</h4>
        @php
            // Modalidades por MONTO: las únicas que admiten emitir la NC a una
            // sala distinta a la del CCF (ver DteBorradorService).
            $tiposPorMontoNc = collect(\App\Enums\TipoNotaCredito::cases())
                ->filter(fn ($t) => $t->esPorMonto())->map(fn ($t) => $t->value)->values()->all();
        @endphp
        <form method="POST" action="{{ route('facturacion.nota-credito.store', $dte) }}"
              class="grid grid-cols-1 gap-3 items-end"
              x-data="{
                  tipo: @js(old('tipo', '')),
                  porMonto: @js($tiposPorMontoNc),
                  get permiteOtraSala() { return this.porMonto.includes(this.tipo); },
              }"
              onsubmit="return confirm('¿Crear una nota de crédito para este CCF?');">
            @csrf
            <div>
                <x-input-label for="tipo_nc" value="Tipo de nota de crédito *" />
                <select id="tipo_nc" name="tipo" x-model="tipo" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm" required>
                    <option value="">— Seleccione —</option>
                    @foreach (\App\Enums\TipoNotaCredito::opciones() as $valor => $label)
                        <option value="{{ $valor }}" @selected(old('tipo') === $valor)>{{ $label }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('tipo')" class="mt-1" />
                <p class="mt-1 text-xs text-gray-400">Devolución y faltante usan las líneas de este CCF. Avería permite otros productos. Pronto pago y ajustes usan conceptos manuales.</p>
            </div>

            {{-- SALA RECEPTORA: solo para las notas por monto (pronto pago…).
                 Permite emitir a una sala administrativa del mismo cliente que no
                 tenga CCF propios. El CCF relacionado y el cliente NO cambian. --}}
            @if (! empty($salasNotaCredito))
                <div x-show="permiteOtraSala" x-cloak
                     class="rounded-md border border-amber-200 bg-amber-50 p-3">
                    <x-input-label for="sala_nc_ccf" value="Sala receptora de la Nota de Crédito" />
                    <select id="sala_nc_ccf" name="cliente_sucursal_id"
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
                        Para <strong>pronto pago</strong> podés emitir la nota a una sala administrativa
                        del mismo cliente, aunque nunca haya recibido un CCF.
                        Solo cambia el establecimiento y la dirección mostrados:
                        <strong>el CCF relacionado, el NIT/NRC del cliente y el saldo acreditable no cambian.</strong>
                    </p>
                </div>
                <p class="text-xs text-gray-400" x-show="tipo !== '' && ! permiteOtraSala" x-cloak>
                    Esta nota se emite a la misma sala del CCF relacionado.
                </p>
            @endif
            <div>
                <x-input-label for="motivo" value="Motivo / observaciones (opcional)" />
                <x-text-input id="motivo" name="motivo" type="text" class="mt-1 block w-full"
                              placeholder="Ej. Devolución parcial de mercadería" :value="old('motivo')" />
            </div>
            <div>
                <button class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                    Crear nota de crédito
                </button>
            </div>
        </form>
    </div>
</div>
