@php
    $e = $lista ?? null;

    // Clientes de exportación activos con su lista de precios (producto_id => precio).
    $clientesJs = $clientes->map(fn ($c) => [
        'id' => (string) $c->id,
        'nombre' => $c->nombre,
        'direccion' => (string) $c->direccion,
        'fda' => (string) $c->fda_reg_number,
        'precios' => $c->productos->mapWithKeys(fn ($a) => [(string) $a->exportacion_producto_id => (float) $a->precio_caja]),
    ])->values();

    // Catálogo activo: una entrada por presentación, con su sección del catálogo.
    $productosJs = $productos->map(fn ($p) => [
        'id' => (string) $p->id,
        'nombre' => $p->nombre_es,
        'nombre_en' => (string) $p->nombre_en,
        'unidad' => (string) $p->unidad,
        'upc' => (int) $p->unidades_por_caja,
        // «Caja 12×12 · 144 u»: distingue presentaciones del mismo producto.
        'etiqueta' => $p->etiquetaEmpaque(),
        'gramos' => (float) $p->gramos_por_unidad,
        'precio_base' => $p->precio_caja !== null ? (float) $p->precio_caja : null,
        'neto' => (float) $p->peso_neto_caja_kg,
        'bruto' => (float) $p->peso_bruto_caja_kg,
        'categoria' => $p->base?->categoria?->label() ?? 'Otros',
        'orden' => $p->base?->categoria?->orden() ?? 99,
    ])->values();

    // Líneas iniciales: old() tras un error de validación, o los items guardados.
    // Las guardadas (con id) conservan su snapshot; las nuevas se resuelven en Alpine.
    $snapshotDe = fn ($id) => $e?->items?->firstWhere('id', (int) $id);
    $lineas = collect(old('items') ?? ($e?->items ?? collect())->map(fn ($i) => [
            'id' => $i->id,
            'exportacion_producto_id' => $i->exportacion_producto_id,
            'cantidad_cajas' => $i->cantidad_cajas,
            'precio_caja' => (float) $i->precio_caja,
        ])->values()->all())
        ->map(function ($fila) use ($snapshotDe) {
            $item = ! empty($fila['id']) ? $snapshotDe($fila['id']) : null;
            $precio = isset($fila['precio_caja']) && $fila['precio_caja'] !== '' && $fila['precio_caja'] !== null
                ? (float) $fila['precio_caja']
                : ($item !== null ? (float) $item->precio_caja : null);

            return [
                'id' => $item?->id,
                'producto_id' => (string) ($item?->exportacion_producto_id ?? $fila['exportacion_producto_id'] ?? ''),
                'cajas' => (int) ($fila['cantidad_cajas'] ?? 0) ?: '',
                'precio' => $precio,
                'nombre' => $item?->nombre_es ?? '',
                'nombre_en' => $item?->nombre_en ?? '',
                'etiqueta' => $item !== null ? \App\Support\Exportaciones\EmpaqueExportacion::etiqueta($item->unidad, (int) $item->unidades_por_caja) : '',
                'upc' => (int) ($item?->unidades_por_caja ?? 0),
                'neto' => $item !== null ? (float) $item->peso_neto_caja_kg : 0,
                'bruto' => $item !== null ? (float) $item->peso_bruto_caja_kg : 0,
            ];
        })
        ->filter(fn ($l) => $l['id'] !== null || $l['producto_id'] !== '')
        ->values();

    $encabezadoInicial = [
        'clienteId' => (string) old('exportacion_cliente_id', $e?->exportacion_cliente_id ?? ''),
        'nombre' => old('cliente_nombre', $e?->cliente_nombre ?? ''),
        'direccion' => old('cliente_direccion', $e?->cliente_direccion ?? ''),
        'fda' => old('fda_reg_number', $e?->fda_reg_number ?? ''),
    ];
@endphp

