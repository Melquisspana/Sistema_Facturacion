<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hoja de despacho · {{ $pedido->nombreSala() }}</title>
    {{--
        La hoja de despacho de UN envío de la fábrica: lo que va en la caja, lo que no va con
        su motivo y lo que queda para otro envío. Sale sola al tocar «Imprimir y mandar» y se
        vuelve a sacar desde «Hechos hoy». Llama a window.print() al cargar. Sin precios: la
        fábrica no tiene `productos.ver`.

        Forma (12/11/2026, pedido del dueño): la misma estructura y estilo que el PDF del CCF
        (facturacion/pdf: línea de color arriba, logo y empresa a la izquierda, el recuadro del
        documento a la derecha, el bloque de a quién va, la tabla con encabezado oscuro, firmas
        y pie; bordes finos de 1px, grises #C9CDD4/#D4D8DE, tinta #20242C), pero sin montos,
        sin datos tributarios, sin sello ni código de generación, y con un acento propio para
        que nadie la confunda con una factura: el VERDE de la paleta del CCF (#235C42 /
        #2F6B4E, el de «aceptado») en lugar de su vino #6E2142, y en el recuadro de la derecha
        «HOJA DE DESPACHO» con «No es factura · Sin valor tributario». Sin número de envío ni
        de pedido. La orden de compra solo aparece si el pedido la tiene. Lo común (estilos,
        encabezado con el recuadro y la leyenda, firmas) sale de components/documento, que
        comparte con el PDF del día del vendedor («Documentos impresos» en la guía).

        No es la hoja del pedido entero (rutas/pedidos/hoja, de la oficina, con todos los
        envíos y la facturación): esta es solo de un envío y la abre la fábrica con su permiso.

        Página impresa (docs/GUIA_PANTALLAS.md, «Páginas para imprimir»): documento aparte con
        sus propios estilos, sin el bundle ni modo oscuro, carta vertical; ~12 productos entran
        en una página. Cada parte lleva su título en palabras: se lee igual en blanco y negro.

        La hoja describe el envío tal como salió: lo que no fue se calcula con ese envío y los
        anteriores, nunca con los de después (PedidosFabrica::faltanteAlEnvio). Los motivos se
        guardan por línea y no por envío: solo el último envío (sin ninguno después, ni
        deshecho) los muestra, con el estado actual (mandado en parte, «queda pendiente»;
        cerrado, «no va»). Uno anterior dice solo «No fue en este envío», sin motivos.

        Si el envío corrige a uno anulado con «Corregir», un aviso arriba: «Corrige la hoja de
        las HH:MM» (la hora de la hoja anulada).

        Variables: $pedido (con PedidosFabrica::relaciones(conSala: true)), $envio (vigente, con
        lineas.linea.producto), $faltan [['linea', 'cantidad']], $ultimo (bool) y $corrige
        (el envío anulado que corrige, o null; PedidosFabrica::corrigeA).
    --}}
    <style>
        @include('fabrica.pedidos._estilos-despacho')
        @include('fabrica.pedidos._estilos-imprimir')
    </style>
</head>
<body>


<div class="no-print">
    <a href="{{ route('fabrica.pedidos.index') }}">Volver a Pedidos</a>
    <button type="button" onclick="window.print()">Imprimir</button>
</div>

@include('fabrica.pedidos._hoja-despacho')

{{-- Se imprime sola al abrir. --}}
<script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
