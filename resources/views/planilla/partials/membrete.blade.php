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
{{-- Leyenda del recuadro (docs/GUIA_PANTALLAS.md, «Documentos impresos»): «Documento interno»,
     como dice el pie («Control interno de remuneraciones»). Los recibos de vacaciones y de
     indemnización la quitan (''): prueban un pago que manda la ley y se firman con sello, y
     nada en el papel debe restarles validez. Nunca «no es factura»: no viene al caso. --}}
@php($leyenda = array_key_exists('leyenda', get_defined_vars()) ? $leyenda : 'Documento interno')

{{-- La forma de los documentos impresos con el acento violeta de Planilla: el negocio y la
     empleadora a la izquierda; a la derecha el recuadro con el nombre del documento y su folio. --}}
<x-documento.encabezado :empresa="mb_strtoupper($negocio)" :logo="$logo"
    :detalle="$empleadora ? 'Empleadora: '.$empleadora : null" etiqueta="Planilla" :titulo="$documento" :leyenda="$leyenda">
    @if ($folio)
        <tr><td class="folio mono" colspan="2">{!! $folio !!}</td></tr>
    @endif
</x-documento.encabezado>