<div class="space-y-6"
     x-data="listaEmpaqueForm({{ Js::from($lineas) }}, {{ Js::from($productosJs) }}, {{ Js::from($clientesJs) }}, {{ Js::from($encabezadoInicial) }})">

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
                    Al elegirlo se cargan su nombre y dirección del directorio, y sus productos con SU precio.
                    <a href="{{ route('clientes.index', ['tipo_cliente' => 'exportacion']) }}" class="text-indigo-600 hover:underline">Ver clientes de exportación</a>
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
                {{-- FDA de la EMPRESA: ya no se teclea por lista. Sale de Configuración →
                     Parámetros fiscales, se muestra para que se vea qué va a imprimirse, y
                     viaja en un campo oculto para que la lista conserve su propio valor
                     histórico igual que el resto del encabezado. --}}
                <label class="block text-sm font-medium text-gray-700">FDA de la empresa</label>
                <input type="hidden" name="fda_reg_number" value="{{ old('fda_reg_number', $e?->fda_reg_number ?? $defaults['fda_reg_number'] ?? '') }}">
                <p class="mt-2 font-mono text-sm text-gray-800">
                    {{ old('fda_reg_number', $e?->fda_reg_number ?? $defaults['fda_reg_number'] ?? '') ?: '— sin configurar —' }}
                </p>
                <p class="mt-1 text-xs text-gray-400">Se configura una sola vez en Configuración → Parámetros fiscales.</p>
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
                {{-- El número de factura YA NO SE ESCRIBE. Sale de las FEX vinculadas: se
                     tecleaba a mano, se imprimía en el Excel antes de que la factura
                     existiera, y nadie conciliaba los dos números. --}}
                <label class="block text-sm font-medium text-gray-700">Factura</label>
                <p class="mt-2 text-sm {{ $e && $e->facturas()->isNotEmpty() ? 'font-mono text-gray-800' : 'text-gray-400' }}">
                    @if ($e && $e->facturas()->isNotEmpty())
                        {{ $e->textoFactura() }}
                    @else
                        Se completa sola al vincular la factura de exportación.
                    @endif
                </p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Observaciones</label>
                <input type="text" name="observaciones" value="{{ old('observaciones', $e?->observaciones) }}"
                       class="mt-1 w-full rounded-md border-gray-300 text-sm">
                @error('observaciones') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    {{-- HOJA DE PEDIDO. A cada cliente se le vende casi siempre lo mismo: se muestran
         sus productos con su precio y solo se escriben las cajas de este embarque.
         Lo que queda sin cajas no entra a la lista. --}}
    <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-4 sm:p-6 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Productos</h3>
                <p class="text-xs text-gray-500" x-show="clienteId" x-cloak>
                    Escribí las cajas de lo que lleva este pedido. Lo que queda vacío no entra.
                </p>
            </div>
            <p class="text-sm tabular-nums text-gray-700" x-show="clienteId" x-cloak>
                <span x-text="enviar().length"></span> productos ·
                <span x-text="totalCajas"></span> cajas ·
                <span x-text="peso(netoTotal)"></span> kg neto ·
                <strong x-text="dinero(valorTotal)"></strong>
            </p>
        </div>

        <p class="rounded-md bg-gray-50 px-3 py-6 text-center text-sm text-gray-500" x-show="!clienteId">
            Elegí primero el cliente de exportación: aparecen sus productos con su precio.
        </p>

        <div x-show="clienteId" x-cloak class="space-y-3">
            <div class="flex flex-wrap items-center gap-3">
                <div class="min-w-56 flex-1">
                    <label for="filtro_productos" class="sr-only">Buscar producto</label>
                    <input id="filtro_productos" type="search" x-model="filtro" autocomplete="off"
                           placeholder="Buscar en los productos del cliente… (maní, 12x18, coconut)"
                           class="w-full rounded-md border-gray-300 text-sm">
                </div>
                <button type="button" @click="soloPedido = !soloPedido" :aria-pressed="soloPedido"
                        :class="soloPedido ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
                        class="rounded-md border px-3 py-2 text-sm">
                    Solo lo del pedido (<span x-text="enviar().length"></span>)
                </button>
                <label class="inline-flex items-center gap-2 text-xs text-gray-500" x-show="clienteTieneLista()">
                    <input type="checkbox" x-model="mostrarTodo" class="rounded border-gray-300">
                    Todo el catálogo
                </label>
            </div>
            <p class="text-xs text-amber-600" x-show="!clienteTieneLista()">
                Este cliente todavía no tiene productos asignados: se muestra todo el catálogo con el precio base.
            </p>
            @error('items') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="overflow-x-auto rounded-lg border border-gray-200">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">Productos de la lista de empaque</caption>
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <th scope="col" class="py-2 px-3 min-w-[15rem]">Producto</th>
                            <th scope="col" class="py-2 px-3 text-right">Cajas</th>
                            <th scope="col" class="py-2 px-3 text-right">Precio caja</th>
                            <th scope="col" class="py-2 px-3 text-right">Valor</th>
                            <th scope="col" class="hidden py-2 px-3 text-right lg:table-cell">Neto kg</th>
                            <th scope="col" class="hidden py-2 px-3 text-right lg:table-cell">Bruto kg</th>
                        </tr>
                    </thead>
                    <template x-for="grupo in grupos()" :key="grupo.titulo">
                        <tbody class="divide-y divide-gray-100">
                            <tr class="bg-gray-50">
                                <th scope="colgroup" colspan="6" class="px-3 py-1.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-500" x-text="grupo.titulo"></th>
                            </tr>
                            <template x-for="l in grupo.lineas" :key="l.key">
                                <tr :class="Number(l.estado.cajas) > 0 ? 'bg-indigo-50' : ''">
                                    <td class="py-2 px-3 align-top">
                                        <div class="font-medium text-gray-800" x-text="l.nombre"></div>
                                        <div class="text-xs text-gray-500">
                                            <span x-text="l.etiqueta"></span>
                                            <span x-show="l.gramos" x-text="` · ${l.gramos} g`"></span>
                                            <span class="italic" x-show="l.nombre_en" x-text="` · ${l.nombre_en}`"></span>
                                        </div>
                                        <span class="mt-0.5 inline-block rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600" x-show="l.tipo === 'guardada'"
                                              title="Ya estaba en la lista: conserva los pesos con los que se agregó">ya en la lista</span>
                                        <span class="mt-0.5 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700" x-show="l.tipo === 'nueva' && l.conPrecioBase">precio base</span>
                                    </td>
                                    <td class="py-2 px-3 text-right align-top">
                                        <input type="number" min="0" step="1" inputmode="numeric" x-model="l.estado.cajas" placeholder="—"
                                               :aria-label="`Cajas de ${l.nombre}, ${l.etiqueta}`"
                                               class="w-20 rounded-md border-gray-300 text-right text-sm tabular-nums">
                                    </td>
                                    <td class="py-2 px-3 text-right align-top">
                                        <input type="number" min="0" step="0.01" :value="precioTexto(l)" @input="fijarPrecio(l, $event.target.value)"
                                               :aria-label="`Precio por caja de ${l.nombre}, ${l.etiqueta}`"
                                               class="w-24 rounded-md border-gray-300 text-right text-sm tabular-nums">
                                        <p class="mt-0.5 ml-auto max-w-[10rem] text-right text-xs text-amber-600" x-show="avisoPrecio(l)" x-text="avisoPrecio(l)"></p>
                                    </td>
                                    <td class="py-2 px-3 text-right align-top font-medium tabular-nums text-gray-800"
                                        x-text="Number(l.estado.cajas) > 0 ? dinero(precioDe(l) * l.estado.cajas) : ''"></td>
                                    <td class="hidden py-2 px-3 text-right align-top tabular-nums text-gray-600 lg:table-cell"
                                        x-text="Number(l.estado.cajas) > 0 ? peso(l.neto * l.estado.cajas) : ''"></td>
                                    <td class="hidden py-2 px-3 text-right align-top tabular-nums text-gray-600 lg:table-cell"
                                        x-text="Number(l.estado.cajas) > 0 ? peso(l.bruto * l.estado.cajas) : ''"></td>
                                </tr>
                            </template>
                        </tbody>
                    </template>
                    <tbody x-show="grupos().length === 0">
                        <tr>
                            <td colspan="6" class="py-6 text-center text-sm text-gray-400"
                                x-text="soloPedido ? 'Todavía no hay cajas en este pedido.' : 'Ningún producto coincide con la búsqueda.'"></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-gray-200 bg-gray-50 font-semibold text-gray-800">
                            <td class="py-2 px-3">Totales</td>
                            <td class="py-2 px-3 text-right tabular-nums" x-text="totalCajas"></td>
                            <td class="py-2 px-3"></td>
                            <td class="py-2 px-3 text-right tabular-nums" x-text="dinero(valorTotal)"></td>
                            <td class="hidden py-2 px-3 text-right tabular-nums lg:table-cell" x-text="peso(netoTotal)"></td>
                            <td class="hidden py-2 px-3 text-right tabular-nums lg:table-cell" x-text="peso(brutoTotal)"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- Solo viajan las líneas con cajas, con índices seguidos. --}}
            <template x-for="(l, i) in enviar()" :key="l.key">
                <div hidden>
                    <input type="hidden" :name="`items[${i}][id]`" :value="l.estado.id ?? ''">
                    <input type="hidden" :name="`items[${i}][exportacion_producto_id]`" :value="l.pid">
                    <input type="hidden" :name="`items[${i}][cantidad_cajas]`" :value="l.estado.cajas">
                    <input type="hidden" :name="`items[${i}][precio_caja]`" :value="precioDe(l)">
                </div>
            </template>

            <p class="text-xs text-gray-400">
                Vista previa aproximada: el Excel final calcula con las fórmulas de la plantilla. Si cambiás un precio, al
                finalizar la lista ese pasa a ser el del cliente.
            </p>
        </div>
    </div>
