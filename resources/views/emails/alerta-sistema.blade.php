{{--
    Aviso interno del sistema (issue #163). Tres casos: se abrió una falla, sigue y
    empeoró, o ya se normalizó. Sin datos de clientes ni del servidor: solo qué
    falló, desde cuándo, qué hacer y el enlace.
--}}
<x-mail::message>
@if ($accion === 'normalizado')
# Ya se normalizó

**{{ $nombre }}** vuelve a andar bien. Estaba fallando desde el {{ $desde }}.
@else
# {{ $accion === 'sigue' ? 'Sigue la falla' : 'Algo falló' }}

**Qué falló:** {{ $hallazgo->titulo }}. {{ $hallazgo->detalle }}

**Desde:** {{ $desde }}

**Qué hacer:** {{ $hallazgo->queHacer }}
@endif

<x-mail::button :url="$enlace">
Abrir el sistema
</x-mail::button>

@if ($accion !== 'normalizado')
Si se repite, llega un solo correo con todo junto, y otro cuando se normalice.
@endif
@if ($ultimoDelDia)

Este es el último aviso de hoy de este tipo: se llegó al tope diario.
@endif
</x-mail::message>
