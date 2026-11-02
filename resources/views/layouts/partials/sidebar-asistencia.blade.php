<nav class="space-y-6 px-3 py-5">
    {{-- Personal junta Asistencia y Planilla. Cada enlace aparece si su módulo está encendido
         y el usuario tiene su permiso: el candado real sigue en las rutas. --}}
    @php($veAsistencia = config('asistencia.enabled') && auth()->user()->can('asistencia.ver'))
    <x-sidebar-group titulo="Personal" icono="asistencia">
        @if ($veAsistencia)
            <x-sidebar-link :href="route('asistencia.dashboard')" :active="request()->routeIs('asistencia.dashboard')">Hoy</x-sidebar-link>
        @endif
        <x-sidebar-link :href="route('personal.empleados')" :active="request()->routeIs('personal.*', 'asistencia.empleados.*', 'planilla.empleados*')">Empleados</x-sidebar-link>
        @if ($veAsistencia)
            <x-sidebar-link :href="route('asistencia.reporte')" :active="request()->routeIs('asistencia.reporte*', 'asistencia.jornadas.*', 'asistencia.marcaciones.*')">Reporte</x-sidebar-link>
        @endif
        @if ($vePlanilla)
            <x-sidebar-link :href="route('planilla.index')" :active="request()->routeIs('planilla.index', 'planilla.show', 'planilla.preparar', 'planilla.create', 'planilla.impresos', 'planilla.pagos')">Planilla</x-sidebar-link>
            <x-sidebar-link :href="route('planilla.anticipos')" :active="request()->routeIs('planilla.anticipos*')">Anticipos</x-sidebar-link>
            <x-sidebar-link :href="route('planilla.formatos')" :active="request()->routeIs('planilla.formatos')">Formatos</x-sidebar-link>
        @endif
    </x-sidebar-group>
</nav>
