<x-configuracion-layout titulo="Gmail (Prontos Pagos)">
    {{--
        Tres bloques bien separados: estado de la conexión, credenciales y
        parámetros de búsqueda.

        NO HAY TOKENS EN NINGUNA VARIABLE DE ESTA VISTA. De la cuenta conectada se
        publican correo, permisos, quién la conectó y desde cuándo; el modelo tiene
        los tokens en $hidden y el controlador ni los lee.
    --}}

    <div class="space-y-6">

        {{-- ==================================================== CONEXIÓN --}}
        <x-configuracion.seccion
            titulo="Conexión con la cuenta"
            descripcion="Qué cuenta de Gmail autorizó el acceso de solo lectura.">

            <x-slot name="acciones">
                <x-configuracion.badge :estado="$conexion['conectada']
                    ? \App\Ajustes\Resumen\EstadoTarjeta::Activo
                    : \App\Ajustes\Resumen\EstadoTarjeta::NoConfigurado" />
            </x-slot>

            <div class="flex flex-wrap items-start justify-between gap-4">
                <dl class="min-w-0 space-y-1 text-sm">
                    @if ($conexion['conectada'])
                        <div class="flex flex-wrap gap-2">
                            <dt class="text-gray-500">Cuenta:</dt>
                            <dd class="break-all font-medium text-gray-800">{{ $conexion['correo'] ?: 'sin correo registrado' }}</dd>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <dt class="text-gray-500">Permisos:</dt>
                            <dd class="break-all text-gray-700">{{ $conexion['scopes'] ?: 'solo lectura' }}</dd>
                        </div>
                        @if ($conexion['conectado_por'])
                            <div class="flex flex-wrap gap-2">
                                <dt class="text-gray-500">Conectada por:</dt>
                                <dd class="text-gray-700">{{ $conexion['conectado_por'] }}</dd>
                            </div>
                        @endif
                        @if ($conexion['desde'])
                            <div class="flex flex-wrap gap-2">
                                <dt class="text-gray-500">Conectada desde:</dt>
                                <dd class="text-gray-700" title="{{ $conexion['desde']->format('d/m/Y H:i') }}">
                                    {{ $conexion['desde']->diffForHumans() }}
                                </dd>
                            </div>
                        @endif
                    @else
                        <p class="text-sm text-gray-600">
                            No hay ninguna cuenta conectada. Sin conexión, el módulo usa únicamente lo ya descargado.
                        </p>
                    @endif

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
                    @endif
                </dl>

                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    @if ($configuracion->completo())
                        <a href="{{ route('ppq.gmail.conectar') }}"
                           class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">
                            {{ $conexion['conectada'] ? 'Reconectar' : 'Conectar' }}
                        </a>
                    @endif

                    <form method="POST" action="{{ route('configuracion.integraciones.gmail.probar') }}">
                        @csrf
                        <button type="submit" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Probar conexión
                        </button>
                    </form>

                    @if ($conexion['conectada'])
                        <form method="POST" action="{{ route('configuracion.integraciones.gmail.desconectar') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">
                                Desconectar
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            <p class="mt-3 text-xs text-gray-400">
                La prueba consulta el perfil de la cuenta. <span class="font-medium">No descarga correos ni sincroniza nada.</span>
                Desconectar borra los permisos guardados; los documentos ya descargados no se tocan.
            </p>
        </x-configuracion.seccion>

        {{-- ================================================ CREDENCIALES --}}
        <x-configuracion.seccion
            titulo="Credenciales de Google"
            descripcion="Lo que la aplicación necesita para pedirle permiso a Google.">

            <form method="POST" action="{{ route('configuracion.integraciones.gmail.update') }}" class="space-y-5">
                @csrf
                @method('PUT')

                <label class="flex items-start gap-3">
                    <input type="checkbox" name="activada" value="1"
                           @checked(old('activada', $campos['activada']->valor))
                           class="mt-1 rounded border-gray-300 text-indigo-600">
                    <span>
                        <span class="block text-sm font-medium text-gray-800">Integración con Gmail activada</span>
                        <span class="block text-xs text-gray-500">
                            Apagada, el módulo no intenta conectarse ni buscar correos. No borra nada de lo ya descargado.
                        </span>
                    </span>
                </label>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-configuracion.campo nombre="client_id" etiqueta="ID de cliente" :estado="$campos['client_id']"
                                           ayuda="Identificador público de la aplicación en la consola de Google."
                                           clave-tecnica="GMAIL_CLIENT_ID">
                        <input type="text" id="client_id" name="client_id" autocomplete="off"
                               value="{{ old('client_id', $campos['client_id']->valor) }}"
                               class="w-full rounded-md border-gray-300 text-sm">
                    </x-configuracion.campo>

                    <x-configuracion.campo nombre="redirect_uri" etiqueta="URL de retorno" :estado="$campos['redirect_uri']"
                                           ayuda="Tiene que coincidir EXACTAMENTE con la registrada en Google."
                                           clave-tecnica="GMAIL_REDIRECT_URI">
                        <input type="text" id="redirect_uri" name="redirect_uri" autocomplete="off"
                               value="{{ old('redirect_uri', $campos['redirect_uri']->valor) }}"
                               class="w-full rounded-md border-gray-300 text-sm">
                    </x-configuracion.campo>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        Guardar credenciales
                    </button>
                </div>
            </form>

            {{-- ---- Secreto: estado y acción, nunca el valor ---- --}}
            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-md border border-gray-200 bg-gray-50 p-4">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-800">Secreto de cliente</p>
                    <p class="mt-0.5 text-xs text-gray-500">
                        @if ($secreto->configurado)
                            <span class="tracking-widest">••••••••</span>
                            &middot; Configurado &middot; Fuente: {{ $secreto->fuente->etiqueta() }}
                        @else
                            Sin configurar: Google rechazará la autorización.
                        @endif
                    </p>
                </div>

                <a href="{{ route('configuracion.integraciones.gmail.secreto.edit') }}"
                   class="shrink-0 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    {{ $secreto->configurado ? 'Reemplazar' : 'Configurar' }}
                </a>
            </div>
        </x-configuracion.seccion>

        {{-- ================================================== BÚSQUEDAS --}}
        <x-configuracion.seccion
            titulo="Dónde buscar"
            descripcion="Qué etiqueta y qué consultas usa el módulo para encontrar albaranes y documentos.">

            <form method="POST" action="{{ route('configuracion.integraciones.gmail.update') }}" class="space-y-5">
                @csrf
                @method('PUT')

                {{-- Este formulario manda TODOS los campos: el guardado es uno solo y
                     compara el conjunto contra lo guardado, así que omitir alguno lo
                     interpretaría como un borrado. --}}
                <input type="hidden" name="activada" value="{{ $campos['activada']->valor ? 1 : 0 }}">
                <input type="hidden" name="client_id" value="{{ $campos['client_id']->valor }}">
                <input type="hidden" name="redirect_uri" value="{{ $campos['redirect_uri']->valor }}">

                <x-configuracion.campo nombre="label_albaranes" etiqueta="Etiqueta de los albaranes" :estado="$campos['label_albaranes']"
                                       ayuda="Etiqueta de Gmail donde el cliente deja los albaranes."
                                       clave-tecnica="PPQ_GMAIL_LABEL">
                    <input type="text" id="label_albaranes" name="label_albaranes"
                           value="{{ old('label_albaranes', $campos['label_albaranes']->valor) }}"
                           class="w-full rounded-md border-gray-300 text-sm">
                </x-configuracion.campo>

                <x-configuracion.campo nombre="enviados_query" etiqueta="Búsqueda de documentos enviados" :estado="$campos['enviados_query']"
                                       ayuda="Se le agrega el número buscado."
                                       clave-tecnica="PPQ_GMAIL_ENVIADOS_QUERY">
                    <input type="text" id="enviados_query" name="enviados_query"
                           value="{{ old('enviados_query', $campos['enviados_query']->valor) }}"
                           class="w-full rounded-md border-gray-300 font-mono text-sm">
                </x-configuracion.campo>

                <x-configuracion.campo nombre="dte_adjunto_query" etiqueta="Filtro de adjunto del documento" :estado="$campos['dte_adjunto_query']"
                                       ayuda="Evita que un número corto gane sobre el correo correcto."
                                       clave-tecnica="PPQ_GMAIL_DTE_ADJUNTO_QUERY">
                    <input type="text" id="dte_adjunto_query" name="dte_adjunto_query"
                           value="{{ old('dte_adjunto_query', $campos['dte_adjunto_query']->valor) }}"
                           class="w-full rounded-md border-gray-300 font-mono text-sm">
                </x-configuracion.campo>

                <div class="flex justify-end">
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        Guardar búsquedas
                    </button>
                </div>
            </form>

            <p class="mt-4 text-xs text-gray-400">
                Carpeta de descargas: <code>{{ $carpeta->valor }}</code> &middot; se administra en el servidor.
            </p>
        </x-configuracion.seccion>
    </div>
</x-configuracion-layout>
