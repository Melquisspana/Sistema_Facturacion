{{-- Encabezado común de los tres impresos.

     Dos identidades y NO son intercambiables:

       · el NEGOCIO —«Dulces La Negrita»— con su logo;
       · la EMPLEADORA, la persona de quien es el negocio.

     Ninguna de las dos es «quien entregó el pago». Ese dato sale del pago
     registrado y va al pie, persona por persona. --}}
{{-- Se incluye con @include, no como componente: por eso valores por defecto a mano
     y NO @props, que compila la maquinaria de componentes y rompe la vista. --}}
@php($empleadora = $empleadora ?? null)
@php($folio = $folio ?? null)
@php($logo = $logo ?? null)

<div class="hi-membrete">
    @if ($logo)
        <img src="{{ $logo }}" alt="" class="hi-logo">
    @endif
    <div class="hi-identidad">
        <div class="hi-negocio">{{ mb_strtoupper($negocio) }}</div>
        @if ($empleadora)
            <div class="hi-empleadora">
                <span class="et">Empleadora:</span> <span class="nom">{{ $empleadora }}</span>
            </div>
        @endif
    </div>
    <div class="hi-tipo">
        <div class="hi-doc">{{ $documento }}</div>
        @if ($folio)
            <div class="hi-folio mono">{!! $folio !!}</div>
        @endif
    </div>
</div>
