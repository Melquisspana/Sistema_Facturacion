<?php

use App\Support\Sistema\ProxiesDeConfianza;

return [

    /*
    | Proxies de confianza: solo lo que llega desde estas IP o redes puede fijar la IP
    | real del visitante y el esquema (X-Forwarded-For/Host/Port/Proto). Lo lee el
    | middleware TrustProxies en cada pedido; las cabeceras se fijan en bootstrap/app.php.
    |
    | TRUSTED_PROXIES: IP o redes CIDR separadas por coma. Vacío = 127.0.0.1 (Tailscale
    | Serve en Windows, el comportamiento de siempre). En Docker, la IP fija del contenedor
    | de cloudflared. Comodines, redes /0 o un valor mal escrito vuelven a 127.0.0.1.
    */
    'proxies' => ProxiesDeConfianza::desde(env('TRUSTED_PROXIES')),

];
