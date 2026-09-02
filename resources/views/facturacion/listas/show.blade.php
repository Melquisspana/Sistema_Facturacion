@php
    $puedeGestionar = auth()->user()?->can('exportaciones.gestionar') ?? false;
    $editable = $lista->puedeEditarse();
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Lista de empaque #{{ $lista->id }}
                @if ($lista->requiereRevision())
                    <span class="ms-2 inline-block rounded-full bg-amber-100 px-2.5 py-0.5 align-middle text-xs font-medium text-amber-700">Requiere revisión</span>
                @elseif ($lista->estaFinalizada())
                    <span class="ms-2 inline-block rounded-full bg-green-100 px-2.5 py-0.5 align-middle text-xs font-medium text-green-700">Finalizada</span>
                @else
                    <span class="ms-2 inline-block rounded-full bg-gray-100 px-2.5 py-0.5 align-middle text-xs font-medium text-gray-600">Borrador</span>
                @endif
                @if ($lista->archivada)
                    {{-- Marca heredada del flujo anterior. No hay forma de archivar desde
                         acá; se muestra para que una lista que no sale en el listado normal
                         explique por qué en cuanto se abre. --}}
                    <span class="ms-1 inline-block rounded-full bg-gray-200 px-2.5 py-0.5 align-middle text-xs text-gray-600">
                        {{ $lista->esPruebaApitest() ? 'Prueba APITEST / Archivada' : 'Archivada' }}
                    </span>
                @elseif ($lista->esPruebaApitest())
                    <span class="ms-1 inline-block rounded-full bg-gray-100 px-2.5 py-0.5 align-middle text-xs text-gray-600">Prueba APITEST</span>
                @endif
            </h2>

            <div class="flex flex-wrap gap-2">
                @if ($lista->items->isNotEmpty())
                    <a href="{{ route('facturacion.listas.excel', $lista) }}"
                       class="rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700">Descargar Excel</a>
                    <a href="{{ route('facturacion.listas.imprimir', $lista) }}" target="_blank" rel="noopener"
                       class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Imprimir</a>
                @endif

                @if ($puedeGestionar)
                    @if ($editable)
                        <a href="{{ route('facturacion.listas.edit', $lista) }}"
                           class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Editar</a>
                    @endif
                    @unless ($lista->requiereRevision())
                        <form method="POST" action="{{ route('facturacion.listas.duplicar', $lista) }}">
                            @csrf
                            <button class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200"
                                    title="Crea una copia con los mismos productos y la fecha de hoy, en borrador y sin facturas">Duplicar</button>
                        </form>
                    @endunless
                    @if ($lista->puedeFinalizarse())
                        <form method="POST" action="{{ route('facturacion.listas.finalizar', $lista) }}"
                              onsubmit="return confirm('¿Finalizar la lista? Deja de poder editarse; corregirla después exige reabrirla indicando el motivo.');">
                            @csrf @method('PATCH')
                            <button class="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700">Finalizar lista</button>
                        </form>
                    @endif
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ session('error') }}</div>
            @endif
            @if (session('aviso_precios'))
                <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-700">{{ session('aviso_precios') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">
                    <ul class="list-inside list-disc">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @if ($lista->requiereRevision())
                <div class="rounded-md bg-amber-50 border border-amber-200 p-4 text-sm text-amber-700">
                    <p class="font-medium">Lista congelada: viene del flujo anterior y falta clasificarla.</p>
                    <p class="mt-1">
                        Su estado guardado es «<strong>{{ $lista->estadoOriginalHeredado() }}</strong>», que el flujo actual no
                        conoce. No se cambió ningún dato, y <strong>no se puede editar, facturar, finalizar ni borrar</strong>
                        hasta que un administrador decida qué es. Tratarla como borrador dejaría modificar una lista que quizá
                        se aprobó en su momento.
                    </p>
                    @if ($lista->revision_motivo)
                        <p class="mt-1 text-xs">{{ $lista->revision_motivo }}</p>
                    @endif
                </div>
            @elseif ($lista->revision_resolucion)
                <div class="rounded-md bg-gray-50 border border-gray-200 p-4 text-sm text-gray-600">
                    Lista heredada ya clasificada como <strong>{{ $lista->revision_resolucion }}</strong>
                    el {{ $lista->revision_resuelta_en?->format('d/m/Y H:i') }}.
                    Estado original: <strong>{{ $lista->revision_estado_original }}</strong>.
                    Motivo: {{ $lista->revision_motivo }}
                </div>
            @endif

            {{-- Estado del flujo: cuatro pasos, sin cola. --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6">
                @php
                    $tieneItems = $lista->items->isNotEmpty();
                    $tieneFactura = $facturas->isNotEmpty();
                    $pasos = [
                        ['n' => 1, 'texto' => 'Preparar lista', 'hecho' => $tieneItems],
                        ['n' => 2, 'texto' => 'Facturar', 'hecho' => $tieneFactura],
                        ['n' => 3, 'texto' => 'Generar documentos', 'hecho' => $tieneItems],
                        ['n' => 4, 'texto' => 'Finalizada', 'hecho' => $lista->estaFinalizada()],
                    ];
                @endphp
                <ol class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm">
                    @foreach ($pasos as $paso)
                        <li class="flex items-center gap-2">
                            <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold {{ $paso['hecho'] ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-500' }}">
                                {{ $paso['hecho'] ? '✓' : $paso['n'] }}
                            </span>
                            <span class="{{ $paso['hecho'] ? 'font-medium text-gray-800' : 'text-gray-500' }}">{{ $paso['texto'] }}</span>
                            @unless ($loop->last)
                                <span aria-hidden="true" class="ms-1 text-gray-300">→</span>
                            @endunless
                        </li>
                    @endforeach
                </ol>

                {{-- Qué falta para el paso siguiente, dicho en una línea. Sin esto el
                     usuario descubre el bloqueo al pulsar el botón y leer un error. --}}
                <p class="mt-4 border-t border-gray-100 pt-3 text-sm">
                    @if ($lista->requiereRevision())
                        <span class="text-amber-700">
                            Congelada hasta que un administrador la clasifique: no se puede editar, facturar ni finalizar.
                        </span>
                    @elseif ($lista->estaFinalizada())
                        <span class="text-gray-600">Lista finalizada: el trabajo sobre este documento terminó.</span>
                    @elseif (! $lista->cliente?->cliente_id)
                        <span class="text-amber-700">
                            Cliente del directorio no vinculado: habilitá al cliente para exportación desde su ficha antes de facturar.
                        </span>
                    @elseif ($lista->items->isEmpty())
                        <span class="text-amber-700">La lista necesita productos antes de facturar.</span>
                    @elseif ($facturas->isEmpty())
                        <span class="text-blue-700">Lista para facturar.</span>
                    @else
                        <span class="text-gray-600">
                            Facturada. Cuando el embarque esté completo, finalizá la lista.
                        </span>
                    @endif
                </p>
            </div>

            {{-- Encabezado --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">Exportador</dt>
                        <dd class="mt-0.5 font-medium text-gray-800">{{ $lista->exportador_nombre }}</dd>
                        <dd class="text-gray-500">{{ $lista->exportador_direccion ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">Cliente</dt>
                        <dd class="mt-0.5 font-medium text-gray-800">
                            @if ($lista->cliente?->cliente_id)
                                <a href="{{ route('clientes.show', $lista->cliente->cliente_id) }}" class="text-indigo-600 hover:underline">{{ $lista->cliente_nombre }}</a>
                            @else
                                {{ $lista->cliente_nombre }}
                            @endif
                        </dd>
                        <dd class="text-gray-500">{{ $lista->cliente_direccion ?? '—' }}</dd>
                    </div>
                    <div class="space-y-2">
                        <div>
                            <dt class="inline text-xs uppercase tracking-wide text-gray-400">Fecha:</dt>
                            <dd class="inline font-medium text-gray-800">{{ $lista->fecha?->format('d/m/Y') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="inline text-xs uppercase tracking-wide text-gray-400">Factura:</dt>
                            <dd class="inline font-mono text-gray-800">{{ $lista->textoFactura() ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="inline text-xs uppercase tracking-wide text-gray-400">FDA de la empresa:</dt>
                            <dd class="inline font-mono text-gray-800">{{ $lista->fda_reg_number ?: '—' }}</dd>
                        </div>
                    </div>
                </dl>

                @if ($lista->observaciones)
                    <p class="mt-4 border-t border-gray-100 pt-3 text-sm text-gray-600">{{ $lista->observaciones }}</p>
                @endif

                @if ($lista->estaFinalizada())
                    <p class="mt-4 border-t border-gray-100 pt-3 text-sm text-gray-600">
                        Finalizada el {{ $lista->finalizada_en?->format('d/m/Y H:i') ?? '—' }}.
                        Ya no se edita ni se borra, y sus facturas no se pueden desvincular.
                    </p>
                @endif
            </div>

            {{-- Facturas de exportación vinculadas. Una lista puede tener varias. --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-6 py-4">
                    <div>
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">
                            Facturas de exportación ({{ $facturas->count() }})
                        </h3>
                        <p class="mt-1 text-xs text-gray-500">Los números de la lista salen de acá: no se teclean.</p>
                    </div>
                    {{-- Sin cliente del directorio vinculado no hay receptor para la FEX, así
                         que no se ofrece ningún camino a facturar: el aviso de arriba explica
                         qué falta. Ofrecer un botón que sólo puede terminar en error enseña a
                         desconfiar del resto de la pantalla. --}}
                    @if ($puedeGestionar && $editable && $lista->cliente?->cliente_id)
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('facturacion.listas.facturar', $lista) }}"
                               class="rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-700"
                               title="Abre el formulario normal de factura de exportación con esta lista en contexto">
                                Facturar en el editor
                            </a>
                            @if ($facturas->isEmpty() && $lista->items->isNotEmpty())
                                <form method="POST" action="{{ route('facturacion.listas.facturar-rapido', $lista) }}"
                                      onsubmit="return confirm('Se creará un borrador de FEX copiando cajas y precios de esta lista. No se firma ni se transmite.\n\n¿Continuar?');">
                                    @csrf
                                    <button class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Crear FEX con estas líneas</button>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>

                @if ($facturas->isEmpty())
                    <p class="px-6 py-8 text-center text-sm text-gray-400">
                        Sin facturas vinculadas todavía. La lista se puede finalizar solo cuando tenga al menos una.
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Facturas de exportación vinculadas a la lista #{{ $lista->id }}</caption>
                            <thead>
                                <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <th scope="col" class="py-3 px-4">Número</th>
                                    <th scope="col" class="py-3 px-4">Estado</th>
                                    <th scope="col" class="py-3 px-4">Fecha</th>
                                    <th scope="col" class="py-3 px-4 text-right">Total</th>
                                    @if ($puedeGestionar && $editable)
                                        <th scope="col" class="py-3 px-4 text-right">Acciones</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($facturas as $factura)
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-3 px-4">
                                            <a href="{{ route('facturacion.show', $factura) }}" class="font-mono text-indigo-600 hover:underline">
                                                {{ $factura->numero_control ?: 'Borrador #'.$factura->id }}
                                            </a>
                                        </td>
                                        <td class="py-3 px-4"><x-estado-dte-badge :estado="$factura->estado" /></td>
                                        <td class="py-3 px-4 tabular-nums text-gray-600">{{ $factura->fecha_emision?->format('d/m/Y') ?? '—' }}</td>
                                        <td class="py-3 px-4 text-right tabular-nums text-gray-800">${{ number_format((float) $factura->total_pagar, 2) }}</td>
                                        @if ($puedeGestionar && $editable)
                                            <td class="py-3 px-4 text-right">
                                                <form method="POST" action="{{ route('facturacion.listas.facturas.desvincular', [$lista, $factura]) }}"
                                                      onsubmit="return confirm('¿Quitar el vínculo con esta factura? El documento fiscal NO se borra ni se modifica.');">
                                                    @csrf @method('DELETE')
                                                    <button class="text-xs text-red-600 hover:underline">Desvincular</button>
                                                </form>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($puedeGestionar && $editable && $fexVinculables->isNotEmpty())
                    <form method="POST" action="{{ route('facturacion.listas.facturas.vincular', $lista) }}"
                          class="flex flex-wrap items-end gap-3 border-t border-gray-200 px-6 py-4">
                        @csrf
                        <div>
                            <label for="dte_id" class="block text-xs font-medium text-gray-600">Vincular una factura ya existente</label>
                            <select id="dte_id" name="dte_id" required class="mt-1 rounded-md border-gray-300 text-sm">
                                @foreach ($fexVinculables as $candidata)
                                    <option value="{{ $candidata->id }}">
                                        {{ $candidata->numero_control ?: 'Borrador #'.$candidata->id }} — ${{ number_format((float) $candidata->total_pagar, 2) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <button class="mb-0.5 rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Vincular</button>
                    </form>
                @endif
            </div>

            {{-- Productos --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">Productos de la lista de empaque #{{ $lista->id }}</caption>
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                <th scope="col" class="py-3 px-4 text-right">Cajas</th>
                                <th scope="col" class="py-3 px-4">Descripción</th>
                                <th scope="col" class="py-3 px-4">Empaque</th>
                                <th scope="col" class="py-3 px-4 text-right">Unid./caja</th>
                                <th scope="col" class="py-3 px-4 text-right">Total unid.</th>
                                <th scope="col" class="py-3 px-4 text-right">Precio caja</th>
                                <th scope="col" class="py-3 px-4 text-right">Valor total</th>
                                <th scope="col" class="py-3 px-4 text-right">Neto kg</th>
                                <th scope="col" class="py-3 px-4 text-right">Bruto kg</th>
                                <th scope="col" class="py-3 px-4 text-right">Neto lb</th>
                                <th scope="col" class="py-3 px-4 text-right">Bruto lb</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($lista->items as $item)
                                <tr class="hover:bg-gray-50">
                                    <td class="py-3 px-4 text-right font-medium tabular-nums text-gray-800">{{ $item->cantidad_cajas }}</td>
                                    <td class="py-3 px-4">
                                        <div class="font-medium text-gray-800">{{ $item->nombre_es }}</div>
                                        <div class="text-xs text-gray-500">{{ $item->nombre_en }}</div>
                                    </td>
                                    <td class="py-3 px-4 text-gray-600">{{ $item->unidad ?? '—' }}</td>
                                    <td class="py-3 px-4 text-right tabular-nums text-gray-600">{{ $item->unidades_por_caja }}</td>
                                    <td class="py-3 px-4 text-right tabular-nums text-gray-600">{{ number_format($item->totalUnidades()) }}</td>
                                    <td class="py-3 px-4 text-right tabular-nums text-gray-600">${{ number_format((float) $item->precio_caja, 2) }}</td>
                                    <td class="py-3 px-4 text-right font-medium tabular-nums text-gray-800">${{ number_format($item->valorTotal(), 2) }}</td>
                                    <td class="py-3 px-4 text-right tabular-nums text-gray-600">{{ number_format($item->pesoNetoTotalKg(), 1) }}</td>
                                    <td class="py-3 px-4 text-right tabular-nums text-gray-600">{{ number_format($item->pesoBrutoTotalKg(), 1) }}</td>
                                    <td class="py-3 px-4 text-right tabular-nums text-gray-600">{{ number_format($item->pesoNetoTotalLb(), 1) }}</td>
                                    <td class="py-3 px-4 text-right tabular-nums text-gray-600">{{ number_format($item->pesoBrutoTotalLb(), 1) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="py-10 text-center text-gray-400">
                                        Sin productos.
                                        @if ($puedeGestionar && $editable)
                                            <a href="{{ route('facturacion.listas.edit', $lista) }}" class="text-indigo-600 hover:underline">Agregalos editando la lista</a>.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($lista->items->isNotEmpty())
                            <tfoot>
                                <tr class="border-t border-gray-200 bg-gray-50 font-semibold text-gray-800">
                                    <td class="py-3 px-4 text-right tabular-nums">{{ $lista->totalCajas() }}</td>
                                    <td class="py-3 px-4" colspan="3">Totales</td>
                                    <td class="py-3 px-4 text-right tabular-nums">{{ number_format($lista->totalUnidades()) }}</td>
                                    <td class="py-3 px-4"></td>
                                    <td class="py-3 px-4 text-right tabular-nums">${{ number_format($lista->valorTotal(), 2) }}</td>
                                    <td class="py-3 px-4 text-right tabular-nums">{{ number_format($lista->pesoNetoTotalKg(), 1) }}</td>
                                    <td class="py-3 px-4 text-right tabular-nums">{{ number_format($lista->pesoBrutoTotalKg(), 1) }}</td>
                                    <td class="py-3 px-4 text-right tabular-nums">{{ number_format($lista->pesoNetoTotalLb(), 1) }}</td>
                                    <td class="py-3 px-4 text-right tabular-nums">{{ number_format($lista->pesoBrutoTotalLb(), 1) }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>

            {{-- Clasificación de una lista heredada. Solo administrador. --}}
            @if ($lista->requiereRevision() && auth()->user()?->hasRole('administrador'))
                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Clasificar esta lista heredada</h3>
                    <p class="mt-2 text-sm text-gray-600">
                        Decidí qué era esta lista en el flujo anterior. El estado original
                        («<strong>{{ $lista->estadoOriginalHeredado() }}</strong>») se conserva pase lo que pase, junto con quién
                        clasificó y por qué.
                    </p>
                    <form method="POST" action="{{ route('facturacion.listas.resolver-revision', $lista) }}"
                          class="mt-3 flex flex-wrap items-end gap-3">
                        @csrf
                        <div>
                            <label for="clasificacion" class="block text-xs font-medium text-gray-600">Queda como</label>
                            <select id="clasificacion" name="clasificacion" required class="mt-1 rounded-md border-gray-300 text-sm">
                                <option value="borrador">Borrador — sigue en curso y se puede editar</option>
                                <option value="finalizada">Finalizada — ya facturada y cerrada</option>
                                <option value="archivada">Archivada — prueba o descartada</option>
                            </select>
                            @error('clasificacion') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="min-w-64 flex-1">
                            <label for="motivo_revision" class="block text-xs font-medium text-gray-600">En qué te basás</label>
                            <input id="motivo_revision" type="text" name="motivo" required minlength="10" maxlength="255"
                                   value="{{ old('motivo') }}" class="mt-1 w-full rounded-md border-gray-300 text-sm"
                                   placeholder="ej. Confirmado con contabilidad: el embarque nunca salió">
                            @error('motivo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <button class="mb-0.5 rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-700">Clasificar</button>
                    </form>
                    <p class="mt-2 text-xs text-gray-500">
                        «Finalizada» exige que la lista tenga al menos una factura vigente; si no la tiene, clasificala como
                        borrador y facturala, o archivala.
                    </p>
                </div>
            @elseif ($lista->requiereRevision())
                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 text-sm text-gray-600">
                    Solo un <strong>administrador</strong> puede clasificar una lista heredada. Pedile que la resuelva para
                    poder trabajar con ella.
                </div>
            @endif

            {{-- Corrección de una lista finalizada: con motivo y auditada. --}}
            @if ($puedeGestionar && $lista->estaFinalizada())
                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Corregir esta lista</h3>
                    <p class="mt-2 text-sm text-gray-600">
                        Una lista finalizada no se edita en silencio. Para corregirla hay que reabrirla indicando el motivo, que queda
                        guardado junto con quién la reabrió y cuándo.
                    </p>
                    <form method="POST" action="{{ route('facturacion.listas.reabrir', $lista) }}" class="mt-3 flex flex-wrap items-end gap-3">
                        @csrf
                        <div class="min-w-64 flex-1">
                            <label for="motivo" class="block text-xs font-medium text-gray-600">Motivo de la reapertura</label>
                            <input id="motivo" type="text" name="motivo" required minlength="10" maxlength="255"
                                   value="{{ old('motivo') }}"
                                   class="mt-1 w-full rounded-md border-gray-300 text-sm"
                                   placeholder="ej. Faltó una caja de camote en el contenedor 2">
                            @error('motivo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <button class="mb-0.5 rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-700">Reabrir lista</button>
                    </form>
                </div>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3">
                <a href="{{ route('facturacion.listas.index') }}" class="text-sm text-indigo-600 hover:underline">← Volver a listas de empaque</a>

                @if ($puedeGestionar && $editable && $facturas->isEmpty())
                    <form method="POST" action="{{ route('facturacion.listas.destroy', $lista) }}"
                          onsubmit="return confirm('¿Eliminar esta lista de empaque? Esta acción no se puede deshacer.');">
                        @csrf @method('DELETE')
                        <button class="text-sm text-red-600 hover:underline">Eliminar lista</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
