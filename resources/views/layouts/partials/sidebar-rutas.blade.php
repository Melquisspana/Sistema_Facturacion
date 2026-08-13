{{-- Sidebar del área Rutas / Cobros. Deliberadamente SIN enlaces de Facturación,
     PPQ ni Planta: quien trabaja rutas no navega documentos fiscales desde acá.
     Se irán agregando entradas a medida que existan rutas reales; nunca enlaces
     rotos.

     Ocultar no autoriza: cada grupo de rutas lleva su propio middleware. --}}
@php
    // Mismo estilo de título de grupo que los otros sidebars.
    $tituloGrupo = 'mb-1.5 flex items-center gap-1.5 px-3 text-[11px] font-semibold uppercase tracking-wider text-gray-400 dark:text-paper-500';
@endphp
<nav class="space-y-6 px-3 py-5">

    <div>
        <p class="{{ $tituloGrupo }}"><x-sidebar-icon name="rutas" />Rutas / Cobros</p>
        <div class="space-y-0.5">
            <x-sidebar-link :href="route('rutas.dashboard')" :active="request()->routeIs('rutas.dashboard')">Inicio</x-sidebar-link>
        </div>
    </div>

    {{-- Operación. Salidas va primero porque es lo que se mira todos los días;
         el catálogo de rutas se toca de vez en cuando. --}}
    <div>
        <p class="{{ $tituloGrupo }}">Operación</p>
        <div class="space-y-0.5">
            <x-sidebar-link :href="route('rutas.salidas.index')" :active="request()->routeIs('rutas.salidas.*')">Salidas</x-sidebar-link>
            {{-- La bandeja cruza todas las salidas: es donde se contesta «qué me
                 falta» sin abrir viaje por viaje. Va pegada a Salidas porque se
                 usan juntas. --}}
            <x-sidebar-link :href="route('rutas.documentos.index')" :active="request()->routeIs('rutas.documentos.*')">Documentos</x-sidebar-link>
            <x-sidebar-link :href="route('rutas.rutas.index')" :active="request()->routeIs('rutas.rutas.*')">Rutas</x-sidebar-link>
        </div>
    </div>
</nav>
