{{--
    Perfil de EXPORTACIÓN dentro de la ficha del cliente.

    El cliente es uno solo. Esto no es otro directorio ni otro registro paralelo:
    es la parte internacional del MISMO cliente, y por eso vive acá y no en un
    módulo aparte. Nombre, documento, país y dirección fiscal no se repiten —se
    leen de la ficha de arriba—; lo único que se pide es lo que el directorio no
    guarda.

    Se dibuja solo para clientes de tipo exportación: en un cliente nacional este
    bloque no existe y la ficha queda exactamente como estaba.
--}}

@php
    $perfil = $cliente->exportacionClientes->first();
    $puedeGestionar = auth()->user()?->can('exportaciones.gestionar') ?? false;
@endphp

<div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
    <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="font-medium text-gray-700 dark:text-paper-100">Exportación</h3>
            <p class="text-sm text-gray-500 dark:text-paper-300">
                Datos del embarque y lista de precios por caja. El nombre, el documento y la dirección fiscal salen de la ficha de arriba.
            </p>
        </div>
        <div class="flex items-center gap-3">
            @if ($perfil)
                <span class="inline-flex rounded-full px-2 py-0.5 text-xs {{ $perfil->activo ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">
                    {{ $perfil->activo ? 'Habilitado' : 'Deshabilitado' }}
                </span>
            @endif
            @if ($puedeGestionar)
                <form method="POST"
                      action="{{ $perfil && $perfil->activo ? route('clientes.exportacion.deshabilitar', $cliente) : route('clientes.exportacion.habilitar', $cliente) }}">
                    @csrf
                    <button class="text-sm text-indigo-600 hover:underline">
                        {{ $perfil && $perfil->activo ? 'Deshabilitar para exportación' : ($perfil ? 'Volver a habilitar' : 'Habilitar para exportación') }}
                    </button>
                </form>
            @endif
        </div>
    </div>

    @if (! $perfil)
        <p class="text-sm text-gray-500 dark:text-paper-300">
            Este cliente todavía no está habilitado para exportación. Habilitarlo no crea otro cliente: agrega su FDA de importador,
            su contacto de embarque y su lista de precios sobre el mismo registro.
        </p>
    @else
        @unless ($perfil->activo)
            <p class="mb-4 rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-700">
                Deshabilitado: no aparece al armar listas de empaque nuevas. Sus precios y su histórico están intactos.
            </p>
        @endunless

        @if ($cliente->tieneDocumentoProvisional())
            {{-- Bloqueo real, no un aviso cosmético: CrearFexDesdeExportacionService
                 rechaza crear cualquier borrador FEX con el documento centinela. Se dice
                 acá, donde está el campo que hay que corregir. --}}
            <p class="mb-4 rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">
                <strong>Documento fiscal provisional.</strong> Este cliente todavía tiene el número centinela
                (<span class="font-mono">{{ $cliente->num_documento }}</span>), así que <strong>no se le puede facturar</strong>:
                la creación de la factura de exportación queda bloqueada hasta que se cargue el documento real del importador.
                Editá el cliente para corregirlo.
            </p>
        @endif

        @if ($perfil->fda_requiere_revision)
            <p class="mb-4 rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-700">
                <strong>Revisá el FDA.</strong> El número guardado acá
                (<span class="font-mono">{{ $perfil->fda_reg_number }}</span>) coincide con el de la <strong>empresa exportadora</strong>,
                que ahora se administra en Configuración → Parámetros fiscales y ya sale solo en la lista de empaque.
                Este campo es el FDA del <strong>importador</strong>: si el importador no tiene uno, dejalo vacío.
                No se borró nada automáticamente.
            </p>
        @endif

        {{-- Campos internacionales adicionales: SOLO los que el directorio no tiene. --}}
        @if ($puedeGestionar)
            <form method="POST" action="{{ route('clientes.exportacion.update', $cliente) }}" class="mb-6">
                @csrf @method('PUT')
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label for="exp_fda" class="block text-sm font-medium text-gray-700 dark:text-paper-100">FDA del importador</label>
                        <input id="exp_fda" type="text" name="fda_reg_number" value="{{ old('fda_reg_number', $perfil->fda_reg_number) }}"
                               class="mt-1 w-full rounded-md border-gray-300 text-sm" placeholder="opcional">
                        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">El de la empresa se configura una sola vez en Parámetros fiscales.</p>
                        @error('fda_reg_number') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="exp_contacto" class="block text-sm font-medium text-gray-700 dark:text-paper-100">Contacto del embarque</label>
                        <input id="exp_contacto" type="text" name="contacto" value="{{ old('contacto', $perfil->contacto) }}"
                               class="mt-1 w-full rounded-md border-gray-300 text-sm" placeholder="opcional">
                        @error('contacto') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="exp_direccion" class="block text-sm font-medium text-gray-700 dark:text-paper-100">Dirección de entrega o bodega</label>
                        <input id="exp_direccion" type="text" name="direccion" value="{{ old('direccion', $perfil->direccion) }}"
                               class="mt-1 w-full rounded-md border-gray-300 text-sm" placeholder="solo si difiere de la fiscal">
                        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">Si es la misma que la fiscal, dejala vacía.</p>
                        @error('direccion') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mt-3">
                    <x-primary-button>Guardar datos de exportación</x-primary-button>
                </div>
            </form>
        @else
            <dl class="mb-6 grid grid-cols-1 gap-x-8 gap-y-3 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-500 dark:text-paper-300">FDA del importador</dt><dd class="font-mono">{{ $perfil->fdaImportador() ?? '—' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-paper-300">Contacto del embarque</dt><dd>{{ $perfil->contacto ?? '—' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-paper-300">Dirección de entrega</dt><dd>{{ $perfil->direccionEntregaBodega() ?? 'la misma que la fiscal' }}</dd></div>
            </dl>
        @endif

        {{-- Lista de precios --}}
        <div class="border-t border-gray-100 dark:border-ink-600 pt-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h4 class="text-sm font-medium text-gray-700 dark:text-paper-100">
                    Lista de precios ({{ $perfil->productos->count() }} producto{{ $perfil->productos->count() === 1 ? '' : 's' }})
                </h4>
                @if ($puedeGestionar)
                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('clientes.exportacion.productos.asignar-catalogo', $cliente) }}">
                            @csrf
                            <button class="rounded-md bg-gray-100 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-200"
                                    title="Agrega todo el catálogo activo que falte usando su precio base. Los productos sin precio base quedan fuera.">
                                Asignar catálogo con precio base
                            </button>
                        </form>
                    </div>
                @endif
            </div>

            @if ($puedeGestionar && $productosDisponibles->isNotEmpty())
                <form method="POST" action="{{ route('clientes.exportacion.productos.store', $cliente) }}"
                      class="mb-4 flex flex-wrap items-end gap-3 rounded-md bg-gray-50 p-3">
                    @csrf
                    <div class="min-w-56 flex-1">
                        <label for="exp_producto" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Producto</label>
                        <select id="exp_producto" name="exportacion_producto_id" required class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            <option value="">— elegí un producto —</option>
                            @foreach ($productosDisponibles as $disponible)
                                <option value="{{ $disponible->id }}">
                                    {{ $disponible->nombre_es }}@if ($disponible->precio_caja !== null) — base ${{ number_format((float) $disponible->precio_caja, 2) }}@endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="exp_precio" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Precio por caja ($)</label>
                        <input id="exp_precio" type="number" name="precio_caja" min="0" step="0.01" required
                               class="mt-1 w-36 rounded-md border-gray-300 text-sm">
                    </div>
                    <label class="inline-flex items-center gap-2 pb-2 text-xs text-gray-600 dark:text-paper-300">
                        <input type="checkbox" name="confirmar_cero" value="1" class="rounded border-gray-300">
                        Confirmo si es $0.00
                    </label>
                    <button class="mb-0.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Agregar</button>
                </form>
                @error('exportacion_producto_id') <p class="mb-3 text-xs text-red-600">{{ $message }}</p> @enderror
                @error('precio_caja') <p class="mb-3 text-xs text-red-600">{{ $message }}</p> @enderror
            @endif

            @if ($perfil->productos->isEmpty())
                <p class="text-sm text-gray-500 dark:text-paper-300">
                    Sin productos asignados. Al armar una lista de empaque, este cliente usaría el precio base del catálogo y la lista lo avisaría.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">Precios de exportación de {{ $cliente->nombre }}</caption>
                        <thead class="bg-gray-50 text-gray-600 dark:text-paper-300">
                            <tr>
                                <th scope="col" class="p-2 text-left font-medium">Producto</th>
                                <th scope="col" class="p-2 text-right font-medium">Precio caja</th>
                                <th scope="col" class="p-2 text-right font-medium">Por unidad</th>
                                <th scope="col" class="p-2 text-left font-medium">Estado</th>
                                @if ($puedeGestionar)
                                    <th scope="col" class="p-2 text-right font-medium">Acciones</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                            @foreach ($perfil->productos as $asignacion)
                                <tr>
                                    <td class="p-2">
                                        @if ($asignacion->producto)
                                            <a href="{{ route('productos.exportacion.show', $asignacion->producto) }}" class="text-indigo-600 hover:underline">
                                                {{ $asignacion->producto->nombre_es }}
                                            </a>
                                            @unless ($asignacion->producto->activo)
                                                <span class="ms-1 inline-flex rounded-full bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">Archivado</span>
                                            @endunless
                                        @else
                                            <span class="text-gray-400">Producto eliminado</span>
                                        @endif
                                    </td>
                                    <td class="p-2 text-right font-mono tabular-nums">${{ number_format((float) $asignacion->precio_caja, 2) }}</td>
                                    <td class="p-2 text-right font-mono tabular-nums text-gray-500 dark:text-paper-300">
                                        {{ $asignacion->precioPorUnidad() !== null ? '$'.number_format($asignacion->precioPorUnidad(), 2) : '—' }}
                                    </td>
                                    <td class="p-2">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs {{ $asignacion->activo ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">
                                            {{ $asignacion->activo ? 'Habilitado' : 'Deshabilitado' }}
                                        </span>
                                    </td>
                                    @if ($puedeGestionar)
                                        <td class="p-2">
                                            <div class="flex flex-wrap items-center justify-end gap-2">
                                                <form method="POST" action="{{ route('clientes.exportacion.productos.update', [$cliente, $asignacion]) }}"
                                                      class="flex items-center gap-1">
                                                    @csrf @method('PATCH')
                                                    <label class="sr-only" for="precio_{{ $asignacion->id }}">Precio por caja de {{ $asignacion->producto?->nombre_es }}</label>
                                                    <input id="precio_{{ $asignacion->id }}" type="number" name="precio_caja" step="0.01" min="0"
                                                           value="{{ number_format((float) $asignacion->precio_caja, 2, '.', '') }}"
                                                           class="w-24 rounded-md border-gray-300 text-sm">
                                                    <input type="hidden" name="confirmar_cero" value="1">
                                                    <button class="text-xs text-indigo-600 hover:underline">Guardar</button>
                                                </form>
                                                <form method="POST" action="{{ route('clientes.exportacion.productos.update', [$cliente, $asignacion]) }}">
                                                    @csrf @method('PATCH')
                                                    <input type="hidden" name="toggle_activo" value="1">
                                                    <button class="text-xs text-gray-600 hover:underline dark:text-paper-300">
                                                        {{ $asignacion->activo ? 'Deshabilitar' : 'Habilitar' }}
                                                    </button>
                                                </form>
                                                <form method="POST" action="{{ route('clientes.exportacion.productos.destroy', [$cliente, $asignacion]) }}"
                                                      onsubmit="return confirm('¿Quitar este producto de la lista de precios del cliente? Las listas de empaque ya creadas no cambian.');">
                                                    @csrf @method('DELETE')
                                                    <button class="text-xs text-red-600 hover:underline">Quitar</button>
                                                </form>
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($puedeGestionar && $otrosPerfilesExportacion->isNotEmpty())
                <form method="POST" action="{{ route('clientes.exportacion.productos.copiar', $cliente) }}"
                      class="mt-4 flex flex-wrap items-end gap-3 border-t border-gray-100 dark:border-ink-600 pt-4">
                    @csrf
                    <div>
                        <label for="exp_origen" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Copiar precios desde</label>
                        <select id="exp_origen" name="origen_id" required class="mt-1 rounded-md border-gray-300 text-sm">
                            @foreach ($otrosPerfilesExportacion as $otro)
                                <option value="{{ $otro->id }}">{{ $otro->nombreLegal() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="exp_modo" class="block text-xs font-medium text-gray-600 dark:text-paper-300">Si ya existe</label>
                        <select id="exp_modo" name="modo" class="mt-1 rounded-md border-gray-300 text-sm">
                            <option value="conservar">Conservar el precio actual</option>
                            <option value="sobrescribir">Sobrescribir con el del origen</option>
                        </select>
                    </div>
                    <button class="mb-0.5 rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Copiar</button>
                </form>
            @endif
        </div>
    @endif
</div>
