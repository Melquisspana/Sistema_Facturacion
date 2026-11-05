<nav class="space-y-6 px-3 py-5">
    {{-- Personal junta Asistencia y Planilla. Cada enlace aparece si su módulo está encendido
         y el usuario tiene su permiso: el candado real sigue en las rutas.

         «Accesos» (quién entra al sistema; antes, «Usuarios» en Facturación) va con
         `usuarios.gestionar` y no depende de los módulos. Empleados solo se ofrece si se
         alcanza Asistencia o Planilla: es lo mismo que pide su pantalla. --}}
    @php($veAsistencia = \App\Enums\ModuloSistema::Asistencia->activo() && auth()->user()->can('asistencia.ver'))
    <x-sidebar-group titulo="Personal" icono="asistencia">
        @if ($veAsistencia)
            <x-sidebar-link :href="route('asistencia.dashboard')" :active="request()->routeIs('asistencia.dashboard')">Hoy</x-sidebar-link>
        @endif
        @if ($veAsistencia || $vePlanilla)
            <x-sidebar-link :href="route('personal.empleados')" :active="request()->routeIs('personal.empleados*', 'personal.repetidos', 'asistencia.empleados.*', 'planilla.empleados*')">Empleados</x-sidebar-link>
        @endif
        @if ($veAsistencia)
            <x-sidebar-link :href="route('asistencia.reporte')" :active="request()->routeIs('asistencia.reporte*', 'asistencia.jornadas.*', 'asistencia.marcaciones.*')">Reporte</x-sidebar-link>
        @endif
        @if ($vePlanilla)
            <x-sidebar-link :href="route('planilla.index')" :active="request()->routeIs('planilla.index', 'planilla.show', 'planilla.preparar', 'planilla.create', 'planilla.impresos', 'planilla.pagos')">Planilla</x-sidebar-link>
            <x-sidebar-link :href="route('planilla.anticipos')" :active="request()->routeIs('planilla.anticipos*')">Anticipos</x-sidebar-link>
        @endif
        @can('usuarios.gestionar')
            <x-sidebar-link :href="route('personal.accesos.index')" :active="request()->routeIs('personal.accesos.*')">Accesos</x-sidebar-link>
        @endcan
    </x-sidebar-group>
</nav>
