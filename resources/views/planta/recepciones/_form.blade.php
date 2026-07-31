{{-- Formulario de recepción: cabecera + líneas dinámicas.

     Las líneas se manejan con Alpine porque una recepción real trae varias y
     obligar a guardar entre línea y línea sería un castigo. Es deliberadamente
     lo más simple que funciona: un arreglo, un botón de añadir y otro de quitar.

     La CANTIDAD BASE que se muestra es solo una ayuda visual. No se envía y, si
     se enviara, el servidor la descartaría: siempre se recalcula en backend al
     guardar y otra vez al confirmar. --}}
@php
    $recepcion = $recepcion ?? null;

    $lineasPrevias = old('detalles', $recepcion?->detalles
        ->map(fn ($d) => [
            'id' => $d->id,
            'planta_insumo_id' => $d->planta_insumo_id,
            'planta_lote_id' => $d->planta_lote_id,
            'cantidad_recibida' => (string) $d->cantidad_recibida,
            'unidad_recibida' => $d->unidad_recibida,
            'contenido_por_unidad' => (string) $d->contenido_por_unidad,
            'factor_conversion' => (string) $d->factor_conversion,
            'estado_destino' => $d->estado_destino->value,
            'lote_codigo_proveedor' => $d->lote_codigo_proveedor,
            'fecha_elaboracion' => $d->fecha_elaboracion?->toDateString(),
            'fecha_vencimiento' => $d->fecha_vencimiento?->toDateString(),
            'observaciones' => $d->observaciones,
        ])->values()->all() ?? []);

    // Datos del catálogo que la interfaz usa para pre-rellenar la línea nueva.
    $catalogoInsumos = $insumos->mapWithKeys(fn ($i) => [$i->id => [
        'unidad_base' => $i->unidad_base->value,
        'controla_lotes' => (bool) $i->controla_lotes,
        'unidad_sugerida' => $i->unidad_recepcion_sugerida,
        'contenido_sugerido' => $i->contenido_sugerido ? (string) $i->contenido_sugerido : null,
        'factor_sugerido' => $i->factor_conversion_sugerido ? (string) $i->factor_conversion_sugerido : null,
    ]]);

    $etiqueta = 'block text-sm font-medium text-gray-700 dark:text-paper-200';
    $campo = 'mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
    $celda = 'w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100';
@endphp