</div>

<script>
    function listaEmpaqueForm(lineasIniciales, productos, clientes, encabezadoInicial) {
        const iniciales = lineasIniciales || [];

        return {
            productos,
            clientes,
            clienteId: encabezadoInicial.clienteId || '',
            encabezado: {
                nombre: encabezadoInicial.nombre || '',
                direccion: encabezadoInicial.direccion || '',
                fda: encabezadoInicial.fda || '',
            },
            filtro: '',
            soloPedido: false,
            mostrarTodo: false,
            // Líneas que ya estaban en la lista: conservan su snapshot de nombre y pesos.
            guardadas: iniciales.filter(l => l.id).map(l => ({ ...l, estado: { id: l.id, cajas: l.cajas, precio: l.precio } })),
            // Una por presentación del catálogo: cajas y precio (null = el vigente).
            nuevas: {},

            init() {
                this.productos.forEach(p => { this.nuevas[p.id] = { id: null, cajas: '', precio: null }; });
                iniciales.filter(l => !l.id && this.nuevas[l.producto_id]).forEach(l => {
                    Object.assign(this.nuevas[l.producto_id], { cajas: l.cajas, precio: l.precio });
                });
                // Al editar, arrancar mostrando lo que ya lleva el pedido.
                this.soloPedido = this.guardadas.length > 0;
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
                // Otro cliente, otros precios: los escritos a mano se descartan.
                Object.values(this.nuevas).forEach(n => { n.precio = null; });
            },
            producto(id) {
                return this.productos.find(p => p.id === String(id)) || null;
            },
            vigente(pid) {
                const c = this.clienteActual();
                return c ? c.precios[pid] : undefined;
            },
            lineas() {
                if (!this.clienteId) return [];
                const conLista = this.clienteTieneLista();
                const yaGuardadas = new Set(this.guardadas.map(g => String(g.producto_id)));
                const out = this.guardadas.map(g => {
                    const p = this.producto(g.producto_id);
                    return {
                        key: 'i' + g.id, tipo: 'guardada', estado: g.estado, pid: g.producto_id,
                        nombre: g.nombre, nombre_en: g.nombre_en, etiqueta: g.etiqueta, gramos: p ? p.gramos : null,
                        upc: g.upc, neto: g.neto, bruto: g.bruto,
                        categoria: p ? p.categoria : 'Otros', orden: p ? p.orden : 99,
                    };
                });
                this.productos.forEach(p => {
                    if (yaGuardadas.has(p.id)) return;
                    const estado = this.nuevas[p.id];
                    const delCliente = this.vigente(p.id) !== undefined;
                    if (conLista && !this.mostrarTodo && !delCliente && !(Number(estado.cajas) > 0)) return;
                    out.push({
                        key: 'p' + p.id, tipo: 'nueva', estado, pid: p.id,
                        nombre: p.nombre, nombre_en: p.nombre_en, etiqueta: p.etiqueta, gramos: p.gramos,
                        upc: p.upc, neto: p.neto, bruto: p.bruto,
                        categoria: p.categoria, orden: p.orden, conPrecioBase: !delCliente,
                    });
                });
                return out;
            },
            normalizar(s) {
                // Sin acentos ni mayúsculas: "marañón" encuentra "maranon"; "12×18" encuentra "12x18".
                return String(s ?? '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/×/g, 'x');
            },
            grupos() {
                const tokens = this.normalizar(this.filtro).trim().split(/\s+/).filter(Boolean);
                const visibles = this.lineas().filter(l => {
                    if (this.soloPedido && !(Number(l.estado.cajas) > 0)) return false;
                    if (tokens.length === 0) return true;
                    const pajar = this.normalizar(`${l.nombre} ${l.nombre_en} ${l.etiqueta} ${l.gramos ?? ''}g`);
                    return tokens.every(t => pajar.includes(t));
                });
                visibles.sort((a, b) => a.orden - b.orden
                    || this.normalizar(a.nombre).localeCompare(this.normalizar(b.nombre)) || a.upc - b.upc);
                const grupos = [];
                visibles.forEach(l => {
                    const ultimo = grupos[grupos.length - 1];
                    if (ultimo && ultimo.titulo === l.categoria) ultimo.lineas.push(l);
                    else grupos.push({ titulo: l.categoria, lineas: [l] });
                });
                return grupos;
            },
            // Todas las líneas con cajas, estén o no a la vista por el filtro.
            enviar() {
                return this.lineas().filter(l => Number(l.estado.cajas) > 0);
            },
            precioDe(l) {
                if (l.estado.precio !== null && l.estado.precio !== '') return Number(l.estado.precio);
                const v = this.vigente(l.pid);
                if (v !== undefined) return v;
                const p = this.producto(l.pid);
                return p && p.precio_base !== null ? p.precio_base : 0;
            },
            // Lo escrito a mano se muestra tal cual (no se reformatea mientras se escribe).
            precioTexto(l) {
                if (l.estado.precio !== null && l.estado.precio !== '') return l.estado.precio;
                return this.precioDe(l).toFixed(2);
            },
            fijarPrecio(l, valor) {
                l.estado.precio = valor === '' ? null : valor;
            },
            // Qué pasa con el precio de la línea al finalizar la lista.
            avisoPrecio(l) {
                if (!(Number(l.estado.cajas) > 0)) return '';
                const precio = this.precioDe(l);
                if (precio <= 0) return 'Falta el precio.';
                const v = this.vigente(l.pid);
                if (v === undefined) return 'Al finalizar queda como su precio.';
                return Math.abs(precio - v) > 0.004 ? `Antes ${this.dinero(v)}: al finalizar cambia.` : '';
            },
            get totalCajas() { return this.enviar().reduce((s, l) => s + (Number(l.estado.cajas) || 0), 0); },
            get valorTotal() { return this.enviar().reduce((s, l) => s + this.precioDe(l) * (Number(l.estado.cajas) || 0), 0); },
            get netoTotal() { return this.enviar().reduce((s, l) => s + l.neto * (Number(l.estado.cajas) || 0), 0); },
            get brutoTotal() { return this.enviar().reduce((s, l) => s + l.bruto * (Number(l.estado.cajas) || 0), 0); },
            dinero(n) { return '$' + (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
            peso(n) { return (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 }); },
        };
    }
</script>
