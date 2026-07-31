{{-- Formulario de ajuste: cabecera + líneas.

     EL TIPO MANDA sobre cómo se capturan las líneas:

       - los tipos que RESTAN (negativo, merma, daño, vencimiento) se eligen
         sobre un saldo que ya existe, así que el selector ofrece buckets reales
         con su saldo a la vista;
       - los tipos que SUMAN (carga inicial, positivo) construyen el bucket:
         insumo, lote, ubicación y estado;
       - la corrección de conteo pide lo CONTADO, no una cantidad: la diferencia
         la calcula el servidor con el saldo bloqueado al confirmar.

     El signo nunca se captura. La cantidad es una magnitud positiva y el tipo
     decide si suma o resta. --}}
@php
    $ajuste = $ajuste ?? null;

    $lineasPrevias = old('detalles', $ajuste?->detalles
        ->map(fn ($d) => [
            'bucket' => $d->planta_insumo_id.'|'.$d->planta_lote_id.'|'.$d->planta_ubicacion_id.'|'.$d->estado_disponibilidad->value,
            'planta_insumo_id' => $d->planta_insumo_id,
            'planta_lote_id' => $d->planta_lote_id,
            'planta_ubicacion_id' => $d->planta_ubicacion_id,
            'estado_disponibilidad' => $d->estado_disponibilidad->value,
            'cantidad' => (string) $d->cantidad,
            'cantidad_conteo' => $d->cantidad_conteo !== null ? (string) $d->cantidad_conteo : '',
            'observaciones' => $d->observaciones,
        ])->values()->all() ?? []);

    $saldos = $buckets->mapWithKeys(fn ($b) => [
        $b->planta_insumo_id.'|'.$b->planta_lote_id.'|'.$b->planta_ubicacion_id.'|'.$b->estado => [
            'texto' => $b->insumo_codigo.' — '.$b->insumo_nombre.' · lote '.$b->lote_codigo
                .' · '.$b->ubicacion_codigo.' · '.$b->estado,
            'saldo' => (string) $b->cantidad,
            'unidad' => $b->unidad_base,
        ],
    ]);

    // Qué insumos controlan lotes, para exigir lote real donde toca.
    $catalogoInsumos = $insumos->mapWithKeys(fn ($i) => [$i->id => [
        'controla_lotes' => (bool) $i->controla_lotes,
        'unidad' => $i->unidad_base->value,
    ]]);

    $lotesPorInsumo = $lotesReales->groupBy('planta_insumo_id')
        ->map(fn ($g) => $g->map(fn ($l) => [
            'id' => $l->id,
            'texto' => $l->codigo_interno.($l->fecha_vencimiento ? ' · vence '.$l->fecha_vencimiento->toDateString() : ''),
        ])->values());

    $etiqueta = 'block text-sm font-medium text-gray-700 dark:text-paper-200';
    $campo = 'mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
    $celda = 'w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100';
@endphp

