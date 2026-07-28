{{-- Sidebar del área Producción (planta). Deliberadamente NO contiene ningún
     enlace del área Facturación: quien trabaja en planta no navega documentos
     fiscales, clientes, PPQ ni exportaciones. Se irán agregando entradas a medida
     que existan rutas reales; nunca enlaces rotos. --}}
@php
    // Mismo estilo de título de grupo que el sidebar de Facturación.
    $tituloGrupo = 'mb-1.5 flex items-center gap-1.5 px-3 text-[11px] font-semibold uppercase tracking-wider text-gray-400 dark:text-paper-500';
@endphp
<nav class="space-y-6 px-3 py-5">

    <div>
        <p class="{{ $tituloGrupo }}"><x-sidebar-icon name="planta" />Producción</p>
        <div class="space-y-0.5">
            <x-sidebar-link :href="route('planta.dashboard')" :active="request()->routeIs('planta.dashboard')">Inicio</x-sidebar-link>
        </div>
    </div>

</nav>
