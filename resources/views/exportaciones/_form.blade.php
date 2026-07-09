@php
    $e = $exportacion ?? null;

    // Clientes de exportación activos con su lista de precios (producto_id => precio).
    $clientesJs = $clientes->map(fn ($c) => [
        'id' => (string) $c->id,
        'nombre' => $c->nombre,
        'direccion' => (string) $c->direccion,
        'fda' => (string) $c->fda_reg_number,
        'precios' => $c->productos->mapWithKeys(fn ($a) => [(string) $a->exportacion_producto_id => (float) $a->precio_caja]),
    ])->values();

    // Catálogo maestro activo (precio_base puede ser null: solo referencia).
    $productosJs = $productos->map(fn ($p) => [
        'id' => (string) $p->id,
        'nombre' => $p->nombre_es,
        'nombre_en' => (string) $p->nombre_en,
        'unidad' => (string) $p->unidad,
        'upc' => (int) $p->unidades_por_caja,
        'precio_base' => $p->precio_caja !== null ? (float) $p->precio_caja : null,
        'neto' => (float) $p->peso_neto_caja_kg,
        'bruto' => (float) $p->peso_bruto_caja_kg,
    ])->values();

    // Filas iniciales: old() tras un error de validación, o los items guardados.
    $snapshotDe = fn ($id) => $e?->items?->firstWhere('id', (int) $id);
    $filas = collect(old('items') ?? ($e?->items ?? collect())->map(fn ($i) => [
            'id' => $i->id,
            'exportacion_producto_id' => $i->exportacion_producto_id,
            'cantidad_cajas' => $i->cantidad_cajas,
        ])->values()->all())
        ->map(function ($fila) use ($snapshotDe) {
            $item = ! empty($fila['id']) ? $snapshotDe($fila['id']) : null;

            return [
                'id' => $fila['id'] ?? null,
                'exportacion_producto_id' => (string) ($fila['exportacion_producto_id'] ?? ''),
                'cantidad_cajas' => (int) ($fila['cantidad_cajas'] ?? 1),
                // Datos de solo lectura para la vista previa (snapshot para existentes;
                // para filas nuevas los resuelve Alpine según el cliente elegido).
                'nombre' => $item?->nombre_es ?? '',
                'nombre_en' => $item?->nombre_en ?? '',
                'unidad' => $item?->unidad ?? '',
                'upc' => $item?->unidades_por_caja ?? 0,
                'precio' => $item !== null ? (float) $item->precio_caja : 0,
                'neto' => $item !== null ? (float) $item->peso_neto_caja_kg : 0,
                'bruto' => $item !== null ? (float) $item->peso_bruto_caja_kg : 0,
                'fuente' => $item !== null ? 'snapshot' : '',
                // Estado transitorio del buscador (combobox) de la fila.
                'busqueda' => '',
                'abierto' => false,
                'resaltado' => 0,
            ];
        })
        ->values();

    $encabezadoInicial = [
        'clienteId' => (string) old('exportacion_cliente_id', $e?->exportacion_cliente_id ?? ''),
        'nombre' => old('cliente_nombre', $e?->cliente_nombre ?? ''),
        'direccion' => old('cliente_direccion', $e?->cliente_direccion ?? ''),
        'fda' => old('fda_reg_number', $e?->fda_reg_number ?? ''),
    ];
@endphp

