<?php

return [

    /*
    | SSO con Cloudflare Access (dominio público facturacion.dulceslanegrita.com).
    |
    | DEFAULT SEGURO: deshabilitado. Solo se activa cuando el .env del SERVIDOR
    | define CLOUDFLARE_ACCESS_ENABLED=true junto con team_domain y aud. En
    | desarrollo (facturacion.test/localhost/Tailscale) nada de esto corre: el
    | login local de Laravel sigue siendo la única puerta.
    |
    | El middleware valida CRIPTOGRÁFICAMENTE el JWT que Cloudflare envía en el
    | header Cf-Access-Jwt-Assertion (firma RS256 contra los certificados
    | públicos del team + issuer + audience + exp/nbf). NUNCA confía en el
    | header Cf-Access-Authenticated-User-Email por sí solo.
    */

    'enabled' => (bool) env('CLOUDFLARE_ACCESS_ENABLED', false),

    /*
    | Team domain COMPLETO de Cloudflare Zero Trust, p. ej.:
    | "miequipo.cloudflareaccess.com" (sin https://). El issuer esperado del JWT
    | es exactamente "https://{team_domain}".
    */
    'team_domain' => (string) env('CLOUDFLARE_ACCESS_TEAM_DOMAIN', ''),

    /*
    | Audience (AUD tag) de la APLICACIÓN Access que protege este sitio. Se copia
    | de Zero Trust -> Access -> Applications -> (la app) -> Overview. Es un
    | identificador público (no un secreto), pero debe coincidir EXACTO.
    */
    'aud' => (string) env('CLOUDFLARE_ACCESS_AUD', ''),

    /*
    | Iniciar sesión local automáticamente cuando el JWT es válido y el email
    | corresponde a un usuario local existente y activo. Nunca crea usuarios ni
    | asigna roles: si el email no existe o está inactivo -> 403 neutro.
    */
    'auto_login' => (bool) env('CLOUDFLARE_ACCESS_AUTO_LOGIN', true),

    /*
    | ÚNICO host donde el SSO corre. Cualquier otro host (facturacion.test,
    | localhost, IP local, Tailscale) queda fuera: login local normal.
    */
    'allowed_host' => (string) env('CLOUDFLARE_ACCESS_ALLOWED_HOST', 'facturacion.dulceslanegrita.com'),

    /*
    | Certificados públicos del team (endpoint oficial de Cloudflare):
    | https://{team_domain}/cdn-cgi/access/certs
    | Se cachean para no pegarle al endpoint en cada request. Si no se pueden
    | obtener o el kid no aparece, la validación FALLA CERRADA (no autentica).
    */
    'certs_cache_ttl' => (int) env('CLOUDFLARE_ACCESS_CERTS_TTL', 3600), // segundos
    'certs_timeout' => (int) env('CLOUDFLARE_ACCESS_CERTS_TIMEOUT', 5),  // segundos
];
