<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-paper-100 leading-tight">Cliente: {{ $cliente->nombre }}</h2>
            <a href="{{ route('clientes.index') }}" class="text-sm text-gray-500 dark:text-paper-300 hover:underline">← Volver al listado</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif

            @php
                $esContribuyente = $cliente->tipo_cliente === \App\Enums\TipoCliente::Contribuyente;
                $sinSalas = $cliente->sucursales->isEmpty();
                // Sin salas, la ubicación del propio cliente es la que valida
                // ValidacionPreJsonService al generar el CCF/NC.
                $sinUbicacionFiscal = blank($cliente->departamento_id) || blank($cliente->municipio_id);
            @endphp

            {{-- Bloqueante: contribuyente sin salas y sin ubicación propia no puede
                 generar CCF/NC. Se avisa acá para que no se descubra al generar. --}}
            @if ($esContribuyente && $sinSalas && $sinUbicacionFiscal)
                <div class="rounded-md bg-red-50 border border-red-300 p-4 text-sm text-red-800">
                    <p class="font-medium">Este cliente todavía no puede facturar.</p>
                    <p class="mt-1">
                        No tiene salas ni ubicación fiscal propia. Un CCF o nota de crédito necesita departamento y municipio,
                        sea de la sala de entrega o del cliente.
                    </p>
                    @can('update', $cliente)
                        <p class="mt-2 flex flex-wrap gap-4">
                            <a href="{{ route('clientes.sucursales.create', $cliente) }}" class="font-medium text-red-700 hover:underline">Agregar la primera sala</a>
                            <a href="{{ route('clientes.edit', $cliente) }}" class="font-medium text-red-700 hover:underline">Completar la ubicación del cliente</a>
                        </p>
                    @endcan
                </div>
            @elseif ($esContribuyente && $sinSalas)
                <div class="rounded-md bg-amber-50 border border-amber-300 p-3 text-sm text-amber-800 flex items-center justify-between gap-4">
                    <span>Este cliente todavía no tiene salas. Los documentos usarán la dirección del cliente.</span>
                    @can('update', $cliente)
                        <a href="{{ route('clientes.sucursales.create', $cliente) }}"
                           class="whitespace-nowrap font-medium text-amber-700 hover:underline">Agregar la primera sala</a>
                    @endcan
                </div>
            @endif

            @if ($cliente->tieneDocumentoProvisional())
                <div class="rounded-md bg-amber-50 border border-amber-300 p-3 text-sm text-amber-800">
                    <strong>Documento provisional</strong> — debe corregirse antes de crear o emitir una FEX.
                </div>
            @endif

            {{-- SALAS — primero, por encima del detalle fiscal. Es la sección por la que
                 se entra a esta ficha: en el día a día se viene a ver o agregar una sala,
                 no a releer el NRC. Los detalles quedan abajo, completos y sin recortar.

                 «Sala» en todo lo visible. La tabla, las rutas, la relación y el modelo
                 siguen llamándose `sucursal`/`sucursales`: renombrarlos sería una
                 migración con riesgo fiscal a cambio de nada. --}}
            @php
                $salas = $cliente->sucursales;                       // ya cargada y ordenada
                $salasActivas = $salas->where('activo', true)->count();
                // Por debajo de este número una lista se lee de un vistazo y un buscador
                // solo estorba; por encima, deja de ser consultable sin controles.
                $muchasSalas = $salas->count() >= 8;
                $salaDestacada = session('sala_destacada');
                $indice = fn ($s) => \Illuminate\Support\Str::lower(trim($s->nombre.' '.$s->codigo.' '.$s->direccion));
            @endphp

            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6"
                 x-data="{
                    q: '',
                    estado: 'todas',
                    salas: {{ \Illuminate\Support\Js::from($salas->map(fn ($s) => [
                        'busca' => $indice($s),
                        'estado' => $s->activo ? 'activas' : 'inactivas',
                    ])->values()) }},
                    get visibles() {
                        return this.salas.filter(s =>
                            (this.q === '' || s.busca.includes(this.q.toLowerCase()))
                            && (this.estado === 'todas' || s.estado === this.estado)
                        ).length;
                    },
                 }">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="font-medium text-gray-700 dark:text-paper-100">Salas</h3>
                        <p class="text-sm text-gray-500 dark:text-paper-300">
                            @if ($salas->isEmpty())
                                Sin salas registradas
                            @else
                                <x-clientes.resumen-salas :total="$salas->count()" :activas="$salasActivas" />
                            @endif
                        </p>
                    </div>
                    @can('update', $cliente)
                        <a href="{{ route('clientes.sucursales.create', $cliente) }}"
                           class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            Agregar sala
                        </a>
                    @endcan
                </div>

                <p class="text-xs text-gray-400 dark:text-paper-500 mt-2 mb-4">
                    Mismo cliente fiscal (NIT/NRC), varias salas. La sala es referencia comercial; el receptor fiscal sigue siendo el cliente.
                </p>

                @if ($muchasSalas)
                    {{-- Filtrado en el navegador sobre las filas YA renderizadas: no hay
                         petición nueva, ni ruta nueva, ni cambio de autorización, y la
                         lista completa sigue estando en el HTML. --}}
                    <div class="flex flex-wrap items-end gap-3 mb-3">
                        <div class="flex-1 min-w-56">
                            <label for="filtro-salas" class="block text-xs font-medium text-gray-500 dark:text-paper-300">Buscar sala</label>
                            <input id="filtro-salas" type="search" x-model="q" placeholder="Nombre, código o dirección…"
                                   class="mt-1 block w-full rounded-md border-gray-300 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100 shadow-sm text-sm">
                        </div>
                        <div>
                            <label for="estado-salas" class="block text-xs font-medium text-gray-500 dark:text-paper-300">Estado</label>
                            <select id="estado-salas" x-model="estado"
                                    class="mt-1 block rounded-md border-gray-300 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100 shadow-sm text-sm">
                                <option value="todas">Todas</option>
                                <option value="activas">Activas</option>
                                <option value="inactivas">Inactivas</option>
                            </select>
                        </div>
                        <p class="pb-2 text-sm text-gray-500 dark:text-paper-300">
                            <span x-text="visibles"></span> de {{ $salas->count() }}
                        </p>
                    </div>
                @endif

                @if ($salas->isEmpty())
                    <div class="rounded-md border border-dashed border-gray-300 dark:border-ink-500 px-4 py-8 text-center">
                        <p class="text-sm text-gray-500 dark:text-paper-300">Este cliente todavía no tiene salas.</p>
                        @can('update', $cliente)
                            <a href="{{ route('clientes.sucursales.create', $cliente) }}"
                               class="mt-2 inline-block text-sm font-medium text-indigo-600 hover:underline">
                                Agregar primera sala
                            </a>
                        @endcan
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-ink-600 text-sm">
                            <thead>
                                <tr class="text-left text-gray-500 dark:text-paper-300">
                                    <th class="px-3 py-2">Código</th>
                                    <th class="px-3 py-2">Sala / nombre comercial</th>
                                    <th class="px-3 py-2">Ubicación</th>
                                    <th class="px-3 py-2">Orden de compra</th>
                                    <th class="px-3 py-2">Estado</th>
                                    <th class="px-3 py-2 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                                @foreach ($salas as $sucursal)
                                    <tr data-busca="{{ $indice($sucursal) }}"
                                        data-estado="{{ $sucursal->activo ? 'activas' : 'inactivas' }}"
                                        @if ($muchasSalas)
                                            x-show="(q === '' || $el.dataset.busca.includes(q.toLowerCase())) && (estado === 'todas' || $el.dataset.estado === estado)"
                                        @endif
                                        class="{{ (string) $salaDestacada === (string) $sucursal->id ? 'bg-green-50' : '' }}">
                                        <td class="px-3 py-2 font-mono">{{ $sucursal->codigo ?? '—' }}</td>
                                        <td class="px-3 py-2 font-medium">
                                            {{ $sucursal->nombre }}
                                            @if ((string) $salaDestacada === (string) $sucursal->id)
                                                <span class="ml-1 inline-flex px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-700">Recién creada</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">{{ $sucursal->direccion ?? '—' }}</td>
                                        <td class="px-3 py-2">
                                            @if (is_null($sucursal->requiere_orden_compra))
                                                <span class="text-gray-400 dark:text-paper-500">Hereda del cliente</span>
                                            @elseif ($sucursal->requiere_orden_compra)
                                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-indigo-100 text-indigo-700">Sí</span>
                                            @else
                                                No
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $sucursal->activo ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">
                                                {{ $sucursal->activo ? 'Activa' : 'Inactiva' }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 text-right whitespace-nowrap">
                                            @can('update', $cliente)
                                                <a href="{{ route('clientes.sucursales.edit', [$cliente, $sucursal]) }}" class="text-indigo-600 hover:underline">Editar</a>
                                                <form method="POST" action="{{ route('clientes.sucursales.toggle-activo', [$cliente, $sucursal]) }}" class="inline">
                                                    @csrf @method('PATCH')
                                                    <button class="text-amber-600 hover:underline ml-2">{{ $sucursal->activo ? 'Inactivar' : 'Activar' }}</button>
                                                </form>
                                                <form method="POST" action="{{ route('clientes.sucursales.destroy', [$cliente, $sucursal]) }}" class="inline"
                                                      onsubmit="return confirm('¿Eliminar esta sala?');">
                                                    @csrf @method('DELETE')
                                                    <button class="text-red-600 hover:underline ml-2">Eliminar</button>
                                                </form>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($muchasSalas)
                        <p x-show="visibles === 0" x-cloak class="px-3 py-6 text-center text-sm text-gray-400 dark:text-paper-500">
                            Ninguna sala coincide con la búsqueda.
                        </p>
                    @endif
                @endif
            </div>

            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $cliente->activo ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">
                        {{ $cliente->activo ? 'Activo' : 'Inactivo' }}
                    </span>
                    <div class="flex items-center gap-3">
                        @can('update', $cliente)
                            <a href="{{ route('clientes.edit', $cliente) }}" class="text-indigo-600 hover:underline text-sm">Editar</a>
                            <form method="POST" action="{{ route('clientes.toggle-activo', $cliente) }}">
                                @csrf @method('PATCH')
                                <button class="text-amber-600 hover:underline text-sm">{{ $cliente->activo ? 'Inactivar' : 'Activar' }}</button>
                            </form>
                        @endcan
                    </div>
                </div>

                <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3 text-sm">
                    <div><dt class="text-gray-500 dark:text-paper-300">Tipo de cliente</dt><dd>{{ $cliente->tipo_cliente?->label() ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Tipo de persona</dt><dd>{{ $cliente->tipo_persona?->label() ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Documento</dt><dd>{{ $cliente->tipo_documento?->label() }} <span class="font-mono">{{ $cliente->num_documento }}</span></dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">NRC</dt><dd class="font-mono">{{ $cliente->nrc ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Tamaño de contribuyente</dt><dd>{{ $cliente->tamanio_contribuyente?->label() ?? '—' }}</dd></div>
                    <div>
                        <dt class="text-gray-500 dark:text-paper-300">Agente de retención</dt>
                        <dd>
                            @if ($cliente->es_agente_retencion)
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-indigo-100 text-indigo-700">Sí</span>
                            @else
                                No
                            @endif
                        </dd>
                    </div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Descuento global (%)</dt><dd class="font-mono">{{ number_format($cliente->descuento_global_default ?? 0, 2) }}%</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Condición de pago</dt><dd>{{ \App\Enums\CondicionPago::tryFrom((int) $cliente->condicion_operacion_default)?->label() ?? '— Sin definir —' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Código interno</dt><dd class="font-mono">{{ $cliente->codigo ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Nombre comercial</dt><dd>{{ $cliente->nombre_comercial ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Actividad económica</dt><dd>{{ $cliente->actividadEconomica?->nombre ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">País</dt><dd>{{ $cliente->pais?->nombre ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Departamento</dt><dd>{{ $cliente->departamento?->nombre ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Municipio</dt><dd>{{ $cliente->municipio?->nombre ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Dirección</dt><dd>{{ $cliente->direccion ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Complemento</dt><dd>{{ $cliente->complemento_direccion ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Correo</dt><dd>{{ $cliente->correo ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Teléfono</dt><dd>{{ $cliente->telefono ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Contacto principal</dt><dd>{{ $cliente->contacto_principal ?? '—' }}</dd></div>
                    <div class="md:col-span-2"><dt class="text-gray-500 dark:text-paper-300">Observaciones</dt><dd>{{ $cliente->observaciones ?? '—' }}</dd></div>
                    <div>
                        <dt class="text-gray-500 dark:text-paper-300">Requiere orden de compra (CCF)</dt>
                        <dd>
                            @if ($cliente->requiere_orden_compra)
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-indigo-100 text-indigo-700">Sí</span>
                                <span class="text-gray-500 dark:text-paper-300">— etiqueta: "{{ $cliente->etiqueta_orden_compra ?? 'Orden de compra' }}"</span>
                            @else
                                No
                            @endif
                        </dd>
                    </div>
                    <div><dt class="text-gray-500 dark:text-paper-300">Observaciones de facturación</dt><dd>{{ $cliente->observaciones_facturacion ?? '—' }}</dd></div>
                </dl>
            </div>

            {{-- Perfil documental: exigencias propias de este cliente sobre sus notas de
                 crédito. Se muestra SIEMPRE, también cuando no hay perfil, para que la
                 opción sea descubrible en vez de vivir solo en un comando de consola. --}}
            @php $perfilDoc = $cliente->perfilDocumento; @endphp
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                    <div>
                        <h3 class="font-medium text-gray-700 dark:text-paper-100">Perfil documental</h3>
                        <p class="text-sm text-gray-500 dark:text-paper-300">
                            Códigos de albarán del cliente, origen del descuento por modalidad y formato de exportación.
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        @if ($perfilDoc)
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $perfilDoc->activo ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">
                                {{ $perfilDoc->activo ? 'Activo' : 'Inactivo' }}
                            </span>
                        @endif
                        @can('update', $cliente)
                            <a href="{{ route('clientes.perfil-documento.edit', $cliente) }}" class="text-indigo-600 hover:underline text-sm">
                                {{ $perfilDoc ? 'Configurar' : 'Configurar perfil' }}
                            </a>
                        @endcan
                    </div>
                </div>

                @if (! $perfilDoc)
                    <p class="text-sm text-gray-500 dark:text-paper-300">
                        Sin perfil. Este cliente calcula sus notas de crédito con el criterio general del sistema.
                    </p>
                @else
                    <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-8 gap-y-3 text-sm mb-4">
                        <div><dt class="text-gray-500 dark:text-paper-300">Código de proveedor</dt><dd class="font-mono">{{ $perfilDoc->codigo_proveedor ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500 dark:text-paper-300">Formato de exportación</dt><dd class="font-mono">{{ $perfilDoc->formato_export ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500 dark:text-paper-300">Albarán obligatorio</dt><dd>{{ $perfilDoc->exige_albaran_en_nc ? 'Sí' : 'No' }}</dd></div>
                        <div><dt class="text-gray-500 dark:text-paper-300">Tolerancia</dt><dd class="font-mono">{{ number_format((float) $perfilDoc->tolerancia_albaran, 2) }}</dd></div>
                    </dl>

                    @if ($perfilDoc->tiposNc->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-paper-300">Ninguna modalidad mapeada: todas siguen el criterio histórico.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <caption class="sr-only">Modalidades de nota de crédito configuradas para este cliente</caption>
                                <thead class="bg-gray-50 text-gray-600 dark:text-paper-300">
                                    <tr>
                                        <th scope="col" class="p-2 text-left font-medium">Modalidad</th>
                                        <th scope="col" class="p-2 text-left font-medium">Código</th>
                                        <th scope="col" class="p-2 text-left font-medium">Descuento</th>
                                        <th scope="col" class="p-2 text-right font-medium">Tasa</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-ink-600">
                                    @foreach ($perfilDoc->tiposNc as $regla)
                                        <tr>
                                            <td class="p-2">{{ $regla->tipo_nota_credito?->label() ?? '—' }}</td>
                                            <td class="p-2 font-mono">{{ $regla->codigo_externo }}</td>
                                            <td class="p-2">{{ $regla->descuento_origen->label() }}</td>
                                            <td class="p-2 text-right font-mono">{{ $regla->descuento_tasa !== null ? number_format((float) $regla->descuento_tasa, 2).'%' : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endif
            </div>

            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-700 dark:text-paper-100 mb-3">Historial de auditoría</h3>
                @forelse ($actividades as $actividad)
                    <div class="flex items-start gap-3 py-2 border-b border-gray-100 dark:border-ink-600 last:border-0 text-sm">
                        <div class="text-gray-400 dark:text-paper-500 whitespace-nowrap">{{ $actividad->created_at->format('d/m/Y H:i') }}</div>
                        <div>
                            <span class="font-medium">{{ $actividad->causer?->name ?? 'Sistema' }}</span>
                            {{ $actividad->description }}
                            @if ($actividad->properties->has('attributes'))
                                <span class="text-gray-400 dark:text-paper-500">
                                    ({{ collect($actividad->properties->get('attributes'))->keys()->implode(', ') }})
                                </span>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-400 dark:text-paper-500">Sin actividad registrada.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
