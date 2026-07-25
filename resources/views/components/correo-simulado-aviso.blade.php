{{--
    Aviso discreto del CANDADO de correo real: fuera de producción ningún flujo envía
    correos de verdad (ver App\Support\Correo\CandadoCorreoReal). Informativo, en azul:
    no es un error, es el comportamiento esperado del entorno de desarrollo.
    En producción no se renderiza nada.
--}}
@php($candadoCorreo = app(\App\Support\Correo\CandadoCorreoReal::class))

@if ($candadoCorreo->debeSimular())
    <div class="flex items-start gap-2 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-800">
        <svg class="mt-0.5 h-4 w-4 flex-none" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M18 10A8 8 0 112 10a8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
        </svg>
        <span>{{ $candadoCorreo->avisoInterfaz() }}</span>
    </div>
@endif
