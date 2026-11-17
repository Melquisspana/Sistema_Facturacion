{{-- «¿Quién lo entregó?» de una tarjeta de «Por entregar»: copia la lista de la página
     (rutas.por-entregar._quienes, que va una sola vez) dentro de la tarjeta, con x-html. Las
     copias quedan con el `quien` de la tarjeta y con su propio grupo de botones
     (quien-{clave}), así elegir en una tarjeta no toca las otras. Sin JavaScript no aparece,
     como antes no se podía confirmar: los botones de confirmar esperan a que se elija.

     Recibe: $clave (de la tarjeta). --}}
<div x-data="{ grupo: @js('quien-'.$clave) }" x-html="document.getElementById('lista-quienes').innerHTML"></div>
