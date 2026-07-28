<?php

/*
| Módulo Producción / Planta — área operativa AISLADA del sistema.
|
| Nombre técnico interno: «planta». Etiqueta visible en la UI: «Producción».
| La distinción es deliberada: en este proyecto la palabra "producción" ya
| significa AMBIENTE FISCAL DE HACIENDA (ambiente '01', emisión real, el badge
| "NO EMITE PRODUCCIÓN", DTE_TRANSMISION_ALLOW_PRODUCTION, APP_ENV=production).
| Usar «planta» en rutas, permisos, config y namespaces evita que alguien lea
| "Producción apagada" y crea que se apagó la emisión fiscal.
|
| El módulo NO emite DTE, no toca correlativos, firma, transmisión, correo, PPQ,
| exportaciones ni contabilidad.
*/
return [
    /*
    | Interruptor del MÓDULO COMPLETO (no de una capacidad suelta, como los flags
    | de config/dte.php). Apagado:
    |   - /planta responde 404 para TODOS los roles, incluido administrador;
    |   - el área desaparece del selector superior;
    |   - la navegación histórica queda idéntica.
    |
    | Las rutas se registran SIEMPRE (aunque el flag esté apagado): el 404 lo
    | produce el middleware `modulo.planta`. Así route('planta.dashboard') nunca
    | lanza RouteNotFoundException al renderizar una vista compartida.
    |
    | NO tiene ninguna relación con el ambiente MH ni con APP_ENV.
    */
    'enabled' => (bool) env('PLANTA_ENABLED', false),
];
