<x-configuracion-layout titulo="Confirmar cambios">
    {{--
        Segundo paso al APAGAR un módulo (N2). No se guardó nada todavía: el
        controlador escribe recién cuando este formulario vuelve con `confirmacion`.
        Prender no pasa por acá.

        Se reenvía la decisión de cada casilla ('1' o '0'); qué ajuste se escribe lo
        decide App\Enums\ModuloSistema, no el nombre que llegue del navegador.
    --}}
    <x-configuracion.confirmacion-n2
        :titulo="count($apagados) === 1 ? 'Vas a apagar un módulo' : 'Vas a apagar '.count($apagados).' módulos'"
        consecuencia="Los datos no se borran: al volver a encenderlo, todo sigue donde estaba. Quien esté usando el módulo deja de verlo en su próximo clic."
        :cambios="$cambios"
        :accion="route('configuracion.modulos.update')"
        metodo="PUT"
        :campos="$valores"
        :volver="route('configuracion.modulos')"
        etiqueta-confirmar="Confirmar y apagar">
        <p class="font-medium">Qué deja de verse:</p>
        <ul class="mt-1 list-inside list-disc space-y-1">
            @foreach ($apagados as $modulo)
                <li><span class="font-medium">{{ $modulo->etiqueta() }}:</span> {{ $modulo->alApagar() }}</li>
            @endforeach
        </ul>
    </x-configuracion.confirmacion-n2>
</x-configuracion-layout>
