{{-- Panel de inicio del área Producción (planta).

     SOLO LECTURA. No hay un solo formulario ni un botón de acción: cada tarjeta
     es un conteo y un enlace al listado que lo explica.

     NINGUNA CIFRA ES UNA SUMA DE CANTIDADES. `planta_existencias` guarda libras,
     litros y unidades en la misma columna, así que un total que las mezclara no
     significaría nada físico. Lo que se cuenta son insumos distintos, filas de
     saldo y documentos.

     UNA TARJETA QUE NO SE DIBUJA NO ES UNA TARJETA OCULTA: el controlador ni
     siquiera ejecutó su consulta. `null` es «sin permiso» y `0` es «con permiso
     y sin datos», y son cosas distintas.

     Nada de esta área toca la facturación electrónica. --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Producción</h2>
    </x-slot>

    @php
        // Vocabulario de color del módulo, el mismo que devuelven los enums y el
        // que usa PlantaDashboardQuery::severidadTransito(). Las clases se
        // escriben completas para que Tailwind no las purgue.
        $anillo = [
            'gray' => 'ring-gray-200 dark:ring-ink-600',
            'amber' => 'ring-amber-300 dark:ring-amber-500/40',
            'red' => 'ring-red-300 dark:ring-red-500/40',
        ];
        $tinta = [
            'gray' => 'text-gray-800 dark:text-paper-100',
            'amber' => 'text-amber-700 dark:text-amber-300',
            'red' => 'text-red-700 dark:text-red-300',
        ];
        $rotulo = 'text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-paper-400';
        $cifra = 'mt-2 text-3xl font-semibold tabular-nums';
        $nota = 'mt-1 text-sm text-gray-500 dark:text-paper-400';
        $enlace = 'mt-3 inline-block text-sm text-indigo-600 hover:underline dark:text-indigo-300';
        $tarjeta = 'rounded-xl bg-white p-5 shadow-sm ring-1 dark:bg-ink-800';

        $sinIndicadores = $traslados === null
            && $existencias === null
            && $recepciones === null
            && $lotes === null
            && $ajustes === null;
    @endphp

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                <h1 class="text-2xl font-semibold text-gray-800 dark:text-paper-100">Área de Producción</h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-paper-300">
                    Resumen del inventario de planta: qué hay, qué está detenido y qué necesita atención.
                    Todos los indicadores son <strong>conteos de solo lectura</strong> y cada uno lleva al
                    listado que lo explica.
                </p>
                <p class="mt-2 text-sm text-gray-600 dark:text-paper-300">
                    Las cantidades no se totalizan entre insumos: conviven libras, litros y unidades, y un
                    número que las sumara no significaría nada. Para ver cantidades con su unidad está la
                    pantalla de Existencias.
                </p>
                <p class="mt-3 text-xs text-gray-400 dark:text-paper-500">
                    Esta área es independiente de la facturación electrónica: no emite documentos, no toca
                    correlativos y no modifica nada de lo ya emitido.
                </p>
            </div>

            @if ($sinIndicadores)
                <div class="rounded-xl bg-white p-6 text-sm text-gray-600 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:text-paper-300">
                    No tenés permisos para consultar indicadores operativos. Podés entrar al área, pero los
                    listados de inventario, documentos y catálogos requieren permisos que tu usuario no tiene.
                </div>
            @else
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">

                    {{-- 1. Traslados en tránsito. La tarjeta que más importa: es
                         saldo que salió de una bodega y todavía no llegó a la otra,
                         invisible en ambas si nadie lo mira. --}}
                    @if ($traslados !== null)
                        @php
                            $sev = \App\Support\Planta\PlantaDashboardQuery::severidadTransito(
                                $traslados['cantidad'] > 0 ? $traslados['dias'] : null
                            );
                        @endphp
                        <div class="{{ $tarjeta }} {{ $anillo[$sev] }}">
                            <p class="{{ $rotulo }}">Traslados en tránsito</p>
                            @if ($traslados['cantidad'] === 0)
                                <p class="{{ $cifra }} {{ $tinta['gray'] }}">0</p>
                                <p class="{{ $nota }}">Nada en tránsito.</p>
                            @else
                                <p class="{{ $cifra }} {{ $tinta[$sev] }}">
                                    {{ $traslados['cantidad'] }}
                                    <span class="text-base font-normal text-gray-400 dark:text-paper-500">
                                        {{ $traslados['cantidad'] === 1 ? 'traslado' : 'traslados' }}
                                    </span>
                                </p>
                                <p class="{{ $nota }}">
                                    El más antiguo lleva
                                    <strong>{{ $traslados['dias'] }} {{ $traslados['dias'] === 1 ? 'día' : 'días' }}</strong>.
                                </p>
                            @endif
                            <a href="{{ route('planta.traslados.index', ['estado' => \App\Enums\Planta\EstadoTrasladoPlanta::Enviado->value]) }}"
                               class="{{ $enlace }}">Ver traslados en tránsito</a>
                        </div>
                    @endif

                    {{-- 2. Insumos con existencia disponible. Cuenta INSUMOS
                         DISTINTOS, no filas y no cantidad. --}}
                    @if ($existencias !== null)
                        <div class="{{ $tarjeta }} {{ $anillo['gray'] }}">
                            <p class="{{ $rotulo }}">Insumos con existencia disponible</p>
                            <p class="{{ $cifra }} {{ $tinta['gray'] }}">{{ $existencias['insumosDisponibles'] }}</p>
                            <p class="{{ $nota }}">
                                @if ($existencias['insumosDisponibles'] === 0)
                                    Sin existencias disponibles.
                                @else
                                    Insumos distintos con saldo utilizable.
                                @endif
                            </p>
                            <a href="{{ route('planta.existencias.index', ['estado' => \App\Enums\Planta\EstadoDisponibilidad::Disponible->value]) }}"
                               class="{{ $enlace }}">Ver existencias disponibles</a>
                        </div>
                    @endif

                    {{-- 3. Retenido. Es un CONTEO DE REGISTROS, no una cantidad
                         física: por eso el rótulo dice «registros con saldo». --}}
                    @if ($existencias !== null)
                        @php $sevRet = $existencias['retenidos'] > 0 ? 'amber' : 'gray'; @endphp
                        <div class="{{ $tarjeta }} {{ $anillo[$sevRet] }}">
                            <p class="{{ $rotulo }}">Existencias retenidas</p>
                            <p class="{{ $cifra }} {{ $tinta[$sevRet] }}">
                                {{ $existencias['retenidos'] }}
                                <span class="text-base font-normal text-gray-400 dark:text-paper-500">
                                    {{ $existencias['retenidos'] === 1 ? 'registro con saldo' : 'registros con saldo' }}
                                </span>
                            </p>
                            <p class="{{ $nota }}">
                                @if ($existencias['retenidos'] === 0)
                                    Nada retenido.
                                @else
                                    Saldo existente pero fuera de la operación hasta que calidad lo libere.
                                @endif
                            </p>
                            <a href="{{ route('planta.existencias.index', ['estado' => \App\Enums\Planta\EstadoDisponibilidad::Retenido->value]) }}"
                               class="{{ $enlace }}">Ver existencias retenidas</a>
                        </div>
                    @endif

                    {{-- 4. Rechazado. Mismo criterio: conteo, nunca suma. --}}
                    @if ($existencias !== null)
                        @php $sevRech = $existencias['rechazados'] > 0 ? 'red' : 'gray'; @endphp
                        <div class="{{ $tarjeta }} {{ $anillo[$sevRech] }}">
                            <p class="{{ $rotulo }}">Existencias rechazadas</p>
                            <p class="{{ $cifra }} {{ $tinta[$sevRech] }}">
                                {{ $existencias['rechazados'] }}
                                <span class="text-base font-normal text-gray-400 dark:text-paper-500">
                                    {{ $existencias['rechazados'] === 1 ? 'registro con saldo' : 'registros con saldo' }}
                                </span>
                            </p>
                            <p class="{{ $nota }}">
                                @if ($existencias['rechazados'] === 0)
                                    Nada rechazado.
                                @else
                                    Saldo apartado por calidad. Retirarlo del inventario se hace con un ajuste.
                                @endif
                            </p>
                            <a href="{{ route('planta.existencias.index', ['estado' => \App\Enums\Planta\EstadoDisponibilidad::Rechazado->value]) }}"
                               class="{{ $enlace }}">Ver existencias rechazadas</a>
                        </div>
                    @endif

                    {{-- 5. Recepciones en borrador: trabajo a medio capturar. Un
                         borrador todavía no movió inventario. --}}
                    @if ($recepciones !== null)
                        @php $sevRec = $recepciones > 0 ? 'amber' : 'gray'; @endphp
                        <div class="{{ $tarjeta }} {{ $anillo[$sevRec] }}">
                            <p class="{{ $rotulo }}">Recepciones pendientes de confirmar</p>
                            <p class="{{ $cifra }} {{ $tinta[$sevRec] }}">{{ $recepciones }}</p>
                            <p class="{{ $nota }}">
                                @if ($recepciones === 0)
                                    Sin recepciones pendientes.
                                @else
                                    Borradores que aún no movieron inventario.
                                @endif
                            </p>
                            <a href="{{ route('planta.recepciones.index', ['estado' => \App\Enums\Planta\EstadoRecepcionPlanta::Borrador->value]) }}"
                               class="{{ $enlace }}">Ver recepciones en borrador</a>
                        </div>
                    @endif

                    {{-- 6. Lotes vencidos y por vencer. Solo lotes reales, activos
                         y CON SALDO: un lote vencido y agotado ya no es accionable. --}}
                    @if ($lotes !== null)
                        @php
                            $sevLotes = match (true) {
                                $lotes['vencidos'] > 0 => 'red',
                                $lotes['porVencer'] > 0 => 'amber',
                                default => 'gray',
                            };
                        @endphp
                        <div class="{{ $tarjeta }} {{ $anillo[$sevLotes] }}">
                            <p class="{{ $rotulo }}">Lotes por vencimiento</p>
                            @if ($lotes['vencidos'] === 0 && $lotes['porVencer'] === 0)
                                <p class="{{ $cifra }} {{ $tinta['gray'] }}">0</p>
                                <p class="{{ $nota }}">Sin lotes vencidos ni próximos a vencer.</p>
                            @else
                                <div class="mt-2 flex items-end gap-6">
                                    <div>
                                        <p class="text-3xl font-semibold tabular-nums {{ $lotes['vencidos'] > 0 ? $tinta['red'] : $tinta['gray'] }}">
                                            {{ $lotes['vencidos'] }}
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-paper-400">vencidos</p>
                                    </div>
                                    <div>
                                        <p class="text-3xl font-semibold tabular-nums {{ $lotes['porVencer'] > 0 ? $tinta['amber'] : $tinta['gray'] }}">
                                            {{ $lotes['porVencer'] }}
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-paper-400">por vencer ({{ $diasVentana }} días)</p>
                                    </div>
                                </div>
                                <p class="{{ $nota }}">Solo lotes activos que todavía tienen saldo.</p>
                            @endif
                            <div class="flex flex-wrap gap-4">
                                <a href="{{ route('planta.lotes.index', ['vencimiento' => \App\Support\Planta\LoteQuery::VENCIMIENTO_VENCIDOS, 'activo' => \App\Support\Planta\LoteQuery::ACTIVO_SI]) }}"
                                   class="{{ $enlace }}">Ver vencidos</a>
                                <a href="{{ route('planta.lotes.index', ['vencimiento' => \App\Support\Planta\LoteQuery::VENCIMIENTO_POR_VENCER, 'dias' => $diasVentana, 'activo' => \App\Support\Planta\LoteQuery::ACTIVO_SI]) }}"
                                   class="{{ $enlace }}">Ver próximos a vencer</a>
                            </div>
                        </div>
                    @endif

                    {{-- 7. Ajustes confirmados recientes. Señal de cuánta corrección
                         manual está necesitando el inventario. --}}
                    @if ($ajustes !== null)
                        <div class="{{ $tarjeta }} {{ $anillo['gray'] }}">
                            <p class="{{ $rotulo }}">Ajustes confirmados ({{ $diasVentana }} días)</p>
                            <p class="{{ $cifra }} {{ $tinta['gray'] }}">{{ $ajustes }}</p>
                            <p class="{{ $nota }}">
                                @if ($ajustes === 0)
                                    Sin ajustes confirmados en los últimos {{ $diasVentana }} días.
                                @else
                                    Correcciones de inventario aplicadas en los últimos {{ $diasVentana }} días.
                                @endif
                            </p>
                            <a href="{{ route('planta.ajustes.index', ['estado' => \App\Enums\Planta\EstadoAjustePlanta::Confirmado->value, 'desde' => $desdeVentana]) }}"
                               class="{{ $enlace }}">Ver ajustes confirmados</a>
                        </div>
                    @endif

                </div>
            @endif

        </div>
    </div>
</x-app-layout>
