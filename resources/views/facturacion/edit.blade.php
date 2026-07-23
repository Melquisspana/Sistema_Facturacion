<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Editar borrador {{ $dte->tipo_dte->label() }} #{{ $dte->id }}
        </h2>
    </x-slot>

    @php
        $esFex = $dte->tipo_dte === \App\Enums\TipoDte::FacturaExportacion;

        // Generar: botón deshabilitado sin líneas + confirm con resumen del documento.
        $sinLineas = $dte->lineas->isEmpty();
        $confirmGenerar = 'Generar '.$dte->tipo_dte->label().":\n\n"
            .'Cliente: '.($dte->cliente?->nombre ?? 'Consumidor final')."\n"
            .'Sala: '.($dte->clienteSucursal?->nombre ?? '—')."\n"
            .'Orden de compra: '.($dte->numero_orden_compra ?? '—')."\n"
            .'Productos: '.$dte->lineas->count()."\n"
            .'Total a pagar: $'.number_format((float) $dte->total_pagar, 2)."\n\n"
            .'Se consume el correlativo interno y el documento ya no podrá editarse. ¿Continuar?';
    @endphp
    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Mensajes en vivo del editor AJAX (éxito/error sin recargar). Vacío por defecto. --}}
            <div id="ccf-flash" role="status" aria-live="polite" class="hidden rounded-md border p-3 text-sm"></div>

            {{-- Aviso SUAVE de OC duplicada: solo advierte (con link); no bloquea generar. --}}
            @if ($ocDuplicada ?? null)
                <div class="rounded-md bg-amber-50 border border-amber-300 p-3 text-sm text-amber-800">
                    <strong>⚠ La orden de compra {{ $dte->numero_orden_compra }} ya se usó en el CCF
                    <a href="{{ route('facturacion.show', $ocDuplicada) }}" class="underline font-semibold">
                        {{ $ocDuplicada->numero_control ?? $ocDuplicada->numero_interno ?? ('#'.$ocDuplicada->id) }}</a>.</strong>
                    Verificá que no estés facturando dos veces la misma orden. Podés generar igual si es correcto.
                </div>
            @endif

            {{-- Encabezado compacto: solo información clave del documento + Generar. --}}
            <div class="bg-white shadow sm:rounded-lg p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center flex-wrap gap-x-2 gap-y-1">
                            <span class="font-semibold text-gray-800">{{ $dte->cliente?->nombre ?? 'Sin cliente' }}</span>
                            @if ($dte->clienteSucursal?->nombre)
                                <span class="text-indigo-600 text-sm">— {{ $dte->clienteSucursal->nombre }}</span>
                            @endif
                            <x-estado-dte-badge :estado="$dte->estado" />
                        </div>
                        <dl class="mt-1.5 flex flex-wrap gap-x-5 gap-y-1 text-xs text-gray-500">
                            <div><dt class="inline text-gray-400">Fecha:</dt> <dd class="inline">{{ $dte->fecha_emision?->format('d/m/Y') ?? '—' }}</dd></div>
                            <div><dt class="inline text-gray-400">Orden de compra:</dt> <dd class="inline">{{ $dte->numero_orden_compra ?? '—' }}</dd></div>
                            <div><dt class="inline text-gray-400">Emisor:</dt> <dd class="inline">{{ $dte->establecimiento?->nombre ?? '—' }} / {{ $dte->puntoVenta?->nombre ?? '—' }}</dd></div>
                            <div><dt class="inline text-gray-400">Retención IVA:</dt> <dd class="inline">{{ $dte->aplica_retencion_iva ? 'Sí' : 'No' }}</dd></div>
                            @if ($esFex && $dte->exportacionOrigen)
                                <div>
                                    <dt class="inline text-gray-400">Origen:</dt>
                                    <dd class="inline">Lista de Empaque
                                        <a href="{{ route('exportaciones.show', $dte->exportacionOrigen) }}" class="text-indigo-600 hover:underline">#{{ $dte->exportacionOrigen->id }}</a>
                                    </dd>
                                </div>
                            @endif
                        </dl>
                    </div>
                    @can('update', $dte)
                        <form method="POST" action="{{ route('facturacion.generar', $dte) }}"
                              onsubmit="return confirm(@js($confirmGenerar));">
                            @csrf
                            <button data-generar-btn @disabled($sinLineas)
                                    @if ($sinLineas) title="Agregá al menos un producto para generar." @endif
                                    class="inline-flex items-center px-4 py-2 text-white text-sm font-medium rounded-md {{ $sinLineas ? 'bg-gray-300 cursor-not-allowed' : 'bg-green-600 hover:bg-green-700' }}">
                                Generar
                            </button>
                        </form>
                    @endcan
                </div>
            </div>

            {{-- Receptor (solo FEX tipo 11): destino, documento, actividad, correo,
                 dirección y teléfono (si existe). Solo presentación. --}}
            @if ($datosReceptor ?? null)
                <div class="bg-white shadow sm:rounded-lg p-4">
                    <h3 class="font-semibold text-gray-700 mb-2">Receptor</h3>
                    <p class="font-medium text-gray-900 mb-2">{{ $datosReceptor['nombre'] }}</p>
                    <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-2 text-sm">
                        <div class="space-y-2">
                            <div><dt class="inline text-gray-500">Destino:</dt> <dd class="inline">{{ $datosReceptor['destino'] ?? '—' }}</dd></div>
                            <div><dt class="inline text-gray-500">Documento:</dt> <dd class="inline">{{ $datosReceptor['documento'] ?? '—' }}</dd></div>
                            <div><dt class="inline text-gray-500">Actividad:</dt> <dd class="inline">{{ $datosReceptor['actividad'] ?? '—' }}</dd></div>
                        </div>
                        <div class="space-y-2">
                            <div><dt class="inline text-gray-500">Correo:</dt> <dd class="inline break-all">{{ $datosReceptor['correo'] ?? '—' }}</dd></div>
                            <div><dt class="inline text-gray-500">Dirección:</dt> <dd class="inline">{{ $datosReceptor['direccion'] ?? '—' }}</dd></div>
                            @if ($datosReceptor['telefono'] ?? null)
                                <div><dt class="inline text-gray-500">Teléfono:</dt> <dd class="inline">{{ $datosReceptor['telefono'] }}</dd></div>
                            @endif
                        </div>
                    </dl>
                </div>
            @endif

            {{-- Datos aduaneros (solo FEX tipo 11, editable mientras siga en borrador):
                 tipo de ítem, aduana/recinto de salida, tipo de régimen, régimen e
                 incoterm. Guardar NO genera JSON, NO consume correlativo, NO firma. --}}
            @if ($esFex && ($catalogosAduaneros ?? null))
                @can('update', $dte)
                    <div class="bg-white shadow sm:rounded-lg p-4">
                        <h3 class="font-semibold text-gray-700 mb-1">Datos aduaneros</h3>
                        <p class="text-xs text-gray-400 mb-3">Aplican al documento completo. Se pueden cambiar hasta generar la factura.</p>
                        <form method="POST" action="{{ route('facturacion.datos-aduaneros.update', $dte) }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                            @csrf
                            @method('PATCH')
                            <div>
                                <x-input-label for="ad-tipo-item" value="Tipo de ítem exportado" />
                                <select id="ad-tipo-item" name="tipo_item_expor" required
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                                    @foreach ($catalogosAduaneros['tiposItemExpor'] as $tipo)
                                        <option value="{{ $tipo->value }}" @selected(old('tipo_item_expor', $dte->tipo_item_expor) == $tipo->value)>{{ $tipo->label() }}</option>
                                    @endforeach
                                </select>
                                @error('tipo_item_expor')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <x-input-label for="ad-recinto" value="Aduana / recinto de salida" />
                                <select id="ad-recinto" name="recinto_fiscal" required
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                                    @foreach ($catalogosAduaneros['recintosFiscales'] as $r)
                                        <option value="{{ $r->codigo }}" @selected(old('recinto_fiscal', $dte->recinto_fiscal) == $r->codigo)>{{ $r->valor }}</option>
                                    @endforeach
                                </select>
                                @error('recinto_fiscal')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <x-input-label for="ad-tipo-regimen" value="Tipo de régimen" />
                                <select id="ad-tipo-regimen" name="tipo_regimen" required
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                                    @foreach ($catalogosAduaneros['tiposRegimen'] as $tr)
                                        <option value="{{ $tr->codigo }}" @selected(old('tipo_regimen', $dte->tipo_regimen) == $tr->codigo)>{{ $tr->valor }}</option>
                                    @endforeach
                                </select>
                                @error('tipo_regimen')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <x-input-label for="ad-regimen" value="Régimen" />
                                <select id="ad-regimen" name="regimen" required
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                                    @foreach ($catalogosAduaneros['regimenes'] as $rg)
                                        <option value="{{ $rg->codigo }}" @selected(old('regimen', $dte->regimen) == $rg->codigo)>{{ $rg->valor }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-gray-400">{{ \App\Support\Dte\DatosExportacionPresentacion::AYUDA_REGIMEN }} No es un monto.</p>
                                @error('regimen')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <x-input-label for="ad-incoterm" value="Incoterm" />
                                <select id="ad-incoterm" name="cod_incoterms" required
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                                    @foreach ($catalogosAduaneros['incoterms'] as $inc)
                                        <option value="{{ $inc->codigo }}" @selected(old('cod_incoterms', $dte->cod_incoterms) == $inc->codigo)>{{ $inc->valor }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-gray-400">La descripción se resuelve automáticamente del catálogo al guardar.</p>
                                @error('cod_incoterms')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="sm:col-span-2 lg:col-span-5">
                                <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Guardar datos aduaneros</button>
                            </div>
                        </form>
                    </div>
                @endcan
            @endif

            {{-- Área de trabajo: productos (principal, ancho) + resumen (panel sticky).
                 En móvil el resumen va primero para que el total quede visible sin bajar. --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

                {{-- Principal: catálogo de productos disponibles con buscador grande. --}}
                @can('update', $dte)
                <div class="order-2 lg:order-1 lg:col-span-2">
                    <div class="bg-white shadow sm:rounded-lg p-5" x-data="{ filtro: '' }">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-semibold text-gray-700">Productos disponibles</h3>
                            <span class="text-xs text-gray-400">{{ count($productosDisponibles) }} producto(s) activos</span>
                        </div>

                        {{-- Modo escáner: pegar/escanear un código de barras y Enter agrega el producto
                             (o suma 1 a la cantidad si ya estaba en las líneas). No duplica línea. --}}
                        <div class="mb-4 rounded-lg border border-indigo-200 bg-indigo-50/60 p-3">
                            <form method="POST" action="{{ route('facturacion.productos.escanear', $dte) }}" data-ajax="scanner">
                                @csrf
                                <label for="escanear-barra" class="block text-sm font-medium text-indigo-900 mb-1">
                                    Escanear código de barras
                                </label>
                                <input id="escanear-barra" name="codigo_barra" type="text" autofocus
                                       autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                                       placeholder="Escaneá o escribí el código y presioná Enter…"
                                       onkeydown="if (event.key === 'Enter') { event.preventDefault(); this.form.requestSubmit(); }"
                                       class="block w-full border-indigo-300 rounded-lg shadow-sm py-2.5 text-base focus:border-indigo-500 focus:ring-indigo-500">
                                <p class="mt-1 text-xs text-indigo-700">Cada escaneo suma 1 a la cantidad si el producto ya está agregado.</p>
                                @error('codigo_barra')
                                    <p class="mt-1 text-xs text-rose-600 font-medium">{{ $message }}</p>
                                @enderror
                            </form>
                        </div>

                        {{-- Buscador grande y prominente (el listado ya está visible; filtrar es opcional). --}}
                        <div class="mb-4">
                            <x-input-label for="filtro-productos" value="Filtrar por nombre, código interno o código de barra" class="sr-only" />
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
                                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd"/></svg>
                                </span>
                                <input id="filtro-productos" type="text" x-model="filtro" autocomplete="off"
                                       placeholder="Buscar por nombre, código interno o código de barra…"
                                       class="block w-full border-gray-300 rounded-lg shadow-sm pl-10 py-2.5 text-base focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                        </div>

                        @if (count($productosDisponibles) === 0)
                            <p class="text-sm text-gray-400">No hay productos activos para agregar.</p>
                        @else
                            {{-- "relative": ancla aquí los <label class="sr-only"> de cada fila (uno
                                 por input de cantidad) para que el contenedor los recorte con su
                                 propio scroll, en vez de escaparse y estirar el alto real del
                                 documento completo (causaba una franja blanca al final de la página). --}}
                            <div class="relative overflow-x-auto max-h-[70vh] overflow-y-auto border border-gray-100 rounded-md">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50 sticky top-0 z-10">
                                        <tr class="text-left text-gray-500">
                                            <th class="px-3 py-2">Código</th>
                                            <th class="px-3 py-2">Código barra</th>
                                            <th class="px-3 py-2">Producto</th>
                                            <th class="px-3 py-2 text-right">Precio aplicado</th>
                                            <th class="px-3 py-2">Cantidad</th>
                                            <th class="px-3 py-2">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($productosDisponibles as $p)
                                            <tr x-show="filtro === '' || @js($p['filtro']).includes(filtro.toLowerCase().trim())"
                                                class="hover:bg-gray-50">
                                                <td class="px-3 py-2 font-mono">{{ $p['codigo'] ?? '—' }}</td>
                                                <td class="px-3 py-2 font-mono text-gray-500">{{ $p['codigo_barra'] ?? '—' }}</td>
                                                <td class="px-3 py-2 font-medium">{{ $p['nombre'] }}</td>
                                                <td class="px-3 py-2 text-right">
                                                    @if ($p['sin_precio'])
                                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">Sin precio</span>
                                                    @else
                                                        <span class="font-mono">${{ $p['precio_fmt'] }}</span>
                                                        <span class="block text-[10px] {{ $p['es_especial'] ? 'text-indigo-600' : 'text-gray-400' }}">{{ $p['origen_label'] }}</span>
                                                    @endif
                                                </td>
                                                @if ($p['sin_precio'])
                                                    <td colspan="2" class="px-3 py-2 text-xs text-gray-400">No se puede agregar sin precio.</td>
                                                @else
                                                    @php $qty = $cantidadesPorProducto[$p['id']] ?? null; @endphp
                                                    <td colspan="2" class="px-3 py-2">
                                                        {{-- Auto-agregar: al escribir una cantidad (>0) se agrega/actualiza la línea;
                                                             0 o vacío la quita. Idempotente por producto (no duplica). El botón es respaldo. --}}
                                                        <form method="POST" action="{{ route('facturacion.productos.cantidad', [$dte, $p['id']]) }}"
                                                              data-ajax="cantidad" data-producto="{{ $p['id'] }}" class="flex items-end gap-2">
                                                            @csrf
                                                            <div>
                                                                <label class="sr-only" for="cant-add-{{ $p['id'] }}">Cantidad</label>
                                                                {{-- Cantidad entera: step 1, min 0 (0/vacío quita la línea). --}}
                                                                <input id="cant-add-{{ $p['id'] }}" type="number" name="cantidad"
                                                                       value="{{ $qty ?? '' }}" step="1" min="0" inputmode="numeric" placeholder="0"
                                                                       autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                                                                       onchange="this.form.requestSubmit()"
                                                                       class="block w-20 border-gray-300 rounded-md shadow-sm text-sm {{ $qty ? 'ring-1 ring-indigo-300 bg-indigo-50/50' : '' }}">
                                                            </div>
                                                            <button class="px-3 py-2 {{ $qty ? 'bg-gray-600 hover:bg-gray-700' : 'bg-indigo-600 hover:bg-indigo-700' }} text-white text-sm rounded-md">
                                                                {{ $qty ? 'Actualizar' : 'Agregar' }}
                                                            </button>
                                                        </form>
                                                    </td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        @if ($esFex)
                            {{-- Línea SIN producto de catálogo nacional: para ítems de exportación que no
                                 están en el catálogo (p. ej. copiados de una Lista de Empaque). --}}
                            <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50/60 p-3">
                                <h4 class="text-sm font-medium text-amber-900 mb-2">Agregar línea libre (sin producto de catálogo)</h4>
                                <form method="POST" action="{{ route('facturacion.lineas-libres.store', $dte) }}" class="flex flex-wrap items-end gap-3">
                                    @csrf
                                    <div class="grow min-w-[14rem]">
                                        <label class="block text-xs font-medium text-gray-600">Descripción</label>
                                        <input type="text" name="descripcion" required maxlength="1000"
                                               class="mt-1 w-full rounded-md border-gray-300 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600">Cajas</label>
                                        <input type="number" name="cantidad" required min="1" step="1"
                                               class="mt-1 w-24 rounded-md border-gray-300 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600">Precio por caja ($)</label>
                                        <input type="number" name="precio_unitario" required min="0" step="0.01"
                                               class="mt-1 w-28 rounded-md border-gray-300 text-sm">
                                    </div>
                                    <input type="hidden" name="unidad_codigo" value="99">
                                    <button class="rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">Agregar</button>
                                </form>
                                @error('descripcion') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                @error('cantidad') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                @error('precio_unitario') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    </div>
                </div>
                @endcan

                {{-- Panel lateral: resumen del CCF (productos agregados + totales + Generar), sticky. --}}
                <div class="order-1 lg:order-2 @cannot('update', $dte) lg:col-span-3 @endcannot">
                    <div class="lg:sticky lg:top-6" id="resumen-panel">
                        @include('facturacion.partials.resumen-ccf', ['dte' => $dte, 'esAgenteRetencion' => $esAgenteRetencion ?? null, 'confirmGenerar' => $confirmGenerar])
                    </div>
                </div>
            </div>

            <div>
                <a href="{{ route('facturacion.index') }}" class="text-sm text-gray-500 hover:underline">← Volver al listado</a>
            </div>
        </div>
    </div>
</x-app-layout>
