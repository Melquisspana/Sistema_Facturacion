<x-configuracion-layout titulo="Correo">
    {{--
        Una pantalla, tres cosas distintas que antes estaban repartidas o no
        existían:

          1. SERVIDOR SMTP        — por dónde sale el correo.
          2. DOCUMENTOS FISCALES  — auto-envío, JWS y plantilla.
          3. CONTABILIDAD         — resumen y enlace a su pantalla.

        La contraseña del servidor NO tiene campo en este formulario: vive en su
        propia pantalla porque la confirmación de los demás campos reenvía valores
        en campos ocultos, y un secreto no puede hacer ese viaje.
    --}}

    <div class="space-y-6">

        {{-- ============================================================ SMTP --}}
        <x-configuracion.seccion
            titulo="Servidor de correo (SMTP)"
            descripcion="Por dónde salen los documentos hacia los clientes y hacia contabilidad.">

            <x-slot name="acciones">
                <x-configuracion.badge :estado="$smtp['servidor']->configurado
                    ? \App\Ajustes\Resumen\EstadoTarjeta::Configurado
                    : \App\Ajustes\Resumen\EstadoTarjeta::NoConfigurado" />
            </x-slot>

            <form method="POST" action="{{ route('configuracion.correo.smtp.update') }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-configuracion.campo nombre="servidor" etiqueta="Servidor" :estado="$smtp['servidor']"
                                           ayuda="Por ejemplo smtp.gmail.com." clave-tecnica="MAIL_HOST">
                        <input type="text" id="servidor" name="servidor"
                               value="{{ old('servidor', $smtp['servidor']->valor) }}"
                               class="w-full rounded-md border-gray-300 text-sm" placeholder="smtp.ejemplo.com">
                    </x-configuracion.campo>

                    <x-configuracion.campo nombre="puerto" etiqueta="Puerto" :estado="$smtp['puerto']"
                                           ayuda="587 para STARTTLS, 465 para TLS implícito." clave-tecnica="MAIL_PORT">
                        <input type="number" id="puerto" name="puerto" min="1" max="65535"
                               value="{{ old('puerto', $smtp['puerto']->valor) }}"
                               class="w-full rounded-md border-gray-300 text-sm" placeholder="587">
                    </x-configuracion.campo>

                    <x-configuracion.campo nombre="seguridad" etiqueta="Seguridad de la conexión" :estado="$smtp['seguridad']"
                                           ayuda="La opción automática deja que decida el puerto." clave-tecnica="MAIL_SCHEME">
                        @php $seguridadActual = old('seguridad', $smtp['seguridad']->valor ?? 'auto'); @endphp
                        <select id="seguridad" name="seguridad" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="auto" @selected($seguridadActual === 'auto')>Automática (según el puerto)</option>
                            <option value="smtp" @selected($seguridadActual === 'smtp')>STARTTLS</option>
                            <option value="smtps" @selected($seguridadActual === 'smtps')>TLS implícito</option>
                        </select>
                    </x-configuracion.campo>

                    <x-configuracion.campo nombre="usuario" etiqueta="Usuario" :estado="$smtp['usuario']"
                                           ayuda="En Gmail y similares, la dirección completa." clave-tecnica="MAIL_USERNAME">
                        <input type="text" id="usuario" name="usuario" autocomplete="off"
                               value="{{ old('usuario', $smtp['usuario']->valor) }}"
                               class="w-full rounded-md border-gray-300 text-sm">
                    </x-configuracion.campo>

                    <x-configuracion.campo nombre="remitente" etiqueta="Correo remitente" :estado="$smtp['remitente']"
                                           ayuda="Muchos servidores exigen que coincida con el usuario." clave-tecnica="MAIL_FROM_ADDRESS">
                        <input type="email" id="remitente" name="remitente"
                               value="{{ old('remitente', $smtp['remitente']->valor) }}"
                               class="w-full rounded-md border-gray-300 text-sm">
                    </x-configuracion.campo>

                    <x-configuracion.campo nombre="remitente_nombre" etiqueta="Nombre remitente" :estado="$smtp['remitente_nombre']"
                                           ayuda="Lo que el cliente ve en su bandeja." clave-tecnica="MAIL_FROM_NAME">
                        <input type="text" id="remitente_nombre" name="remitente_nombre"
                               value="{{ old('remitente_nombre', $smtp['remitente_nombre']->valor) }}"
                               class="w-full rounded-md border-gray-300 text-sm">
                    </x-configuracion.campo>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        Guardar servidor
                    </button>
                </div>
            </form>

            {{-- ---- Contraseña: estado + acción, nunca el valor ---- --}}
            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-md border border-gray-200 bg-gray-50 p-4">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-800">Contraseña</p>
                    <p class="mt-0.5 text-xs text-gray-500">
                        @if ($passwordSmtp->configurado)
                            {{-- Puntos DECORATIVOS de longitud fija: no dicen nada del valor. --}}
                            <span class="tracking-widest">••••••••</span>
                            &middot; Configurada &middot; Fuente: {{ $passwordSmtp->fuente->etiqueta() }}
                        @else
                            Sin configurar: el servidor rechazará la autenticación.
                        @endif
                    </p>
                </div>

                <a href="{{ route('configuracion.correo.smtp.password.edit') }}"
                   class="shrink-0 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    {{ $passwordSmtp->configurado ? 'Reemplazar' : 'Configurar' }}
                </a>
            </div>

            {{-- ---- Prueba de conexión ---- --}}
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0 text-xs text-gray-500">
                    <p>Medio de envío activo: <span class="font-medium">{{ $medioEnvio->valor ?? 'sin definir' }}</span></p>
                    @if ($ultimaPrueba)
                        <p title="{{ $ultimaPrueba->created_at?->format('d/m/Y H:i') }}">
                            Última verificación: {{ $ultimaPrueba->created_at?->diffForHumans() }}
                            &middot; {{ $ultimaPrueba->resultado->etiqueta() }}
                        </p>
                        @if (! $ultimaPrueba->exitosa() && $ultimaPrueba->mensaje)
                            <p class="mt-0.5 text-red-600">{{ $ultimaPrueba->mensaje }}</p>
                        @endif
                    @else
                        <p>Nunca se ha comprobado la conexión con este servidor.</p>
                    @endif
                </div>

                <form method="POST" action="{{ route('configuracion.correo.smtp.probar') }}" class="shrink-0">
                    @csrf
                    <button type="submit" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Probar conexión
                    </button>
                </form>
            </div>

            <p class="mt-2 text-xs text-gray-400">
                La prueba se conecta al servidor y comprueba las credenciales. <span class="font-medium">No envía ningún correo.</span>
            </p>
        </x-configuracion.seccion>

        {{-- ============================================ DOCUMENTOS FISCALES --}}
        <x-configuracion.seccion
            titulo="Documentos fiscales"
            descripcion="Qué se envía al cliente cuando se acepta un documento, y con qué texto.">

            <form method="POST" action="{{ route('configuracion.correo.update') }}" class="space-y-5">
                @csrf
                @method('PUT')

                <label class="flex items-start gap-3">
                    <input type="checkbox" name="auto_envio" value="1" @checked($autoEnvio) class="mt-1 rounded border-gray-300 text-indigo-600">
                    <span>
                        <span class="block text-sm font-medium text-gray-800">Enviar automáticamente el documento al cliente al ser aceptado por Hacienda</span>
                        <span class="block text-xs text-gray-500">Si está desactivado, el envío es manual desde la pantalla del documento.</span>
                    </span>
                </label>

                <label class="flex items-start gap-3">
                    <input type="checkbox" name="adjuntar_jws" value="1" @checked($adjuntarJws) class="mt-1 rounded border-gray-300 text-indigo-600">
                    <span>
                        <span class="block text-sm font-medium text-gray-800">Adjuntar el JWS firmado</span>
                        <span class="block text-xs text-gray-500">El PDF y el JSON oficial se adjuntan siempre; el JWS es opcional.</span>
                    </span>
                </label>

                <div>
                    <label for="plantilla" class="mb-1 block text-sm font-medium text-gray-700">Plantilla del cuerpo del correo</label>
                    <textarea id="plantilla" name="plantilla" rows="10"
                              class="w-full rounded-md border-gray-300 font-mono text-sm">{{ old('plantilla', $plantilla) }}</textarea>
                    <div class="mt-2 text-xs text-gray-500">
                        Variables disponibles:
                        @foreach ($variables as $v)
                            <code class="mr-1 inline-block rounded bg-gray-100 px-1.5 py-0.5 text-gray-700">{{ $v }}</code>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        Guardar documentos fiscales
                    </button>
                </div>
            </form>
        </x-configuracion.seccion>

        {{-- ==================================================== CONTABILIDAD --}}
        {{--
            Aquí SOLO se muestra el estado, con enlace a su pantalla. La sección
            existe porque conceptualmente el correo de contabilidad pertenece a
            Correo, pero repetir el formulario en dos sitios sería tener dos
            pantallas que editan la misma fila: no divergirían (las dos escriben
            por la misma API) pero sí obligarían a mantener dos formularios y a
            que el usuario adivine cuál es "el bueno".
        --}}
        <x-configuracion.seccion
            titulo="Contabilidad"
            descripcion="A quién se le manda copia de los documentos.">

            <x-slot name="acciones">
                <x-configuracion.badge :estado="$correoContabilidad
                    ? \App\Ajustes\Resumen\EstadoTarjeta::Configurado
                    : \App\Ajustes\Resumen\EstadoTarjeta::NoConfigurado" />
            </x-slot>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <dl class="min-w-0 space-y-1 text-sm">
                    <div class="flex gap-2">
                        <dt class="text-gray-500">Correo de contabilidad:</dt>
                        <dd class="break-all font-medium text-gray-800">{{ $correoContabilidad ?: 'sin configurar' }}</dd>
                    </div>
                    <div class="flex gap-2">
                        <dt class="text-gray-500">Copia oculta en los envíos:</dt>
                        <dd class="font-medium text-gray-800">{{ $enviarCopia ? 'Activada' : 'Desactivada' }}</dd>
                    </div>
                </dl>

                <a href="{{ route('configuracion.contabilidad.edit') }}"
                   class="shrink-0 rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Configurar contabilidad
                </a>
            </div>
        </x-configuracion.seccion>
    </div>
</x-configuracion-layout>
