<x-configuracion-layout titulo="Certificado y firmador">
    {{--
        Firmador oficial del Ministerio de Hacienda y certificado del emisor.

        PANTALLA DE SOLO LECTURA. La única acción es «Probar firma», que manda al
        firmador un documento inventado.

        La primera pregunta de cualquiera que abra esta pantalla es «¿dónde veo el
        vencimiento del certificado?». Se responde arriba y sin rodeos, porque la
        respuesta —el certificado no está en esta aplicación— también explica por
        qué no hay un botón para subirlo.
    --}}

    <div class="space-y-6">

        {{-- ====================================================== ESTADO --}}
        <x-configuracion.seccion
            titulo="Estado del firmador"
            descripcion="Qué hay configurado. Esta pantalla no comprueba por su cuenta si el servicio responde: para eso está el botón de abajo.">

            <x-slot name="acciones">
                <x-configuracion.badge :estado="$estado['estado']" />
            </x-slot>

            <p class="text-sm text-gray-700">{{ $estado['resumen'] }}</p>

            <dl class="mt-4 space-y-1 text-sm">
                <div class="flex flex-wrap gap-2">
                    <dt class="text-gray-500">Servicio:</dt>
                    <dd class="break-all font-medium text-gray-800">{{ $estado['url'] ?: 'sin definir' }}</dd>
                </div>
                <div class="flex flex-wrap gap-2">
                    <dt class="text-gray-500">NIT del certificado:</dt>
                    <dd class="font-medium text-gray-800">{{ $estado['nit'] ?: 'sin definir' }}</dd>
                </div>
                <div class="flex flex-wrap gap-2">
                    <dt class="text-gray-500">Contraseña del certificado:</dt>
                    <dd class="text-gray-700">{{ $estado['password_configurada'] ? 'configurada' : 'sin configurar' }}</dd>
                </div>
            </dl>

            {{-- EL CHEQUEO QUE DE VERDAD ROMPE DOCUMENTOS. Si el NIT del certificado
                 y el del emisor divergen, cada documento se firma con el certificado
                 de otro contribuyente y nada más lo detecta. --}}
            <p class="mt-3 rounded-md px-3 py-2 text-xs {{ $estado['coherencia_nit']['ok'] ? 'bg-gray-50 text-gray-600' : 'bg-red-50 font-medium text-red-800' }}">
                {{ $estado['coherencia_nit']['detalle'] }}
            </p>
        </x-configuracion.seccion>

        {{-- ================================================== CERTIFICADO --}}
        <x-configuracion.seccion
            titulo="El certificado"
            descripcion="Dónde está y por qué esta pantalla no puede decir cuándo vence.">

            <p class="text-sm text-gray-700">{{ $estado['certificado_nota'] }}</p>

            <div class="mt-3 space-y-2 text-xs text-gray-600">
                <p>
                    <span class="font-medium text-gray-800">Qué NO se puede mostrar sin mover el certificado:</span>
                    su huella digital SHA-256, su fecha de emisión y su vencimiento. Para calcularlos habría
                    que abrir el archivo <code>.crt</code>, y esta aplicación no lo tiene.
                </p>
                <p>
                    <span class="font-medium text-gray-800">Por qué no se sube por la web:</span> subirlo pondría
                    el material de firma en un segundo sitio, alcanzable desde una sesión de navegador, a cambio
                    de tres datos informativos. El aviso de vencimiento se puede resolver mejor por otra vía
                    —leyendo el certificado en el servidor donde ya está— sin duplicarlo.
                </p>
                <p>
                    <span class="font-medium text-gray-800">Qué sí se puede comprobar:</span> que el firmador esté
                    levantado y que procese una petición de firma. Es lo que hace el botón de abajo.
                </p>
            </div>
        </x-configuracion.seccion>

        {{-- ====================================================== PRUEBA --}}
        <x-configuracion.seccion
            titulo="Probar firma"
            descripcion="Manda al firmador un documento inventado, con NIT de relleno y contraseña falsa.">

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 text-sm">
                    @if ($ultimaPrueba)
                        <p class="text-xs text-gray-500" title="{{ $ultimaPrueba->created_at?->format('d/m/Y H:i') }}">
                            Última verificación: {{ $ultimaPrueba->created_at?->diffForHumans() }}
                            &middot; {{ $ultimaPrueba->resultado->etiqueta() }}
                        </p>
                        @if (! $ultimaPrueba->exitosa() && $ultimaPrueba->mensaje)
                            <p class="mt-1 text-xs text-red-600">{{ $ultimaPrueba->mensaje }}</p>
                        @endif
                    @else
                        <p class="text-xs text-gray-500">Nunca se ha comprobado el firmador desde esta pantalla.</p>
                    @endif

                    @unless ($prueba['puede'])
                        <p class="mt-2 rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600">{{ $prueba['razon'] }}</p>
                    @endunless
                </div>

                @if ($prueba['puede'])
                    <form method="POST" action="{{ route('configuracion.fiscal.firmador.probar') }}" class="shrink-0">
                        @csrf
                        <button type="submit" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Probar firma
                        </button>
                    </form>
                @endif
            </div>

            {{-- Se explica de antemano que el resultado bueno es un RECHAZO. Sin esto,
                 quien lo pulse va a leer "el firmador rechazó el documento" y va a
                 creer que algo falla. --}}
            <p class="mt-3 rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600">
                El resultado correcto es que el firmador <span class="font-medium">rechace</span> el documento:
                significa que está levantado y que procesa la petición hasta darse cuenta de que no tiene ese
                certificado. Si en cambio lo firmara, sería una señal de alarma — un firmador que firma
                cualquier cosa no es un firmador que funciona.
            </p>

            <p class="mt-2 text-xs text-gray-400">
                <span class="font-medium">No se lee ningún documento real, no se usa el certificado ni la
                contraseña reales, no se toca la base de datos y no se envía nada a Hacienda.</span>
            </p>
        </x-configuracion.seccion>

        {{-- ==================================================== AJUSTES --}}
        <x-configuracion.seccion
            titulo="Parámetros del firmador"
            descripcion="Se administran en el archivo de configuración del servidor.">

            <div class="divide-y divide-gray-100">
                @foreach ($ajustes as $ajuste)
                    <x-configuracion.fila-fiscal :ajuste="$ajuste" />
                @endforeach
            </div>
        </x-configuracion.seccion>

        {{-- =================================================== CANDADOS --}}
        <x-configuracion.seccion
            titulo="Candados de la firma"
            descripcion="Los dos interruptores que deciden si se firma, y si esa firma vale.">

            <div class="divide-y divide-gray-100">
                @foreach ($candados as $candado)
                    <x-configuracion.fila-fiscal :ajuste="$candado" />
                @endforeach
            </div>

            <p class="mt-4 text-xs text-gray-500">
                El inventario completo de candados fiscales está en
                <a href="{{ route('configuracion.fiscal.hacienda') }}" class="font-medium text-indigo-600 hover:underline">Hacienda / API</a>.
            </p>
        </x-configuracion.seccion>
    </div>
</x-configuracion-layout>
