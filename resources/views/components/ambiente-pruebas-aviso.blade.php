{{--
    Advertencia SIEMPRE visible cuando el DTE pertenece al ambiente de pruebas
    (ambiente="00"), sin importar su estado (aceptado, firmado, generado, rechazado)
    ni si tiene sello real de APITEST. La única fuente de verdad es $dte->ambiente,
    nunca el sello ni el estado — un documento de pruebas aceptado con sello real
    no debe confundirse visualmente con uno de producción.

    Espera:
      $dte  el modelo Dte (usa $dte->ambiente).

    Solo presentación: no transmite, no muestra secretos.
--}}
@props(['dte'])

@if ($dte->ambiente?->value === '00')
    <div class="rounded-md border-2 border-amber-500 bg-amber-50 p-3 text-center text-sm font-bold tracking-wide text-amber-800">
        AMBIENTE DE PRUEBAS
        <span class="mt-0.5 block text-xs font-normal tracking-normal text-amber-700">Documento sin validez fiscal en producción</span>
    </div>
@endif
