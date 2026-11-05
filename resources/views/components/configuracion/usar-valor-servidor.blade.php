@props(['estado'])
{{--
    «Usar el valor del servidor»: borra lo guardado de ESTE ajuste para que vuelva a
    mandar el .env. Solo aparece cuando hay algo guardado en la aplicación y el ajuste
    se puede escribir; nunca para un secreto (tiene su propia pantalla).

    Es un botón de envío del formulario oculto que dibuja <x-configuracion-layout>
    (atributo `form`), así funciona aunque esté dentro del formulario de la pantalla:
    los formularios no se pueden anidar. La ruta valida la clave contra el catálogo,
    pide el permiso del nivel y, si es N2, muestra antes la confirmación.
--}}
@if ($estado
    && $estado->editable
    && ! $estado->esSecreto
    && $estado->fuente === \App\Ajustes\Definicion\FuenteAjuste::BaseDeDatos)
    <span aria-hidden="true">&middot;</span>
    <button type="submit" form="form-usar-valor-servidor" formnovalidate
            formaction="{{ route('configuracion.ajustes.servidor', $estado->clave) }}"
            class="font-medium text-indigo-600 underline hover:text-indigo-800"
            title="Borra lo guardado en la aplicación y vuelve a mandar el archivo de configuración del servidor (.env).">
        Usar el valor del servidor
    </button>
@endif
