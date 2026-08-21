@props(['activo', 'accion' => null, 'permiso' => 'asistencia.gestionar', 'etiquetaActivo' => 'Activo', 'etiquetaInactivo' => 'Inactivo'])
{{-- Estado activo/inactivo del módulo Asistencia.

     Con permiso y con acción es un botón que alterna; sin cualquiera de las dos,
     una etiqueta inerte. No se dibuja un botón que va a responder 403: ofrecer
     una acción imposible es peor que no ofrecerla.

     `permiso` es un parámetro porque las personas y los lectores se administran
     con permisos DISTINTOS —`asistencia.gestionar` y
     `asistencia.dispositivos.gestionar`—, y un componente que asumiera uno solo
     dibujaría el botón equivocado en la mitad de las pantallas.

     Ocultar no autoriza: el candado real está en el middleware de la ruta. --}}
@php
    $clases = $activo
        ? 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300'
        : 'bg-gray-100 text-gray-500 dark:bg-ink-700 dark:text-paper-400';
    $etiqueta = $activo ? $etiquetaActivo : $etiquetaInactivo;
@endphp

@if ($accion && auth()->user()?->can($permiso))
    <form method="POST" action="{{ $accion }}" class="inline">
        @csrf
        @method('PATCH')
        <button type="submit" title="Cambiar estado"
                class="inline-block rounded-full px-2.5 py-0.5 text-xs font-medium transition hover:opacity-80 {{ $clases }}">
            {{ $etiqueta }}
        </button>
    </form>
@else
    <span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-medium {{ $clases }}">{{ $etiqueta }}</span>
@endif
