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
            Este cliente todavía no está habilitado para exportación. Habilitarlo no crea otro cliente: agrega su contacto de embarque
            y su lista de precios sobre el mismo registro.
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

        {{-- Campos internacionales adicionales: SOLO los que el directorio no tiene. --}}
        @if ($puedeGestionar)
            <form method="POST" action="{{ route('clientes.exportacion.update', $cliente) }}" class="mb-6">
                @csrf @method('PUT')
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
            <dl class="mb-6 grid grid-cols-1 gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
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
                <a href="{{ route('productos.exportacion.index') }}" class="text-sm text-indigo-600 hover:underline">
                    Asignar productos desde el catálogo →
                </a>
            </div>
            <p class="mb-3 text-xs text-gray-500 dark:text-paper-300">
                Los productos se asignan desde <strong>Productos › De exportación</strong>, con el botón «Clientes» de cada
                presentación. Cada precio se actualiza solo al finalizar una lista de empaque.
            </p>

            @if ($perfil->productos->isEmpty())
                <p class="text-sm text-gray-500 dark:text-paper-300">
                    Todavía no tiene productos. Se le asignan desde el catálogo, o solos al finalizar su primera lista de empaque.
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
                                            <div class="text-xs text-gray-500 dark:text-paper-300">
                                                {{ $asignacion->producto->etiquetaEmpaque() }}
                                                @if ($asignacion->precio_fijado_en)
                                                    · {{ $asignacion->precio_desde_exportacion_id ? 'de la lista del' : 'fijado el' }} {{ $asignacion->precio_fijado_en->format('d/m/Y') }}
                                                @endif
                                            </div>
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
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

        </div>
    @endif
</div>
