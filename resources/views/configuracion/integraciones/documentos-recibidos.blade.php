<x-configuracion-layout titulo="Buzón de compras (IMAP)">
    {{--
        De dónde se leen los documentos que mandan los proveedores.

        El usuario del buzón se muestra PARCIALMENTE TAPADO: alcanza para que quien
        administra confirme de un vistazo qué buzón está configurado, sin que la
        pantalla reparta una dirección completa. La contraseña no llega a esta
        vista: tiene su propia pantalla.
    --}}

    <div class="space-y-6">

        {{-- ====================================================== ESTADO --}}
        <x-configuracion.seccion
            titulo="Estado de la conexión"
            descripcion="El lector es de solo lectura: no borra, no mueve y no marca como leído.">

            <x-slot name="acciones">
                <x-configuracion.badge :estado="match (true) {
                    ! $completa && $estado['driver'] !== 'imap' => \App\Ajustes\Resumen\EstadoTarjeta::Desactivado,
                    ! $completa => \App\Ajustes\Resumen\EstadoTarjeta::NoConfigurado,
                    default => \App\Ajustes\Resumen\EstadoTarjeta::Configurado,
                }" />
            </x-slot>

            <div class="flex flex-wrap items-start justify-between gap-4">
                <dl class="min-w-0 space-y-1 text-sm">
                    <div class="flex flex-wrap gap-2">
                        <dt class="text-gray-500">Servidor:</dt>
                        <dd class="break-all font-medium text-gray-800">
                            {{ $estado['host'] ? $estado['host'].':'.$estado['port'] : 'sin configurar' }}
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

                <form method="POST" action="{{ route('configuracion.integraciones.documentos-recibidos.probar') }}" class="shrink-0">
                    @csrf
                    <button type="submit" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
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
            descripcion="Servidor y credenciales del buzón donde llegan los documentos de compra.">

            <form method="POST" action="{{ route('configuracion.integraciones.documentos-recibidos.update') }}" class="space-y-5">
                @csrf
                @method('PUT')

                @php
                    $lectura = old('lectura', $campos['lectura']->valor ?? 'none');
                    $seguridad = old('seguridad', $campos['seguridad']->valor ?? 'ssl');
                @endphp

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-configuracion.campo nombre="lectura" etiqueta="Lectura del buzón" :estado="$campos['lectura']"
                                           ayuda="Desactivada, las compras se cargan a mano."
                                           clave-tecnica="DOCUMENTOS_RECIBIDOS_MAIL_DRIVER">
                        <select id="lectura" name="lectura" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="imap" @selected($lectura === 'imap')>Activada (IMAP)</option>
                            <option value="none" @selected($lectura === 'none')>Desactivada</option>
                        </select>
                    </x-configuracion.campo>

                    <x-configuracion.campo nombre="servidor" etiqueta="Servidor" :estado="$campos['servidor']"
                                           ayuda="Por ejemplo imap.mail.yahoo.com."
                                           clave-tecnica="DOCUMENTOS_RECIBIDOS_MAIL_HOST">
                        <input type="text" id="servidor" name="servidor"
                               value="{{ old('servidor', $campos['servidor']->valor) }}"
                               class="w-full rounded-md border-gray-300 text-sm">
                    </x-configuracion.campo>

                    <x-configuracion.campo nombre="puerto" etiqueta="Puerto" :estado="$campos['puerto']"
                                           ayuda="Habitualmente 993 con SSL."
                                           clave-tecnica="DOCUMENTOS_RECIBIDOS_MAIL_PORT">
                        <input type="number" id="puerto" name="puerto" min="1" max="65535"
                               value="{{ old('puerto', $campos['puerto']->valor) }}"
                               class="w-full rounded-md border-gray-300 text-sm">
                    </x-configuracion.campo>

                    <x-configuracion.campo nombre="seguridad" etiqueta="Seguridad de la conexión" :estado="$campos['seguridad']"
                                           clave-tecnica="DOCUMENTOS_RECIBIDOS_MAIL_ENCRYPTION">
                        <select id="seguridad" name="seguridad" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="ssl" @selected($seguridad === 'ssl')>SSL</option>
                            <option value="tls" @selected($seguridad === 'tls')>TLS</option>
                            <option value="ninguna" @selected($seguridad === 'ninguna')>Sin cifrado</option>
                        </select>
                    </x-configuracion.campo>

                    <x-configuracion.campo nombre="usuario" etiqueta="Usuario" :estado="$campos['usuario']"
                                           ayuda="Dirección completa del buzón de compras."
                                           clave-tecnica="DOCUMENTOS_RECIBIDOS_MAIL_USERNAME">
                        <input type="text" id="usuario" name="usuario" autocomplete="off"
                               value="{{ old('usuario', $campos['usuario']->valor) }}"
                               class="w-full rounded-md border-gray-300 text-sm">
                    </x-configuracion.campo>
                </div>

                <div class="border-t border-gray-100 pt-4">
                    <p class="mb-3 text-sm font-medium text-gray-700">Qué se revisa</p>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-configuracion.campo nombre="carpeta" etiqueta="Carpeta" :estado="$campos['carpeta']"
                                               clave-tecnica="DOCUMENTOS_RECIBIDOS_MAIL_FOLDER">
                            <input type="text" id="carpeta" name="carpeta"
                                   value="{{ old('carpeta', $campos['carpeta']->valor) }}"
                                   class="w-full rounded-md border-gray-300 text-sm">
                        </x-configuracion.campo>

                        <x-configuracion.campo nombre="busqueda" etiqueta="Filtro de búsqueda" :estado="$campos['busqueda']"
                                               ayuda="ALL revisa toda la carpeta."
                                               clave-tecnica="DOCUMENTOS_RECIBIDOS_MAIL_SEARCH">
                            <input type="text" id="busqueda" name="busqueda"
                                   value="{{ old('busqueda', $campos['busqueda']->valor) }}"
                                   class="w-full rounded-md border-gray-300 font-mono text-sm">
                        </x-configuracion.campo>

                        <x-configuracion.campo nombre="espera" etiqueta="Tiempo de espera (segundos)" :estado="$campos['espera']"
                                               clave-tecnica="DOCUMENTOS_RECIBIDOS_MAIL_TIMEOUT">
                            <input type="number" id="espera" name="espera" min="1" max="120"
                                   value="{{ old('espera', $campos['espera']->valor) }}"
                                   class="w-full rounded-md border-gray-300 text-sm">
                        </x-configuracion.campo>

                        <x-configuracion.campo nombre="limite" etiqueta="Correos por sincronización" :estado="$campos['limite']"
                                               ayuda="Tope de correos que revisa cada sincronización manual."
                                               clave-tecnica="DOCUMENTOS_RECIBIDOS_LIMITE">
                            <input type="number" id="limite" name="limite" min="1" max="500"
                                   value="{{ old('limite', $campos['limite']->valor) }}"
                                   class="w-full rounded-md border-gray-300 text-sm">
                        </x-configuracion.campo>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        Guardar buzón
                    </button>
                </div>
            </form>

            {{-- ---- Contraseña: estado y acción, nunca el valor ---- --}}
            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-md border border-gray-200 bg-gray-50 p-4">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-800">Contraseña del buzón</p>
                    <p class="mt-0.5 text-xs text-gray-500">
                        @if ($secreto->configurado)
                            <span class="tracking-widest">••••••••</span>
                            &middot; Configurada &middot; Fuente: {{ $secreto->fuente->etiqueta() }}
                        @else
                            Sin configurar: el servidor rechazará la autenticación.
                        @endif
                    </p>
                </div>

                <a href="{{ route('configuracion.integraciones.documentos-recibidos.secreto.edit') }}"
                   class="shrink-0 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    {{ $secreto->configurado ? 'Reemplazar' : 'Configurar' }}
                </a>
            </div>

            <p class="mt-4 text-xs text-gray-400">
                Carpeta de adjuntos: <code>{{ $carpeta->valor }}</code> &middot; se administra en el servidor.
            </p>
        </x-configuracion.seccion>
    </div>
</x-configuracion-layout>