<div x-data="{
        tipo: @js(old('tipo', $ajuste?->tipo?->value ?? 'positivo')),
        saldos: {{ Js::from($saldos) }},
        catalogo: {{ Js::from($catalogoInsumos) }},
        lotes: {{ Js::from($lotesPorInsumo) }},
        lineas: {{ Js::from($lineasPrevias) }},
        get resta() { return ['negativo', 'merma', 'dano', 'vencimiento'].includes(this.tipo); },
        get esConteo() { return this.tipo === 'correccion_conteo'; },
        get eligeBucket() { return this.resta || this.esConteo; },
        agregar() {
            this.lineas.push({ bucket: '', planta_insumo_id: '', planta_lote_id: '',
                planta_ubicacion_id: '', estado_disponibilidad: 'disponible',
                cantidad: '', cantidad_conteo: '', observaciones: '' });
        },
        quitar(i) { this.lineas.splice(i, 1); },
        info(linea) { return this.saldos[linea.bucket] ?? null; },
        controlaLotes(linea) {
            const d = this.catalogo[linea.planta_insumo_id];
            return d ? d.controla_lotes : false;
        },
        lotesDe(linea) { return this.lotes[linea.planta_insumo_id] ?? []; },
     }"
     x-init="if (lineas.length === 0) agregar()"
     class="space-y-5">

    {{-- Cabecera --}}
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
        <div>
            <label for="tipo" class="{{ $etiqueta }}">Tipo de ajuste</label>
            <select name="tipo" id="tipo" x-model="tipo" required class="{{ $campo }}">
                @foreach ($tipos as $t)
                    <option value="{{ $t->value }}">{{ $t->label() }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-gray-500 dark:text-paper-400" x-show="tipo === 'carga_inicial'">
                Solo para buckets que nunca han tenido movimientos.
            </p>
            @error('tipo')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="fecha" class="{{ $etiqueta }}">Fecha</label>
            <input type="date" name="fecha" id="fecha" required
                   value="{{ old('fecha', $ajuste?->fecha?->toDateString() ?? now()->toDateString()) }}"
                   class="{{ $campo }}">
            @error('fecha')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="responsable_user_id" class="{{ $etiqueta }}">Responsable (usuario)</label>
            <select name="responsable_user_id" id="responsable_user_id" class="{{ $campo }}">
                <option value="">Sin usuario</option>
                @foreach ($usuarios as $usuario)
                    <option value="{{ $usuario->id }}"
                        @selected(old('responsable_user_id', $ajuste?->responsable_user_id) == $usuario->id)>
                        {{ $usuario->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="responsable_nombre" class="{{ $etiqueta }}">Responsable (nombre)</label>
            <input type="text" name="responsable_nombre" id="responsable_nombre" maxlength="120"
                   value="{{ old('responsable_nombre', $ajuste?->responsable_nombre) }}"
                   placeholder="Quien constató el hecho" class="{{ $campo }}">
        </div>

        <div class="sm:col-span-2">
            <label for="motivo" class="{{ $etiqueta }}">Motivo</label>
            <textarea name="motivo" id="motivo" rows="2" required minlength="10" maxlength="2000"
                      placeholder="Por qué cambia el saldo"
                      class="{{ $campo }}">{{ old('motivo', $ajuste?->motivo) }}</textarea>
            <p class="mt-1 text-xs text-gray-500 dark:text-paper-400">
                Obligatorio: un ajuste es el único documento que cambia el saldo sin respaldo externo.
            </p>
            @error('motivo')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="sm:col-span-2 lg:col-span-3">
            <label for="observaciones" class="{{ $etiqueta }}">Observaciones</label>
            <textarea name="observaciones" id="observaciones" rows="2" class="{{ $campo }}">{{ old('observaciones', $ajuste?->observaciones) }}</textarea>
        </div>
    </div>

    {{-- Líneas --}}
    <div class="pt-5 border-t border-gray-100 dark:border-ink-700">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-gray-800 dark:text-paper-100">Líneas del ajuste</h3>
            <button type="button" @click="agregar()"
                    class="rounded-md bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-200 dark:hover:bg-ink-600">
                + Añadir línea
            </button>
        </div>

        @error('detalles')<p class="mb-2 text-xs text-red-600">{{ $message }}</p>@enderror

        <div class="space-y-3">
            <template x-for="(linea, i) in lineas" :key="i">
                <div class="rounded-lg border border-gray-200 p-4 dark:border-ink-600">

                    {{-- Tipos que restan o corrigen: se elige un saldo existente --}}
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" x-show="eligeBucket">
                        <div class="lg:col-span-2">
                            <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Saldo existente</label>
                            <select :name="eligeBucket ? `detalles[${i}][bucket]` : ''" x-model="linea.bucket" class="{{ $celda }}">
                                <option value="">Selecciona…</option>
                                @foreach ($buckets as $b)
                                    <option value="{{ $b->planta_insumo_id }}|{{ $b->planta_lote_id }}|{{ $b->planta_ubicacion_id }}|{{ $b->estado }}">
                                        {{ $b->insumo_codigo }} — {{ $b->insumo_nombre }}
                                        · lote {{ $b->lote_codigo }} · {{ $b->ubicacion_codigo }}
                                        · {{ $b->estado }} · {{ $b->cantidad }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Saldo actual</label>
                            <p class="mt-1.5 text-sm font-semibold text-gray-800 dark:text-paper-100">
                                <span x-text="info(linea) ? info(linea).saldo : '—'"></span>
                                <span class="text-xs font-normal text-gray-500 dark:text-paper-400"
                                      x-text="info(linea) ? info(linea).unidad : ''"></span>
                            </p>
                        </div>

                        <div x-show="esConteo">
                            <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Cantidad contada</label>
                            <input type="number" step="0.0001" min="0"
                                   :name="esConteo ? `detalles[${i}][cantidad_conteo]` : ''"
                                   x-model="linea.cantidad_conteo" class="{{ $celda }}">
                            <p class="text-[11px] text-gray-400">La diferencia la calcula el servidor.</p>
                        </div>

                        <div x-show="resta">
                            <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Cantidad a restar</label>
                            <input type="number" step="0.0001" min="0.0001"
                                   :name="resta ? `detalles[${i}][cantidad]` : ''"
                                   x-model="linea.cantidad"
                                   :max="info(linea) ? info(linea).saldo : null" class="{{ $celda }}">
                        </div>
                    </div>

                    {{-- Tipos que suman: se construye el bucket --}}
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5" x-show="! eligeBucket">
                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Insumo</label>
                            <select :name="! eligeBucket ? `detalles[${i}][planta_insumo_id]` : ''"
                                    x-model="linea.planta_insumo_id" class="{{ $celda }}">
                                <option value="">Selecciona…</option>
                                @foreach ($insumos as $insumo)
                                    <option value="{{ $insumo->id }}">{{ $insumo->codigo }} — {{ $insumo->nombre }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Lote</label>
                            <select :name="! eligeBucket ? `detalles[${i}][planta_lote_id]` : ''"
                                    x-model="linea.planta_lote_id" class="{{ $celda }}"
                                    :disabled="! controlaLotes(linea)">
                                <option value="">
                                    <span x-text="controlaLotes(linea) ? 'Selecciona…' : 'Genérico automático'"></span>
                                </option>
                                <template x-for="l in lotesDe(linea)" :key="l.id">
                                    <option :value="l.id" x-text="l.texto"></option>
                                </template>
                            </select>
                            <p class="text-[11px] text-gray-400" x-show="! controlaLotes(linea)">
                                Este insumo no controla lotes: se usa su genérico.
                            </p>
                        </div>

                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Ubicación</label>
                            <select :name="! eligeBucket ? `detalles[${i}][planta_ubicacion_id]` : ''"
                                    x-model="linea.planta_ubicacion_id" class="{{ $celda }}">
                                <option value="">Selecciona…</option>
                                @foreach ($ubicaciones as $u)
                                    <option value="{{ $u->id }}">{{ $u->codigo }} — {{ $u->nombre }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Disponibilidad</label>
                            <select :name="! eligeBucket ? `detalles[${i}][estado_disponibilidad]` : ''"
                                    x-model="linea.estado_disponibilidad" class="{{ $celda }}">
                                @foreach ($disponibilidades as $d)
                                    <option value="{{ $d->value }}">{{ $d->label() }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Cantidad</label>
                            <input type="number" step="0.0001" min="0.0001"
                                   :name="! eligeBucket ? `detalles[${i}][cantidad]` : ''"
                                   x-model="linea.cantidad" class="{{ $celda }}">
                        </div>
                    </div>

                    <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-4">
                        <div class="lg:col-span-3">
                            <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Observaciones de la línea</label>
                            <input type="text" maxlength="500" :name="`detalles[${i}][observaciones]`"
                                   x-model="linea.observaciones" class="{{ $celda }}">
                        </div>
                        <div class="flex items-end justify-end">
                            <button type="button" @click="quitar(i)" x-show="lineas.length > 1"
                                    class="rounded-md bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100 dark:bg-red-500/10 dark:text-red-300">
                                Quitar
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <p class="mt-3 text-xs text-gray-500 dark:text-paper-400">
            El signo lo decide el tipo, no el formulario. En una corrección de conteo, la cantidad del
            sistema se lee bloqueada al confirmar: lo que se vea aquí es orientativo.
        </p>
    </div>
</div>
