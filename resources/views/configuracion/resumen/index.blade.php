<x-configuracion-layout titulo="Resumen">
    {{--
        Panel de ESTADO de la configuración del sistema. No edita nada: cada
        tarjeta lleva a la pantalla que administra lo suyo, cuando esa pantalla
        existe.

        Todo lo que se pinta viene resuelto de App\Ajustes\Resumen\ResumenConfiguracion.
        Esta vista no consulta configuración ni llama a servicios, y en particular
        NO HACE RED: el estado de Hacienda, el firmador y SMTP es el declarado más,
        si la hubo, la última comprobación que alguien disparó a mano.
    --}}

    <div class="space-y-6">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Estado de la configuración</h2>
            <p class="mt-1 text-sm text-gray-500">
                Qué está configurado, de dónde sale cada valor y qué falta. Esta pantalla no cambia nada.
            </p>
        </div>

        @if ($atencion)
            {{-- Lo que pide atención, arriba y por su nombre: un panel de estado en
                 el que hay que buscar el problema entre once tarjetas no sirve. --}}
            <div class="rounded-md border border-amber-200 bg-amber-50 p-4">
                <p class="text-sm font-medium text-amber-800">
                    {{ count($atencion) }} {{ count($atencion) === 1 ? 'punto requiere' : 'puntos requieren' }} atención
                </p>
                <ul class="mt-2 list-inside list-disc space-y-0.5 text-sm text-amber-800">
                    @foreach ($atencion as $t)
                        <li>{{ $t->titulo }}: {{ $t->detalle }}</li>
                    @endforeach
                </ul>
            </div>
        @else
            <div class="rounded-md border border-green-200 bg-green-50 p-4 text-sm text-green-700">
                No hay avisos de configuración pendientes.
            </div>
        @endif

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($tarjetas as $tarjeta)
                <x-configuracion.tarjeta :tarjeta="$tarjeta" />
            @endforeach
        </div>

        <p class="text-xs text-gray-400">
            Los estados de Hacienda, el firmador y el correo son los que declara la configuración.
            Esta pantalla no se conecta a ningún servicio externo: para comprobar el servidor de correo
            de verdad, usá «Probar conexión» en la sección Correo.
        </p>
    </div>
</x-configuracion-layout>
