{{-- EL TOKEN DEL LECTOR, LA ÚNICA VEZ QUE SE VE.

     Llega por `flash`, así que vive exactamente una petición: recargar esta
     página lo hace desaparecer. No hay ninguna ruta que lo devuelva después y en
     base solo está su SHA-256. Si se pierde, no se recupera — se rota otra vez,
     que además es lo correcto si alguien pudo haberlo visto.

     Se muestra en un bloque aparte y no como un `status` normal a propósito: es
     lo único de todo el módulo que hay que copiar ANTES de irse de la pantalla, y
     un mensaje verde de dos líneas no comunica eso.

     `select-all` para poder copiarlo de un clic; `break-all` porque son 64
     caracteres y en móvil no cabe de otra forma. --}}
@if (session('token_generado'))
    <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-500/40 dark:bg-amber-500/10">
        <div class="flex items-start gap-3">
            <x-sidebar-icon name="asistencia" class="mt-0.5 h-5 w-5 shrink-0 text-amber-700 dark:text-amber-300" />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">
                    Token del lector «{{ session('token_generado_codigo') }}» — copialo ahora
                </p>
                <p class="mt-1 text-xs text-amber-800 dark:text-amber-300">
                    Es la única vez que se muestra. El servidor solo guarda su huella criptográfica:
                    no hay forma de volver a verlo. Si lo perdés, se rota otro.
                </p>

                <code class="mt-3 block select-all break-all rounded-md bg-white px-3 py-2 font-mono text-sm text-gray-900 ring-1 ring-amber-300 dark:bg-ink-900 dark:text-paper-100 dark:ring-amber-500/40">{{ session('token_generado') }}</code>

                <p class="mt-3 text-xs text-amber-800 dark:text-amber-300">
                    En el firmware del ESP32 va en las cabeceras de cada marcación:
                </p>
                <pre class="mt-1 overflow-x-auto rounded-md bg-white px-3 py-2 text-xs text-gray-700 ring-1 ring-amber-200 dark:bg-ink-900 dark:text-paper-300 dark:ring-amber-500/30">X-Dispositivo: {{ session('token_generado_codigo') }}
X-Dispositivo-Token: (el token de arriba)</pre>
            </div>
        </div>
    </div>
@endif