<div class="space-y-6"
     x-data="listaEmpaqueForm({{ Js::from($filas) }}, {{ Js::from($productosJs) }}, {{ Js::from($clientesJs) }}, {{ Js::from($encabezadoInicial) }})">

    <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 space-y-5">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Encabezado</h3>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Cliente de exportación *</label>
                <select name="exportacion_cliente_id" x-model="clienteId" @change="clienteCambiado()" required
                        class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    <option value="">— elegí un cliente —</option>
                    @foreach ($clientes as $c)
                        <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-400">
                    Al elegirlo se cargan nombre, dirección y FDA, y los productos con SU precio.
                    <a href="{{ route('exportaciones.clientes.index') }}" class="text-indigo-600 hover:underline">Gestionar clientes</a>
                </p>
                @error('exportacion_cliente_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Nombre del cliente (como saldrá en el Excel) *</label>
                <input type="text" name="cliente_nombre" x-model="encabezado.nombre" required
                       class="mt-1 w-full rounded-md border-gray-300 text-sm">
                @error('cliente_nombre') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Dirección del cliente</label>
                <input type="text" name="cliente_direccion" x-model="encabezado.direccion"
                       class="mt-1 w-full rounded-md border-gray-300 text-sm">
                @error('cliente_direccion') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">FDA reg. number</label>
                <input type="text" name="fda_reg_number" x-model="encabezado.fda"
                       class="mt-1 w-full rounded-md border-gray-300 text-sm">
                @error('fda_reg_number') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 border-t border-gray-100 pt-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Exportador *</label>
                <input type="text" name="exportador_nombre" value="{{ old('exportador_nombre', $e?->exportador_nombre ?? $defaults['exportador_nombre'] ?? '') }}" required
                       class="mt-1 w-full rounded-md border-gray-300 text-sm">
                @error('exportador_nombre') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Dirección del exportador</label>
                <input type="text" name="exportador_direccion" value="{{ old('exportador_direccion', $e?->exportador_direccion ?? $defaults['exportador_direccion'] ?? '') }}"
                       class="mt-1 w-full rounded-md border-gray-300 text-sm">
                @error('exportador_direccion') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Fecha *</label>
                <input type="date" name="fecha" value="{{ old('fecha', optional($e?->fecha)->format('Y-m-d') ?? now()->format('Y-m-d')) }}" required
                       class="mt-1 w-full rounded-md border-gray-300 text-sm">
                @error('fecha') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Factura</label>
                <input type="text" name="factura" value="{{ old('factura', $e?->factura) }}"
                       class="mt-1 w-full rounded-md border-gray-300 text-sm" placeholder="texto libre, opcional">
                @error('factura') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Observaciones</label>
                <input type="text" name="observaciones" value="{{ old('observaciones', $e?->observaciones) }}"
                       class="mt-1 w-full rounded-md border-gray-300 text-sm">
                @error('observaciones') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Productos</h3>
            <div class="flex items-center gap-4">
                <label class="inline-flex items-center gap-2 text-xs text-gray-500" x-show="clienteTieneLista()">
                    <input type="checkbox" x-model="mostrarTodo" class="rounded border-gray-300">
                    Mostrar todo el catálogo (los no asignados usan precio base)
                </label>
                <button type="button" @click="agregar()" :disabled="!clienteId"
                        :class="clienteId ? 'bg-gray-800 hover:bg-gray-700' : 'bg-gray-300 cursor-not-allowed'"
                        class="rounded-md px-3 py-1.5 text-sm font-medium text-white">+ Agregar producto</button>
            </div>
        </div>
        <p class="text-xs text-amber-600" x-show="clienteId && !clienteTieneLista()" x-cloak>
            Este cliente no tiene lista de precios propia: se muestra todo el catálogo con el PRECIO BASE como referencia.
        </p>
        @error('items') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200">
                        <th class="py-2 px-3 w-2/5">Producto</th>
                        <th class="py-2 px-3 text-right">Cajas</th>
                        <th class="py-2 px-3 text-right">Unid./caja</th>
                        <th class="py-2 px-3 text-right">Precio caja</th>
                        <th class="py-2 px-3 text-right">Precio unidad</th>
                        <th class="py-2 px-3 text-right">Valor</th>
                        <th class="py-2 px-3 text-right">Neto kg</th>
                        <th class="py-2 px-3 text-right">Bruto kg</th>
                        <th class="py-2 px-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <template x-for="(fila, idx) in filas" :key="fila.key">
                        <tr>
                            <td class="py-2 px-3">
                                {{-- Item existente: conserva su snapshot, solo se edita la cantidad. --}}
                                <template x-if="fila.id">
                                    <div>
                                        <input type="hidden" :name="`items[${idx}][id]`" :value="fila.id">
                                        <span class="font-medium text-gray-800" x-text="fila.nombre"></span>
                                        <span class="ms-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500" title="Precios y pesos congelados al agregarlo">snapshot</span>
                                    </div>
                                </template>
                                <template x-if="!fila.id">
                                    {{-- Buscador tipo combobox: filtra por nombre es/en, unidades, empaque y precios. --}}
                                    <div class="relative" @click.outside="fila.abierto = false">
                                        <input type="hidden" :name="`items[${idx}][exportacion_producto_id]`" :value="fila.exportacion_producto_id">
                                        <input type="text" autocomplete="off" spellcheck="false"
                                               :value="fila.abierto ? fila.busqueda : (fila.nombre || '')"
                                               @input="fila.busqueda = $event.target.value; fila.abierto = true; fila.resaltado = 0"
                                               @focus="fila.busqueda = ''; fila.abierto = true; fila.resaltado = 0"
                                               @keydown.down.prevent="fila.abierto = true; fila.resaltado = Math.min(fila.resaltado + 1, filtrados(fila).length - 1)"
                                               @keydown.up.prevent="fila.resaltado = Math.max(fila.resaltado - 1, 0)"
                                               @keydown.enter.prevent="elegirResaltado(fila)"
                                               @keydown.escape="fila.abierto = false"
                                               placeholder="Escribí para buscar producto…"
                                               class="w-full rounded-md border-gray-300 text-sm">

                                        {{-- Resultados --}}
                                        <div x-show="fila.abierto" x-cloak
                                             class="absolute left-0 z-20 mt-1 w-full min-w-[26rem] max-h-80 overflow-y-auto rounded-md border border-gray-200 bg-white shadow-lg">
                                            <template x-for="(p, i) in filtrados(fila)" :key="p.id">
                                                <button type="button" @click="seleccionar(fila, p)" @mouseenter="fila.resaltado = i"
                                                        :class="i === fila.resaltado ? 'bg-indigo-50' : ''"
                                                        class="block w-full border-b border-gray-50 px-3 py-2 text-left text-sm">
                                                    <div class="flex items-center gap-2">
                                                        <span class="font-medium text-gray-800" x-text="p.nombre"></span>
                                                        <span class="rounded-full bg-amber-100 px-1.5 py-0.5 text-xs text-amber-700" x-show="p.fuente === 'base'">precio base</span>
                                                    </div>
                                                    <div class="text-xs text-gray-400" x-show="p.nombre_en" x-text="p.nombre_en"></div>
                                                    <div class="text-xs text-gray-500" x-text="`${p.upc} unidades · ${p.unidad || 'sin empaque'}`"></div>
                                                    <div class="text-xs text-gray-600" x-text="`${dinero(p.precio)} caja · ${p.upc >= 1 ? dinero(p.precio / p.upc) : '—'} unidad`"></div>
                                                </button>
                                            </template>
                                            <div x-show="filtrados(fila).length === 0" class="px-3 py-3 text-center text-xs text-gray-400">
                                                Sin resultados. Probá con otro texto, o activá "Mostrar todo el catálogo".
                                            </div>
                                        </div>

                                        {{-- Producto elegido: detalle legible bajo el buscador. --}}
                                        <p class="mt-0.5 text-xs text-gray-500" x-show="fila.exportacion_producto_id && !fila.abierto" x-cloak
                                           x-text="`${fila.upc} unidades · ${fila.unidad || 'sin empaque'} · ${dinero(fila.precio)} caja · ${fila.upc >= 1 ? dinero(fila.precio / fila.upc) : '—'} unidad`"></p>
                                        <p class="mt-0.5 text-xs text-amber-600" x-show="fila.exportacion_producto_id && !fila.abierto && fila.fuente === 'base'" x-cloak>
                                            Sin precio propio del cliente: usa el precio base del catálogo.
                                        </p>
                                    </div>
                                </template>
                            </td>
                            <td class="py-2 px-3 text-right">
                                <input type="number" min="1" step="1" required :name="`items[${idx}][cantidad_cajas]`"
                                       x-model.number="fila.cantidad_cajas"
                                       class="w-20 rounded-md border-gray-300 text-sm text-right">
                            </td>
                            <td class="py-2 px-3 text-right text-gray-600" x-text="fila.upc || '—'"></td>
                            <td class="py-2 px-3 text-right text-gray-600">
                                <span x-text="dinero(fila.precio)"></span>
                            </td>
                            <td class="py-2 px-3 text-right text-gray-600" title="Precio caja ÷ unidades por caja"
                                x-text="fila.upc >= 1 ? dinero(fila.precio / fila.upc) : '—'"></td>
                            <td class="py-2 px-3 text-right font-medium text-gray-800" x-text="dinero(fila.precio * (fila.cantidad_cajas || 0))"></td>
                            <td class="py-2 px-3 text-right text-gray-600" x-text="peso(fila.neto * (fila.cantidad_cajas || 0))"></td>
                            <td class="py-2 px-3 text-right text-gray-600" x-text="peso(fila.bruto * (fila.cantidad_cajas || 0))"></td>
                            <td class="py-2 px-3 text-right">
                                <button type="button" @click="quitar(idx)" class="text-red-600 hover:underline text-xs">Quitar</button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="filas.length === 0">
                        <td colspan="9" class="py-6 text-center text-gray-400" x-text="clienteId ? 'Sin productos. Usá “+ Agregar producto”.' : 'Elegí primero el cliente de exportación.'"></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t border-gray-200 bg-gray-50 font-semibold text-gray-800">
                        <td class="py-2 px-3">Totales</td>
                        <td class="py-2 px-3 text-right" x-text="totalCajas"></td>
                        <td class="py-2 px-3"></td>
                        <td class="py-2 px-3"></td>
                        <td class="py-2 px-3"></td>
                        <td class="py-2 px-3 text-right" x-text="dinero(valorTotal)"></td>
                        <td class="py-2 px-3 text-right" x-text="peso(netoTotal)"></td>
                        <td class="py-2 px-3 text-right" x-text="peso(brutoTotal)"></td>
                        <td class="py-2 px-3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="text-xs text-gray-400">
            Vista previa aproximada: el Excel final calcula con las fórmulas de la plantilla.
            Los productos nuevos usan el precio del CLIENTE (o el base, avisando) y quedan congelados al guardar.
        </p>
    </div>
</div>

<script>
    function listaEmpaqueForm(filasIniciales, productos, clientes, encabezadoInicial) {
        let siguienteKey = 0;

        return {
            productos,
            clientes,
            clienteId: encabezadoInicial.clienteId || '',
            encabezado: {
                nombre: encabezadoInicial.nombre || '',
                direccion: encabezadoInicial.direccion || '',
                fda: encabezadoInicial.fda || '',
            },
            mostrarTodo: false,
            filas: (filasIniciales || []).map(f => ({ ...f, key: siguienteKey++ })),

            init() {
                // Repoblar precios de filas nuevas tras un error de validación.
                this.filas.filter(f => !f.id && f.exportacion_producto_id).forEach(f => this.aplicarProducto(f));
            },
            clienteActual() {
                return this.clientes.find(c => c.id === String(this.clienteId)) || null;
            },
            clienteTieneLista() {
                const c = this.clienteActual();
                return c !== null && Object.keys(c.precios).length > 0;
            },
            clienteCambiado() {
                const c = this.clienteActual();
                if (c) {
                    this.encabezado.nombre = c.nombre;
                    this.encabezado.direccion = c.direccion;
                    this.encabezado.fda = c.fda;
                }
                // Reprecificar las filas nuevas con la lista del cliente elegido; si un
                // producto elegido ya no está disponible para este cliente, se limpia.
                const idsVisibles = this.disponibles().map(p => p.id);
                this.filas.filter(f => !f.id && f.exportacion_producto_id).forEach(f => {
                    if (idsVisibles.includes(String(f.exportacion_producto_id))) {
                        this.aplicarProducto(f);
                    } else {
                        f.exportacion_producto_id = '';
                        f.nombre = ''; f.nombre_en = ''; f.unidad = ''; f.upc = 0;
                        f.precio = 0; f.neto = 0; f.bruto = 0; f.fuente = '';
                        f.busqueda = ''; f.abierto = false; f.resaltado = 0;
                    }
                });
            },
            // Catálogo visible: solo los asignados al cliente (con su precio), o todo el
            // catálogo con precio base si el cliente no tiene lista o se pide ver todo.
            disponibles() {
                const c = this.clienteActual();
                const conLista = this.clienteTieneLista();
                return this.productos
                    .map(p => {
                        const precioCliente = c ? c.precios[p.id] : undefined;
                        return {
                            ...p,
                            precio: precioCliente !== undefined ? precioCliente : (p.precio_base ?? 0),
                            fuente: precioCliente !== undefined ? 'cliente' : 'base',
                        };
                    })
                    .filter(p => !conLista || this.mostrarTodo || p.fuente === 'cliente');
            },
            agregar() {
                if (!this.clienteId) return;
                this.filas.push({
                    key: siguienteKey++,
                    id: null,
                    exportacion_producto_id: '',
                    cantidad_cajas: 1,
                    nombre: '', nombre_en: '', unidad: '', upc: 0, precio: 0, neto: 0, bruto: 0, fuente: '',
                    busqueda: '', abierto: false, resaltado: 0,
                });
            },
            quitar(idx) {
                this.filas.splice(idx, 1);
            },
            aplicarProducto(fila) {
                const p = this.disponibles().find(p => p.id === String(fila.exportacion_producto_id))
                    // Puede quedar fuera del filtro (cambió el cliente): buscar en todo el catálogo.
                    || this.productos.map(p => ({ ...p, precio: p.precio_base ?? 0, fuente: 'base' }))
                        .find(p => p.id === String(fila.exportacion_producto_id));
                if (p) {
                    const c = this.clienteActual();
                    const precioCliente = c ? c.precios[p.id] : undefined;
                    fila.nombre = p.nombre;
                    fila.nombre_en = p.nombre_en || '';
                    fila.unidad = p.unidad || '';
                    fila.upc = p.upc;
                    fila.neto = p.neto;
                    fila.bruto = p.bruto;
                    fila.precio = precioCliente !== undefined ? precioCliente : (p.precio_base ?? 0);
                    fila.fuente = precioCliente !== undefined ? 'cliente' : 'base';
                }
            },
            // --- Buscador (combobox) por fila ---
            normalizar(s) {
                // Sin acentos ni mayúsculas: "marañón" encuentra "maranon" y viceversa.
                return String(s ?? '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            },
            // Filtra por nombre es/en, unidades por caja, empaque y precios (caja y unidad).
            // Cada palabra escrita debe aparecer en algún campo. Máximo 40 resultados.
            filtrados(fila) {
                const lista = this.disponibles();
                const q = this.normalizar(fila.busqueda).trim();
                if (q === '') return lista.slice(0, 40);
                const tokens = q.split(/\s+/);
                return lista.filter(p => {
                    const pajar = this.normalizar(
                        `${p.nombre} ${p.nombre_en} ${p.unidad} ${p.upc} `
                        + `${(Number(p.precio) || 0).toFixed(2)} `
                        + `${p.upc >= 1 ? (p.precio / p.upc).toFixed(2) : ''}`
                    );
                    return tokens.every(t => pajar.includes(t));
                }).slice(0, 40);
            },
            seleccionar(fila, p) {
                fila.exportacion_producto_id = p.id;
                this.aplicarProducto(fila);
                fila.abierto = false;
                fila.busqueda = '';
            },
            elegirResaltado(fila) {
                if (!fila.abierto) return;
                const lista = this.filtrados(fila);
                if (lista.length > 0) this.seleccionar(fila, lista[Math.min(fila.resaltado, lista.length - 1)]);
            },
            get totalCajas() { return this.filas.reduce((s, f) => s + (Number(f.cantidad_cajas) || 0), 0); },
            get valorTotal() { return this.filas.reduce((s, f) => s + f.precio * (Number(f.cantidad_cajas) || 0), 0); },
            get netoTotal() { return this.filas.reduce((s, f) => s + f.neto * (Number(f.cantidad_cajas) || 0), 0); },
            get brutoTotal() { return this.filas.reduce((s, f) => s + f.bruto * (Number(f.cantidad_cajas) || 0), 0); },
            dinero(n) { return '$' + (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
            peso(n) { return (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 }); },
        };
    }
</script>