{{-- Cabecera --}}
<div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
    <div>
        <label for="fecha" class="{{ $etiqueta }}">Fecha de entrada</label>
        <input type="date" name="fecha" id="fecha" required
               value="{{ old('fecha', $recepcion?->fecha?->toDateString() ?? now()->toDateString()) }}"
               class="{{ $campo }}">
        <p class="mt-1 text-xs text-gray-500 dark:text-paper-400">Cuándo llegó la mercancía, no cuándo se captura.</p>
        @error('fecha')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="planta_ubicacion_id" class="{{ $etiqueta }}">Ubicación de entrada</label>
        <select name="planta_ubicacion_id" id="planta_ubicacion_id" required class="{{ $campo }}">
            <option value="">Selecciona…</option>
            @foreach ($ubicaciones as $ubicacion)
                <option value="{{ $ubicacion->id }}"
                    @selected(old('planta_ubicacion_id', $recepcion?->planta_ubicacion_id) == $ubicacion->id)>
                    {{ $ubicacion->codigo }} — {{ $ubicacion->nombre }}
                </option>
            @endforeach
        </select>
        @error('planta_ubicacion_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="planta_proveedor_id" class="{{ $etiqueta }}">Proveedor</label>
        <select name="planta_proveedor_id" id="planta_proveedor_id" class="{{ $campo }}">
            <option value="">Sin proveedor</option>
            @foreach ($proveedores as $proveedor)
                <option value="{{ $proveedor->id }}"
                    @selected(old('planta_proveedor_id', $recepcion?->planta_proveedor_id) == $proveedor->id)>
                    {{ $proveedor->nombre }}
                </option>
            @endforeach
        </select>
        @error('planta_proveedor_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="documento_referencia" class="{{ $etiqueta }}">Documento de referencia</label>
        <input type="text" name="documento_referencia" id="documento_referencia" maxlength="60"
               value="{{ old('documento_referencia', $recepcion?->documento_referencia) }}"
               placeholder="Factura, remisión o guía" class="{{ $campo }}">
        @error('documento_referencia')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="responsable_user_id" class="{{ $etiqueta }}">Responsable (usuario)</label>
        <select name="responsable_user_id" id="responsable_user_id" class="{{ $campo }}">
            <option value="">Sin usuario</option>
            @foreach ($usuarios as $usuario)
                <option value="{{ $usuario->id }}"
                    @selected(old('responsable_user_id', $recepcion?->responsable_user_id) == $usuario->id)>
                    {{ $usuario->name }}
                </option>
            @endforeach
        </select>
        @error('responsable_user_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="responsable_nombre" class="{{ $etiqueta }}">Responsable (nombre)</label>
        <input type="text" name="responsable_nombre" id="responsable_nombre" maxlength="120"
               value="{{ old('responsable_nombre', $recepcion?->responsable_nombre) }}"
               placeholder="Quien recibió, si no tiene cuenta" class="{{ $campo }}">
        @error('responsable_nombre')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div class="sm:col-span-2 lg:col-span-3">
        <label for="observaciones" class="{{ $etiqueta }}">Observaciones</label>
        <textarea name="observaciones" id="observaciones" rows="2" class="{{ $campo }}">{{ old('observaciones', $recepcion?->observaciones) }}</textarea>
        @error('observaciones')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
</div>

{{-- Líneas --}}
<div class="pt-5 border-t border-gray-100 dark:border-ink-700"
     x-data="{
        catalogo: {{ Js::from($catalogoInsumos) }},
        lineas: {{ Js::from($lineasPrevias) }},
        agregar() {
            this.lineas.push({
                id: null, planta_insumo_id: '', planta_lote_id: '',
                cantidad_recibida: '', unidad_recibida: '', contenido_por_unidad: '',
                factor_conversion: '1', estado_destino: 'disponible',
                lote_codigo_proveedor: '', fecha_elaboracion: '', fecha_vencimiento: '', observaciones: '',
            });
        },
        quitar(i) { this.lineas.splice(i, 1); },
        alElegirInsumo(linea) {
            const datos = this.catalogo[linea.planta_insumo_id];
            if (! datos) return;
            // Solo rellena lo que esté vacío: no pisa lo que la persona escribió.
            if (! linea.unidad_recibida && datos.unidad_sugerida) linea.unidad_recibida = datos.unidad_sugerida;
            if (! linea.contenido_por_unidad && datos.contenido_sugerido) linea.contenido_por_unidad = datos.contenido_sugerido;
            if ((! linea.factor_conversion || linea.factor_conversion === '1') && datos.factor_sugerido) linea.factor_conversion = datos.factor_sugerido;
        },
        base(linea) {
            const n = (v) => { const x = parseFloat(v); return isNaN(x) ? 0 : x; };
            const r = n(linea.cantidad_recibida) * n(linea.contenido_por_unidad) * n(linea.factor_conversion);
            return r > 0 ? r.toFixed(4) : '—';
        },
        unidad(linea) {
            const datos = this.catalogo[linea.planta_insumo_id];
            return datos ? datos.unidad_base : '';
        },
     }"
     x-init="if (lineas.length === 0) agregar()">

    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-semibold text-gray-800 dark:text-paper-100">Líneas recibidas</h3>
        <button type="button" @click="agregar()"
                class="rounded-md bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-200 dark:hover:bg-ink-600">
            + Añadir línea
        </button>
    </div>

    @error('detalles')<p class="mb-2 text-xs text-red-600">{{ $message }}</p>@enderror

    <div class="space-y-4">
        <template x-for="(linea, i) in lineas" :key="i">
            <div class="rounded-lg border border-gray-200 p-4 dark:border-ink-600">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <input type="hidden" :name="`detalles[${i}][id]`" :value="linea.id ?? ''">

                    <div class="lg:col-span-2">
                        <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Insumo</label>
                        <select :name="`detalles[${i}][planta_insumo_id]`" x-model="linea.planta_insumo_id"
                                @change="alElegirInsumo(linea)" required class="{{ $celda }}">
                            <option value="">Selecciona…</option>
                            @foreach ($insumos as $insumo)
                                <option value="{{ $insumo->id }}">{{ $insumo->codigo }} — {{ $insumo->nombre }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Cantidad recibida</label>
                        <input type="number" step="0.0001" min="0.0001" required
                               :name="`detalles[${i}][cantidad_recibida]`" x-model="linea.cantidad_recibida"
                               class="{{ $celda }}">
                    </div>

                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Unidad de compra</label>
                        <input type="text" maxlength="30" required placeholder="saco, caja, kg…"
                               :name="`detalles[${i}][unidad_recibida]`" x-model="linea.unidad_recibida"
                               class="{{ $celda }}">
                    </div>

                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Contenido por unidad</label>
                        <input type="number" step="0.0001" min="0.0001" required
                               :name="`detalles[${i}][contenido_por_unidad]`" x-model="linea.contenido_por_unidad"
                               class="{{ $celda }}">
                    </div>

                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Factor de conversión</label>
                        <input type="number" step="0.00000001" min="0.00000001" required
                               :name="`detalles[${i}][factor_conversion]`" x-model="linea.factor_conversion"
                               class="{{ $celda }}">
                    </div>

                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Entra al inventario</label>
                        <p class="mt-1.5 text-sm font-semibold text-gray-800 dark:text-paper-100">
                            <span x-text="base(linea)"></span>
                            <span class="text-xs font-normal text-gray-500 dark:text-paper-400" x-text="unidad(linea)"></span>
                        </p>
                        <p class="text-[11px] text-gray-400">Cálculo de referencia; el servidor lo recalcula.</p>
                    </div>

                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Destino</label>
                        <select :name="`detalles[${i}][estado_destino]`" x-model="linea.estado_destino" class="{{ $celda }}">
                            <option value="disponible">Disponible</option>
                            @can('planta.calidad.gestionar')
                                <option value="retenido">Retenido</option>
                            @endcan
                        </select>
                    </div>

                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Lote del proveedor</label>
                        <input type="text" maxlength="60" :name="`detalles[${i}][lote_codigo_proveedor]`"
                               x-model="linea.lote_codigo_proveedor" class="{{ $celda }}">
                    </div>

                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Elaboración</label>
                        <input type="date" :name="`detalles[${i}][fecha_elaboracion]`"
                               x-model="linea.fecha_elaboracion" class="{{ $celda }}">
                    </div>

                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-paper-400">Vencimiento</label>
                        <input type="date" :name="`detalles[${i}][fecha_vencimiento]`"
                               x-model="linea.fecha_vencimiento" class="{{ $celda }}">
                    </div>

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
        El lote se resuelve al confirmar: los insumos con control de lotes reciben un lote interno nuevo,
        y los que no lo controlan usan su lote genérico.
    </p>
</div>
