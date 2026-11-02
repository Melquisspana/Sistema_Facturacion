{{-- Sidebar del área «Gastos y pagos» (App\Enums\AreaSistema::Gastos).

     ── Lo que NO es una fila del menú, y por qué ──

     «Registrar gasto» y «Registrar pago» son ACCIONES, y viven
     como botones dentro de la pantalla donde se hacen. Un menú que mezcla «dónde
     estoy» con «qué hago» hay que leerlo entero cada vez.

     «Avisos» tampoco. Un aviso no es un lugar al que ir: tiene que verse donde ya se
     está mirando el trabajo, o sea arriba de «Por pagar». Acá solo aparece su número.

     Las variables ($gastosFase2, $gastosAvisosSinLeer) las calcula
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
</nav>
