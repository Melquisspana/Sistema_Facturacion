@props(['activo', 'accion', 'bloqueado' => false, 'motivoBloqueo' => null])
{{-- Estado activo/inactivo. Con permiso de gestión es un botón que alterna;
     sin él, una etiqueta. `bloqueado` cubre los casos que el backend rechaza de
     todas formas (ubicaciones de sistema): se muestra inerte para no ofrecer
     una acción que va a devolver 403. El candado real está en el controlador. --}}
@php
    $clases = $activo
        ? 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300'
        : 'bg-gray-100 text-gray-500 dark:bg-ink-700 dark:text-paper-400';
    $etiqueta = $activo ? 'Activo' : 'Inactivo';
@endphp

@if (auth()->user()?->can('planta.catalogos.gestionar') && ! $bloqueado)
    <form method="POST" action="{{ $accion }}" class="inline">
        @csrf
        @method('PATCH')
        <button type="submit" title="Cambiar estado"
                class="inline-block rounded-full px-2.5 py-0.5 text-xs font-medium {{ $clases }}">
            {{ $etiqueta }}
        </button>
    </form>
@else
    <span @if ($bloqueado && $motivoBloqueo) title="{{ $motivoBloqueo }}" @endif
          class="inline-block rounded-full px-2.5 py-0.5 text-xs font-medium {{ $clases }}">
        {{ $etiqueta }}
    </span>
@endif
