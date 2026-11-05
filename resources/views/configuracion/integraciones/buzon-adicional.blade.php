<x-configuracion-layout titulo="Segundo buzón de compras">
    {{--
        Segundo buzón IMAP para los cambios de correo: mientras los proveedores mandan a
        las dos direcciones, se leen las dos. Igual que el buzón principal: usuario
        PARCIALMENTE TAPADO, contraseña en su propia pantalla y prueba en solo lectura.
        Sin servidor, el segundo buzón está apagado.
    --}}

    <div class="space-y-6">

        {{-- ====================================================== ESTADO --}}
        <x-configuracion.seccion
            titulo="Estado de la conexión"
            descripcion="Se lee igual que el principal: no borra, no mueve y no marca como leído. Un mismo documento que llegue a los dos buzones se registra una sola vez.">

            <x-slot name="acciones">
                <x-configuracion.badge :estado="match (true) {
                    $estado['driver'] !== 'imap' => \App\Ajustes\Resumen\EstadoTarjeta::Desactivado,
                    ! $completa => \App\Ajustes\Resumen\EstadoTarjeta::NoConfigurado,
                    default => \App\Ajustes\Resumen\EstadoTarjeta::Configurado,
                }" />
            </x-slot>

            <div class="flex flex-wrap items-start justify-between gap-4">
                <dl class="min-w-0 space-y-1 text-sm">
                    <div class="flex flex-wrap gap-2">
                        <dt class="text-gray-500">Servidor:</dt>
                        <dd class="break-all font-medium text-gray-800">
                            {{ $estado['host'] ? $estado['host'].':'.$estado['port'] : 'sin configurar (apagado)' }}
                        </dd>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <dt class="text-gray-500">Usuario:</dt>
                        <dd class="break-all text-gray-700">{{ $estado['usuario_parcial'] ?: 'sin configurar' }}</dd>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <dt class="text-gray-500">Contraseña:</dt>
                        <dd class="text-gray-700">{{ $estado['password_configurada'] ? 'configurada' : 'sin configurar' }}</dd>
                    </div>

                    @if ($ultimaPrueba)
                        <div class="flex flex-wrap gap-2 pt-1 text-xs text-gray-500">
                            <dt>Última verificación:</dt>
                            <dd title="{{ $ultimaPrueba->created_at?->format('d/m/Y H:i') }}">
                                {{ $ultimaPrueba->created_at?->diffForHumans() }} &middot; {{ $ultimaPrueba->resultado->etiqueta() }}
                            </dd>
                        </div>
                        @if (! $ultimaPrueba->exitosa() && $ultimaPrueba->mensaje)
                            <p class="text-xs text-red-600">{{ $ultimaPrueba->mensaje }}</p>
                        @endif
                    @else
                        <p class="pt-1 text-xs text-gray-500">Nunca se ha comprobado la conexión con este buzón.</p>
                    @endif
                </dl>

                <form method="POST" action="{{ route('configuracion.integraciones.buzon-adicional.probar') }}" class="shrink-0">
                    @csrf
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Probar conexión
                    </button>
                </form>
            </div>

            @unless ($soportado)
                <p class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    Este servidor no tiene la extensión IMAP de PHP: la revisión del buzón no puede funcionar
                    aquí por más que la configuración sea correcta.
                </p>
            @endunless

            <p class="mt-3 text-xs text-gray-400">
                La prueba abre el buzón en modo solo lectura y lo cierra en el acto.
                <span class="font-medium">No lee correos, no descarga adjuntos y no sincroniza.</span>
            </p>
        </x-configuracion.seccion>

        {{-- ==================================================== CONEXIÓN --}}
        <x-configuracion.seccion
            titulo="Datos de conexión"
            descripcion="Cuando dejes la dirección vieja, borrá el servidor: el sistema vuelve a leer solo el buzón principal.">

            <form method="POST" action="{{ route('configuracion.integraciones.buzon-adicional.update') }}" class="space-y-5">
                @csrf
                @method('PUT')

                @php $seguridad = old('seguridad', $campos['seguridad']->valor ?? 'ssl'); @endphp

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-configuracion.campo nombre="servidor" etiqueta="Servidor" :estado="$campos['servidor']"
                                           ayuda="Por ejemplo imap.mail.yahoo.com. Vacío: el segundo buzón está apagado."
                                           clave-tecnica="DOCUMENTOS_RECIBIDOS_MAIL2_HOST">
                        <input type="text" id="servidor" name="servidor"
                               value="{{ old('servidor', $campos['servidor']->valor) }}"
                               class="w-full rounded-md border-gray-300 text-sm">
                    </x-configuracion.campo>

                    <x-configuracion.campo nombre="puerto" etiqueta="Puerto" :estado="$campos['puerto']"
                                           ayuda="Habitualmente 993 con SSL."
                                           clave-tecnica="DOCUMENTOS_RECIBIDOS_MAIL2_PORT">
                        <input type="number" id="puerto" name="puerto" min="1" max="65535"
                               value="{{ old('puerto', $campos['puerto']->valor) }}"
                               class="w-full rounded-md border-gray-300 text-sm">
                    </x-configuracion.campo>

                    <x-configuracion.campo nombre="seguridad" etiqueta="Seguridad de la conexión" :estado="$campos['seguridad']"
                                           clave-tecnica="DOCUMENTOS_RECIBIDOS_MAIL2_ENCRYPTION">
                        <select id="seguridad" name="seguridad" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="ssl" @selected($seguridad === 'ssl')>SSL</option>
                            <option value="tls" @selected($seguridad === 'tls')>TLS</option>
                            <option value="ninguna" @selected($seguridad === 'ninguna')>Sin cifrado</option>
                        </select>
                    </x-configuracion.campo>

                    <x-configuracion.campo nombre="usuario" etiqueta="Usuario" :estado="$campos['usuario']"
                                           ayuda="Dirección completa del segundo buzón."
                                           clave-tecnica="DOCUMENTOS_RECIBIDOS_MAIL2_USERNAME">
                        <input type="text" id="usuario" name="usuario" autocomplete="off"
                               value="{{ old('usuario', $campos['usuario']->valor) }}"
                               class="w-full rounded-md border-gray-300 text-sm">
                    </x-configuracion.campo>

                    <x-configuracion.campo nombre="carpeta" etiqueta="Carpeta" :estado="$campos['carpeta']"
                                           :ayuda="'Tiene que ser distinta de la del buzón principal («'.$carpetaPrincipal.'»).'"
                                           clave-tecnica="DOCUMENTOS_RECIBIDOS_MAIL2_FOLDER">
                        <input type="text" id="carpeta" name="carpeta"
                               value="{{ old('carpeta', $campos['carpeta']->valor) }}"
                               class="w-full rounded-md border-gray-300 text-sm">
                    </x-configuracion.campo>
                </div>

                <div class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:justify-end">
                    <a href="{{ route('configuracion.integraciones.buzon-adicional') }}"
                       class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Cancelar
                    </a>
                    <button type="submit"
                            class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">
                        Guardar buzón
                    </button>
                </div>
            </form>

            {{-- ---- Contraseña: estado y acción, nunca el valor ---- --}}
            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-md border border-gray-200 bg-gray-50 p-4">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-800">Contraseña del segundo buzón</p>
                    <p class="mt-0.5 text-xs text-gray-500">
                        @if ($secreto->configurado)
                            <span class="tracking-widest">••••••••</span>
                            &middot; Configurada &middot; Fuente: {{ $secreto->fuente->etiqueta() }}
                        @else
                            Sin configurar: el servidor rechazará la autenticación.
                        @endif
                    </p>
                </div>

                <a href="{{ route('configuracion.integraciones.buzon-adicional.secreto.edit') }}"
                   class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    {{ $secreto->configurado ? 'Reemplazar' : 'Configurar' }}
                </a>
            </div>
        </x-configuracion.seccion>
    </div>
</x-configuracion-layout>
