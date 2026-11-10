# Diagramas del sistema

Hechos con Archify (`tt-a1i/archify`, subcarpeta `archify`) el 7 de septiembre de 2026. Archify no viene en el repositorio: se instala aparte como skill, en `.claude/skills/archify`.

El de arquitectura se regeneró el 10 de noviembre de 2026 (#26): el título ya no lleva el nombre de la empresa y la interfaz figura como es, **Blade · Alpine**. Livewire estaba instalado sin uso —no había ningún componente en `app/`— y se quitó de `composer.json` en el PR #37 (03/10/2026); esa es la decisión registrada.

## Abrir

- [Arquitectura](arquitectura.html)
- [Emisión de DTE](emision-dte.html)

Los HTML son autónomos. No requieren arrancar Laravel ni conectarse a la base de datos. Los diagramas no están integrados al menú del sistema. Textos en español; controles del visor y atributo HTML lang con fallback inglés. El selector Flow permite comparar los estilos incluidos y el botón Light/Dark cambia el tema.

## Evidencia del código

- `composer.json`, `package.json`: Laravel 12, Blade con Alpine.js y Vite. Sin Livewire.
- `routes/web.php`, rutas y controladores de las áreas: agrupación funcional del mapa.
- `app/Http/Controllers/Facturacion/DteController.php`, `generarTransmitirProduccion`, `procesarFirmaTransmision`: preflight, confirmación, generación, firma y transmisión desde la petición web.
- `app/Services/Dte/DteTransmisionResiliente.php`: consulta antes del reenvío, reintentos acotados, sin activar contingencia automáticamente.
- `app/Services/Dte/DteTransmisionService.php`: recepción y persistencia del resultado.
- `app/Services/Dte/EnvioDteCorreoService.php`: historial y despacho de EnviarDteCorreo.
- `app/Observers/DteObserver.php`: autoenvío al aceptar, sujeto a ajustes, destinatario e historial.

Es un resumen del código local, no una verificación de infraestructura activa. No se consultó .env ni se ejecutó la aplicación. Las flechas del tramo Borrador → Preflight → Generar DTE → Firmar omiten etiquetas porque los extremos ya describen el orden de esas operaciones. El nodo Consultar / reintentar resume un subproceso; sus resultados y retornos no están desglosados.

## Validación y revisión

Ambos diagramas: validación showcase 9/9, 0 errores, 0 advertencias; browser_evidence: passed mediante Edge/Chromium, cuatro tamaños (1440×900, 1600×1000, 1920×1080, 2048×1320), sin desbordamiento.

Revisión perceptual de capturas: passed para la composición estática, temas claro a 1440×900 y oscuro a 2048×1320. No se probaron manualmente búsqueda, selección ni exportación. Las capturas y recibos visual-check están junto a los HTML; visualReview: pending en esos recibos es el estado automático de Archify y no sustituye esta revisión separada.

Arquitectura (regenerada el 10/11/2026): sin rondas de corrección, porque solo cambiaron textos. Fuente SHA-256 `888a27d2e7647cff908a9c25145d0528683b86f3ad029e536fad5912c4751d79`; HTML SHA-256 `8b1f3ea8425907d2de5ad0d35a046b48dd6613f6c8d776cb0bb9b8dc8985c0fa`. Validación showcase 9/9 y comprobación en navegador (Edge) en los cuatro tamaños, sin desbordamiento. Revisión de las capturas a la vista: 1440×900 en claro y 2048×1320 en oscuro. Sus recibos guardan la ruta de la copia de trabajo donde se generó.

Flujo DTE: correction_rounds: 1. Fuente SHA-256 `b5f80f4749423dd9934cf95cef064a182cfa32e29b2029120ddb0b6a5389073c`; HTML SHA-256 `b9b689a9ad0b1f18336987e723ce79126e30a9c4870f742bb0af553950f6aad4`.

Los recibos completos son `arquitectura.receipt.json` y `emision-dte.receipt.json`. Para regenerar, usar `node .claude/skills/archify/bin/archify.mjs deliver` con el tipo, fuente JSON, salida HTML y `--quality showcase --json`, y después `visual-check` sobre el HTML (con `ARCHIFY_CHROME` apuntando a Edge o Chrome si no lo encuentra solo). Leer primero el SKILL.md instalado.

Los diagramas no usan datos reales: ni nombres de empresa, clientes o personas, ni datos del servidor.
