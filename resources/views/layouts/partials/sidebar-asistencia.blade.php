<nav class="space-y-6 px-3 py-5">
    <x-sidebar-group titulo="Asistencia" icono="asistencia">
        <x-sidebar-link :href="route('asistencia.dashboard')" :active="request()->routeIs('asistencia.dashboard')">Hoy</x-sidebar-link>
        <x-sidebar-link :href="route('asistencia.empleados.index')" :active="request()->routeIs('asistencia.empleados.*')">Personal</x-sidebar-link>
        <x-sidebar-link :href="route('asistencia.reporte')" :active="request()->routeIs('asistencia.reporte*', 'asistencia.jornadas.*', 'asistencia.marcaciones.*')">Reporte</x-sidebar-link>
    </x-sidebar-group>
</nav>
