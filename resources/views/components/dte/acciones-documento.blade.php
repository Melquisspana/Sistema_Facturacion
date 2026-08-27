@props([
    'dte',
    'invalidacion' => null,
    'mostrarReversion' => false,
    'salasNotaCredito' => [],
])

{{--
    «Acciones del documento» — sección ÚNICA y uniforme para FCF (01), CCF (03),
    NC (05) y FEX (11). Antes cada tipo veía una composición distinta: en el CCF la
    invalidación caía en la columna izquierda de un grid de dos, y en los demás tipos
    ocupaba el ancho completo, lo que la hacía parecer «otra pantalla» según el
    documento. Ahora los cuatro tipos renderizan ESTE mismo componente y las
    diferencias entran solo como props:

      · `invalidacion`      → null cuando la ability `verInvalidacion` no aplica;
      · `mostrarReversion`  → solo el CCF aceptado tiene reversión con NC;
      · `salasNotaCredito`  → solo alimenta el selector de sala del bloque de NC.

    La barra de arriba es acceso rápido; debajo van las tarjetas con el detalle. Los
    dos conceptos correctivos quedan SEPARADOS a propósito:

      Invalidación oficial → evento `anulardte` ante Hacienda.
      Reversión con NC     → crea un borrador de nota de crédito, no transmite nada.

    No hay lógica fiscal aquí: cada acción apunta a la ruta que ya existía y las
    abilities se consultan igual que antes.
--}}
@php
    // Las acciones de PDF existen para cualquier documento; el resto depende de
    // permisos y del tipo. `puedeInvalidar` solo decide si la tarjeta se muestra:
    // que el botón esté habilitado lo resuelve el propio componente con los candados.
    $puedeCorreo = auth()->user()?->can('enviarCorreo', $dte);
    $puedeInvalidar = (bool) $invalidacion;
    $pdfEtiqueta = $dte->estado === \App\Enums\EstadoDte::Aceptado ? 'PDF oficial' : 'PDF para revisión';
    $dosBloques = $puedeInvalidar && $mostrarReversion;
@endphp

<div id="acciones-documento" class="space-y-4">
    <div>
        <h3 class="font-semibold text-gray-700">Acciones del documento</h3>
        <p class="mt-0.5 text-sm text-gray-500">Todo lo que se puede hacer con este documento, en un solo lugar.</p>
    </div>

    {{-- Barra de acceso rápido. Mismo orden en los cuatro tipos; lo que no aplica
         simplemente no aparece, sin reacomodar el resto. --}}
    <div class="bg-white shadow sm:rounded-lg p-4 border border-gray-200">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('facturacion.pdf', $dte) }}" target="_blank"
               class="inline-flex items-center gap-2 rounded-md border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Ver {{ $pdfEtiqueta }}
            </a>
            <a href="{{ route('facturacion.pdf.descargar', $dte) }}"
               class="inline-flex items-center gap-2 rounded-md border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Descargar PDF
            </a>
            <a href="{{ route('facturacion.imprimir', $dte) }}" target="_blank"
               class="inline-flex items-center gap-2 rounded-md border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Imprimir
            </a>

            {{-- Correo: este acceso rápido es un ANCLA a la sección, nunca un segundo
                 botón de envío. El sistema mantiene deliberadamente UN solo punto de
                 envío —el de la sección «Correo del cliente», cuya etiqueta cambia entre
                 «Enviar correo y abrir PDF» y «Reenviar y abrir PDF» según el estado— y ya
                 se eliminó una vez el atajo del encabezado. Por eso este enlace lleva el
                 nombre de la SECCIÓN y no repite la frase del botón ni apunta a la ruta de
                 envío. Ver DteCorreoClienteRapidoTest. --}}
            @if ($puedeCorreo)
                <a href="#correo-cliente"
                   class="inline-flex items-center gap-2 rounded-md border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Correo del cliente
                </a>
            @endif

            @if ($mostrarReversion)
                <a href="#reversion-nota-credito"
                   class="inline-flex items-center gap-2 rounded-md border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Revertir con nota de crédito
                </a>
            @endif

            @if ($puedeInvalidar)
                @php
                    // Mismo criterio que usa la tarjeta: candados del entorno O documento
                    // no candidato. Si está bloqueada, el acceso rápido lleva a la tarjeta
                    // (donde se explican las razones) en vez de abrir un asistente inútil.
                    $invBloqueada = ($invalidacion['candados']['bloqueado'] ?? true)
                        || ! ($invalidacion['puede_transmitir'] ?? false);
                @endphp
                @if ($invBloqueada || ($invalidacion['ya_invalidado'] ?? false))
                    <a href="#invalidacion-oficial"
                       class="inline-flex items-center gap-2 rounded-md border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Invalidar oficialmente
                    </a>
                @else
                    <button type="button" x-data="" x-on:click="$dispatch('open-modal', 'invalidar-dte')"
                            class="inline-flex items-center gap-2 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-100">
                        Invalidar oficialmente
                    </button>
                @endif
            @endif
        </div>
    </div>

    {{-- Tarjetas de detalle. Con las dos presentes van lado a lado; con una sola,
         a ancho completo. Es la única diferencia de layout entre tipos. --}}
    @if ($puedeInvalidar || $mostrarReversion)
        <div class="grid grid-cols-1 {{ $dosBloques ? 'xl:grid-cols-2' : '' }} gap-4 items-start">
            @if ($mostrarReversion)
                <div id="reversion-nota-credito" class="scroll-mt-6">
                    <x-dte.reversion-nota-credito :dte="$dte" :salasNotaCredito="$salasNotaCredito" />
                </div>
            @endif

            @if ($puedeInvalidar)
                <div id="invalidacion-oficial" class="scroll-mt-6">
                    <x-dte.invalidacion-oficial :dte="$dte" :invalidacion="$invalidacion" />
                </div>
            @endif
        </div>
    @endif
</div>
