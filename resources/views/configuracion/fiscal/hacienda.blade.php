<x-configuracion-layout titulo="Hacienda / API">
    {{--
        Conexión con el Ministerio de Hacienda.

        PANTALLA DE SOLO LECTURA. No hay formularios ni botón de guardar, y eso se
        dice arriba con todas las letras: si el usuario tiene que deducirlo de la
        ausencia de un botón, la mitad de las veces deducirá que la pantalla está
        rota.

        La única acción es «Probar conexión», y su alcance exacto —inicia sesión
        contra pruebas, no envía documentos— está escrito junto al botón, no
        escondido en una ayuda.
    --}}

    <div class="space-y-6">

        {{-- ================================================== AMBIENTES --}}
        <x-configuracion.seccion
            titulo="Estado de la conexión"
            descripcion="Qué ambiente viaja dentro del documento y contra qué cuenta del Ministerio de Hacienda se autentica el sistema.">

            <x-slot name="acciones">
                <x-configuracion.badge :estado="$estado['estado']" />
            </x-slot>

            <p class="text-sm text-gray-700">{{ $estado['resumen'] }}</p>

            {{-- LAS DOS PREGUNTAS, UNA AL LADO DE LA OTRA. Es la única forma de que
                 se vea que son ajustes distintos: en dos secciones separadas nadie
                 los compara, y cruzarlos es el error más caro de esta pantalla. --}}
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="rounded-lg border border-gray-200 p-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Dentro del documento</p>
                    <p class="mt-1 text-sm font-medium text-gray-900">
                        {{ $estado['ambiente_documento'] }} — {{ $estado['ambiente_documento_etiqueta'] }}
                    </p>
                    <p class="mt-1 text-xs text-gray-500">
                        Es el ambiente que el Ministerio de Hacienda lee dentro del documento y el que decide
                        si tiene validez fiscal.
                    </p>
                </div>

                <div class="rounded-lg border border-gray-200 p-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Credenciales</p>
                    <p class="mt-1 text-sm font-medium {{ $estado['es_produccion'] ? 'text-red-700' : 'text-gray-900' }}">
                        {{ $estado['es_produccion'] ? 'PRODUCCIÓN — cuenta real' : 'Pruebas (apitest)' }}
                    </p>
                    <p class="mt-1 text-xs text-gray-500">
                        Decide contra qué cuenta y qué servidor del Ministerio de Hacienda se inicia sesión.
                    </p>
                </div>
            </div>

            <p class="mt-3 rounded-md px-3 py-2 text-xs {{ $estado['coherencia']['ok'] ? 'bg-gray-50 text-gray-600' : 'bg-red-50 font-medium text-red-800' }}">
                {{ $estado['coherencia']['detalle'] }}
            </p>
        </x-configuracion.seccion>

        {{-- ================================================ CREDENCIALES --}}
        <x-configuracion.seccion
            titulo="Credenciales y acceso"
            descripcion="Esta pantalla dice si hay credenciales y de qué variables salen. Nunca muestra su valor, y no hay forma de escribirlas desde aquí.">

            <dl class="space-y-1 text-sm">
                <div class="flex flex-wrap gap-2">
                    <dt class="text-gray-500">Credenciales del ambiente activo:</dt>
                    <dd class="font-medium text-gray-800">{{ $estado['credenciales_configuradas'] ? 'Configuradas' : 'Sin configurar' }}</dd>
                </div>
                {{-- QUÉ variables alimentan el acceso, sin revelar ningún valor. Sin
                     este dato es imposible saber si producción está usando sus
                     credenciales o cayó de vuelta a las antiguas: los valores ya
                     vienen resueltos y las dos rutas se ven idénticas. --}}
                <div class="flex flex-wrap gap-2">
                    <dt class="text-gray-500">De dónde salen:</dt>
                    <dd class="text-gray-700">{{ $estado['fuente_detalle'] }}</dd>
                </div>
                <div class="flex flex-wrap gap-2">
                    <dt class="text-gray-500">Token fijado a mano:</dt>
                    <dd class="text-gray-700">{{ $estado['token_manual'] ? 'sí — se usa tal cual y no se pide uno nuevo' : 'no' }}</dd>
                </div>
                <div class="flex flex-wrap gap-2">
                    <dt class="text-gray-500">Token en memoria:</dt>
                    <dd class="text-gray-700">
                        {{ $estado['token_cacheado'] ? 'sí' : 'no' }}
                        &middot; vigencia oficial {{ $estado['vigencia_horas'] }} h
                    </dd>
                </div>
            </dl>

            <p class="mt-3 rounded-md bg-purple-50 px-3 py-2 text-xs text-purple-900">
                Las credenciales del Ministerio de Hacienda no se administran desde la web, y no es algo
                pendiente de hacer: con ellas se emiten documentos fiscales en nombre de la empresa. Una
                pantalla que las escriba convierte una sesión de administrador abierta en capacidad de
                facturar. Se cambian en el archivo de configuración del servidor.
            </p>
        </x-configuracion.seccion>

        {{-- ====================================================== PRUEBA --}}
        <x-configuracion.seccion
            titulo="Probar conexión"
            descripcion="Comprueba el acceso al ambiente de PRUEBAS del Ministerio de Hacienda.">

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
                        <p class="text-xs text-gray-500">Nunca se ha comprobado el acceso desde esta pantalla.</p>
                    @endif

                    @unless ($prueba['puede'])
                        <p class="mt-2 rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600">{{ $prueba['razon'] }}</p>
                    @endunless
                </div>

                {{-- El botón desaparece cuando no se puede probar, y el motivo queda a
                     la izquierda. Un botón que siempre se puede pulsar y siempre
                     contesta "bloqueado" enseña a ignorar el mensaje. --}}
                @if ($prueba['puede'])
                    <form method="POST" action="{{ route('configuracion.fiscal.hacienda.probar') }}" class="shrink-0">
                        @csrf
                        <button type="submit" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Probar conexión
                        </button>
                    </form>
                @endif
            </div>

            <p class="mt-3 text-xs text-gray-400">
                La prueba solo inicia sesión contra el ambiente de pruebas.
                <span class="font-medium">No transmite ningún documento, no firma nada y no toca correlativos.</span>
                Contra la cuenta real no hay botón a propósito: esa comprobación existe, pero solo desde
                la consola del servidor.
            </p>
        </x-configuracion.seccion>

        {{-- ================================================== DIRECCIONES --}}
        <x-configuracion.seccion
            titulo="Direcciones y parámetros"
            descripcion="A dónde se conecta el sistema y con qué parámetros. Se administra en el archivo del servidor.">

            <dl class="mb-4 space-y-1 rounded-lg border border-gray-200 p-3 text-sm">
                <div class="flex flex-wrap gap-2">
                    <dt class="text-gray-500">Autenticación (efectiva):</dt>
                    <dd class="break-all font-medium text-gray-800">{{ $estado['url_auth'] }}</dd>
                </div>
                <div class="flex flex-wrap gap-2">
                    <dt class="text-gray-500">Recepción (efectiva):</dt>
                    <dd class="break-all font-medium text-gray-800">{{ $estado['url_recepcion'] }}</dd>
                </div>
                <p class="pt-1 text-xs text-gray-500">
                    «Efectiva» = la dirección que se usaría ahora mismo, ya resuelta. Si la dirección base
                    está vacía, se completa con el servidor oficial del ambiente activo.
                </p>
            </dl>

            <div class="divide-y divide-gray-100">
                @foreach ($ajustes as $ajuste)
                    <x-configuracion.fila-fiscal :ajuste="$ajuste" />
                @endforeach
            </div>
        </x-configuracion.seccion>

        {{-- ==================================================== CANDADOS --}}
        <x-configuracion.seccion
            titulo="Candados fiscales"
            descripcion="Los interruptores que separan «el sistema está preparado» de «el sistema está mandando documentos reales». Hasta ahora vivían repartidos por el código y no había ninguna pantalla donde verlos juntos.">

            <x-slot name="acciones">
                <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $resumenCandados['abiertos'] > 0 ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800' }}">
                    {{ $resumenCandados['abiertos'] }} de {{ $resumenCandados['total'] }} abiertos
                </span>
            </x-slot>

            <p class="mb-4 text-xs text-gray-500">
                Un candado resaltado en ámbar está en su posición de RIESGO. No siempre es «encendido»:
                en el modo de ensayo lo peligroso es tenerlo apagado, porque apagarlo es lo que quita la
                red de seguridad.
            </p>

            <div class="space-y-5">
                @foreach ($candados as $grupo => $filas)
                    <div>
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ $grupo }}</h4>
                        <div class="mt-1 divide-y divide-gray-100">
                            @foreach ($filas as $fila)
                                <x-configuracion.fila-fiscal :ajuste="$fila" />
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="mt-4 rounded-md bg-red-50 px-3 py-2 text-xs text-red-800">
                Ninguno de estos candados se abre desde esta pantalla, ni con confirmación. Abrirlos desde
                la web exigirá la ceremonia completa (permiso crítico, frase exacta y contraseña) y esa
                ceremonia todavía no está conectada a ninguna acción real.
            </p>
        </x-configuracion.seccion>

        {{-- =============================================== CONFIG. MUERTA --}}
        <x-configuracion.seccion
            titulo="Configuración sin efecto"
            descripcion="Claves que existen en el archivo de configuración y que nadie lee, o que dicen lo mismo que otra.">

            <p class="mb-3 text-xs text-gray-500">
                Se listan en vez de borrarse en silencio: quitarlas es un cambio al motor fiscal y merece su
                propia revisión. Lo peligroso no es que sobren — es que alguien las edite creyendo que
                sirven para algo.
            </p>

            <dl class="divide-y divide-gray-100">
                @foreach ($muertas as $muerta)
                    <div class="py-2">
                        <dt class="text-sm font-medium text-gray-800"><code>{{ $muerta['clave'] }}</code></dt>
                        <dd class="mt-0.5 text-xs text-gray-500">{{ $muerta['problema'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-configuracion.seccion>
    </div>
</x-configuracion-layout>
