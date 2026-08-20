<x-configuracion-layout titulo="Confirmar cambios">
    {{--
        Segundo paso del guardado de cualquier pantalla con ajustes N2. Se llega
        aquí SIEMPRE que el envío no traiga la confirmación: el controlador no
        escribe nada hasta que este formulario vuelve.

        Los valores que se reenvían ya pasaron la validación del formulario y
        ninguno es secreto: cada secreto tiene su propia pantalla justamente para
        no aparecer en los campos ocultos de aquí.
    --}}
    <x-configuracion.confirmacion-n2
        :titulo="$titulo"
        :consecuencia="$consecuencia"
        :cambios="$cambios"
        :accion="$accion"
        metodo="PUT"
        :campos="$valores"
        :volver="$volver" />
</x-configuracion-layout>
