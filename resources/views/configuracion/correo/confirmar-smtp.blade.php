<x-configuracion-layout titulo="Confirmar cambio del servidor de correo">
    {{--
        Segundo paso del guardado del servidor SMTP. Se llega aquí SIEMPRE que el
        envío no traiga la confirmación: el controlador no escribe nada hasta que
        este formulario vuelve.

        Los valores que se reenvían ya pasaron la validación del formulario y
        ninguno es secreto: la contraseña tiene su propia pantalla justamente para
        no aparecer en los campos ocultos de aquí.
    --}}
    <x-configuracion.confirmacion-n2
        titulo="Vas a cambiar por dónde sale el correo"
        consecuencia="Si estos datos no son correctos, los documentos dejarán de llegar a los clientes y a contabilidad. No se rompe ninguna pantalla: los envíos simplemente fallan, y eso suele descubrirse tarde. Después de guardar, usá «Probar conexión»."
        :cambios="$cambios"
        :accion="route('configuracion.correo.smtp.update')"
        metodo="PUT"
        :campos="$valores"
        :volver="route('configuracion.correo.edit')"
        etiqueta-confirmar="Confirmar y guardar servidor" />
</x-configuracion-layout>
