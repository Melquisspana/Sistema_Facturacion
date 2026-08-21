<x-configuracion-layout titulo="Parámetros fiscales">
    {{--
        Impuestos, umbrales y valores por defecto con los que nace un documento.

        PANTALLA DE SOLO LECTURA, sin acciones: aquí no hay nada que probar.

        Lo que esta pantalla tiene que dejar claro es una distinción que la lista
        de valores, por sí sola, no transmite: la tasa del IVA no es una
        preferencia de la empresa —es la ley— y la forma de pago por defecto sí lo
        es. Ambas se ven igual en un archivo de configuración; aquí no.
    --}}

    <div class="space-y-6">

        <x-configuracion.seccion
            titulo="Impuestos, retención y umbrales"
            descripcion="Lo que aplica la calculadora al construir el documento.">

            <p class="mb-3 rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600">
                Las tasas y los umbrales marcados como <span class="font-medium">solo lectura</span> no son una
                preferencia de la empresa: los fija la ley o el catálogo oficial. No se abren a edición ni con
                confirmación, porque una pantalla que permita «probar» con el impuesto lo permitirá también
                sobre documentos reales.
            </p>

            <div class="divide-y divide-gray-100">
                @foreach ($parametros as $parametro)
                    <x-configuracion.fila-fiscal :ajuste="$parametro" />
                @endforeach
            </div>
        </x-configuracion.seccion>

        <x-configuracion.seccion
            titulo="Exportación"
            descripcion="Valores con los que nace un borrador de factura de exportación. El usuario puede cambiarlos en el editor antes de generar el documento.">

            <p class="mb-3 text-xs text-gray-500">
                Son valores POR DEFECTO: cambiarlos no toca ninguna factura de exportación ya creada.
            </p>

            <div class="divide-y divide-gray-100">
                @foreach ($exportacion as $ajuste)
                    <x-configuracion.fila-fiscal :ajuste="$ajuste" />
                @endforeach
            </div>
        </x-configuracion.seccion>

        {{-- Se dice por qué NADA de esta pantalla es editable todavía. Sin esta nota,
             ver "Editable con confirmación" junto a un valor que no se puede tocar
             parece un fallo de la pantalla, no una fase del trabajo. --}}
        <x-configuracion.seccion titulo="Por qué todavía no se edita nada aquí">
            <p class="text-sm text-gray-700">
                Varios de estos parámetros están clasificados como editables y aun así no hay ningún campo
                donde escribirlos. Es deliberado: hoy quien los consume los lee del archivo de configuración
                del servidor. Abrir el formulario antes de cambiar esos consumidores daría lo peor de los dos
                mundos — una pantalla que aparenta cambiar el plazo de crédito o el umbral de retención, dice
                «guardado», y no cambia absolutamente nada en el documento siguiente.
            </p>
            <p class="mt-2 text-sm text-gray-700">
                Se abrirán de a poco, cada uno junto con su consumidor y con la prueba que demuestre que el
                valor guardado llega al documento.
            </p>
        </x-configuracion.seccion>
    </div>
</x-configuracion-layout>
