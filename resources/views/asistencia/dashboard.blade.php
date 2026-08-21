{{-- Pantalla de inicio del área Asistencia.

     SOLO CONTEOS DE COSAS QUE EXISTEN. No hay tardanzas, ausentes ni horas
     trabajadas: esas cifras necesitan horarios, y los horarios no existen. Un
     indicador inventado en una pantalla de inicio es peor que ninguno, porque
     nadie vuelve a dudar de él.

     «Marcaciones de hoy» es un conteo, no un historial: responde «¿el lector está
     registrando?», que es lo que se mira al entrar. El historial con filtros es
     otra fase y otra pantalla, y por eso esa tarjeta NO enlaza a ningún lado.

     Todos los números los calcula App\Support\Asistencia\PanelAsistencia. Esta
     vista no consulta nada. --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">Asistencia</h2>
    </x-slot>

    @php
        $tarjeta = 'rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
        $rotulo = 'text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-paper-400';
        $cifra = 'mt-2 text-3xl font-semibold tabular-nums text-gray-800 dark:text-paper-100';
        $nota = 'mt-1 text-sm text-gray-500 dark:text-paper-400';
        $enlace = 'mt-3 inline-block text-sm text-indigo-600 hover:underline dark:text-indigo-300';

        $sinNada = $resumen['empleados_total'] === 0 && $resumen['lectores_total'] === 0;
    @endphp

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">

            <x-asistencia.avisos />

            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                <h1 class="text-2xl font-semibold text-gray-800 dark:text-paper-100">Control de asistencia</h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-paper-300">
                    Marcaciones con lector de huella. El dispositivo manda un número de ranura y este
                    servidor decide de quién es y a qué hora se marcó: <strong>la hora oficial la pone
                    el servidor</strong>, nunca el reloj del lector.
                </p>
                <p class="mt-3 text-xs text-gray-400 dark:text-paper-500">
                    Área independiente de la facturación electrónica: no emite documentos, no toca
                    correlativos y no modifica nada de lo ya emitido.
                </p>
            </div>

            @if ($sinNada)
                {{-- Estado vacío REAL: no hay nada dado de alta. Se dice en qué orden
                     hay que hacer las cosas, porque asignar una ranura exige que
                     exista el lector primero. --}}
                <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-paper-100">Todavía no hay nada configurado</h2>
                    <ol class="mt-3 list-inside list-decimal space-y-1 text-sm text-gray-600 dark:text-paper-300">
                        <li>Dá de alta el <strong>lector</strong> y copiá su token al firmware del ESP32.</li>
                        <li>Dá de alta a las <strong>personas</strong> que van a marcar.</li>
                        <li>Guardá la huella de cada quien <strong>en el sensor</strong> y anotá acá qué ranura le tocó.</li>
                    </ol>
                    <div class="mt-4 flex flex-wrap gap-3">
                        @can('asistencia.dispositivos.gestionar')
                            <a href="{{ route('asistencia.dispositivos.create') }}"
                               class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Dar de alta un lector</a>
                        @endcan
                        @can('asistencia.gestionar')
                            <a href="{{ route('asistencia.empleados.create') }}"
                               class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-ink-600 dark:text-paper-200 dark:hover:bg-ink-700">Dar de alta una persona</a>
                        @endcan
                    </div>
                </div>
            @else
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

                    <div class="{{ $tarjeta }}">
                        <p class="{{ $rotulo }}">Personas activas</p>
                        <p class="{{ $cifra }}">{{ $resumen['empleados_activos'] }}</p>
                        <p class="{{ $nota }}">
                            de {{ $resumen['empleados_total'] }} dadas de alta
                        </p>
                        <a href="{{ route('asistencia.empleados.index') }}" class="{{ $enlace }}">Ver empleados →</a>
                    </div>

                    <div class="{{ $tarjeta }}">
                        <p class="{{ $rotulo }}">Ranuras asignadas</p>
                        <p class="{{ $cifra }}">{{ $resumen['huellas_activas'] }}</p>
                        <p class="{{ $nota }}">asignaciones vigentes en los sensores</p>
                        @if ($resumen['empleados_sin_huella'] > 0)
                            {{-- El único «problema» que esta pantalla se permite señalar,
                                 porque se deduce de los datos y no de una regla inventada:
                                 gente activa que no puede marcar. --}}
                            <p class="mt-3 rounded-md bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                                {{ $resumen['empleados_sin_huella'] }} persona(s) activa(s) sin ranura: no pueden marcar.
                            </p>
                        @endif
                    </div>

                    <div class="{{ $tarjeta }}">
                        <p class="{{ $rotulo }}">Lectores activos</p>
                        <p class="{{ $cifra }}">{{ $resumen['lectores_activos'] }}</p>
                        <p class="{{ $nota }}">
                            @if ($resumen['ultima_conexion'])
                                último contacto {{ $resumen['ultima_conexion']->diffForHumans() }}
                            @else
                                ningún lector se ha comunicado todavía
                            @endif
                        </p>
                        @can('asistencia.dispositivos.gestionar')
                            <a href="{{ route('asistencia.dispositivos.index') }}" class="{{ $enlace }}">Ver lectores →</a>
                        @endcan
                    </div>

                    {{-- SIN enlace: el historial de marcaciones todavía no existe y no
                         se promete una pantalla que no está hecha. --}}
                    <div class="{{ $tarjeta }}">
                        <p class="{{ $rotulo }}">Marcaciones de hoy</p>
                        <p class="{{ $cifra }}">{{ $resumen['marcaciones_hoy'] }}</p>
                        <p class="{{ $nota }}">
                            {{ $resumen['personas_hoy'] }} persona(s) &middot; {{ $resumen['fecha_hoy'] }}
                        </p>
                        <p class="mt-3 text-xs text-gray-400 dark:text-paper-500">
                            Conteo del día en {{ $resumen['zona'] }}. El historial con filtros llega más adelante.
                        </p>
                    </div>
                </div>

                <div class="rounded-xl bg-white p-5 text-sm text-gray-600 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:text-paper-300">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-paper-100">Lo que todavía no está</h2>
                    <p class="mt-2">
                        Historial de marcaciones con filtros, reportes diario y mensual, cálculo de horas
                        trabajadas y enrolamiento de huellas desde el sistema. Se construyen en las fases
                        siguientes; mientras tanto, la huella se guarda en el sensor y acá se anota a quién
                        corresponde cada ranura.
                    </p>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
