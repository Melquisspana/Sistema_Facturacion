{{-- Sidebar del área Asistencia (App\Enums\AreaSistema::Asistencia, prefijo
     /asistencia, permisos asistencia.*).

     Deliberadamente SIN enlaces de Facturación, Producción ni Cobros: quien
     administra al personal no navega documentos fiscales desde acá.

     Solo se dibuja lo que EXISTE. No hay «Marcaciones», «Reportes» ni
     «Enrolamiento» porque esas pantallas todavía no están hechas, y un enlace que
     no lleva a ninguna parte enseña al usuario a desconfiar del resto del menú.

     Ocultar no autoriza: cada ruta lleva su propio middleware de permiso. Los
     @can de acá solo evitan ofrecer una puerta que va a responder 403. --}}
<nav class="space-y-6 px-3 py-5">

    <x-sidebar-group titulo="Asistencia" icono="asistencia">
        <x-sidebar-link :href="route('asistencia.dashboard')" :active="request()->routeIs('asistencia.dashboard')">Resumen</x-sidebar-link>
    </x-sidebar-group>

    {{-- Personas y sus ranuras del sensor. Las huellas no tienen entrada propia:
         se administran DENTRO de la ficha de cada persona, que es donde «qué
         ranura es de quién» se entiende sin tener que cruzar dos listados. --}}
    <x-sidebar-group titulo="Personal" icono="usuarios" clave="asistencia-personal"
                     :activo="request()->routeIs('asistencia.empleados.*', 'asistencia.marcaciones.*')">
        <x-sidebar-link :href="route('asistencia.empleados.index')" :active="request()->routeIs('asistencia.empleados.*')">Empleados</x-sidebar-link>
        <x-sidebar-link :href="route('asistencia.marcaciones.index')" :active="request()->routeIs('asistencia.marcaciones.*')">Historial de marcaciones</x-sidebar-link>
    </x-sidebar-group>

    {{-- Los lectores llevan su propio permiso: dar de alta uno o rotarle el token
         produce un secreto, y quien administra personal no tiene por qué poder
         dejar el lector de la puerta sin autenticar. --}}
    @can('asistencia.dispositivos.gestionar')
        <x-sidebar-group titulo="Equipos" icono="sistema" clave="asistencia-equipos"
                         :activo="request()->routeIs('asistencia.dispositivos.*')">
            <x-sidebar-link :href="route('asistencia.dispositivos.index')" :active="request()->routeIs('asistencia.dispositivos.*')">Lectores de huella</x-sidebar-link>
        </x-sidebar-group>
    @endcan
</nav>
