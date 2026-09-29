{{-- Sidebar del área «Gastos y pagos» (App\Enums\AreaSistema::Gastos).

     DOS grupos y ocho filas en total. Antes eran dos ÁREAS distintas en el selector
     superior, y el selector se había llenado de puertas sin que ninguna dijera a quién
     le tocaba cuál. Juntarlas en una sola área con dos grupos deja el selector corto y
     la barra legible: se ve el grupo en el que estás y el título del otro.

     ── Lo que NO es una fila del menú, y por qué ──

     «Registrar gasto», «Registrar pago» y «Preparar planilla» son ACCIONES, y viven
     como botones dentro de la pantalla donde se hacen. Un menú que mezcla «dónde
     estoy» con «qué hago» hay que leerlo entero cada vez.

     «Avisos» tampoco. Un aviso no es un lugar al que ir: tiene que verse donde ya se
     está mirando el trabajo, o sea arriba de «Por pagar». Acá solo aparece su número.

     ── El candado de los salarios ──

     Juntar la navegación NO junta los permisos. El grupo de Planilla se dibuja solo si
     $vePlanilla, que exige el módulo encendido Y `planilla.ver`. Quien entra con
     `gastos.ver` a secas no ve ni el título del grupo: los sueldos son confidenciales y
     hasta la existencia del menú dice algo.

     Y esto es PRESENTACIÓN. Quien intente entrar por la URL se topa con el middleware
     de cada ruta (`permission:planilla.ver`, `modulo.planilla`) y con
     AccesoGastos::ver(), que además esconde las obligaciones de planilla dentro de
     Gastos. Tres candados distintos, ninguno apoyado en este archivo.

     ── Sin consultas ──

     Las variables ($gastosFase2, $gastosAvisosSinLeer, $vePlanilla) las calcula
     layouts/navigation.blade.php. Ninguna vista compartida consulta la base: una tabla
     que faltaba en el menú ya tumbó el sistema entero una vez. --}}
<nav class="space-y-6 px-3 py-5">

    {{-- ══ Gastos ══ --}}
    <x-sidebar-group titulo="Gastos" icono="contabilidad" clave="gastos"
                     :activo="request()->routeIs('gastos.*')">
        {{-- Por pagar es la puerta: lo primero que se pregunta es qué falta pagar.
             Lleva al PANEL, que responde esa pregunta entera —lo que se debe, lo
             programado, lo que falta completar y lo que se pagó— en una pantalla.

             El listado con búsqueda y filtros (`gastos.index`) sigue existiendo y se
             marca activo desde acá: no es otro sitio, es la misma pregunta mirada con
             lupa. Por eso no ocupa una fila propia del menú. --}}
        <x-sidebar-link :href="route('gastos.panel')"
                        :active="request()->routeIs('gastos.panel') || request()->routeIs('gastos.index') || request()->routeIs('gastos.show') || request()->routeIs('gastos.create')">
            Por pagar
            @if ($gastosAvisosSinLeer > 0)
                <span class="ml-1 rounded-full bg-indigo-600 px-1.5 py-0.5 text-[0.65rem] font-semibold text-white">{{ $gastosAvisosSinLeer }}</span>
            @endif
        </x-sidebar-link>

        {{-- Las cuentas abiertas son una PREGUNTA distinta de «qué vence»: no tienen
             fecha, tienen saldo. Por eso es su propia fila y no un filtro de Por pagar. --}}
        <x-sidebar-link :href="route('gastos.cuentas')" :active="request()->routeIs('gastos.cuentas*')">
            Cuentas de proveedores
        </x-sidebar-link>

        <x-sidebar-link :href="route('gastos.pagos.index')" :active="request()->routeIs('gastos.pagos.*')">
            Historial de pagos
        </x-sidebar-link>

        {{-- Solo si la fase 2 está migrada en esta base: entre desplegar el código y
             correr las migraciones hay una ventana, y durante esa ventana esta fila
             llevaría a un 503. --}}
        @if ($gastosFase2)
            <x-sidebar-link :href="route('gastos.reglas.index')" :active="request()->routeIs('gastos.reglas.*')">
                Gastos que se repiten
            </x-sidebar-link>
        @endif

        <x-sidebar-link :href="route('gastos.informes')" :active="request()->routeIs('gastos.informes*')">
            Informes
        </x-sidebar-link>
    </x-sidebar-group>

    {{-- ══ Planilla ══
         Las mismas cuatro pantallas aprobadas, sin renombrar nada. Lo único que cambió
         es que ahora viven dentro de esta barra en vez de en un área aparte. --}}
    @if ($vePlanilla)
        <x-sidebar-group titulo="Planilla" icono="planilla" clave="planilla"
                         :activo="request()->routeIs('planilla.*')">
            <x-sidebar-link :href="route('planilla.index')"
                            :active="request()->routeIs('planilla.index') || request()->routeIs('planilla.show') || request()->routeIs('planilla.preparar') || request()->routeIs('planilla.create') || request()->routeIs('planilla.pagos') || request()->routeIs('planilla.impresos')">
                Planillas
            </x-sidebar-link>

            <x-sidebar-link :href="route('planilla.empleados')" :active="request()->routeIs('planilla.empleados*')">
                Personas
            </x-sidebar-link>

            <x-sidebar-link :href="route('planilla.anticipos')" :active="request()->routeIs('planilla.anticipos*')">
                Anticipos
            </x-sidebar-link>

            <x-sidebar-link :href="route('planilla.formatos')" :active="request()->routeIs('planilla.formatos')">
                Formatos
            </x-sidebar-link>
        </x-sidebar-group>
    @endif
</nav>
