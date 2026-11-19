{{-- El logo de la empresa en las pantallas: solo la cara de la niña, sin cuadro (18/11/2026,
     pedido de Melqui). Lleva un borde crema fino recortado a su silueta para que el pelo negro se
     vea también en modo oscuro, sin el fondo claro de antes. Mide 216×288 y pesa 19 KB.
     Quien lo usa fija la altura y deja w-auto.
     Los PDF de los DTE siguen con su logo de siempre (images/dte/logo-pdf.png, ver config/dte.php). --}}
<img src="{{ asset('images/logo-cara.png') }}" alt="{{ \App\Support\NombreEmpresa::actual() }}" width="216" height="288" {{ $attributes }}>
