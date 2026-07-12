<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Preparar emisión real</h2>
    </x-slot>

    @php
        // Clases literales para el JIT de Tailwind (no interpola variables).
        $colorChip = [
            'ok' => 'bg-green-100 text-green-700',
            'advertencia' => 'bg-amber-100 text-amber-700',
            'critico' => 'bg-rose-100 text-rose-700',
            'info' => 'bg-gray-100 text-gray-600',
        ];
    @endphp

    @php
        $proximoNum = $correlativo['proximo']['siguiente'] ?? null;
        $ultimoNum = $correlativo['proximo']['ultimo_numero'] ?? null;
        $workerActivo = ($worker['estado'] ?? null) === 'activo';
    @endphp

    <div class="py-8" x-data="{ barreraConta: false }">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ session('error') }}</div>
            @endif

            {{-- Intro --}}
            <div class="rounded-md bg-blue-50 border border-blue-200 p-4 text-sm text-blue-800">
                <p class="font-semibold">Esta pantalla es solo un checklist de preparación.</p>
                <p class="mt-1">No emite, no firma, no transmite, no mueve correlativos ni envía correos. Sirve para
                    revisar, cuando se decida, si todo está listo para emitir un CCF real. La emisión real sigue
                    haciéndose desde la ficha del documento, con su doble confirmación y la frase de seguridad.</p>
            </div>

            {{-- 0) Correlativo en GRANDE + barrera anti-Conta Portable (obligatoria) --}}
            <section class="bg-white shadow-sm ring-2 ring-rose-200 sm:rounded-xl p-6 space-y-4">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-rose-600">Correlativo y barrera anti-Conta Portable</h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="rounded-lg bg-gray-50 ring-1 ring-gray-200 p-5 text-center">
                        <p class="text-xs uppercase tracking-wide text-gray-400">Último real aceptado</p>
                        <p class="mt-1 text-4xl font-bold tabular-nums text-gray-800">{{ $ultimoNum !== null ? $ultimoNum : '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-indigo-50 ring-1 ring-indigo-200 p-5 text-center">
                        <p class="text-xs uppercase tracking-wide text-indigo-500">Próximo esperado</p>
                        <p class="mt-1 text-4xl font-bold tabular-nums text-indigo-700">{{ $proximoNum !== null ? $proximoNum : '—' }}</p>
                    </div>
                </div>

                <div class="rounded-md border border-rose-300 bg-rose-50 p-3 text-sm font-semibold text-rose-800">
                    ⚠️ Si Conta Portable ya emitió este correlativo{{ $proximoNum !== null ? ' ('.$proximoNum.')' : '' }}, NO continuar.
                </div>

                <label class="flex items-start gap-3 rounded-md border-2 p-4 cursor-pointer transition"
                       :class="barreraConta ? 'border-emerald-300 bg-emerald-50' : 'border-rose-300 bg-white'">
                    <input type="checkbox" x-model="barreraConta" class="mt-0.5 h-5 w-5 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                    <span class="text-sm text-gray-800">
                        <span class="font-semibold">Confirmo que Conta Portable está detenido o alineado y NO emitió el correlativo{{ $proximoNum !== null ? ' '.$proximoNum : '' }}.</span>
                        <span class="block mt-1 text-xs text-gray-500">
                            Esta barrera es una confirmación manual adicional. No abre producción por sí sola ni cambia ningún candado:
                            solo desbloquea los pasos de preparación de esta pantalla. La emisión real sigue exigiendo su frase y candados aparte.
                        </span>
                    </span>
                </label>

                <div x-show="!barreraConta" x-cloak class="text-xs text-rose-600">
                    Marcá la barrera anti-Conta para ver los pasos de preparación para emisión real.
                </div>
            </section>

            {{-- Worker / cola: estado prominente --}}
            @if ($workerActivo)
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-800 flex items-center gap-2">
                    <span class="inline-block h-2.5 w-2.5 rounded-full bg-green-500"></span>
                    <span><span class="font-semibold">Worker activo</span> — último pulso {{ $worker['hace'] ?? '—' }}. Los correos y jobs en cola se procesan.</span>
                </div>
            @else
                <div class="rounded-md bg-rose-50 border border-rose-300 p-4 text-sm text-rose-800">
                    <p class="flex items-center gap-2 font-semibold">
                        <span class="inline-block h-2.5 w-2.5 rounded-full bg-rose-500"></span>
                        Worker/cola {{ ($worker['estado'] ?? '') === 'inactivo' ? 'posiblemente detenido' : 'sin datos' }}
                        @if (($worker['hace'] ?? null)) — último pulso {{ $worker['hace'] }} @endif
                    </p>
                    <p class="mt-1">Correos y jobs en cola <span class="font-semibold">no saldrán</span>. Arrancá <span class="font-mono">queue:work</span> (start-queue.bat) antes de emitir real.</p>
                </div>
            @endif

            {{-- 1) Estado del sistema --}}
            <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 space-y-3">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">1. Estado del sistema</h3>
                <x-modo-dte-aviso :modo="$estado" />
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">Modo DTE</dt>
                        <dd class="mt-0.5"><span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $colorChip[$estado['color']] ?? $colorChip['info'] }}">{{ $estado['etiqueta'] }}</span></dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">Ambiente configurado</dt>
                        <dd class="mt-0.5 font-medium text-gray-800">{{ $ambienteTransmision }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">¿Modo paralelo seguro?</dt>
                        <dd class="mt-0.5 font-medium {{ !empty($estado['modo_seguro']) ? 'text-green-700' : 'text-rose-700' }}">
                            {{ !empty($estado['modo_seguro']) ? 'Sí — no emite producción' : 'No — emisión real posible' }}
                        </dd>
                    </div>
                </dl>
            </section>

            {{-- 2) Servicios necesarios --}}
            <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 space-y-3">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">2. Servicios necesarios</h3>
                <ul class="divide-y divide-gray-100">
                    @foreach ($servicios as $s)
                        <li class="flex flex-wrap items-start justify-between gap-2 py-2.5">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-800">{{ $s['label'] }}</div>
                                <div class="text-xs text-gray-500">{{ $s['detalle'] }}</div>
                                @if ($s['clave'] === 'firmador')
                                    {{-- Prueba EN VIVO del firmador, bajo demanda (no bloquea el render). --}}
                                    <div x-data="firmadorProbe()" class="mt-1.5">
                                        <button type="button" @click="probar()" :disabled="cargando"
                                                class="rounded-md bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200 disabled:opacity-50">
                                            <span x-show="!cargando">Probar firmador ahora</span>
                                            <span x-show="cargando" x-cloak>Probando…</span>
                                        </button>
                                        <template x-if="resultado">
                                            <span class="ms-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold"
                                                  :class="resultado.disponible ? 'bg-green-100 text-green-700' : 'bg-rose-100 text-rose-700'"
                                                  x-text="resultado.disponible ? 'Firmador disponible' : 'No responde'"></span>
                                        </template>
                                        <p class="mt-0.5 text-xs text-gray-400" x-show="resultado" x-cloak x-text="resultado?.mensaje"></p>
                                    </div>
                                @endif
                            </div>
                            <span class="shrink-0 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $colorChip[$s['estado']] ?? $colorChip['info'] }}">{{ $s['valor'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- 3) Seguridad --}}
            <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 space-y-3">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">3. Seguridad</h3>

                <div>
                    <p class="text-sm text-gray-700 font-medium">Candados de transmisión</p>
                    @if (empty($estado['candados']['razones']))
                        <p class="mt-1 text-sm text-rose-700">No hay candados que bloqueen una transmisión real: revisá con cuidado antes de continuar.</p>
                    @else
                        <ul class="mt-1 space-y-1">
                            @foreach ($estado['candados']['razones'] as $razon)
                                <li class="flex items-start gap-2 text-sm text-gray-600">
                                    <span class="mt-0.5 inline-block h-4 w-4 shrink-0 rounded-full bg-green-100 text-center text-xs leading-4 text-green-700">✓</span>
                                    <span>{{ $razon }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="rounded-md border border-amber-300 bg-amber-50 p-4">
                    <p class="text-sm font-semibold text-amber-800">Frase requerida para emitir real</p>
                    <p class="mt-1 font-mono text-base font-bold tracking-wide text-amber-900 select-all">{{ $frase }}</p>
                    <p class="mt-2 text-xs text-amber-700">
                        Al emitir de verdad, esta frase se escribe a mano en la ficha del documento. <br>
                        <span class="font-semibold">No escribás esta frase si no vas a emitir de verdad.</span>
                        Aquí no hay ningún botón para emitir: esta pantalla es solo checklist.
                    </p>
                </div>
            </section>

            {{-- 4) Correlativo --}}
            <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 space-y-3">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">4. Correlativo</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wide text-gray-400">Último CCF real aceptado (producción)</p>
                        @if ($correlativo['ultimo'])
                            <p class="mt-1 font-mono font-medium text-gray-800">{{ $correlativo['ultimo']['numero_control'] }}</p>
                            <p class="text-xs text-gray-500">
                                {{ $correlativo['ultimo']['fecha'] ?? '—' }} · total ${{ $correlativo['ultimo']['total'] }}
                                @if ($correlativo['ultimo']['sello']) · sello {{ $correlativo['ultimo']['sello'] }} @endif
                            </p>
                        @else
                            <p class="mt-1 text-gray-500">Todavía no hay CCF real aceptado en este sistema.</p>
                        @endif
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wide text-gray-400">Próximo correlativo esperado (CCF producción)</p>
                        @if ($correlativo['proximo'])
                            <p class="mt-1 font-mono font-medium text-gray-800">{{ $correlativo['proximo']['serie'] }} · nº {{ $correlativo['proximo']['siguiente'] }}</p>
                            <p class="text-xs text-gray-500">Último asignado en el sistema: {{ $correlativo['proximo']['ultimo_numero'] }}.</p>
                        @else
                            <p class="mt-1 text-gray-500">No hay correlativo de CCF de producción configurado.</p>
                        @endif
                    </div>
                </div>
                <div class="rounded-md border border-amber-300 bg-amber-50 p-3 text-xs text-amber-800">
                    Confirmá manualmente que <span class="font-semibold">Conta Portable está detenido o alineado</span> antes de emitir desde este sistema:
                    el próximo número real debe continuar la numeración sin chocar con lo que Conta ya emitió.
                </div>
            </section>

            {{-- 5) Higiene de configuración (SOLO REPORTE — no cambia .env ni nada) --}}
            <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 space-y-3">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">5. Higiene de configuración (solo reporte)</h3>
                <p class="text-xs text-gray-500">Flags que conviene cerrar para un "paralelo limpio". Esta pantalla <span class="font-semibold">no cambia .env</span> ni ningún candado: solo informa. Los cambios los hace el operador a mano.</p>
                <ul class="divide-y divide-gray-100">
                    @foreach ($higiene as $h)
                        <li class="flex flex-wrap items-start justify-between gap-2 py-2.5">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-800">{{ $h['label'] }}
                                    <span class="ms-1 font-mono text-xs text-gray-400">{{ $h['env'] }}</span>
                                </div>
                                <div class="text-xs text-gray-500">{{ $h['motivo'] }}</div>
                                <div class="text-xs text-gray-400">Actual: <span class="font-medium text-gray-600">{{ $h['actual'] }}</span> · Recomendado: {{ $h['recomendado'] }}</div>
                            </div>
                            <span class="shrink-0 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $h['ok'] ? $colorChip['ok'] : $colorChip['advertencia'] }}">
                                {{ $h['ok'] ? 'ok' : 'revisar' }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- 6) Backup --}}
            <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 space-y-3">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">6. Backup de base de datos</h3>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="text-sm">
                        @if ($backup['existe'])
                            <p class="font-medium text-gray-800">Último backup: {{ $backup['nombre'] }}</p>
                            <p class="text-xs text-gray-500">{{ $backup['fecha'] }} · {{ $backup['tamano'] }} · {{ $backup['detalle'] }}</p>
                        @else
                            <p class="font-medium text-gray-800">Sin backups encontrados</p>
                            <p class="text-xs text-gray-500">{{ $backup['detalle'] }}</p>
                        @endif
                    </div>
                    <span class="shrink-0 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ empty($backup['es_hoy']) ? $colorChip['critico'] : $colorChip['ok'] }}">
                        {{ !empty($backup['es_hoy']) ? 'de hoy' : 'no es de hoy' }}
                    </span>
                </div>
                @if (empty($backup['es_hoy']))
                    <div class="rounded-md border border-rose-300 bg-rose-50 p-3 text-sm font-semibold text-rose-800">
                        ⚠️ No hay backup de HOY. Generá un backup del día antes de emitir un CCF real.
                    </div>
                @endif
                @if ($puedeBackup)
                    <form method="POST" action="{{ route('facturacion.preparar-produccion.backup') }}"
                          onsubmit="return confirm('¿Generar un backup solo de base de datos ahora? No emite ni transmite nada.');">
                        @csrf
                        <button class="rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">
                            Generar backup solo de BD
                        </button>
                        <span class="ms-2 text-xs text-gray-400">Genera un respaldo (--only-db, sin notificaciones). No emite ni transmite.</span>
                    </form>
                @else
                    <p class="text-xs text-gray-400">La generación de backup está disponible para administradores.</p>
                @endif
            </section>

            {{-- Aviso cuando la barrera no está confirmada: los pasos quedan bloqueados. --}}
            <div x-show="!barreraConta" x-cloak class="rounded-md border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                <span class="font-semibold">Pasos de preparación bloqueados.</span>
                Confirmá la barrera anti-Conta Portable (arriba) para ver el flujo recomendado de emisión real.
            </div>

            {{-- 7) Flujo recomendado — solo visible tras confirmar la barrera anti-Conta --}}
            <section x-show="barreraConta" x-cloak class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">7. Flujo recomendado</h3>
                <ol class="mt-3 space-y-1.5 text-sm text-gray-700 list-decimal list-inside">
                    <li>Confirmar que Conta Portable está detenido o alineado.</li>
                    <li>Generar backup de base de datos.</li>
                    <li>Verificar que el worker/cola esté activo.</li>
                    <li>Verificar que el firmador Java responda.</li>
                    <li>Verificar conexión a internet estable.</li>
                    <li>Crear el CCF (cliente, OC, productos, totales).</li>
                    <li>Revisar el PDF, el cliente, la orden de compra y los totales.</li>
                    <li>Escribir la frase <span class="font-mono font-semibold">{{ $frase }}</span> solo si se va a emitir real.</li>
                    <li>Firmar y transmitir desde la ficha del documento.</li>
                    <li>Verificar sello de recepción, estado y PDF final.</li>
                    <li>Revertir el sistema a PARALELO SEGURO.</li>
                </ol>
            </section>

        </div>
    </div>

    <script>
        function firmadorProbe() {
            return {
                cargando: false,
                resultado: null,
                async probar() {
                    this.cargando = true;
                    this.resultado = null;
                    try {
                        const r = await fetch('{{ route('facturacion.preparar-produccion.firmador') }}', {
                            headers: { 'Accept': 'application/json' },
                        });
                        this.resultado = await r.json();
                    } catch (e) {
                        this.resultado = { disponible: false, mensaje: 'No se pudo consultar el firmador.' };
                    } finally {
                        this.cargando = false;
                    }
                },
            };
        }
    </script>
</x-app-layout>
