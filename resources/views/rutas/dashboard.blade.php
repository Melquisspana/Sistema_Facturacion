<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Cobros</h2>
            <p class="text-xs text-gray-500 dark:text-paper-400">{{ $desde->translatedFormat('d M Y') }} → {{ $hasta->translatedFormat('d M Y') }}</p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-rutas.avisos />

            {{-- ==========================================================================
                 TODAS las cifras de esta pantalla vienen de UNA sola llamada a
                 BandejaDocumentos::consultar(), hecha por el controlador. Acá no se
                 calcula nada: no hay restas de contadores, ni sumas de montos, ni reglas
                 de PPQ o de NC reescritas en Blade. Si falta un número, se agrega al
                 servicio que ya lo sabe calcular; nunca acá.

                 Cada tarjeta enlaza a la bandeja arrastrando $enlaceBase (fechas, ruta y
                 sala). Así el listado que se abre es EXACTAMENTE el universo del número
                 en el que se hizo clic, y no otro más grande que parezca no cuadrar.
                 ========================================================================== --}}
            @php
                $caja = 'rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none';
                $cajaLink = $caja.' block transition hover:ring-indigo-300';
                $rotulo = 'text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-paper-400';
                $plata = 'mt-1 text-2xl font-semibold tabular-nums text-gray-800 dark:text-paper-100';
                $numero = 'mt-1 text-3xl font-semibold tabular-nums text-gray-800 dark:text-paper-100';
                $pie = 'mt-0.5 text-[11px] text-gray-400 dark:text-paper-500';
                $campo = 'mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
                $etiqueta = 'block text-xs font-medium text-gray-500 dark:text-paper-400';
                $bandeja = \App\Services\Rutas\BandejaDocumentos::class;
                $hayFiltros = collect($filtros)->filter(fn ($v) => filled($v))->isNotEmpty();
            @endphp

            {{-- ===================== Filtros de cabecera =====================
                 Solo los DUROS (fechas, ruta, sala): son columnas reales y los resuelve
                 la base. Los derivados —entrega, cobro, saldo, antigüedad— no están acá
                 a propósito: son el DESTINO de cada tarjeta, y ponerlos en los dos
                 lugares duplicaría la interfaz sin agregar nada. --}}
            <form method="GET" class="mb-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div>
                        <label for="desde" class="{{ $etiqueta }}">Salidas desde</label>
                        <input id="desde" type="date" name="desde" value="{{ $desde->toDateString() }}" class="{{ $campo }}">
                    </div>
                    <div>
                        <label for="hasta" class="{{ $etiqueta }}">Hasta</label>
                        <input id="hasta" type="date" name="hasta" value="{{ $hasta->toDateString() }}" class="{{ $campo }}">
                    </div>
                    <div>
                        <label for="ruta_id" class="{{ $etiqueta }}">Ruta</label>
                        <select id="ruta_id" name="ruta_id" class="{{ $campo }}">
                            <option value="">Todas</option>
                            @foreach ($rutas as $r)
                                <option value="{{ $r->id }}" @selected(($filtros['ruta_id'] ?? '') == $r->id)>{{ $r->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="sucursal_id" class="{{ $etiqueta }}">Sala</label>
                        <select id="sucursal_id" name="sucursal_id" class="{{ $campo }}">
                            <option value="">Todas</option>
                            @foreach ($sucursales as $s)
                                <option value="{{ $s->id }}" @selected(($filtros['sucursal_id'] ?? '') == $s->id)>{{ $s->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="mt-3 flex items-center gap-3">
                    <button class="rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 dark:bg-paper-100 dark:text-ink-900 dark:hover:bg-white">Aplicar</button>
                    @if ($hayFiltros)
                        <a href="{{ route('rutas.dashboard') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Limpiar</a>
                    @endif
                    <p class="ml-auto text-[11px] text-gray-400 dark:text-paper-500">
                        Cifras del período mostrado, no del histórico completo.
                    </p>
                </div>
            </form>

            {{-- ===================== BANDA 1 · Dinero =====================
                 El saldo NUNCA como una sola cifra. Va partido en sus dos componentes
                 porque son dos trabajos distintos con dueños distintos: lo que está
                 fuera del PPQ es trabajo NUESTRO (falta ingresarlo), lo que está dentro
                 y sin pagar es plata que hay que IR A COBRAR. Sumados, no se sabría cuál
                 de los dos está creciendo. --}}
            <h3 class="mb-2 text-sm font-semibold text-gray-700 dark:text-paper-200">Dinero</h3>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <a href="{{ route('rutas.documentos.index', $enlaceBase + ['saldo' => $bandeja::SALDO_CON]) }}"
                   class="{{ $cajaLink }} hover:ring-red-300">
                    <p class="{{ $rotulo }}">Saldo pendiente</p>
                    <p class="{{ $plata }} @if ($dinero['saldo'] > 0) !text-red-700 dark:!text-red-400 @endif">${{ number_format($dinero['saldo'], 2) }}</p>
                    <p class="{{ $pie }}">{{ $dinero['documentos_con_saldo'] }} documento{{ $dinero['documentos_con_saldo'] === 1 ? '' : 's' }} por cobrar</p>
                </a>

                <a href="{{ route('rutas.documentos.index', $enlaceBase + ['ppq' => $bandeja::PPQ_FUERA, 'saldo' => $bandeja::SALDO_CON]) }}"
                   class="{{ $cajaLink }} hover:ring-amber-300">
                    <p class="{{ $rotulo }}">Fuera de PPQ</p>
                    <p class="{{ $plata }} @if ($dinero['saldo_fuera_ppq'] > 0) !text-amber-700 dark:!text-amber-400 @endif">${{ number_format($dinero['saldo_fuera_ppq'], 2) }}</p>
                    <p class="{{ $pie }}">{{ $dinero['documentos_fuera_ppq'] }} sin ingresar · trabajo nuestro</p>
                </a>

                <a href="{{ route('rutas.documentos.index', $enlaceBase + ['ppq' => $bandeja::PPQ_PENDIENTE, 'saldo' => $bandeja::SALDO_CON]) }}"
                   class="{{ $cajaLink }}">
                    <p class="{{ $rotulo }}">En PPQ sin pagar</p>
                    <p class="{{ $plata }}">${{ number_format($dinero['saldo_en_ppq'], 2) }}</p>
                    <p class="{{ $pie }}">{{ $dinero['documentos_en_ppq'] }} presentados · hay que cobrar</p>
                </a>

                <a href="{{ route('rutas.documentos.index', $enlaceBase + ['requiere_nc' => '1']) }}"
                   class="{{ $cajaLink }}">
                    <p class="{{ $rotulo }}">NC aceptada por aplicar</p>
                    <p class="{{ $plata }}">${{ number_format($dinero['nc_aceptada'], 2) }}</p>
                    <p class="{{ $pie }}">Calleja todavía no la descontó</p>
                </a>
            </div>

            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="{{ $caja }}">
                    <p class="{{ $rotulo }}">Facturado</p>
                    <p class="{{ $plata }}">${{ number_format($dinero['facturado'], 2) }}</p>
                    <p class="{{ $pie }}">en el período</p>
                </div>
                <div class="{{ $caja }}">
                    <p class="{{ $rotulo }}">Cobrado</p>
                    <p class="{{ $plata }} @if ($dinero['cobrado'] > 0) !text-green-700 dark:!text-green-400 @endif">${{ number_format($dinero['cobrado'], 2) }}</p>
                    <p class="{{ $pie }}">conciliado en el TXT de Calleja</p>
                </div>
                <div class="{{ $caja }}">
                    <p class="{{ $rotulo }}">NC ya aplicada</p>
                    <p class="{{ $plata }}">${{ number_format($dinero['nc_aplicada'], 2) }}</p>
                    <p class="{{ $pie }}">descontada por Calleja</p>
                </div>
            </div>

            {{-- ===================== BANDA 2 · Calidad del dato =====================
                 Va acá arriba, pegada al dinero, y no escondida al final. Un total que
                 se traga los huecos parece exacto: si hay documentos sin monto, quien
                 mira el saldo tiene que enterarse en el mismo golpe de vista, no
                 después. Cuando no hay ningún hueco igual se dice —que conste que se
                 revisó—, en una línea en vez de cuatro tarjetas. --}}
            <h3 class="mt-6 mb-2 text-sm font-semibold text-gray-700 dark:text-paper-200">Calidad del dato</h3>
            @if ($dinero['sin_monto'] > 0 || $dinero['saldo_desconocido'] > 0)
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="{{ $caja }} ring-amber-200 dark:ring-amber-900">
                        <p class="{{ $rotulo }}">Documentos sin monto</p>
                        <p class="{{ $numero }} @if ($dinero['sin_monto'] > 0) !text-amber-700 dark:!text-amber-400 @endif">{{ $dinero['sin_monto'] }}</p>
                        <p class="{{ $pie }}">quedan FUERA de las sumas de arriba</p>
                    </div>
                    <a href="{{ route('rutas.documentos.index', $enlaceBase + ['saldo' => $bandeja::SALDO_DESCONOCIDO]) }}"
                       class="{{ $cajaLink }} hover:ring-amber-300">
                        <p class="{{ $rotulo }}">Saldo desconocido</p>
                        <p class="{{ $numero }} @if ($dinero['saldo_desconocido'] > 0) !text-amber-700 dark:!text-amber-400 @endif">{{ $dinero['saldo_desconocido'] }}</p>
                        <p class="{{ $pie }}">no se pudo calcular · no se dan por cobrados</p>
                    </a>
                </div>
            @else
                <div class="{{ $caja }} flex items-center gap-2">
                    <span class="text-green-600 dark:text-green-400" aria-hidden="true">✓</span>
                    <p class="text-sm text-gray-600 dark:text-paper-300">
                        Todos los documentos del período tienen monto y saldo calculable: los totales de arriba están completos.
                    </p>
                </div>
            @endif

            {{-- ===================== BANDA 3 · Antigüedad del saldo =====================
                 Los tramos se leen de Cobranza::TRAMOS y no se escriben acá: si mañana
                 cambian, cambian en un solo sitio. «Sin fecha» va aparte y nunca dentro
                 de 0-30: no tener fecha no es ser reciente, es no saber. --}}
            <h3 class="mt-6 mb-2 text-sm font-semibold text-gray-700 dark:text-paper-200">Antigüedad del saldo</h3>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                @foreach (array_keys(\App\Services\Rutas\Cobranza::TRAMOS) as $tramo)
                    @php $celda = $antiguedad[$tramo]; $viejo = $tramo === '90+'; @endphp
                    <a href="{{ route('rutas.documentos.index', $enlaceBase + ['antiguedad' => $tramo, 'saldo' => $bandeja::SALDO_CON]) }}"
                       class="{{ $cajaLink }} {{ $viejo ? 'hover:ring-red-300' : '' }} {{ $viejo && $celda['monto'] > 0 ? '!ring-red-300 dark:!ring-red-800' : '' }}">
                        <p class="{{ $rotulo }}">{{ $tramo }} días</p>
                        <p class="{{ $plata }} @if ($viejo && $celda['monto'] > 0) !text-red-700 dark:!text-red-400 @endif">${{ number_format($celda['monto'], 2) }}</p>
                        <p class="{{ $pie }}">{{ $celda['documentos'] }} documento{{ $celda['documentos'] === 1 ? '' : 's' }}</p>
                    </a>
                @endforeach
                <a href="{{ route('rutas.documentos.index', $enlaceBase + ['antiguedad' => 'sin_fecha', 'saldo' => $bandeja::SALDO_CON]) }}"
                   class="{{ $cajaLink }}">
                    <p class="{{ $rotulo }}">Sin fecha</p>
                    <p class="{{ $plata }}">${{ number_format($antiguedad['sin_fecha']['monto'], 2) }}</p>
                    <p class="{{ $pie }}">{{ $antiguedad['sin_fecha']['documentos'] }} sin fecha de emisión</p>
                </a>
            </div>

            {{-- ===================== BANDA 4 · Documentación pendiente =====================
                 La pregunta sin dinero: «¿qué papeles me faltan?». --}}
            <h3 class="mt-6 mb-2 text-sm font-semibold text-gray-700 dark:text-paper-200">Documentación pendiente</h3>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <a href="{{ route('rutas.documentos.index', $enlaceBase + ['entrega' => $bandeja::ENTREGA_SIN_ALBARAN]) }}"
                   class="{{ $cajaLink }}">
                    <p class="{{ $rotulo }}">Sin albarán</p>
                    <p class="{{ $numero }}">{{ $resumen['sin_albaran'] }}</p>
                    <p class="{{ $pie }}">no consta la entrega</p>
                </a>
                <a href="{{ route('rutas.documentos.index', $enlaceBase + ['papel' => $bandeja::PAPEL_PENDIENTE]) }}"
                   class="{{ $cajaLink }}">
                    <p class="{{ $rotulo }}">Papel pendiente</p>
                    <p class="{{ $numero }}">{{ $resumen['total'] - $resumen['documentacion_fisica'] }}</p>
                    <p class="{{ $pie }}">no volvió firmado</p>
                </a>
                <a href="{{ route('rutas.documentos.index', $enlaceBase + ['requiere_nc' => '1']) }}"
                   class="{{ $cajaLink }}">
                    <p class="{{ $rotulo }}">Requieren NC</p>
                    <p class="{{ $numero }}">{{ $resumen['requieren_nc'] }}</p>
                    <p class="{{ $pie }}">marcados para corregir</p>
                </a>
                <div class="{{ $caja }}">
                    <p class="{{ $rotulo }}">NC vigentes</p>
                    <p class="{{ $numero }}">{{ $resumen['nc_vigentes'] }}</p>
                    <p class="{{ $pie }}">de {{ $resumen['nc_reales'] }} emitidas</p>
                </div>
            </div>

            {{-- ===================== BANDA 5 · Saldo por ruta =====================
                 Contesta «¿qué ruta tiene que salir a cobrar?». Las filas salen de
                 SaldoPorRuta, que agrupa y delega los montos en Cobranza: la suma de
                 esta tabla y el total de la banda 1 no pueden discrepar porque salen de
                 la misma función. --}}
            <h3 class="mt-6 mb-2 text-sm font-semibold text-gray-700 dark:text-paper-200">Saldo por ruta</h3>
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200 dark:bg-ink-700 dark:text-paper-400 dark:border-ink-600">
                                <th class="py-3 px-4">Ruta</th>
                                <th class="py-3 px-4 text-right">Saldo</th>
                                <th class="py-3 px-4 text-right">Fuera de PPQ</th>
                                <th class="py-3 px-4 text-right">En PPQ sin pagar</th>
                                <th class="py-3 px-4 text-center">Docs.</th>
                                <th class="py-3 px-4">Lo más viejo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                            @forelse ($porRuta as $fila)
                                <tr class="hover:bg-gray-50 dark:hover:bg-ink-700">
                                    <td class="py-3 px-4 font-medium text-gray-800 dark:text-paper-100">
                                        <a href="{{ route('rutas.documentos.index', $enlaceBase + array_filter(['ruta_id' => $fila['ruta_id'], 'saldo' => $bandeja::SALDO_CON])) }}"
                                           class="hover:underline">{{ $fila['ruta'] }}</a>
                                    </td>
                                    <td class="py-3 px-4 text-right tabular-nums font-semibold text-gray-800 dark:text-paper-100">${{ number_format($fila['saldo'], 2) }}</td>
                                    <td class="py-3 px-4 text-right tabular-nums text-amber-700 dark:text-amber-400">${{ number_format($fila['fuera_ppq'], 2) }}</td>
                                    <td class="py-3 px-4 text-right tabular-nums text-gray-600 dark:text-paper-300">${{ number_format($fila['en_ppq'], 2) }}</td>
                                    <td class="py-3 px-4 text-center tabular-nums text-gray-600 dark:text-paper-300">{{ $fila['documentos'] }}</td>
                                    <td class="py-3 px-4">
                                        @if ($fila['tramo_viejo'])
                                            <span class="rounded px-1.5 py-0.5 text-xs font-semibold {{ $fila['tramo_viejo'] === '90+' ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' : 'bg-gray-100 text-gray-600 dark:bg-ink-700 dark:text-paper-300' }}">
                                                {{ $fila['tramo_viejo'] }} días
                                            </span>
                                        @else
                                            <span class="text-xs text-gray-400 dark:text-paper-500">—</span>
                                        @endif
                                        @if ($fila['sin_fecha'] > 0)
                                            <span class="ml-1 text-[11px] text-gray-400 dark:text-paper-500">+{{ $fila['sin_fecha'] }} sin fecha</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-8 px-4 text-center text-gray-500 dark:text-paper-400">
                                        Ninguna ruta tiene saldo pendiente en este período.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- ===================== Operación =====================
                 Lo que este dashboard ya mostraba. No depende de la ventana de fechas:
                 son estados del catálogo, no del período. --}}
            <h3 class="mt-8 mb-2 text-sm font-semibold text-gray-700 dark:text-paper-200">Operación</h3>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <a href="{{ route('rutas.rutas.index', ['activa' => 1]) }}" class="{{ $cajaLink }}">
                    <p class="{{ $rotulo }}">Rutas activas</p>
                    <p class="{{ $numero }}">{{ $rutasActivas }}</p>
                    <p class="{{ $pie }}">de {{ $rutasTotales }} en total</p>
                </a>
                <a href="{{ route('rutas.salidas.index', ['estado' => 'en_curso']) }}" class="{{ $cajaLink }} hover:ring-amber-300">
                    <p class="{{ $rotulo }}">Salidas en curso</p>
                    <p class="{{ $numero }}">{{ $enCurso }}</p>
                    <p class="{{ $pie }}">viajando ahora mismo</p>
                </a>
                <a href="{{ route('rutas.salidas.index', ['estado' => 'planificada']) }}" class="{{ $cajaLink }}">
                    <p class="{{ $rotulo }}">Salidas planificadas</p>
                    <p class="{{ $numero }}">{{ $planificadas }}</p>
                    <p class="{{ $pie }}">todavía sin arrancar</p>
                </a>
                <div class="{{ $caja }}">
                    <p class="{{ $rotulo }}">Salas sin ruta</p>
                    <p class="{{ $numero }}">{{ $salasSinRuta }}</p>
                    <p class="{{ $pie }}">activas, sin ruta habitual</p>
                </div>
            </div>

            <div class="mt-8">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-paper-200">Últimas salidas</h3>
                    <a href="{{ route('rutas.salidas.index') }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">Ver todas</a>
                </div>

                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200 dark:bg-ink-700 dark:text-paper-400 dark:border-ink-600">
                                    <th class="py-3 px-4">Ruta</th>
                                    <th class="py-3 px-4">Fechas</th>
                                    <th class="py-3 px-4">Vendedores</th>
                                    <th class="py-3 px-4">Estado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                                @forelse ($ultimas as $salida)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-ink-700">
                                        <td class="py-3 px-4 font-medium text-gray-800 dark:text-paper-100">
                                            <a href="{{ route('rutas.salidas.show', $salida) }}" class="hover:underline">{{ $salida->ruta->nombre }}</a>
                                        </td>
                                        <td class="py-3 px-4 text-gray-600 dark:text-paper-300">{{ $salida->periodoLegible() }}</td>
                                        <td class="py-3 px-4 text-gray-600 dark:text-paper-300">
                                            {{ $salida->vendedores->pluck('name')->implode(' · ') ?: '—' }}
                                        </td>
                                        <td class="py-3 px-4"><x-rutas.estado-badge :estado="$salida->estado" /></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="py-8 px-4 text-center text-gray-500 dark:text-paper-400">
                                            Todavía no hay salidas registradas.
                                            @can('rutas.gestionar')
                                                <a href="{{ route('rutas.salidas.create') }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Crear la primera</a>.
                                            @endcan
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
