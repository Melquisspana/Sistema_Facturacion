<x-configuracion-layout titulo="Sistema">
    {{--
        Respaldos, cola, salud y entorno.

        Solo los dos campos de respaldos son editables; todo lo demás es evidencia.
        La salud NO se recalcula acá: son los mismos checks de DiagnosticoSistemaService
        que usan el Dashboard y «Salud del sistema».
    --}}

    @php
        // Nombres completos y no `use`: un bloque @php se compila DENTRO de la
        // función de la plantilla, y ahí una declaración `use` es un error de
        // sintaxis de PHP.
        // Los niveles del diagnóstico compartido (correcto/advertencia/critico) se
        // traducen al vocabulario cerrado de los badges del Centro de Configuración.
        $nivelABadge = fn (string $nivel) => match ($nivel) {
            'critico' => \App\Ajustes\Resumen\EstadoTarjeta::Error,
            'advertencia' => \App\Ajustes\Resumen\EstadoTarjeta::Advertencia,
            default => \App\Ajustes\Resumen\EstadoTarjeta::Activo,
        };
    @endphp

    <div class="space-y-6">

        {{-- =================================================== RESPALDOS --}}
        <x-configuracion.seccion
            titulo="Respaldos"
            descripcion="Cada cuánto se limpian, a quién se avisa y qué pasó la última vez.">

            <x-slot name="acciones">
                <x-configuracion.badge :estado="match (true) {
                    ! $respaldos['hay_valido_hoy'] => \App\Ajustes\Resumen\EstadoTarjeta::Error,
                    ! $respaldos['avisos_configurados'] => \App\Ajustes\Resumen\EstadoTarjeta::Advertencia,
                    default => \App\Ajustes\Resumen\EstadoTarjeta::Activo,
                }" />
            </x-slot>

            <form method="POST" action="{{ route('configuracion.sistema.update') }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-configuracion.campo nombre="retencion" etiqueta="Días de retención" :estado="$campos['retencion']"
                                           ayuda="Bajarlo BORRA respaldos existentes en la siguiente limpieza."
                                           clave-tecnica="BACKUP_DIARIO_DIAS_RETENCION">
                        <input type="number" id="retencion" name="retencion" min="1" max="3650"
                               value="{{ old('retencion', $campos['retencion']->valor) }}"
                               class="w-full rounded-md border-gray-300 text-sm">
                    </x-configuracion.campo>

                    <x-configuracion.campo nombre="avisos" etiqueta="Correo de avisos" :estado="$campos['avisos']"
                                           ayuda="Si nadie lo lee, un respaldo roto pasa inadvertido."
                                           clave-tecnica="BACKUP_NOTIFICACIONES_CORREO">
                        <input type="email" id="avisos" name="avisos"
                               value="{{ old('avisos', $campos['avisos']->valor) }}"
                               class="w-full rounded-md border-gray-300 text-sm">
                    </x-configuracion.campo>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        Guardar política de respaldos
                    </button>
                </div>
            </form>

            {{-- ---- Evidencia: qué pasó de verdad ---- --}}
            <div class="mt-5 rounded-md border border-gray-200 bg-gray-50 p-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <dl class="min-w-0 space-y-1 text-sm">
                        <div class="flex flex-wrap gap-2">
                            <dt class="text-gray-500">Respaldo válido de hoy:</dt>
                            <dd class="font-medium {{ $respaldos['hay_valido_hoy'] ? 'text-green-700' : 'text-red-600' }}">
                                {{ $respaldos['hay_valido_hoy'] ? 'sí' : 'no' }}
                            </dd>
                        </div>

                        @if ($respaldos['ultima'])
                            <div class="flex flex-wrap gap-2">
                                <dt class="text-gray-500">Última ejecución:</dt>
                                <dd class="text-gray-700" title="{{ $respaldos['ultima']->terminado_en?->format('d/m/Y H:i') }}">
                                    {{ $respaldos['ultima']->terminado_en?->diffForHumans() ?? '—' }}
                                    &middot; {{ $respaldos['ultima']->exitoso ? 'correcta' : 'CON ERROR' }}
                                    @if ($respaldos['ultima']->origen) &middot; {{ $respaldos['ultima']->origen }} @endif
                                </dd>
                            </div>
                            @if (! $respaldos['ultima']->exitoso && $respaldos['ultima']->mensaje)
                                <p class="text-xs text-red-600">{{ $respaldos['ultima']->mensaje }}</p>
                            @endif
                        @else
                            <p class="text-gray-600">No hay ninguna ejecución de respaldo registrada.</p>
                        @endif

                        @if ($respaldos['ultima_valida'])
                            <div class="flex flex-wrap gap-2">
                                <dt class="text-gray-500">Último respaldo válido:</dt>
                                <dd class="text-gray-700" title="{{ $respaldos['ultima_valida']->terminado_en?->format('d/m/Y H:i') }}">
                                    {{ $respaldos['ultima_valida']->terminado_en?->diffForHumans() ?? '—' }}
                                    @if ($respaldos['tamano']) &middot; {{ $respaldos['tamano'] }} @endif
                                </dd>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <dt class="text-gray-500">Archivo:</dt>
                                <dd class="text-gray-700">
                                    @if ($respaldos['archivo_presente'] === true) presente en disco
                                    @elseif ($respaldos['archivo_presente'] === false) <span class="text-red-600">NO se encuentra</span>
                                    @else no se puede comprobar
                                    @endif
                                </dd>
                            </div>
                        @endif

                        <div class="flex flex-wrap gap-2">
                            <dt class="text-gray-500">Avisos por correo:</dt>
                            <dd class="text-gray-700">
                                {{ $respaldos['avisos_configurados'] ? 'configurados' : 'sin configurar' }}
                            </dd>
                        </div>
                    </dl>

                    @if ($puedeRespaldar)
                        <form method="POST" action="{{ route('configuracion.sistema.respaldar') }}" class="shrink-0">
                            @csrf
                            <button type="submit" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                Generar respaldo ahora
                            </button>
                        </form>
                    @endif
                </div>

                <p class="mt-3 text-xs text-gray-400">
                    Genera un volcado de la base, verifica su firma y lo registra.
                    <span class="font-medium">No modifica ningún dato del negocio.</span>
                </p>
            </div>
        </x-configuracion.seccion>

        {{-- ======================================================== COLA --}}
        <x-configuracion.seccion
            titulo="Cola de trabajos"
            descripcion="Por dónde salen los correos y los procesos en segundo plano.">

            <x-slot name="acciones">
                <x-configuracion.badge :estado="$nivelABadge($cola['nivel'])" />
            </x-slot>

            <p class="text-sm text-gray-600">{{ $cola['mensaje'] }}</p>

            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs text-gray-500">Conexión</dt>
                    <dd class="font-medium text-gray-800">{{ $cola['conexion'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">Driver</dt>
                    <dd class="font-medium text-gray-800">{{ $cola['driver'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">Pendientes</dt>
                    <dd class="font-medium text-gray-800">{{ $cola['pendientes'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">Fallidos</dt>
                    <dd class="font-medium {{ $cola['fallidos'] > 0 ? 'text-red-600' : 'text-gray-800' }}">{{ $cola['fallidos'] }}</dd>
                </div>
            </dl>

            <p class="mt-3 text-xs text-gray-500">
                Última evidencia del worker:
                @if ($cola['ultimo_pulso'])
                    <span title="{{ $cola['ultimo_pulso']->format('d/m/Y H:i:s') }}">{{ $cola['hace'] }}</span>
                @else
                    ninguna todavía — no hay forma confiable de confirmar si está corriendo.
                @endif
            </p>
        </x-configuracion.seccion>

        {{-- ======================================================= SALUD --}}
        <x-configuracion.seccion
            titulo="Salud del sistema"
            descripcion="Los mismos controles que usa el panel de inicio. No se recalculan acá.">

            <x-slot name="acciones">
                <x-configuracion.badge :estado="$nivelABadge($salud['nivel'])" />
            </x-slot>

            @if ($salud['checks'])
                <ul class="divide-y divide-gray-100 rounded-md border border-gray-200">
                    @foreach ($salud['checks'] as $check)
                        <li class="flex flex-wrap items-start justify-between gap-3 px-4 py-2.5 text-sm">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-800">{{ $check['label'] }}</p>
                                <p class="mt-0.5 text-xs text-gray-500">{{ $check['detalle'] }}</p>
                            </div>
                            <x-configuracion.badge :estado="$nivelABadge($check['nivel'])" />
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-gray-500">No se pudieron calcular los controles de salud en este momento.</p>
            @endif
        </x-configuracion.seccion>

        {{-- ===================================================== ENTORNO --}}
        <x-configuracion.seccion
            titulo="Entorno"
            descripcion="Decisiones del servidor. Se muestran para diagnóstico; no se cambian desde aquí.">

            <x-slot name="acciones">
                <x-configuracion.badge :estado="\App\Ajustes\Resumen\EstadoTarjeta::SoloLectura" />
            </x-slot>

            <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ($entorno as $dato)
                    <div class="rounded-md border border-gray-200 px-3 py-2">
                        <dt class="text-xs text-gray-500">{{ $dato['etiqueta'] }}</dt>
                        <dd class="text-sm font-medium text-gray-800">{{ $dato['valor'] }}</dd>
                        @if ($dato['detalle'])
                            <dd class="mt-0.5 text-xs text-gray-500">{{ $dato['detalle'] }}</dd>
                        @endif
                    </div>
                @endforeach
            </dl>

            <p class="mt-4 text-xs text-gray-400">
                Estos valores se cambian en el archivo de configuración del servidor, no desde la aplicación.
                Nunca se muestran credenciales ni rutas del servidor.
            </p>
        </x-configuracion.seccion>
    </div>
</x-configuracion-layout>
