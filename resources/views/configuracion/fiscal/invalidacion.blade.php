<x-configuracion-layout titulo="Invalidación">
    {{--
        Anular ante el Ministerio de Hacienda un documento que ya fue aceptado.

        PANTALLA DE SOLO LECTURA y sin acciones: no invalida nada y no tiene por
        dónde hacerlo. Muestra sus candados, quién figura como responsable y
        solicitante del evento —datos obligatorios en el esquema del MH— y qué
        documentos están blindados para que nunca puedan invalidarse.
    --}}

    <div class="space-y-6">

        <x-configuracion.seccion
            titulo="Candados de la invalidación"
            descripcion="Invalidar un documento aceptado es irreversible: la protección va por capas y todas tienen que estar abiertas a la vez.">

            <div class="divide-y divide-gray-100">
                @foreach ($candados as $candado)
                    <x-configuracion.fila-fiscal :ajuste="$candado" />
                @endforeach
            </div>

            <p class="mt-4 rounded-md bg-red-50 px-3 py-2 text-xs text-red-800">
                Ninguno se abre desde esta pantalla. Además, la invalidación de un documento de producción
                exige que la dirección de destino sea exactamente la oficial del Ministerio de Hacienda: no
                basta con abrir los interruptores.
            </p>
        </x-configuracion.seccion>

        <x-configuracion.seccion
            titulo="Quién figura en el evento"
            descripcion="El esquema del Ministerio de Hacienda exige nombre y documento de quien realiza la invalidación y de quien la solicita.">

            <div class="divide-y divide-gray-100">
                @foreach ($personas as $persona)
                    <x-configuracion.fila-fiscal :ajuste="$persona" />
                @endforeach
            </div>

            {{-- Son los mejores candidatos a abrirse primero de todo el bloque fiscal:
                 son datos de personas, cambian con la plantilla de la empresa y no
                 tienen nada que hacer en un archivo del servidor. --}}
            <p class="mt-4 rounded-md bg-blue-50 px-3 py-2 text-xs text-blue-800">
                Son datos de personas y cambian cuando cambia la plantilla de la empresa. Hoy se leen del
                archivo del servidor, que es el peor sitio posible para algo así: son los primeros candidatos
                a convertirse en campos editables de esta pantalla.
            </p>
        </x-configuracion.seccion>

        <x-configuracion.seccion
            titulo="Esquema y protecciones"
            descripcion="Versión del evento, códigos del emisor ante el Ministerio de Hacienda y documentos blindados.">

            <div class="divide-y divide-gray-100">
                @foreach ($tecnicos as $tecnico)
                    <x-configuracion.fila-fiscal :ajuste="$tecnico" />
                @endforeach
            </div>
        </x-configuracion.seccion>
    </div>
</x-configuracion-layout>
